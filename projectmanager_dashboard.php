<?php /* Team Lead dashboard: status ng team at tasks. */

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
        'avg performance' => 'bi-award',
        'team velocity' => 'bi-graph-up',
        default => 'bi-info-circle'
    };
}

$canManageTeam = in_array(strtolower($_SESSION['role_slug'] ?? ($_SESSION['role'] ?? '')), ['admin','project_manager'], true);
try {
  if ($canManageTeam && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getDBConnection();
    $action = $_POST['action'] ?? '';
    if ($action === 'add_member') {
      $first = trim($_POST['first_name'] ?? '');
      $last = trim($_POST['last_name'] ?? '');
      $email = trim($_POST['email'] ?? '');
      $r = trim($_POST['role'] ?? 'systemdev');
      $allowed_roles = ['project_manager','systemdev'];
      if (!in_array(strtolower($r), $allowed_roles, true)) { $r = 'systemdev'; }
      $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
      if ($first !== '' && ($email !== '' || $last !== '')) {
        $username = $email !== '' ? explode('@', $email)[0] : (strtolower(preg_replace('/\s+/', '', ($first.$last))) ?: ('user'.time()));
        $password = password_hash('changeme', PASSWORD_DEFAULT);
        try {
          $st = $pdo->prepare('INSERT INTO users (username, email, password, first_name, last_name, role, department, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
          $st->execute([$username, $email, $password, $first, $last, $r, 'ICT', 'active']);
        } catch (Exception $e) {}
      }
      header('Location: projectmanager_dashboard.php'); exit;
    } elseif ($action === 'update_role') {
      $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
      $r = trim($_POST['role'] ?? 'systemdev');
      $allowed_roles = ['project_manager','systemdev'];
      if (!in_array(strtolower($r), $allowed_roles, true)) { $r = 'systemdev'; }
      if ($id > 0) {
        try { $st = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?'); $st->execute([$r, $id]); } catch (Exception $e) {}
      }
      header('Location: projectmanager_dashboard.php'); exit;
    } elseif ($action === 'toggle_status') {
      $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
      if ($id > 0) {
        try {
          $cur = null; $st = $pdo->prepare('SELECT status FROM users WHERE id = ?'); $st->execute([$id]); $cur = $st->fetchColumn();
          $next = (strtolower($cur ?? '') === 'active') ? 'inactive' : 'active';
          $up = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?'); $up->execute([$next, $id]);
        } catch (Exception $e) {}
      }
      header('Location: projectmanager_dashboard.php'); exit;
    }
  }
} catch (Throwable $e) {}




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

  $priorityRows = [];
  try { $priorityRows = $pdo->query("SELECT priority, COUNT(*) AS cnt FROM tasks GROUP BY priority")->fetchAll(); } catch (Throwable $e) { $priorityRows = []; }
  $priorityMap = ['critical'=>0, 'high'=>0, 'medium'=>0, 'low'=>0];
  foreach ($priorityRows as $row) {
    $p = strtolower($row['priority'] ?? 'medium');
    if (isset($priorityMap[$p])) $priorityMap[$p] += (int)$row['cnt'];
    else $priorityMap['medium'] += (int)$row['cnt'];
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
  $avgVelocity = 0;
  if (!empty($weeklyActivity)) {
    $sum = 0; $n = 0;
    foreach ($weeklyActivity as $w) { $sum += (int)($w['velocity'] ?? 0); $n++; }
    $avgVelocity = $n > 0 ? round($sum / $n) : 0;
  }

  $adminDashboardData = [
    'metrics' => [
      ['title'=>'Active Projects','value'=>$activeProjectsCount,'change'=>null,'trend'=>null],
      ['title'=>'Total Tasks','value'=>$totalTasksCount,'change'=>null,'trend'=>null],
      ['title'=>'Team Members','value'=>$teamMembersCount,'change'=>null,'trend'=>null],
      ['title'=>'Overdue Tasks','value'=>$overdueTasksCount,'change'=>null,'trend'=>null],
    ],
    'projectStatus' => [
      ['name'=>'Completed','value'=>$statusMap['Completed'],'color'=>'#06d6a0'],
      ['name'=>'In Progress','value'=>$statusMap['In Progress'],'color'=>'#118ab2'],
      ['name'=>'Pending','value'=>$statusMap['Pending'],'color'=>'#ffd166'],
      ['name'=>'Blocked','value'=>$statusMap['Blocked'],'color'=>'#ef476f'],
    ],
    'taskPriority' => [
      ['name'=>'Critical', 'value'=>$priorityMap['critical'], 'color'=>'#ef476f'],
      ['name'=>'High', 'value'=>$priorityMap['high'], 'color'=>'#ffd166'],
      ['name'=>'Medium', 'value'=>$priorityMap['medium'], 'color'=>'#118ab2'],
      ['name'=>'Low', 'value'=>$priorityMap['low'], 'color'=>'#06d6a0'],
    ],
    'weeklyActivity' => $weeklyActivity,
    'activeProjects' => $activeProjects,
    'criticalAlerts' => $criticalAlerts,
  ];
} catch (Throwable $e) {
  
  $adminDashboardData = [
    'metrics' => [
      ['title'=>'Active Projects','value'=>0,'change'=>null,'trend'=>null],
      ['title'=>'Total Tasks','value'=>0,'change'=>null,'trend'=>null],
      ['title'=>'Team Members','value'=>0,'change'=>null,'trend'=>null],
      ['title'=>'Overdue Tasks','value'=>0,'change'=>null,'trend'=>null],
    ],
    'projectStatus' => [
      ['name'=>'Completed','value'=>0,'color'=>'#06d6a0'],
      ['name'=>'In Progress','value'=>0,'color'=>'#118ab2'],
      ['name'=>'Pending','value'=>0,'color'=>'#ffd166'],
      ['name'=>'Blocked','value'=>0,'color'=>'#ef476f'],
    ],
    'taskPriority' => [
      ['name'=>'Critical', 'value'=>0, 'color'=>'#ef476f'],
      ['name'=>'High', 'value'=>0, 'color'=>'#ffd166'],
      ['name'=>'Medium', 'value'=>0, 'color'=>'#118ab2'],
      ['name'=>'Low', 'value'=>0, 'color'=>'#06d6a0'],
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


$teamRows = [];
try {
  $pdo = isset($pdo) ? $pdo : getDBConnection();
  $teamRows = $pdo->query("SELECT id, first_name, last_name, email, role, department, position, status FROM users ORDER BY first_name ASC LIMIT 500")->fetchAll();
} catch (Throwable $e) { $teamRows = []; }
$js_team = json_encode($teamRows ?: [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);

if (isset($_GET['ajax']) && $_GET['ajax'] === 'metrics') {
  header('Content-Type: application/json');
  echo json_encode($adminDashboardData);
  exit;
}




$totalProjects = count($adminDashboardData['activeProjects']);
$totalCriticalAlerts = count($adminDashboardData['criticalAlerts']);


$js_admin = json_encode($adminDashboardData, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);

?>
<?php 
  
  $page_title = 'Project Manager Dashboard';
  $current_page = basename(__FILE__);
  include 'includes/header.php';
?>


<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<style>
  body { background:#f8fafc; color:#0f172a; padding:18px; overflow-y: auto; }
  .muted { color:#6b7280; }
  .card-hover:hover { transform: translateY(-6px); box-shadow: 0 12px 30px rgba(15,23,42,0.06); transition: .16s; }
  .avatar { width:36px;height:36px;border-radius:8px;background:#e9ecef;display:flex;align-items:center;justify-content:center;font-weight:700;color:#374151; }
  .progress-small { height:10px; }
  .left-border-critical { border-left:4px solid #ef476f; }
  .dot { display:inline-block;width:10px;height:10px;border-radius:2px; }
  .search-input { max-width:420px; }
  .collapse-toggle { cursor:pointer; }
  .stat-number { font-size:2rem; font-weight:700; line-height:1.1; }
  /* Ensure dashboard content can scroll */
  .container-fluid { 
    min-height: calc(100vh - 120px); 
    overflow-y: auto; 
    padding-bottom: 2rem; 
  }
  #metricsGrid .metric-card { height:100%; display:flex; flex-direction:column; }
  .projects-scroll { max-height: 420px; overflow-y: auto; padding-right: .5rem; }
  .section-card { height: 100%; }
  /* Metric Computation Dialog styles */
  .mc-dialog .section-title { display:flex; align-items:center; gap:.5rem; font-weight:600; }
  .mc-code { font-size:.875rem; background:#f8fafc; padding:12px; border-radius:.5rem; display:block; overflow-x:auto; }
  .mc-pre { font-size:.875rem; white-space:pre-wrap; background:#f8fafc; padding:12px; border-radius:.5rem; }
  .mc-badges { display:flex; flex-wrap:wrap; gap:.5rem; }
  .mc-badge { border:1px solid #e5e7eb; background:#f9fafb; color:#334155; padding:.25rem .5rem; border-radius:.375rem; font-size:.8125rem; }
  .mc-grid { display:grid; grid-template-columns:repeat(1, minmax(0,1fr)); gap:.75rem; }
  @media (min-width: 768px) { .mc-grid { grid-template-columns:repeat(2, minmax(0,1fr)); } }
</style>
<div class="container-fluid">

  
  <div class="d-flex flex-column flex-md-row justify-content-between align-items-start mb-4">
    <div>
      <h2 class="mb-0">Project Manager Dashboard</h2>
      <div class="muted">Complete overview of development activities and team performance</div>
    </div>
    <div class="mt-3 mt-md-0 d-flex gap-2 align-items-center">
      <input id="projectSearch" class="form-control form-control-sm search-input" placeholder="Search active projects..." />
      <button id="toggleAlertsBtn" class="btn btn-outline-secondary btn-sm ms-2"><i class="bi bi-bell"></i> Toggle Alerts</button>
    </div>
  </div>

  
  <div class="row row-cols-2 row-cols-md-4 g-3 mb-4" id="metricsGrid">
    <?php foreach($adminDashboardData['metrics'] as $m): ?>
      <div class="col">
        <div class="card p-3 card-hover metric-card" data-metric="<?= htmlspecialchars($m['title']) ?>">
          <div class="d-flex justify-content-between align-items-center">
            <div class="small muted d-flex align-items-center gap-2">
              <i class="bi <?= metricIconClass($m['title']) ?> me-2"></i>
              <span><?= htmlspecialchars($m['title']) ?></span>
            </div>
            <?php if(!empty($m['trend'])): ?>
              <span class="badge bg-secondary"><?= htmlspecialchars($m['trend']) ?></span>
            <?php endif; ?>
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
    <div class="col-12">
      <div class="card border-danger bg-danger bg-opacity-10" id="alertsCard">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <h5 class="mb-1 text-danger"><i class="bi bi-exclamation-triangle-fill"></i> Critical Alerts</h5>
              <div class="muted">Issues requiring immediate attention</div>
            </div>
            <div class="text-end">
              <div class="h4 mb-0"><?= $totalCriticalAlerts ?></div>
              <div class="muted">active</div>
            </div>
          </div>

          <div class="mt-3" id="alertsList">
            <?php foreach($adminDashboardData['criticalAlerts'] as $a):
              $dot = $a['priority'] === 'critical' ? '#ef476f' : ($a['priority'] === 'high' ? '#ffd166' : '#ffd166');
            ?>
              <div class="d-flex justify-content-between align-items-center p-3 bg-white rounded mb-2">
                <div class="d-flex gap-3 align-items-center">
                  <div><span class="dot" style="background:<?= $dot ?>"></span></div>
                  <div>
                    <div class="fw-medium"><?= htmlspecialchars($a['title']) ?></div>
                    <div class="small muted"><?= htmlspecialchars($a['project']) ?></div>
                  </div>
                </div>
                <div class="d-flex gap-3 align-items-center">
                  <div class="small muted"><?= htmlspecialchars($a['age']) ?></div>
                  <button class="btn btn-sm btn-danger investigate-btn" data-project="<?= htmlspecialchars($a['project']) ?>">Investigate</button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

        </div>
      </div>
    </div>
  </div>

  
  <div class="row g-3 mb-4">
    <div class="col-lg-6">
      <div class="card card-hover section-card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
              <h5 class="mb-0">Active Development Projects</h5>
              <div class="muted">Current status of active projects</div>
            </div>
            <div class="muted"><?= count($adminDashboardData['activeProjects']) ?> projects</div>
          </div>

          <div id="projectsList" class="projects-scroll">
            <?php foreach($adminDashboardData['activeProjects'] as $proj): ?>
              <div class="mb-3 project-item" data-name="<?= htmlspecialchars(strtolower($proj['name'])) ?>">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <div>
                    <div class="fw-medium"><?= htmlspecialchars($proj['name']) ?></div>
                    <div class="small muted">
                      <span class="<?= statusColorClass($proj['status']) ?>"><?= htmlspecialchars($proj['status']) ?></span>
                      <?php if($proj['overdue']>0): ?>
                        <span class="badge bg-danger ms-2"><?= $proj['overdue'] ?> overdue</span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="text-end">
                    <div class="fw-medium"><?= $proj['progress'] ?>%</div>
                    <div class="small muted"><?= $proj['team'] ?> members</div>
                  </div>
                </div>
                <div class="progress progress-small">
                  <div class="progress-bar" role="progressbar" style="width: <?= $proj['progress'] ?>%" aria-valuenow="<?= $proj['progress'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
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
      <div class="row g-3">
        <div class="col-md-6">
          <div class="card card-hover mb-3 h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="mb-0">Task Status</h5>
                <div class="muted">Overview</div>
              </div>
              <canvas id="statusPie" style="max-height:200px"></canvas>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card card-hover mb-3 h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="mb-0">Task Priority</h5>
                <div class="muted">By Level</div>
              </div>
              <canvas id="priorityBar" style="max-height:200px"></canvas>
            </div>
          </div>
        </div>
        <div class="col-12">
          <div class="card card-hover">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="mb-0">Weekly Development Activity</h5>
                <div class="muted">Completed / Created / Velocity</div>
              </div>
              <canvas id="weeklyChart" style="max-height:300px"></canvas>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  
  <div class="row g-3 mb-4">
    <div class="col-12">
      <div class="card">
        <div class="card-body">
          <div class="d-flex justify-content-between mb-2">
            <h5 class="mb-0">Weekly Activity (table)</h5>
            <div class="muted">Last 7 days</div>
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr class="muted">
                  <th>Day</th>
                  <th class="text-end">Completed</th>
                  <th class="text-end">Created</th>
                  <th class="text-end">Velocity %</th>
                </tr>
              </thead>
              <tbody id="weeklyTableBody">
                <?php foreach($adminDashboardData['weeklyActivity'] as $row): ?>
                  <tr>
                    <td><?= htmlspecialchars($row['day']) ?></td>
                    <td class="text-end"><?= htmlspecialchars($row['completed']) ?></td>
                    <td class="text-end"><?= htmlspecialchars($row['created']) ?></td>
                    <td class="text-end"><?= htmlspecialchars($row['velocity']) ?>%</td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($canManageTeam): ?>
  <hr class="my-5 border-secondary">
  <div class="row g-3 mb-4">
    <div class="col-12">
      <div class="card card-hover">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
              <h5 class="mb-0">Team Management</h5>
              <div class="muted">Manage roles and status of your team</div>
            </div>
            <div>
              <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addMemberModal"><i class="bi bi-person-plus me-1"></i>Add Member</button>
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-md-6">
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input id="teamSearchInput" class="form-control form-control-sm" placeholder="Search by name or email">
              </div>
            </div>
            <div class="col-md-3">
              <select id="teamRoleFilter" class="form-select form-select-sm">
                <option value="all">All Roles</option>
                <option value="project_manager">Project Manager</option>
                <option value="systemdev">SystemDev</option>
              </select>
            </div>
            <div class="col-md-3">
              <select id="teamStatusFilter" class="form-select form-select-sm">
                <option value="all">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" id="teamTable">
              <thead>
                <tr class="muted"><th>Member</th><th>Role</th><th>Status</th><th class="text-end">Actions</th></tr>
              </thead>
              <tbody id="teamTableBody"></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>



<script>
  // Parse PHP-provided JSON
  const adminData = <?= $js_admin ?>;

  let statusPieChart = null;
  (function renderStatusPie(){
    const ctx = document.getElementById('statusPie').getContext('2d');
    const labels = adminData.projectStatus.map(s=>s.name);
    const data = adminData.projectStatus.map(s=>s.value);
    const colors = adminData.projectStatus.map(s=>s.color);
    statusPieChart = new Chart(ctx, {
      type: 'pie',
      data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth:1 }] },
      options: { plugins: { legend: { position:'bottom' } }, responsive:true, maintainAspectRatio:false }
    });
  })();

  let priorityBarChart = null;
  (function renderPriorityBar(){
    const ctx = document.getElementById('priorityBar').getContext('2d');
    const labels = adminData.taskPriority.map(s=>s.name);
    const data = adminData.taskPriority.map(s=>s.value);
    const colors = adminData.taskPriority.map(s=>s.color);
    priorityBarChart = new Chart(ctx, {
      type: 'bar',
      data: { labels, datasets: [{ label:'Tasks', data, backgroundColor: colors, borderRadius:4 }] },
      options: { plugins: { legend: { display:false } }, scales:{ y:{ beginAtZero:true, ticks:{ stepSize:1 } } }, responsive:true, maintainAspectRatio:false }
    });
  })();

  let weeklyChartInstance = null;
  (function renderWeekly(){
    const ctx = document.getElementById('weeklyChart').getContext('2d');
    const days = adminData.weeklyActivity.map(r=>r.day);
    const completed = adminData.weeklyActivity.map(r=>r.completed);
    const created = adminData.weeklyActivity.map(r=>r.created);
    const velocity = adminData.weeklyActivity.map(r=>r.velocity);
    weeklyChartInstance = new Chart(ctx, {
      type: 'line',
      data: {
        labels: days,
        datasets: [
          { label:'Completed', data:completed, borderColor:'#06d6a0', backgroundColor:'rgba(6,214,160,0.2)', tension:0.3, fill:false },
          { label:'Created', data:created, borderColor:'#118ab2', backgroundColor:'rgba(17,138,178,0.2)', tension:0.3, fill:false },
          { label:'Velocity %', data:velocity, borderColor:'#8b5cf6', backgroundColor:'rgba(139,92,246,0.2)', borderDash:[5,5], tension:0.3, fill:false }
        ]
      },
      options: {
        responsive:true, maintainAspectRatio:false,
        scales: { y: { beginAtZero:true } },
        plugins:{ legend:{ position:'bottom' } }
      }
    });
  })();

  function applyData(data){
    adminData = data;
    document.querySelectorAll('.metric-card').forEach(card=>{
      const title = card.getAttribute('data-metric');
      const m = (data.metrics || []).find(x=>x.title===title);
      if (m) {
        const valEl = card.querySelector('.stat-number');
        if (valEl) valEl.textContent = m.value;
      }
    });
    const labels = (data.projectStatus||[]).map(s=>s.name);
    const vals = (data.projectStatus||[]).map(s=>s.value);
    const colors = (data.projectStatus||[]).map(s=>s.color);
    if (statusPieChart) {
      statusPieChart.data.labels = labels;
      statusPieChart.data.datasets[0].data = vals;
      statusPieChart.data.datasets[0].backgroundColor = colors;
      statusPieChart.update();
    }
    if (priorityBarChart) {
      priorityBarChart.data.labels = (data.taskPriority||[]).map(s=>s.name);
      priorityBarChart.data.datasets[0].data = (data.taskPriority||[]).map(s=>s.value);
      priorityBarChart.data.datasets[0].backgroundColor = (data.taskPriority||[]).map(s=>s.color);
      priorityBarChart.update();
    }
    const days = (data.weeklyActivity||[]).map(r=>r.day);
    const completed = (data.weeklyActivity||[]).map(r=>r.completed);
    const created = (data.weeklyActivity||[]).map(r=>r.created);
    const velocity = (data.weeklyActivity||[]).map(r=>r.velocity);
    if (weeklyChartInstance) {
      weeklyChartInstance.data.labels = days;
      weeklyChartInstance.data.datasets[0].data = completed;
      weeklyChartInstance.data.datasets[1].data = created;
      weeklyChartInstance.data.datasets[2].data = velocity;
      weeklyChartInstance.update();
    }
    const tbody = document.getElementById('weeklyTableBody');
    if (tbody) {
      tbody.innerHTML = (data.weeklyActivity||[]).map(r=>`<tr><td>${r.day}</td><td class="text-end">${r.completed}</td><td class="text-end">${r.created}</td><td class="text-end">${r.velocity}%</td></tr>`).join('');
    }
    const projList = document.getElementById('projectsList');
    if (projList) {
      const statusClass = s => {
        const v = String(s||'').toLowerCase();
        if (v === 'at-risk') return 'text-warning';
        if (v === 'blocked' || v === 'on-hold') return 'text-danger';
        if (v === 'active' || v === 'in-progress') return 'text-success';
        return 'text-primary';
      };
      projList.innerHTML = (data.activeProjects||[]).map(p=>{
        const name = p.name || 'Untitled';
        const progress = parseInt(p.progress||0,10);
        const status = p.status || '';
        const overdue = parseInt(p.overdue||0,10);
        const team = parseInt(p.team||0,10);
        const odBadge = overdue>0 ? `<span class="badge bg-danger ms-2">${overdue} overdue</span>` : '';
        return `
          <div class="mb-3 project-item" data-name="${String(name).toLowerCase()}">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <div>
                <div class="fw-medium">${name}</div>
                <div class="small muted">
                  <span class="${statusClass(status)}">${status}</span>
                  ${odBadge}
                </div>
              </div>
              <div class="text-end">
                <div class="fw-medium">${progress}%</div>
                <div class="small muted">${team} members</div>
              </div>
            </div>
            <div class="progress progress-small">
              <div class="progress-bar" role="progressbar" style="width: ${progress}%" aria-valuenow="${progress}" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
          </div>
        `;
      }).join('');
    }
  }

  function refreshDashboard(){
    fetch('projectmanager_dashboard.php?ajax=metrics').then(r=>r.json()).then(applyData).catch(()=>{});
  }
  setInterval(refreshDashboard, 10000);

  // Interactivity: Toggle Alerts
  const alertsCard = document.getElementById('alertsCard');
  const toggleAlertsBtn = document.getElementById('toggleAlertsBtn');
  if(toggleAlertsBtn) {
    toggleAlertsBtn.addEventListener('click', ()=>{
      const alertsList = document.getElementById('alertsList');
      if(alertsList.style.display === 'none') { alertsList.style.display = ''; toggleAlertsBtn.classList.remove('btn-primary'); toggleAlertsBtn.classList.add('btn-outline-secondary'); }
      else { alertsList.style.display = 'none'; toggleAlertsBtn.classList.remove('btn-outline-secondary'); toggleAlertsBtn.classList.add('btn-primary'); }
    });
  }

  // Interactivity: Search active projects
  const projectSearch = document.getElementById('projectSearch');
  if(projectSearch) {
    projectSearch.addEventListener('input', function(){
      const q = this.value.trim().toLowerCase();
      document.querySelectorAll('.project-item').forEach(item=>{
        const name = item.getAttribute('data-name') || '';
        item.style.display = (q === '' || name.includes(q)) ? '' : 'none';
      });
    });
  }

  // Filter: Show High Priority projects only
  const filterHighBtn = document.getElementById('filterHighBtn');
  if(filterHighBtn) {
    filterHighBtn.addEventListener('click', ()=>{
      document.querySelectorAll('.project-item').forEach(item=>{
        const name = item.querySelector('.fw-medium')?.textContent || '';
        // find matching project in adminData
        const proj = adminData.activeProjects.find(p => p.name === name);
        if(proj) item.style.display = (proj.priority === 'high' || proj.priority === 'critical') ? '' : 'none';
      });
    });
  }
  const showAllBtn = document.getElementById('showAllBtn');
  if(showAllBtn) {
    showAllBtn.addEventListener('click', ()=>{
      document.querySelectorAll('.project-item').forEach(item=> item.style.display = '');
      if(projectSearch) projectSearch.value = '';
    });
  }

  document.querySelectorAll('.investigate-btn').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const project = btn.dataset.project;
      const alertItem = (adminData.criticalAlerts || []).find(a=>a.project===project);
      openAlertModal(alertItem);
    });
  });

  document.querySelectorAll('.metric-card').forEach(card=>{
    card.addEventListener('click', ()=>{
      const metricKey = card.getAttribute('data-metric');
      openMetricModal(metricKey);
    });
  });

  let alertModalInstance = null;
  function openAlertModal(alertItem){
    const modalEl = document.getElementById('alertModal');
    const titleEl = modalEl.querySelector('[data-alert-title]');
    const projEl = modalEl.querySelector('[data-alert-project]');
    const ageEl = modalEl.querySelector('[data-alert-age]');
    const priorityEl = modalEl.querySelector('[data-alert-priority]');
    titleEl.textContent = alertItem?.title || '';
    projEl.textContent = alertItem?.project || '';
    ageEl.textContent = alertItem?.age || '';
    priorityEl.textContent = alertItem?.priority || '';
    alertModalInstance = new bootstrap.Modal(modalEl);
    alertModalInstance.show();
  }
  function openMetricModal(metricKey){
    const modalEl = document.getElementById('metricModal');
    const contentEl = modalEl.querySelector('#mcContent');
    const metric = (adminData.metrics || []).find(x => String(x.title) === String(metricKey));
    const value = metric?.value ?? null;
    const details = {
      'Active Projects': {
        def: 'Projects with status Active or At-Risk.',
        sql: "SELECT COUNT(*) FROM projects p JOIN project_statuses ps ON ps.id = p.project_status_id WHERE ps.`key` IN ('active','at-risk')",
        tables: ['projects','project_statuses'],
        notes: 'Counts current status only.'
      },
      'Total Tasks': {
        def: 'All tasks in the system.',
        sql: 'SELECT COUNT(*) FROM tasks',
        tables: ['tasks'],
        notes: ''
      },
      'Team Members': {
        def: 'All registered users.',
        sql: 'SELECT COUNT(*) FROM users',
        tables: ['users'],
        notes: ''
      },
      'Overdue Tasks': {
        def: 'Tasks past due date and not marked Done.',
        sql: "SELECT COUNT(*) FROM tasks t LEFT JOIN task_statuses ts ON ts.id = t.task_status_id WHERE t.due_date IS NOT NULL AND t.due_date < CURDATE() AND (ts.`key` IS NULL OR ts.`key` <> 'done')",
        tables: ['tasks','task_statuses'],
        notes: 'Excludes tasks with status Done.'
      }
    };
    const d = details[metricKey];
    if (!d) {
      contentEl.innerHTML = '<div class="alert alert-light border">No additional details.</div>';
    } else {
      const valBlock = value !== null ? `<div class="section-title"><span>Current Value</span><span class="badge bg-primary">${value}</span></div>` : '';
      const tables = (d.tables || []).map(t => `<span class="mc-badge">${t}</span>`).join('');
      contentEl.innerHTML = `
        ${valBlock}
        <div class="mc-grid">
          <div>
            <div class="section-title"><i class="bi bi-list-check"></i><span>Definition</span></div>
            <div class="mc-pre">${d.def}</div>
          </div>
          <div>
            <div class="section-title"><i class="bi bi-database"></i><span>SQL Source</span></div>
            <code class="mc-code">${d.sql}</code>
          </div>
          <div>
            <div class="section-title"><i class="bi bi-diagram-3"></i><span>Tables</span></div>
            <div class="mc-badges">${tables}</div>
          </div>
          ${d.notes ? `<div><div class="section-title"><i class="bi bi-info-circle"></i><span>Notes</span></div><div class="mc-pre">${d.notes}</div></div>` : ''}
        </div>
      `;
    }
    const m = new bootstrap.Modal(modalEl);
    m.show();
  }

  function showToast(msg){
    const toastEl = document.getElementById('actionToast');
    if(toastEl){
      toastEl.querySelector('.toast-body').textContent = msg || '';
      const t = new bootstrap.Toast(toastEl);
      t.show();
    }
  }

  // Accessibility: ensure charts redraw on resize (Chart.js handles this but keep safe)
  window.addEventListener('resize', ()=> { /* Chart.js auto-resizes */ });

  // TEAM MANAGEMENT JS
  const TEAM_DATA = <?= $js_team ?>;
  const teamSearchInput = document.getElementById('teamSearchInput');
  const teamRoleFilter = document.getElementById('teamRoleFilter');
  const teamStatusFilter = document.getElementById('teamStatusFilter');
  const teamTableBody = document.getElementById('teamTableBody');
  
  function applyTeamFilters(list){
    const q = (teamSearchInput?.value || '').trim().toLowerCase();
    const roleVal = (teamRoleFilter?.value || 'all').toLowerCase();
    const statusVal = (teamStatusFilter?.value || 'all').toLowerCase();
    return (list||[]).filter(u=>{
      const nm = `${(u.first_name||'')} ${(u.last_name||'')}`.trim();
      const text = `${nm} ${(u.email||'')}`.toLowerCase();
      const r = String(u.role||'').toLowerCase();
      const s = String(u.status||'').toLowerCase();
      const matchQ = !q || text.includes(q);
      const matchR = roleVal==='all' || r===roleVal;
      const matchS = statusVal==='all' || s===statusVal;
      return matchQ && matchR && matchS;
    });
  }
  function renderTeam(list){
    if(!teamTableBody) return;
    teamTableBody.innerHTML = '';
    list.forEach(u=>{
      const nm = `${(u.first_name||'')} ${(u.last_name||'')}`.trim() || 'User';
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${nm}<br><small class="muted">${u.email||''}</small></td><td>${u.role||''}</td><td>${u.status||''}</td><td class="text-end"><button class="btn btn-sm btn-outline-secondary" data-action="edit-role" data-id="${u.id}" data-role="${u.role||'systemdev'}">Edit Role</button> <button class="btn btn-sm btn-outline-primary" data-action="toggle-status" data-id="${u.id}">${String(u.status||'').toLowerCase()==='active'?'Set Inactive':'Set Active'}</button></td>`;
      teamTableBody.appendChild(tr);
    });
  }

  if(teamTableBody) {
    renderTeam(TEAM_DATA);
    
    if(teamSearchInput) teamSearchInput.addEventListener('input', ()=>renderTeam(applyTeamFilters(TEAM_DATA)));
    if(teamRoleFilter) teamRoleFilter.addEventListener('change', ()=>renderTeam(applyTeamFilters(TEAM_DATA)));
    if(teamStatusFilter) teamStatusFilter.addEventListener('change', ()=>renderTeam(applyTeamFilters(TEAM_DATA)));

    teamTableBody.addEventListener('click', (e)=>{
      const btn = e.target.closest('button');
      if(!btn) return;
      const action = btn.dataset.action;
      if(action === 'edit-role') {
        const id = btn.dataset.id;
        const role = btn.dataset.role;
        document.getElementById('roleUserId').value = id;
        document.getElementById('roleSelect').value = role;
        const m = new bootstrap.Modal(document.getElementById('editRoleModal'));
        m.show();
      } else if (action === 'toggle-status') {
        const id = btn.dataset.id;
        document.getElementById('toggleUserId').value = id;
        document.getElementById('toggleStatusForm').submit();
      }
    });
  }
</script>

<div class="modal fade" id="alertModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Alert Investigation</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="card mb-3 border-danger border-opacity-25">
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <div class="small muted">Issue</div>
                <div class="fw-medium" data-alert-title></div>
              </div>
              <div class="col-md-6">
                <div class="small muted">Project</div>
                <div class="fw-medium" data-alert-project></div>
              </div>
              <div class="col-md-6">
                <div class="small muted">Priority Level</div>
                <span class="badge bg-outline text-capitalize" data-alert-priority></span>
              </div>
              <div class="col-md-6">
                <div class="small muted">Time Active</div>
                <div class="fw-medium" data-alert-age></div>
              </div>
            </div>
          </div>
        </div>
        
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-outline-secondary" onclick="showToast('Alert details exported successfully')"><i class="bi bi-file-earmark-text me-1"></i>Export Report</button>
          <button type="button" class="btn btn-primary" onclick="showToast('Alert assigned for immediate action')"><i class="bi bi-check2-square me-1"></i>Assign for Action</button>
          <button type="button" class="btn btn-danger" onclick="showToast('Alert escalated to emergency response')"><i class="bi bi-exclamation-triangle me-1"></i>Escalate</button>
        </div>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="metricModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content mc-dialog">
      <div class="modal-header">
        <h5 class="modal-title">Metric Computation Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="mcContent"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="addMemberModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST">
      <div class="modal-header"><h5 class="modal-title">Add Team Member</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="action" value="add_member">
        <div class="mb-2"><label class="form-label">First Name</label><input name="first_name" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">Last Name</label><input name="last_name" class="form-control"></div>
        <div class="mb-2"><label class="form-label">Email</label><input name="email" type="email" class="form-control"></div>
        <div class="mb-2"><label class="form-label">Role</label>
          <select name="role" class="form-select">
            <option value="systemdev">SystemDev</option>
            <option value="project_manager">Project Manager</option>
          </select>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-primary" type="submit">Create</button></div>
    </form>
  </div>
</div>

<div class="modal fade" id="editRoleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST">
      <div class="modal-header"><h5 class="modal-title">Edit Role</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="action" value="update_role">
        <input type="hidden" id="roleUserId" name="id">
        <div class="mb-2"><label class="form-label">Role</label>
          <select id="roleSelect" name="role" class="form-select">
            <option value="systemdev">SystemDev</option>
            <option value="project_manager">Project Manager</option>
          </select>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
    </form>
  </div>
</div>

<form id="toggleStatusForm" method="POST" style="display:none"><input type="hidden" name="action" value="toggle_status"><input type="hidden" id="toggleUserId" name="id"></form>

<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080;">
  <div id="actionToast" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body">Action successful</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>
<?php include 'includes/footer.php'; ?>