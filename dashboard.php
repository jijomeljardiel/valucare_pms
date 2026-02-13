<?php /* Admin dashboard: kumukuha ng metrics at nagre-render ng overview UI. */
require_once 'includes/auth_guard.php';
require_once 'config/database.php';

date_default_timezone_set('Asia/Manila');

function fmtDate($d, $fmt = 'M j, Y') {
    $dt = new DateTime($d);
    return $dt->format($fmt);
}

function badgeClassPriority($p) {
    return match($p) {
        'critical' => 'badge bg-danger',
        'high' => 'badge bg-warning text-dark',
        'medium' => 'badge bg-info text-dark',
        'low' => 'badge bg-success',
        default => 'badge bg-secondary'
    };
}

function statusColorClass($s) {
    $t = strtolower(trim($s));
    return match($t) {
        'active', 'in-progress' => 'text-success',
        'at-risk' => 'text-warning',
        'blocked', 'on-hold' => 'text-danger',
        default => 'text-primary'
    };
}

function metricIconClass($title) {
    $t = strtolower(trim($title));
    return match($t) {
        'active projects' => 'bi-code-slash',
        'total tasks' => 'bi-check2-square',
        'team members' => 'bi-people',
        'overdue tasks' => 'bi-exclamation-triangle',
        default => 'bi-info-circle'
    };
}

try {
  $pdo = getDBConnection();

  $activeProjectsCount = 0;
  try { $activeProjectsCount = (int)($pdo->query("SELECT COUNT(*) FROM projects p JOIN project_statuses ps ON ps.id = p.project_status_id WHERE ps.`key` IN ('active','at-risk')")->fetchColumn() ?: 0); } catch (Throwable $e) { $activeProjectsCount = 0; }
  $totalTasksCount = 0;
  try { $totalTasksCount = (int)($pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn() ?: 0); } catch (Throwable $e) { $totalTasksCount = 0; }
  $teamMembersCount = 0;
  try { $teamMembersCount = (int)($pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() ?: 0); } catch (Throwable $e) { $teamMembersCount = 0; }
  $overdueTasksCount = 0;
  try { $overdueTasksCount = (int)($pdo->query("SELECT COUNT(*) FROM tasks t LEFT JOIN task_statuses ts ON ts.id = t.task_status_id WHERE t.due_date IS NOT NULL AND t.due_date < CURDATE() AND (ts.`key` IS NULL OR ts.`key` <> 'done')")->fetchColumn() ?: 0); } catch (Throwable $e) { $overdueTasksCount = 0; }

  $statusRows = [];
  try { $statusRows = $pdo->query("SELECT ps.`key` AS status, COUNT(*) AS cnt FROM projects p JOIN project_statuses ps ON ps.id = p.project_status_id GROUP BY ps.`key`")->fetchAll(); } catch (Throwable $e) { $statusRows = []; }
  $statusMap = ['Completed'=>0,'In Progress'=>0,'Pending'=>0,'Blocked'=>0];
  foreach ($statusRows as $row) {
    $s = strtolower($row['status'] ?? '');
    $cnt = (int)$row['cnt'];
    if (in_array($s, ['completed','done'])) $statusMap['Completed'] += $cnt;
    elseif (in_array($s, ['active','in-progress'])) $statusMap['In Progress'] += $cnt;
    elseif (in_array($s, ['pending','planned','waiting','planning'])) $statusMap['Pending'] += $cnt;
    elseif (in_array($s, ['blocked','on-hold'])) $statusMap['Blocked'] += $cnt;
    else $statusMap['Pending'] += $cnt;
  }

  $projRows = [];
  try { $projRows = $pdo->query("SELECT p.id, p.name, ps.`key` AS status, p.progress, p.due_date, p.priority FROM projects p JOIN project_statuses ps ON ps.id = p.project_status_id ORDER BY p.id DESC LIMIT 12")->fetchAll(); } catch (Throwable $e) { $projRows = []; }
  $activeProjects = array_map(function($r){
    $due = $r['due_date'] ?? null;
    $overdue = 0;
    if ($due) {
      try { $overdue = (new DateTime($due) < new DateTime()) ? 1 : 0; } catch (Throwable $e) { $overdue = 0; }
    }
    return [
      'name' => $r['name'] ?? 'Untitled',
      'progress' => isset($r['progress']) ? (int)$r['progress'] : 0,
      'status' => $r['status'] ?? 'active',
      'overdue' => $overdue,
      'team' => 0,
      'priority' => strtolower($r['priority'] ?? 'medium')
    ];
  }, $projRows ?: []);

  $criticalAlerts = [];
  foreach ($activeProjects as $p) {
    if (($p['overdue'] ?? 0) > 0) {
      $criticalAlerts[] = [
        'title' => 'Overdue milestone',
        'priority' => 'high',
        'age' => 'today',
        'project' => $p['name']
      ];
    }
  }

  $createdMap = [];
  $completedMap = [];
  try {
    foreach ($pdo->query("SELECT DATE(created_at) AS d, COUNT(*) AS c FROM tasks WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY d") as $row) {
      $createdMap[$row['d']] = (int)$row['c'];
    }
  } catch (Throwable $e) {}
  try {
    foreach ($pdo->query("SELECT DATE(t.updated_at) AS d, COUNT(*) AS c FROM tasks t LEFT JOIN task_statuses ts ON ts.id = t.task_status_id WHERE ts.`key`='done' AND t.updated_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY d") as $row) {
      $completedMap[$row['d']] = (int)$row['c'];
    }
  } catch (Throwable $e) {}
  $weeklyActivity = [];
  for ($i = 6; $i >= 0; $i--) {
    $date = (new DateTime())->sub(new DateInterval('P'.$i.'D'))->format('Y-m-d');
    $label = (new DateTime($date))->format('D');
    $created = $createdMap[$date] ?? 0;
    $completed = $completedMap[$date] ?? 0;
    $velocity = $created > 0 ? round(($completed / $created) * 100) : 0;
    $weeklyActivity[] = ['day'=>$label,'completed'=>$completed,'created'=>$created,'velocity'=>$velocity];
  }

  $adminDashboardData = [
    'metrics' => [
      ['title'=>'Active Projects','value'=>$activeProjectsCount,'change'=>null,'trend'=>null],
      ['title'=>'Total Tasks','value'=>$totalTasksCount,'change'=>null,'trend'=>null],
      ['title'=>'Team Members','value'=>$teamMembersCount,'change'=>null,'trend'=>null],
      ['title'=>'Overdue Tasks','value'=>$overdueTasksCount,'change'=>null,'trend'=>null],
    ],
    'projectStatus' => [
      ['name'=>'Completed','value'=>$statusMap['Completed'],'color'=>'#22c55e'],
      ['name'=>'In Progress','value'=>$statusMap['In Progress'],'color'=>'#3b82f6'],
      ['name'=>'Pending','value'=>$statusMap['Pending'],'color'=>'#f59e0b'],
      ['name'=>'Blocked','value'=>$statusMap['Blocked'],'color'=>'#ef4444'],
    ],
    'weeklyActivity' => $weeklyActivity,
    'activeProjects' => $activeProjects,
    'criticalAlerts' => $criticalAlerts,
  ];
} catch (Throwable $e) {
  $adminDashboardData = [
    'metrics' => [
      ['title'=>'Active Projects','value'=>0],
      ['title'=>'Total Tasks','value'=>0],
      ['title'=>'Team Members','value'=>0],
      ['title'=>'Overdue Tasks','value'=>0],
    ],
    'projectStatus' => [],
    'weeklyActivity' => [],
    'activeProjects' => [],
    'criticalAlerts' => [],
  ];
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'metrics') {
  header('Content-Type: application/json');
  echo json_encode($adminDashboardData);
  exit;
}

$js_admin = json_encode($adminDashboardData, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
$page_title = 'Dashboard';
include 'includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<style>
  :root {
    --glass-bg: rgba(255, 255, 255, 0.7);
    --glass-border: rgba(255, 255, 255, 0.3);
  }
  body { background:#f1f5f9; color:#1e293b; padding:20px; }
  .muted { color:#64748b; }
  
  .metric-card { 
    background: white;
    border: 1px solid var(--glass-border);
    border-radius: 16px;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
  }
  .metric-card:hover { 
    transform: translateY(-5px);
    box-shadow: 0 12px 24px rgba(0,0,0,0.05);
    background: #fff;
  }
  .metric-card::after {
    content: "";
    position: absolute;
    bottom: 0; left: 0; width: 100%; height: 4px;
    background: linear-gradient(90deg, #6366f1, #a855f7);
    opacity: 0; transition: opacity 0.3s;
  }
  .metric-card:hover::after { opacity: 1; }

  .card { border-radius: 16px; border: none; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 20px; }
  .card-header { background: transparent; border-bottom: 1px solid #f1f5f9; padding: 1.25rem; font-weight: 700; }
  
  .stat-number { font-size: 2.25rem; font-weight: 800; color: #0f172a; }
  .icon-box { 
    width: 48px; height: 48px; 
    border-radius: 12px; 
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem;
    background: #f8fafc;
    color: #6366f1;
  }

  .projects-scroll { max-height: 450px; overflow-y: auto; padding: 5px; }
  .project-item { padding: 15px; border-radius: 12px; border: 1px solid #f1f5f9; transition: background 0.2s; }
  .project-item:hover { background: #f8fafc; }
  
  .progress-small { height: 8px; border-radius: 10px; background: #f1f5f9; }
  .dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right: 8px; }

  .alert-critical { border-left: 5px solid #ef4444; background: #fff1f2; }
  
  .table thead th { background: #f8fafc; border: none; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; color: #64748b; }
</style>

<div class="container-fluid">
  <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4">
    <div>
      <h2 class="fw-extrabold tracking-tight mb-0">System Overview</h2>
      <p class="muted mb-0">Live analytics and project health monitoring.</p>
    </div>
    <div class="mt-3 mt-md-0 d-flex gap-3">
      <div class="input-group">
        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
        <input id="projectSearch" class="form-control border-start-0" placeholder="Search projects..." style="width: 250px;">
      </div>
    </div>
  </div>

  <div class="row g-3 mb-4" id="metricsGrid">
    <?php foreach($adminDashboardData['metrics'] as $m): ?>
      <div class="col-6 col-md-3">
        <div class="card p-4 metric-card" onclick="window.location.href='project_task.php'">
          <div class="d-flex justify-content-between align-items-start mb-3">
            <div class="icon-box">
              <i class="bi <?= metricIconClass($m['title']) ?>"></i>
            </div>
          </div>
          <div class="small muted fw-bold text-uppercase"><?= htmlspecialchars($m['title']) ?></div>
          <div class="stat-number"><?= htmlspecialchars($m['value']) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (!empty($adminDashboardData['criticalAlerts'])): ?>
  <div class="row mb-4">
    <div class="col-12">
      <div class="card border-0 shadow-sm overflow-hidden">
        <div class="card-header bg-white d-flex align-items-center">
            <i class="bi bi-exclamation-octagon-fill text-danger me-2"></i>
            <span>Critical Alerts Requiring Attention</span>
        </div>
        <div class="card-body p-0">
          <div id="alertsList">
            <?php foreach($adminDashboardData['criticalAlerts'] as $a): 
              $color = $a['priority'] === 'high' ? '#f59e0b' : '#ef4444';
            ?>
              <div class="d-flex justify-content-between align-items-center p-3 border-bottom alert-critical">
                <div class="d-flex align-items-center">
                  <span class="dot" style="background:<?= $color ?>"></span>
                  <div>
                    <div class="fw-bold"><?= htmlspecialchars($a['title']) ?></div>
                    <div class="small muted">Project: <?= htmlspecialchars($a['project']) ?></div>
                  </div>
                </div>
                <button class="btn btn-sm btn-dark rounded-pill px-3 investigate-btn" data-project="<?= htmlspecialchars($a['project']) ?>">Action</button>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card h-100">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Project Development Status</h5>
          <span class="badge bg-primary rounded-pill"><?= count($adminDashboardData['activeProjects']) ?> Total</span>
        </div>
        <div class="card-body">
          <div id="projectsList" class="projects-scroll">
            <?php foreach($adminDashboardData['activeProjects'] as $proj): ?>
              <div class="project-item mb-3" data-name="<?= htmlspecialchars(strtolower($proj['name'])) ?>">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <div>
                    <div class="fw-bold h6 mb-0"><?= htmlspecialchars($proj['name']) ?></div>
                    <span class="small fw-bold <?= statusColorClass($proj['status']) ?>"><?= strtoupper($proj['status']) ?></span>
                  </div>
                  <div class="text-end">
                    <span class="fw-bold"><?= $proj['progress'] ?>%</span>
                    <div class="small muted">Completion</div>
                  </div>
                </div>
                <div class="progress progress-small">
                  <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $proj['progress'] ?>%"></div>
                </div>
                <?php if($proj['overdue'] > 0): ?>
                  <div class="mt-2 small text-danger fw-bold"><i class="bi bi-clock-history me-1"></i> PAST DUE DATE</div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="card-footer bg-white border-0 d-flex gap-2 p-3">
            <button id="filterHighBtn" class="btn btn-light btn-sm fw-bold">High Priority</button>
            <button id="showAllBtn" class="btn btn-primary btn-sm fw-bold">Show All</button>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card mb-4">
        <div class="card-header bg-white">Task Distribution</div>
        <div class="card-body">
          <canvas id="statusPie" style="height:250px"></canvas>
        </div>
      </div>
      <div class="card">
        <div class="card-header bg-white">Velocity Trend</div>
        <div class="card-body">
          <canvas id="weeklyChart" style="height:250px"></canvas>
        </div>
      </div>
    </div>
  </div>

  <div class="row mt-4">
    <div class="col-12">
      <div class="card">
        <div class="card-header bg-white">Weekly Activity Metrics</div>
        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead>
              <tr>
                <th class="ps-4">Reporting Day</th>
                <th class="text-center">Tasks Completed</th>
                <th class="text-center">Tasks Created</th>
                <th class="text-end pe-4">Team Velocity (%)</th>
              </tr>
            </thead>
            <tbody id="weeklyTableBody">
              <?php foreach($adminDashboardData['weeklyActivity'] as $row): ?>
                <tr>
                  <td class="ps-4 fw-bold"><?= htmlspecialchars($row['day']) ?></td>
                  <td class="text-center"><?= htmlspecialchars($row['completed']) ?></td>
                  <td class="text-center"><?= htmlspecialchars($row['created']) ?></td>
                  <td class="text-end pe-4">
                    <span class="badge bg-soft-primary text-primary"><?= htmlspecialchars($row['velocity']) ?>%</span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  const adminData = <?= $js_admin ?>;

  // Chart Logic
  let statusPieChart = null;
  (function renderStatusPie(){
    const ctx = document.getElementById('statusPie').getContext('2d');
    statusPieChart = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: adminData.projectStatus.map(s=>s.name),
        datasets: [{
          data: adminData.projectStatus.map(s=>s.value),
          backgroundColor: adminData.projectStatus.map(s=>s.color),
          hoverOffset: 15,
          borderWidth: 0
        }]
      },
      options: { 
        cutout: '70%',
        plugins: { legend: { position:'bottom', labels: { usePointStyle: true, padding: 20 } } },
        responsive:true, maintainAspectRatio:false 
      }
    });
  })();

  let weeklyChartInstance = null;
  (function renderWeekly(){
    const ctx = document.getElementById('weeklyChart').getContext('2d');
    weeklyChartInstance = new Chart(ctx, {
      type: 'line',
      data: {
        labels: adminData.weeklyActivity.map(r=>r.day),
        datasets: [
          { label:'Completed', data:adminData.weeklyActivity.map(r=>r.completed), borderColor:'#22c55e', tension:0.4, fill: true, backgroundColor: 'rgba(34, 197, 94, 0.05)' },
          { label:'Created', data:adminData.weeklyActivity.map(r=>r.created), borderColor:'#3b82f6', tension:0.4, fill: false }
        ]
      },
      options: {
        responsive:true, maintainAspectRatio:false,
        scales: { y: { beginAtZero:true, grid: { display: false } }, x: { grid: { display: false } } },
        plugins:{ legend:{ position:'bottom' } }
      }
    });
  })();

  // Search Interactivity
  const projectSearch = document.getElementById('projectSearch');
  projectSearch.addEventListener('input', function(){
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('.project-item').forEach(item=>{
      const name = item.getAttribute('data-name') || '';
      item.style.display = (q === '' || name.includes(q)) ? '' : 'none';
    });
  });

  // Filters
  document.getElementById('filterHighBtn').addEventListener('click', ()=>{
    document.querySelectorAll('.project-item').forEach(item=>{
      const name = item.querySelector('.fw-bold')?.textContent || '';
      const proj = adminData.activeProjects.find(p => p.name === name);
      item.style.display = (proj && (proj.priority === 'high' || proj.priority === 'critical')) ? '' : 'none';
    });
  });

  document.getElementById('showAllBtn').addEventListener('click', ()=>{
    document.querySelectorAll('.project-item').forEach(item=> item.style.display = '');
    projectSearch.value = '';
  });

  // Metrics Modal Logic (Remains for data transparency)
  function openMetricModal(metricKey){
    const modalEl = document.getElementById('metricModal');
    const metric = (adminData.metrics || []).find(x => x.title === metricKey);
    document.getElementById('mcContent').innerHTML = `<div class="p-4 text-center"><h5>${metricKey} Source Data</h5><p>Current System Value: <strong>${metric.value}</strong></p></div>`;
    new bootstrap.Modal(modalEl).show();
  }

  function showToast(msg){
    const toastEl = document.getElementById('actionToast');
    toastEl.querySelector('.toast-body').textContent = msg;
    new bootstrap.Toast(toastEl).show();
  }
</script>

<div class="modal fade" id="alertModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5>Issue Investigation</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><p id="alertProjName"></p>Immediate action plan required for overdue milestones.</div>
      <div class="modal-footer">
        <button class="btn btn-primary w-100" onclick="showToast('Assigned to project manager')">Assign Task</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="metricModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-body" id="mcContent"></div></div></div></div>

<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080;">
  <div id="actionToast" class="toast align-items-center text-bg-dark border-0" role="alert"><div class="d-flex"><div class="toast-body"></div></div></div>
</div>

<?php include 'includes/footer.php'; ?>