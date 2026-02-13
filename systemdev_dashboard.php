<?php /* SystemDev dashboard: activities, projects, at status. */

require_once 'includes/auth_guard.php';
require_once 'config/database.php';

date_default_timezone_set('Asia/Manila');

$role = strtolower($_SESSION['role'] ?? ($_SESSION['login_user']['role'] ?? ''));
$userId = (int)($_SESSION['user_id'] ?? ($_SESSION['login_user']['id'] ?? 0));
if ($role !== 'systemdev') { header('Location: dashboard.php'); exit; }

$pdo = null;
try { $pdo = getDBConnection(); } catch (Exception $e) { $pdo = null; }

try {
  $activeProjectsCount = 0;
  $totalTasksCount = 0;
  $overdueTasksCount = 0;
  $avgPerformanceRaw = null;
  if ($pdo) {
    $st = $pdo->prepare('SELECT COUNT(DISTINCT project_id) FROM tasks WHERE assignee_id = ?');
    $st->execute([$userId]);
    $activeProjectsCount = (int)($st->fetchColumn() ?: 0);

    $st = $pdo->prepare('SELECT COUNT(*) FROM tasks WHERE assignee_id = ?');
    $st->execute([$userId]);
    $totalTasksCount = (int)($st->fetchColumn() ?: 0);

    $st = $pdo->prepare("SELECT COUNT(*) FROM tasks t LEFT JOIN task_statuses ts ON ts.id = t.task_status_id WHERE t.assignee_id = ? AND t.due_date IS NOT NULL AND t.due_date < CURDATE() AND COALESCE(ts.`key`, 'todo') <> 'done'");
    $st->execute([$userId]);
    $overdueTasksCount = (int)($st->fetchColumn() ?: 0);

    try {
      $st = $pdo->prepare('SELECT AVG(rating) FROM performance_records WHERE user_id = ?');
      $st->execute([$userId]);
      $avgPerformanceRaw = $st->fetchColumn();
    } catch (Exception $e) { $avgPerformanceRaw = null; }
  }
  $avgPerformance = $avgPerformanceRaw !== null ? round((float)$avgPerformanceRaw, 1) : 0;

  $statusRows = [];
  try {
    $st = $pdo->prepare('SELECT COALESCE(ts.`key`, \'' . 'todo' . '\') AS status, COUNT(*) AS cnt FROM tasks t LEFT JOIN task_statuses ts ON ts.id = t.task_status_id WHERE t.assignee_id = ? GROUP BY COALESCE(ts.`key`, \'' . 'todo' . '\')');
    $st->execute([$userId]);
    $statusRows = $st->fetchAll();
  } catch (Exception $e) { $statusRows = []; }
  $blockedCount = 0;
  try {
    $st = $pdo->prepare('SELECT COUNT(*) FROM tasks WHERE assignee_id = ? AND is_blocked = 1');
    $st->execute([$userId]);
    $blockedCount = (int)($st->fetchColumn() ?: 0);
  } catch (Exception $e) {}
  $statusMap = ['Completed'=>0,'In Progress'=>0,'Pending'=>0,'Blocked'=>$blockedCount];
  foreach ($statusRows as $row) {
    $s = strtolower($row['status'] ?? '');
    $cnt = (int)$row['cnt'];
    if (in_array($s, ['completed','done'])) $statusMap['Completed'] += $cnt;
    elseif (in_array($s, ['active','in-progress'])) $statusMap['In Progress'] += $cnt;
    elseif (in_array($s, ['pending','planned','waiting','todo','review'])) $statusMap['Pending'] += $cnt;
    else $statusMap['Pending'] += $cnt;
  }

  $priorityRows = [];
  try {
    $st = $pdo->prepare('SELECT priority, COUNT(*) AS cnt FROM tasks WHERE assignee_id = ? GROUP BY priority');
    $st->execute([$userId]);
    $priorityRows = $st->fetchAll();
  } catch (Exception $e) { $priorityRows = []; }
  $priorityMap = ['critical'=>0, 'high'=>0, 'medium'=>0, 'low'=>0];
  foreach ($priorityRows as $row) {
    $p = strtolower($row['priority'] ?? 'medium');
    if (isset($priorityMap[$p])) $priorityMap[$p] += (int)$row['cnt'];
    else $priorityMap['medium'] += (int)$row['cnt'];
  }

  $projRows = [];
  try {
    $st = $pdo->prepare('SELECT DISTINCT p.id, p.name, p.status, p.progress, p.due_date FROM projects p INNER JOIN tasks t ON t.project_id = p.id WHERE t.assignee_id = ? ORDER BY p.id DESC LIMIT 12');
    $st->execute([$userId]);
    $projRows = $st->fetchAll();
  } catch (Exception $e) { $projRows = []; }
  $activeProjects = array_map(function($r){
    $due = $r['due_date'] ?? null;
    $overdue = 0;
    if ($due) { try { $overdue = (new DateTime($due) < new DateTime()) ? 1 : 0; } catch (Exception $e) { $overdue = 0; } }
    return [ 'name'=>$r['name'] ?? 'Untitled', 'progress'=>isset($r['progress']) ? (int)$r['progress'] : 0, 'status'=>$r['status'] ?? 'active', 'overdue'=>$overdue, 'team'=>0, 'priority'=>'medium' ];
  }, $projRows ?: []);

  $criticalAlerts = [];
  foreach ($activeProjects as $p) {
    if (($p['overdue'] ?? 0) > 0) { $criticalAlerts[] = [ 'title'=>'Overdue milestone', 'priority'=>'high', 'age'=>'today', 'project'=>$p['name'] ]; }
  }

  $createdMap = [];
  $completedMap = [];
  try {
    $st = $pdo->prepare('SELECT DATE(created_at) AS d, COUNT(*) AS c FROM tasks WHERE assignee_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(created_at)');
    $st->execute([$userId]);
    foreach ($st->fetchAll() as $row) { $createdMap[$row['d']] = (int)$row['c']; }
  } catch (Exception $e) {}
  try {
    $st = $pdo->prepare("SELECT DATE(t.updated_at) AS d, COUNT(*) AS c FROM tasks t LEFT JOIN task_statuses ts ON ts.id = t.task_status_id WHERE t.assignee_id = ? AND ts.`key` = 'done' AND t.updated_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(t.updated_at)");
    $st->execute([$userId]);
    foreach ($st->fetchAll() as $row) { $completedMap[$row['d']] = (int)$row['c']; }
  } catch (Exception $e) {}
  $weeklyActivity = [];
  for ($i = 6; $i >= 0; $i--) {
    $date = (new DateTime())->sub(new DateInterval('P'.$i.'D'))->format('Y-m-d');
    $label = (new DateTime($date))->format('D');
    $created = $createdMap[$date] ?? 0;
    $completed = $completedMap[$date] ?? 0;
    $velocity = $created > 0 ? round(($completed / $created) * 100) : 0;
    $weeklyActivity[] = ['day'=>$label,'completed'=>$completed,'created'=>$created,'velocity'=>$velocity];
  }

  $devDashboardData = [
    'metrics' => [
      ['title'=>'Active Projects','value'=>$activeProjectsCount,'change'=>null,'trend'=>null],
      ['title'=>'Total Tasks','value'=>$totalTasksCount,'change'=>null,'trend'=>null],
      ['title'=>'Overdue Tasks','value'=>$overdueTasksCount,'change'=>null,'trend'=>null],
      ['title'=>'Avg Performance','value'=>$avgPerformance,'change'=>null,'trend'=>null],
    ],
    'projectStatus' => [
      ['name'=>'Completed','value'=>$statusMap['Completed'],'color'=>'#22c55e'],
      ['name'=>'In Progress','value'=>$statusMap['In Progress'],'color'=>'#3b82f6'],
      ['name'=>'Pending','value'=>$statusMap['Pending'],'color'=>'#f59e0b'],
      ['name'=>'Blocked','value'=>$statusMap['Blocked'],'color'=>'#ef4444'],
    ],
    'taskPriority' => [
      ['name'=>'Critical', 'value'=>$priorityMap['critical'], 'color'=>'#dc3545'],
      ['name'=>'High', 'value'=>$priorityMap['high'], 'color'=>'#fd7e14'],
      ['name'=>'Medium', 'value'=>$priorityMap['medium'], 'color'=>'#0dcaf0'],
      ['name'=>'Low', 'value'=>$priorityMap['low'], 'color'=>'#198754'],
    ],
    'weeklyActivity' => $weeklyActivity,
    'activeProjects' => $activeProjects,
    'criticalAlerts' => $criticalAlerts,
  ];
} catch (Exception $e) {
  $devDashboardData = [
    'metrics' => [
      ['title'=>'Active Projects','value'=>0,'change'=>null,'trend'=>null],
      ['title'=>'Total Tasks','value'=>0,'change'=>null,'trend'=>null],
      ['title'=>'Overdue Tasks','value'=>0,'change'=>null,'trend'=>null],
      ['title'=>'Avg Performance','value'=>0,'change'=>null,'trend'=>null],
    ],
    'projectStatus' => [
      ['name'=>'Completed','value'=>0,'color'=>'#22c55e'],
      ['name'=>'In Progress','value'=>0,'color'=>'#3b82f6'],
      ['name'=>'Pending','value'=>0,'color'=>'#f59e0b'],
      ['name'=>'Blocked','value'=>0,'color'=>'#ef4444'],
    ],
    'taskPriority' => [
      ['name'=>'Critical', 'value'=>0, 'color'=>'#dc3545'],
      ['name'=>'High', 'value'=>0, 'color'=>'#fd7e14'],
      ['name'=>'Medium', 'value'=>0, 'color'=>'#0dcaf0'],
      ['name'=>'Low', 'value'=>0, 'color'=>'#198754'],
    ],
    'weeklyActivity' => [
      ['day'=>'Mon','completed'=>0,'created'=>0,'velocity'=>0],
      ['day'=>'Tue','completed'=>0,'created'=>0,'velocity'=>0],
      ['day'=>'Wed','completed'=>0,'created'=>0,'velocity'=>0],
      ['day'=>'Thu','completed'=>0,'created'=>0,'velocity'=>0],
      ['day'=>'Fri','completed'=>0,'created'=>0,'velocity'=>0],
      ['day'=>'Sat','completed'=>0,'created'=>0,'velocity'=>0],
      ['day'=>'Sun','completed'=>0,'created'=>0,'velocity'=>0],
    ],
    'activeProjects' => [],
    'criticalAlerts' => [],
  ];
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'metrics') {
  header('Content-Type: application/json');
  echo json_encode($devDashboardData);
  exit;
}

$page_title = 'SystemDev Dashboard';
$current_page = basename(__FILE__);
include 'includes/header.php';

$js_admin = json_encode($devDashboardData, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
  body { background:#f8fafc; color:#0f172a; padding:18px; overflow-y: auto; }
  .muted { color:#6b7280; }
  .card-hover:hover { transform: translateY(-6px); box-shadow: 0 12px 30px rgba(15,23,42,0.06); transition: .16s; }
  .avatar { width:36px;height:36px;border-radius:8px;background:#e9ecef;display:flex;align-items:center;justify-content:center;font-weight:700;color:#374151; }
  .progress-small { height:10px; }
  .dot { display:inline-block;width:10px;height:10px;border-radius:2px; }
  .search-input { max-width:420px; }
  .stat-number { font-size:1.6rem; font-weight:700; }
  .container-fluid { min-height: calc(100vh - 120px); overflow-y: auto; padding-bottom: 2rem; }
</style>
<div class="container-fluid">
  <div class="d-flex flex-column flex-md-row justify-content-between align-items-start mb-4">
    <div>
      <h2 class="mb-0">Developer Overview</h2>
      <div class="muted">Your tasks, projects, and performance</div>
    </div>
    <div class="mt-3 mt-md-0 d-flex gap-2 align-items-center">
      <input id="projectSearch" class="form-control form-control-sm search-input" placeholder="Search active projects..." />
      <button id="toggleAlertsBtn" class="btn btn-outline-secondary btn-sm ms-2"><i class="bi bi-bell"></i> Toggle Alerts</button>
    </div>
  </div>

  <div class="row g-3 mb-4" id="metricsGrid">
    <?php foreach($devDashboardData['metrics'] as $m): ?>
      <div class="col-6 col-md-3">
        <div class="card p-3 card-hover metric-card" data-metric="<?= htmlspecialchars($m['title']) ?>">
          <div class="d-flex justify-content-between align-items-center">
            <div class="small muted d-flex align-items-center gap-2">
              <span><?= htmlspecialchars($m['title']) ?></span>
            </div>
          </div>
          <div class="stat-number mt-1"><?= htmlspecialchars($m['value']) ?></div>
          <div class="small muted mt-1 d-flex justify-content-between align-items-center">
            <span><?= htmlspecialchars($m['change']) ?></span>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-lg-4">
      <div class="card card-hover mb-3 h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2"><h5 class="mb-0">Task Status</h5><div class="muted">Distribution</div></div>
          <canvas id="statusPie" style="max-height:260px"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card card-hover mb-3 h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2"><h5 class="mb-0">Task Priority</h5><div class="muted">By Level</div></div>
          <canvas id="priorityBar" style="max-height:260px"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card card-hover h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2"><h5 class="mb-0">Weekly Activity</h5><div class="muted">Velocity</div></div>
          <canvas id="weeklyChart" style="max-height:260px"></canvas>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-lg-6">
      <div class="card card-hover">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
              <h5 class="mb-0">My Active Projects</h5>
              <div class="muted">Projects linked to your tasks</div>
            </div>
            <div class="muted"><?= count($devDashboardData['activeProjects']) ?> projects</div>
          </div>
          <div id="projectsList">
            <?php foreach($devDashboardData['activeProjects'] as $proj): ?>
              <div class="mb-3 project-item" data-name="<?= htmlspecialchars(strtolower($proj['name'])) ?>">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <div>
                    <div class="fw-medium"><?= htmlspecialchars($proj['name']) ?></div>
                    <div class="small muted">
                      <span class="text-primary"><?= htmlspecialchars($proj['status']) ?></span>
                      <?php if($proj['overdue']>0): ?><span class="badge bg-danger ms-2"><?= $proj['overdue'] ?> overdue</span><?php endif; ?>
                    </div>
                  </div>
                  <div class="text-end">
                    <div class="fw-medium"><?= $proj['progress'] ?>%</div>
                    <div class="small muted">0 members</div>
                  </div>
                </div>
                <div class="progress progress-small"><div class="progress-bar" role="progressbar" style="width: <?= $proj['progress'] ?>%" aria-valuenow="<?= $proj['progress'] ?>" aria-valuemin="0" aria-valuemax="100"></div></div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="mt-3 d-flex gap-2">
            <button id="filterHighBtn" class="btn btn-outline-primary btn-sm">Show High Priority</button>
            <button id="showAllBtn" class="btn btn-outline-secondary btn-sm">Show All</button>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card">
        <div class="card-body">
          <div class="d-flex justify-content-between mb-2"><h5 class="mb-0">Weekly Activity (table)</h5><div class="muted">Last 7 days</div></div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0"><thead><tr class="muted"><th>Day</th><th class="text-end">Completed</th><th class="text-end">Created</th><th class="text-end">Velocity %</th></tr></thead><tbody id="weeklyTableBody">
              <?php foreach($devDashboardData['weeklyActivity'] as $row): ?>
                <tr><td><?= htmlspecialchars($row['day']) ?></td><td class="text-end"><?= htmlspecialchars($row['completed']) ?></td><td class="text-end"><?= htmlspecialchars($row['created']) ?></td><td class="text-end"><?= htmlspecialchars($row['velocity']) ?>%</td></tr>
              <?php endforeach; ?>
            </tbody></table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  const adminData = <?= $js_admin ?>;
  let devStatusPie = null;
  (function(){ const ctx = document.getElementById('statusPie').getContext('2d'); const labels = adminData.projectStatus.map(s=>s.name); const data = adminData.projectStatus.map(s=>s.value); const colors = adminData.projectStatus.map(s=>s.color); devStatusPie = new Chart(ctx,{ type:'pie', data:{ labels, datasets:[{ data, backgroundColor:colors, borderWidth:1 }] }, options:{ plugins:{ legend:{ position:'bottom' } }, responsive:true, maintainAspectRatio:false } }); })();
  
  let devPriorityBar = null;
  (function(){ const ctx = document.getElementById('priorityBar').getContext('2d'); const labels = adminData.taskPriority.map(s=>s.name); const data = adminData.taskPriority.map(s=>s.value); const colors = adminData.taskPriority.map(s=>s.color); devPriorityBar = new Chart(ctx,{ type:'bar', data:{ labels, datasets:[{ label:'Tasks', data, backgroundColor:colors, borderRadius:4 }] }, options:{ plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true, ticks:{ stepSize:1 } } }, responsive:true, maintainAspectRatio:false } }); })();

  let devWeeklyChart = null;
  (function(){ const ctx = document.getElementById('weeklyChart').getContext('2d'); const days = adminData.weeklyActivity.map(r=>r.day); const completed = adminData.weeklyActivity.map(r=>r.completed); const created = adminData.weeklyActivity.map(r=>r.created); const velocity = adminData.weeklyActivity.map(r=>r.velocity); devWeeklyChart = new Chart(ctx,{ type:'line', data:{ labels:days, datasets:[ { label:'Completed', data:completed, borderColor:'#22c55e', backgroundColor:'rgba(34,197,94,0.2)', tension:0.3, fill:false }, { label:'Created', data:created, borderColor:'#3b82f6', backgroundColor:'rgba(59,130,246,0.2)', tension:0.3, fill:false }, { label:'Velocity %', data:velocity, borderColor:'#8b5cf6', backgroundColor:'rgba(139,92,246,0.2)', borderDash:[5,5], tension:0.3, fill:false } ] }, options:{ responsive:true, maintainAspectRatio:false, scales:{ y:{ beginAtZero:true } }, plugins:{ legend:{ position:'bottom' } } }); })();
  function applyDevData(data){
    document.querySelectorAll('.metric-card').forEach(card=>{ const title = card.getAttribute('data-metric'); const m = (data.metrics||[]).find(x=>x.title===title); if (m) { const valEl = card.querySelector('.stat-number'); if (valEl) valEl.textContent = m.value; } });
    
    if (devStatusPie) {
      devStatusPie.data.labels = (data.projectStatus||[]).map(s=>s.name);
      devStatusPie.data.datasets[0].data = (data.projectStatus||[]).map(s=>s.value);
      devStatusPie.data.datasets[0].backgroundColor = (data.projectStatus||[]).map(s=>s.color);
      devStatusPie.update();
    }
    if (devPriorityBar) {
      devPriorityBar.data.labels = (data.taskPriority||[]).map(s=>s.name);
      devPriorityBar.data.datasets[0].data = (data.taskPriority||[]).map(s=>s.value);
      devPriorityBar.data.datasets[0].backgroundColor = (data.taskPriority||[]).map(s=>s.color);
      devPriorityBar.update();
    }

    const days = (data.weeklyActivity||[]).map(r=>r.day);
    const completed = (data.weeklyActivity||[]).map(r=>r.completed);
    const created = (data.weeklyActivity||[]).map(r=>r.created);
    const velocity = (data.weeklyActivity||[]).map(r=>r.velocity);
    if (devWeeklyChart) { devWeeklyChart.data.labels = days; devWeeklyChart.data.datasets[0].data = completed; devWeeklyChart.data.datasets[1].data = created; devWeeklyChart.data.datasets[2].data = velocity; devWeeklyChart.update(); }
    const tbody = document.getElementById('weeklyTableBody'); if (tbody) { tbody.innerHTML = (data.weeklyActivity||[]).map(r=>`<tr><td>${r.day}</td><td class="text-end">${r.completed}</td><td class="text-end">${r.created}</td><td class="text-end">${r.velocity}%</td></tr>`).join(''); }
  }
  function refreshDev(){ fetch('systemdev_dashboard.php?ajax=metrics').then(r=>r.json()).then(applyDevData).catch(()=>{}); }
  setInterval(refreshDev, 10000);
  const toggleAlertsBtn = document.getElementById('toggleAlertsBtn'); toggleAlertsBtn?.addEventListener('click', ()=>{});
  const projectSearch = document.getElementById('projectSearch'); projectSearch?.addEventListener('input', function(){ const q = this.value.trim().toLowerCase(); document.querySelectorAll('.project-item').forEach(item=>{ const name = item.getAttribute('data-name') || ''; item.style.display = (q === '' || name.includes(q)) ? '' : 'none'; }); });
  document.getElementById('filterHighBtn')?.addEventListener('click', ()=>{ document.querySelectorAll('.project-item').forEach(item=>{ const name = item.querySelector('.fw-medium')?.textContent || ''; const proj = adminData.activeProjects.find(p => p.name === name); if(proj) item.style.display = (proj.priority === 'high' || proj.priority === 'critical') ? '' : 'none'; }); });
  document.getElementById('showAllBtn')?.addEventListener('click', ()=>{ document.querySelectorAll('.project-item').forEach(item=> item.style.display = ''); projectSearch.value=''; });
</script>
<?php include 'includes/footer.php'; ?>