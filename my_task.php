<?php /* My Tasks: listahan ng mga tasks na assigned kay user. */
require_once 'includes/auth_guard.php';
require_once 'config/database.php';



date_default_timezone_set('Asia/Manila');
$page_title = 'My Tasks';
$current_page = basename(__FILE__);

$userId = null;
$role = '';
try {
  $role = strtolower($_SESSION['role'] ?? ($_SESSION['login_user']['role'] ?? ''));
  $userId = (int)($_SESSION['login_user']['id'] ?? $_SESSION['user_id'] ?? 0);
} catch (Throwable $e) {}

$pdo = null;
try { $pdo = getDBConnection(); } catch (Throwable $e) { $pdo = null; }

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $user_id = (int)($_SESSION['user_id'] ?? 0);
  if ($action === 'add_comment') {
    $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
    $body = trim($_POST['comment_text'] ?? '');
    if ($task_id > 0 && $user_id && $body !== '') {
      try { $st = $pdo->prepare('INSERT INTO task_comments (task_id, user_id, body) VALUES (?, ?, ?)'); $st->execute([$task_id, $user_id, $body]); } catch (Throwable $e) {}
    }
    header('Location: my_task.php');
    exit;
  } elseif ($action === 'log_time') {
    $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
    $hours = isset($_POST['hours']) ? (float)$_POST['hours'] : 0.0;
    $notes = trim($_POST['notes'] ?? '');
    if ($task_id > 0 && $user_id && $hours > 0) {
      try { $st = $pdo->prepare('INSERT INTO task_time_logs (task_id, user_id, hours, notes) VALUES (?, ?, ?, ?)'); $st->execute([$task_id, $user_id, $hours, $notes]); } catch (Throwable $e) {}
    }
    header('Location: my_task.php');
    exit;
  } elseif ($action === 'mark_blocked') {
    $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
    $reason = trim($_POST['reason'] ?? '');
    if ($task_id > 0 && $reason !== '') {
      try { $st = $pdo->prepare('UPDATE tasks SET is_blocked = 1, blocked_reason = ?, blocked_at = NOW() WHERE id = ?'); $st->execute([$reason, $task_id]); } catch (Throwable $e) {}
    }
    header('Location: my_task.php');
    exit;
  } elseif ($action === 'request_extension') {
    $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
    $requested_due = $_POST['requested_due_date'] ?? null;
    $reason = trim($_POST['reason'] ?? '');
    if ($task_id > 0 && $requested_due) {
      $current_due = null;
      try { $cur = $pdo->prepare('SELECT due_date FROM tasks WHERE id = ?'); $cur->execute([$task_id]); $row = $cur->fetch(); $current_due = $row['due_date'] ?? null; } catch (Throwable $e) { $current_due = null; }
      $diffDays = 0;
      if ($current_due) { try { $diffDays = (new DateTime($requested_due))->diff(new DateTime($current_due))->days; } catch (Throwable $e) { $diffDays = 0; } }
      try {
        $st = $pdo->prepare('INSERT INTO extension_requests (task_id, requested_by, current_due_date, requested_due_date, requested_extension_days, reason, justification, priority) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$task_id, $user_id, $current_due, $requested_due, $diffDays, $reason, null, 'medium']);
      } catch (Throwable $e) {}
    }
    header('Location: my_task.php');
    exit;
  } elseif ($action === 'update_status') {
    $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
    $new_status = trim(strtolower((string)($_POST['new_status'] ?? '')));
    if ($pdo && $task_id > 0 && $new_status !== '' && $user_id) {
      try {
        $st = $pdo->prepare('SELECT id FROM task_statuses WHERE `key` = ? LIMIT 1');
        $st->execute([$new_status]);
        $row = $st->fetch();
        $status_id = (int)($row['id'] ?? 0);
        if ($status_id > 0) {
          $up = $pdo->prepare('UPDATE tasks SET task_status_id = ? WHERE id = ? AND assignee_id = ?');
          $up->execute([$status_id, $task_id, $user_id]);
        }
      } catch (Throwable $e) {}
    }
    $redirProjectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    header('Location: my_task.php'.($redirProjectId ? ('?project_id='.$redirProjectId) : ''));
    exit;
  } elseif ($action === 'upload_task_asset') {
    $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $redirProjectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    if ($pdo && $task_id > 0 && $user_id && isset($_FILES['asset_file']) && is_array($_FILES['asset_file'])) {
      $f = $_FILES['asset_file'];
      if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && ($f['size'] ?? 0) > 0) {
        $name = basename($f['name'] ?? ('file_' . time()));
        $mime = (string)($f['type'] ?? 'application/octet-stream');
        $size = (int)($f['size'] ?? 0);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = ['png','jpg','jpeg','gif','webp','svg','pdf'];
        if (in_array($ext, $allowed, true)) {
          $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'attachments' . DIRECTORY_SEPARATOR . $task_id;
          if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
          $target = $dir . DIRECTORY_SEPARATOR . (uniqid('att_', true) . '.' . $ext);
          if (@move_uploaded_file($f['tmp_name'], $target)) {
            $relPath = 'uploads/attachments/' . $task_id . '/' . basename($target);
            try {
              $pdo->prepare('INSERT INTO attachments (task_id, file_name, file_path, file_size, mime_type, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([$task_id, $name, $relPath, $size, $mime, $user_id]);
            } catch (Throwable $e) { }
          }
        }
      }
    }
    header('Location: my_task.php'.($redirProjectId ? ('?project_id='.$redirProjectId) : ''));
    exit;
  }
}


$projects = [];
$tasksByProject = [];
if ($pdo && $userId) {
  try {
    $st = $pdo->prepare(
      "SELECT p.id, p.name, p.progress, p.due_date
       FROM projects p
       WHERE p.id IN (
         SELECT DISTINCT t.project_id FROM tasks t WHERE t.assignee_id = ?
       )
       ORDER BY p.id DESC"
    );
    $st->execute([$userId]);
    $projects = $st->fetchAll();
  } catch (Throwable $e) {
    $projects = [];
  }
  try {
    $st = $pdo->prepare(
      "SELECT t.id, t.project_id, t.title, t.description, COALESCE(ts.`key`, 'todo') AS status, t.priority, t.due_date
       FROM tasks t
       LEFT JOIN task_statuses ts ON ts.id = t.task_status_id
       WHERE t.assignee_id = ?
       ORDER BY t.id DESC"
    );
    $st->execute([$userId]);
    $rows = $st->fetchAll();
    foreach ($rows as $r) {
      $pid = (int)($r['project_id'] ?? 0);
      if (!isset($tasksByProject[$pid])) $tasksByProject[$pid] = [];
      $tasksByProject[$pid][] = [
        'id' => (int)$r['id'],
        'project_id' => $pid,
        'title' => $r['title'] ?? 'Untitled',
        'description' => $r['description'] ?? '',
        'status' => strtolower($r['status'] ?? 'todo'),
        'priority' => strtolower($r['priority'] ?? 'medium'),
        'due_date' => $r['due_date'] ?? null,
      ];
    }
  } catch (Throwable $e) {
    $tasksByProject = [];
  }
}


$taskStats = [ 'total'=>0, 'todo'=>0, 'in_progress'=>0, 'review'=>0, 'done'=>0 ];
foreach ($tasksByProject as $pid => $list) {
  foreach ($list as $t) {
    $taskStats['total']++;
    $st = str_replace('-', '_', $t['status']);
    if (isset($taskStats[$st])) $taskStats[$st]++;
  }
}

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$report = isset($_GET['report']) ? 1 : 0;
$export = isset($_GET['export']) ? strtolower((string)$_GET['export']) : '';
$from = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
$to = isset($_GET['to']) ? trim((string)$_GET['to']) : '';

if ($pdo && isset($_GET['ajax']) && $_GET['ajax'] === 'comments') {
  header('Content-Type: application/json');
  $tid = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
  $ok = false; $comments = [];
  try {
    $own = $pdo->prepare('SELECT COUNT(1) AS c FROM tasks WHERE id = ? AND assignee_id = ?');
    $own->execute([$tid, $userId]);
    $c = (int)($own->fetch()['c'] ?? 0);
    if ($c > 0) {
      $ok = true;
      $st = $pdo->prepare('SELECT user_id, body FROM task_comments WHERE task_id = ? ORDER BY id DESC');
      $st->execute([$tid]);
      foreach ($st->fetchAll() as $r) { $comments[] = [ 'user_id'=> (int)($r['user_id'] ?? 0), 'body'=> (string)($r['body'] ?? '') ]; }
    }
  } catch (Throwable $e) { $ok = false; }
  echo json_encode([ 'ok' => $ok, 'comments' => $comments ]);
  exit;
}

$projectMap = [];
foreach ($projects as $p) { $projectMap[(int)$p['id']] = (string)($p['name'] ?? 'Project'); }
$doneTasksByProject = [];
foreach ($tasksByProject as $pid => $list) {
  foreach ($list as $t) {
    if (($t['status'] ?? '') !== 'done') continue;
    if ($projectId && (int)$t['project_id'] !== $projectId) continue;
    if (!isset($doneTasksByProject[$pid])) $doneTasksByProject[$pid] = [];
    $doneTasksByProject[$pid][] = $t;
  }
}
$hoursByTask = [];
if ($pdo && $userId) {
  try {
    $st = $pdo->prepare('SELECT task_id, SUM(hours) AS total_hours FROM task_time_logs WHERE user_id = ? GROUP BY task_id');
    $st->execute([$userId]);
    foreach ($st->fetchAll() as $r) { $hoursByTask[(int)$r['task_id']] = (float)($r['total_hours'] ?? 0); }
  } catch (Throwable $e) { $hoursByTask = []; }
}
$totalDone = 0; $totalHours = 0.0;
foreach ($doneTasksByProject as $pid => $list) {
  foreach ($list as $t) { $totalDone++; $totalHours += (float)($hoursByTask[(int)$t['id']] ?? 0.0); }
}
if ($report && $export === 'csv') {
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="accomplishment_report.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Project', 'Task Title', 'Priority', 'Due Date', 'Hours Logged']);
  foreach ($doneTasksByProject as $pid => $list) {
    $pname = $projectMap[(int)$pid] ?? 'Project';
    foreach ($list as $t) {
      $hours = (float)($hoursByTask[(int)$t['id']] ?? 0.0);
      fputcsv($out, [
        $pname,
        (string)($t['title'] ?? 'Untitled'),
        (string)($t['priority'] ?? 'medium'),
        (string)($t['due_date'] ?? ''),
        $hours,
      ]);
    }
  }
  fclose($out);
  exit;
}
include 'includes/header.php';
?>

<style>
  body { background:#f8f9fa; overflow-y: auto !important; }
  .summary-card { border:1px solid #e5e7eb; }
  .muted { color:#6c757d; }
  .kanban-column { border:1px solid #e5e7eb; }
  .kanban-item { border:1px solid #e5e7eb; border-radius:.5rem; padding:.75rem; margin-bottom:.5rem; background:#fff; cursor:pointer; }
  .badge-priority { font-size:.75rem; }
  .kanban-item.dragging { opacity: .6; }
  .kanban-column.drag-over { outline: 2px dashed #0d6efd; }
  .kanban-body { max-height: calc(100vh - 300px); overflow-y: auto; padding-right: .5rem; }
  .kanban-empty { border:2px dashed #dee2e6; }
  .kanban-meta { font-size:.8125rem; }
  .kanban-badges { display:flex; gap:.25rem; flex-wrap:wrap; }
  .text-blue-600 { color:#0d6efd; }
  .text-purple-600 { color:#6f42c1; }
  .text-green-600 { color:#198754; }
  .text-gray-600 { color:#6c757d; }
  .summary-card .h4, .ua-card .h4 { color:#fff; }
</style>

<div class="container py-4 ua-page">
  <?php if ($report): ?>
    <div class="d-flex justify-content-between align-items-start mb-3">
      <div>
        <h2 class="mb-1">Accomplishment Report</h2>
        <div class="text-muted">Summary of your completed tasks<?php if ($projectId) { echo ' in '.htmlspecialchars($projectMap[$projectId] ?? 'Project'); } ?></div>
      </div>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="my_task.php">Back to Projects</a>
        <a class="btn btn-outline-primary" href="my_task.php?report=1<?php echo $projectId ? '&project_id='.$projectId : ''; ?>&export=csv">Export CSV</a>
        <button class="btn btn-secondary" onclick="window.print()" type="button">Print</button>
      </div>
    </div>
    <form class="row g-2 mb-3" method="GET" action="my_task.php">
      <input type="hidden" name="report" value="1">
      <?php if ($projectId): ?><input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>"><?php endif; ?>
      <div class="col-auto"><label class="form-label">From</label><input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>" class="form-control"></div>
      <div class="col-auto"><label class="form-label">To</label><input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>" class="form-control"></div>
      <div class="col-auto align-self-end"><button class="btn btn-primary" type="submit">Apply</button></div>
    </form>
    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3">
        <div class="card ua-card"><div class="card-body">
          <div class="text-muted small">Completed Tasks</div>
          <div class="h4 mb-0"><?php echo (int)$totalDone; ?></div>
        </div></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card ua-card"><div class="card-body">
          <div class="text-muted small">Total Hours</div>
          <div class="h4 mb-0"><?php echo number_format((float)$totalHours, 2); ?></div>
        </div></div>
      </div>
    </div>
    <?php if (empty($doneTasksByProject)): ?>
      <div class="card ua-card"><div class="card-body">No completed tasks found.</div></div>
    <?php else: ?>
      <?php foreach ($doneTasksByProject as $pid => $list): ?>
        <div class="card ua-card mb-3"><div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="fw-semibold"><?php echo htmlspecialchars($projectMap[(int)$pid] ?? 'Project'); ?></div>
            <span class="ua-pill">Done: <?php echo (int)count($list); ?></span>
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr>
                <th>Task Title</th>
                <th>Priority</th>
                <th>Due Date</th>
                <th>Hours Logged</th>
              </tr></thead>
              <tbody>
                <?php foreach ($list as $t): $tid = (int)$t['id']; $hours = (float)($hoursByTask[$tid] ?? 0.0); ?>
                  <tr>
                    <td class="fw-medium"><?php echo htmlspecialchars($t['title'] ?? 'Untitled'); ?></td>
                    <td><?php echo htmlspecialchars($t['priority'] ?? 'medium'); ?></td>
                    <td><?php echo htmlspecialchars($t['due_date'] ?? ''); ?></td>
                    <td><?php echo number_format($hours, 2); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div></div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php elseif (!$projectId): ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="mb-0">My Workspace</h2>
      <div class="d-flex align-items-center gap-2">
        <a class="btn btn-outline-primary" href="my_task.php?report=1">Accomplishment Report</a>
      </div>
    </div>
    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pane-projects" type="button" role="tab">Projects</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-kanban" data-bs-toggle="tab" data-bs-target="#pane-kanban" type="button" role="tab">Kanban</button>
      </li>
    </ul>
    <div class="tab-content pt-3">
      <div class="tab-pane fade show active" id="pane-projects" role="tabpanel">
        <div class="row g-3 mb-3">
          <div class="col-6 col-md-3"><div class="card summary-card text-center p-3"><div class="text-muted small">Total Tasks</div><div class="h4 mb-0"><?php echo (int)$taskStats['total']; ?></div></div></div>
          <div class="col-6 col-md-3"><div class="card summary-card text-center p-3"><div class="text-muted small">To Do</div><div class="h4 mb-0"><?php echo (int)$taskStats['todo']; ?></div></div></div>
          <div class="col-6 col-md-3"><div class="card summary-card text-center p-3"><div class="text-muted small">In Progress</div><div class="h4 mb-0"><?php echo (int)$taskStats['in_progress']; ?></div></div></div>
          <div class="col-6 col-md-3"><div class="card summary-card text-center p-3"><div class="text-muted small">Review</div><div class="h4 mb-0"><?php echo (int)$taskStats['review']; ?></div></div></div>
        </div>
        <div class="row" id="projectList">
          <?php foreach ($projects as $p): $pid = (int)$p['id']; $tasks = $tasksByProject[$pid] ?? []; $by = ['todo'=>0,'in-progress'=>0,'review'=>0,'done'=>0]; foreach ($tasks as $t) { $by[$t['status']] = ($by[$t['status']] ?? 0) + 1; } $totalTasks = ($by['todo'] + $by['in-progress'] + $by['review'] + $by['done']); $progress = $totalTasks > 0 ? (int)round((($by['todo']*25) + ($by['in-progress']*50) + ($by['review']*75) + ($by['done']*100)) / $totalTasks) : 0; ?>
            <div class="col-12 col-md-6 col-lg-4">
              <div class="card h-100">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-start">
                    <div><h6 class="card-title mb-0"><?php echo htmlspecialchars($p['name'] ?? 'Untitled'); ?></h6></div>
                    <span class="badge bg-light text-dark">Progress: <?php echo $progress; ?>%</span>
                  </div>
                  <div class="progress mt-2" style="height:8px"><div class="progress-bar" style="width:<?php echo (int)$progress; ?>%"></div></div>
                  <div class="d-flex gap-2 mt-2">
                    <span class="badge bg-secondary">To Do: <?php echo (int)$by['todo']; ?></span>
                    <span class="badge bg-primary">In Progress: <?php echo (int)$by['in-progress']; ?></span>
                    <span class="badge bg-info text-dark">Review: <?php echo (int)$by['review']; ?></span>
                    <span class="badge bg-success">Done: <?php echo (int)$by['done']; ?></span>
                  </div>
                  <button class="btn btn-outline-primary w-100 mt-2" data-action="view-kanban" data-projectid="<?php echo $pid; ?>">View Tasks in Kanban</button>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (empty($projects)): ?><div class="col-12"><div class="alert alert-light border">No projects assigned.</div></div><?php endif; ?>
        </div>
      </div>
      <div class="tab-pane fade" id="pane-kanban" role="tabpanel">
        <div class="d-flex justify-content-end mb-3 subheader-filters">
          <input id="kanbanSearch" class="form-control form-control-sm" placeholder="Search tasks..." style="max-width: 240px;">
          <select id="kanbanPriorityFilter" class="form-select form-select-sm" style="max-width: 160px;">
            <option value="">Priority: All</option>
            <option value="critical">Critical</option>
            <option value="high">High</option>
            <option value="medium">Medium</option>
            <option value="low">Low</option>
          </select>
          <select id="kanbanDueFilter" class="form-select form-select-sm" style="max-width: 160px;">
            <option value="">Due: All</option>
            <option value="soon">Due Soon</option>
            <option value="overdue">Overdue</option>
          </select>
        </div>
        <div id="kanbanBoard" class="row gx-3">
          <?php $cols = [ 'todo'=>'To Do', 'in-progress'=>'In Progress', 'review'=>'Review', 'done'=>'Done' ]; $colCounts = ['todo'=>0,'in-progress'=>0,'review'=>0,'done'=>0]; foreach (($tasksByProject[$projectId] ?? []) as $tt) { $colCounts[$tt['status']] = ($colCounts[$tt['status']] ?? 0) + 1; } foreach ($cols as $key => $label): $icon = $key==='todo' ? 'bi-circle' : ($key==='in-progress' ? 'bi-play-circle' : ($key==='review' ? 'bi-eye' : 'bi-check-circle')); $color = $key==='todo' ? 'text-gray-600' : ($key==='in-progress' ? 'text-blue-600' : ($key==='review' ? 'text-purple-600' : 'text-green-600')); $count = (int)($colCounts[$key] ?? 0); ?>
            <div class="col-12 col-md-6 col-lg-3">
              <div class="kanban-column card" data-status="<?php echo $key; ?>">
                <div class="card-header d-flex align-items-center justify-content-between">
                  <div class="d-flex align-items-center gap-2"><i class="bi <?php echo $icon; ?> <?php echo $color; ?>"></i><span class="fw-semibold"><?php echo $label; ?></span></div>
                  <span class="badge bg-secondary kanban-counter" data-count-for="<?php echo $key; ?>"><?php echo $count; ?></span>
                </div>
                <div class="card-body kanban-body" id="col-<?php echo $key; ?>">
                  <?php $any=false; foreach (($tasksByProject[$projectId] ?? []) as $t): if (($t['status'] ?? '') !== $key) continue; $any=true; $id=(int)$t['id']; $prio=strtolower((string)($t['priority'] ?? 'medium')); $badge = ($prio==='critical'?'danger':($prio==='high'?'warning':'secondary')); ?>
                    <div class="kanban-item" data-id="<?php echo $id; ?>" data-projectid="<?php echo (int)$projectId; ?>" data-title="<?php echo htmlspecialchars($t['title'] ?? 'Untitled'); ?>" data-description="<?php echo htmlspecialchars($t['description'] ?? ''); ?>" data-projectname="<?php echo htmlspecialchars($projectMap[(int)$projectId] ?? 'Project'); ?>" data-due="<?php echo htmlspecialchars($t['due_date'] ?? ''); ?>" data-priority="<?php echo htmlspecialchars($t['priority'] ?? 'medium'); ?>" data-status="<?php echo $key; ?>">
                      <div class="d-flex align-items-start justify-content-between mb-2">
                        <div class="flex-grow-1">
                          <div class="fw-medium small mb-1"><?php echo htmlspecialchars($t['title'] ?? 'Untitled'); ?></div>
                          <div class="text-muted small"><?php echo htmlspecialchars($projectMap[(int)$projectId] ?? 'Project'); ?></div>
                        </div>
                        <div class="dropdown">
                          <span class="badge bg-<?php echo $badge; ?><?php echo $badge==='warning' ? ' text-dark' : ''; ?> badge-priority text-capitalize me-2"><?php echo htmlspecialchars($t['priority'] ?? 'medium'); ?></span>
                          <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">Actions</button>
                          <ul class="dropdown-menu dropdown-menu-end">
                            <li><button class="dropdown-item" data-action="preview" data-taskid="<?php echo $id; ?>">View Details</button></li>
                            <li><button class="dropdown-item" data-action="comment" data-taskid="<?php echo $id; ?>">Add Comment</button></li>
                            <li><button class="dropdown-item" data-action="time" data-taskid="<?php echo $id; ?>">Log Time</button></li>
                            <li><button class="dropdown-item" data-action="upload-asset" data-taskid="<?php echo $id; ?>" data-projectid="<?php echo (int)$projectId; ?>">Upload Asset</button></li>
                            <li><button class="dropdown-item" data-action="request-extension" data-taskid="<?php echo $id; ?>">Request Extension</button></li>
                            <li><hr class="dropdown-divider"></li>
                            <?php if ($key !== 'todo'): ?><li><button class="dropdown-item" data-action="move" data-taskid="<?php echo $id; ?>" data-status="todo">Move to To Do</button></li><?php endif; ?>
                            <?php if ($key !== 'in-progress'): ?><li><button class="dropdown-item" data-action="move" data-taskid="<?php echo $id; ?>" data-status="in-progress">Move to In Progress</button></li><?php endif; ?>
                            <?php if ($key !== 'review'): ?><li><button class="dropdown-item" data-action="move" data-taskid="<?php echo $id; ?>" data-status="review">Move to Review</button></li><?php endif; ?>
                            <?php if ($key !== 'done'): ?><li><button class="dropdown-item" data-action="move" data-taskid="<?php echo $id; ?>" data-status="done">Move to Done</button></li><?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><button class="dropdown-item text-danger" data-action="block" data-taskid="<?php echo $id; ?>">Mark Blocked</button></li>
                          </ul>
                        </div>
                      </div>
                      <div class="kanban-meta">
                        <div class="d-flex justify-content-between">
                          <div class="small text-muted d-flex align-items-center gap-2"><i class="bi bi-calendar"></i><span><?php echo htmlspecialchars($t['due_date'] ?? ''); ?></span></div>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; if (!$any): ?>
                    <div class="card kanban-empty"><div class="card-body text-center text-muted">No tasks — drag tasks here</div></div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php else: ?>
    <?php
      $project = null;
      foreach ($projects as $p) { if ((int)$p['id'] === $projectId) { $project = $p; break; } }
      $tasks = $tasksByProject[$projectId] ?? [];
      $cols = [ 'todo'=>'To Do', 'in-progress'=>'In Progress', 'review'=>'Review', 'done'=>'Done' ];
      $colCounts = ['todo'=>0,'in-progress'=>0,'review'=>0,'done'=>0];
      foreach ($tasks as $tt) { $colCounts[$tt['status']] = ($colCounts[$tt['status']] ?? 0) + 1; }
    ?>
    <div class="d-flex justify-content-end mb-3 subheader-filters">
      <input id="kanbanSearch" class="form-control form-control-sm" placeholder="Search tasks..." style="max-width: 240px;">
      <select id="kanbanPriorityFilter" class="form-select form-select-sm" style="max-width: 160px;">
        <option value="">Priority: All</option>
        <option value="critical">Critical</option>
        <option value="high">High</option>
        <option value="medium">Medium</option>
        <option value="low">Low</option>
      </select>
      <select id="kanbanDueFilter" class="form-select form-select-sm" style="max-width: 160px;">
        <option value="">Due: All</option>
        <option value="soon">Due Soon</option>
        <option value="overdue">Overdue</option>
      </select>
    </div>
    <div id="kanbanBoard" class="row gx-3">
      <?php foreach ($cols as $key => $label): ?>
        <?php $icon = $key==='todo' ? 'bi-circle' : ($key==='in-progress' ? 'bi-play-circle' : ($key==='review' ? 'bi-eye' : 'bi-check-circle')); $color = $key==='todo' ? 'text-gray-600' : ($key==='in-progress' ? 'text-blue-600' : ($key==='review' ? 'text-purple-600' : 'text-green-600')); $count = (int)($colCounts[$key] ?? 0); ?>
        <div class="col-12 col-md-6 col-lg-3">
          <div class="kanban-column card" data-status="<?php echo $key; ?>">
            <div class="card-header d-flex align-items-center justify-content-between">
              <div class="d-flex align-items-center gap-2"><i class="bi <?php echo $icon; ?> <?php echo $color; ?>"></i><span class="fw-semibold"><?php echo $label; ?></span></div>
              <span class="badge bg-secondary kanban-counter" data-count-for="<?php echo $key; ?>"><?php echo $count; ?></span>
            </div>
            <div class="card-body kanban-body" id="col-<?php echo $key; ?>">
            <?php $any=false; foreach ($tasks as $t) { if ($t['status'] !== $key) continue; $any=true; $id=(int)$t['id']; $prio=strtolower((string)($t['priority'] ?? 'medium')); $badge = ($prio==='critical'?'danger':($prio==='high'?'warning':'secondary')); ?>
              <div class="kanban-item" data-id="<?php echo $id; ?>" data-projectid="<?php echo (int)$projectId; ?>" data-title="<?php echo htmlspecialchars($t['title'] ?? 'Untitled'); ?>" data-description="<?php echo htmlspecialchars($t['description'] ?? ''); ?>" data-projectname="<?php echo htmlspecialchars($project['name'] ?? 'Project'); ?>" data-due="<?php echo htmlspecialchars($t['due_date'] ?? ''); ?>" data-priority="<?php echo htmlspecialchars($t['priority'] ?? 'medium'); ?>" data-status="<?php echo $key; ?>">
                <div class="d-flex align-items-start justify-content-between mb-2">
                  <div class="flex-grow-1">
                    <div class="fw-medium small mb-1"><?php echo htmlspecialchars($t['title'] ?? 'Untitled'); ?></div>
                    <div class="text-muted small"><?php echo htmlspecialchars($project['name'] ?? 'Project'); ?></div>
                  </div>
                  <div class="dropdown">
                    <span class="badge bg-<?php echo $badge; ?><?php echo $badge==='warning' ? ' text-dark' : ''; ?> badge-priority text-capitalize me-2"><?php echo htmlspecialchars($t['priority'] ?? 'medium'); ?></span>
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">Actions</button>
                          <ul class="dropdown-menu dropdown-menu-end">
                            <li><button class="dropdown-item" data-action="preview" data-taskid="<?php echo $id; ?>">View Details</button></li>
                            <li><button class="dropdown-item" data-action="comment" data-taskid="<?php echo $id; ?>">Add Comment</button></li>
                            <li><button class="dropdown-item" data-action="time" data-taskid="<?php echo $id; ?>">Log Time</button></li>
                            <li><button class="dropdown-item" data-action="upload-asset" data-taskid="<?php echo $id; ?>" data-projectid="<?php echo (int)$projectId; ?>">Upload Asset</button></li>
                            <li><button class="dropdown-item" data-action="request-extension" data-taskid="<?php echo $id; ?>">Request Extension</button></li>
                            <li><hr class="dropdown-divider"></li>
                      <li><button class="dropdown-item" data-action="move" data-taskid="<?php echo $id; ?>" data-status="todo">Move to To Do</button></li>
                      <li><button class="dropdown-item" data-action="move" data-taskid="<?php echo $id; ?>" data-status="in-progress">Move to In Progress</button></li>
                      <li><button class="dropdown-item" data-action="move" data-taskid="<?php echo $id; ?>" data-status="review">Move to Review</button></li>
                      <li><button class="dropdown-item" data-action="move" data-taskid="<?php echo $id; ?>" data-status="done">Move to Done</button></li>
                      <li><hr class="dropdown-divider"></li>
                      <li><button class="dropdown-item text-danger" data-action="block" data-taskid="<?php echo $id; ?>">Mark Blocked</button></li>
                    </ul>
                  </div>
                </div>
                <div class="kanban-meta">
                  <div class="d-flex justify-content-between">
                    <div class="small text-muted d-flex align-items-center gap-2"><i class="bi bi-calendar"></i><span><?php echo htmlspecialchars($t['due_date'] ?? ''); ?></span></div>
                  </div>
                </div>
              </div>
            <?php } if (!$any): ?>
              <div class="card kanban-empty"><div class="card-body text-center text-muted">No tasks — drag tasks here</div></div>
            <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <form id="taskStatusForm" method="POST" action="my_task.php" class="d-none">
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" id="taskStatusTaskId" name="task_id" value="">
      <input type="hidden" id="taskStatusNew" name="new_status" value="">
      <?php if ($projectId): ?><input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>"><?php endif; ?>
    </form>

    
  <?php endif; ?>
</div>


<div class="modal fade" id="commentModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="POST" action="my_task.php">
  <input type="hidden" name="action" value="add_comment">
  <div class="modal-header"><h5 class="modal-title">Add Comment</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="mb-2"><label class="form-label">Task ID</label><input name="task_id" type="number" min="1" class="form-control" required></div>
    <textarea name="comment_text" class="form-control" rows="4" placeholder="Enter your comment..." required></textarea>
  </div>
  <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-primary" type="submit">Add Comment</button></div>
</form></div></div>


<div class="modal fade" id="taskPreviewModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="taskPreviewTitle"></h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="mb-2"><span class="ua-pill" id="taskPreviewProject"></span></div>
    <div class="mb-2 d-flex align-items-center gap-2">
      <span class="badge-priority" id="taskPreviewPriority"></span>
      <span class="small text-muted">Status: <span id="taskPreviewStatus"></span></span>
      <span class="small text-muted">Due: <span id="taskPreviewDue"></span></span>
    </div>
    <div id="taskPreviewDescription" class="mb-3"></div>
    <div class="border rounded p-2" id="taskCommentsBox" style="max-height: 240px; overflow:auto;"></div>
    <form id="taskCommentForm" class="mt-2" method="POST" action="my_task.php">
      <input type="hidden" name="action" value="add_comment">
      <input type="hidden" id="taskCommentTaskId" name="task_id" value="">
      <div class="input-group">
        <input name="comment_text" class="form-control" placeholder="Write a comment..." required />
        <button class="btn btn-primary" type="submit">Send</button>
      </div>
    </form>
  </div>
  <div class="modal-footer">
    <button class="btn btn-outline-primary" id="taskStartBtn" type="button">Start</button>
    <button class="btn btn-outline-success" id="taskCompleteBtn" type="button">Complete</button>
    <button class="btn btn-outline-secondary" data-bs-dismiss="modal" data-open="time">Log Time</button>
    <button class="btn btn-outline-danger" data-bs-dismiss="modal" data-open="block">Mark Blocked</button>
    <button class="btn btn-primary" data-bs-dismiss="modal" data-open="ext">Request Extension</button>
  </div>
</div></div></div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var items = document.querySelectorAll('.kanban-item');
  var columns = document.querySelectorAll('.kanban-column');
  var form = document.getElementById('taskStatusForm');
  var idInput = document.getElementById('taskStatusTaskId');
  var statusInput = document.getElementById('taskStatusNew');
  var searchInput = document.getElementById('kanbanSearch');
  var prioSel = document.getElementById('kanbanPriorityFilter');
  var dueSel = document.getElementById('kanbanDueFilter');
  var previewModalEl = document.getElementById('taskPreviewModal');
  var previewTitle = document.getElementById('taskPreviewTitle');
  var previewProj = document.getElementById('taskPreviewProject');
  var previewPrio = document.getElementById('taskPreviewPriority');
  var previewStatus = document.getElementById('taskPreviewStatus');
  var previewDue = document.getElementById('taskPreviewDue');
  var previewDesc = document.getElementById('taskPreviewDescription');
  var commentsBox = document.getElementById('taskCommentsBox');
  var commentForm = document.getElementById('taskCommentForm');
  var commentTaskId = document.getElementById('taskCommentTaskId');
  var startBtn = document.getElementById('taskStartBtn');
  var completeBtn = document.getElementById('taskCompleteBtn');
  items.forEach(function(item){
    item.setAttribute('draggable','true');
    item.addEventListener('dragstart', function(e){
      item.classList.add('dragging');
      var id = item.getAttribute('data-id') || '';
      if (e.dataTransfer) e.dataTransfer.setData('text/plain', id);
    });
    item.addEventListener('dragend', function(){ item.classList.remove('dragging'); });
  });
  columns.forEach(function(col){
    if (col.getAttribute('data-dnd-wired') === '1') return;
    col.setAttribute('data-dnd-wired','1');
    col.addEventListener('dragover', function(e){ e.preventDefault(); col.classList.add('drag-over'); });
    col.addEventListener('dragleave', function(){ col.classList.remove('drag-over'); });
    col.addEventListener('drop', function(e){
      e.preventDefault();
      col.classList.remove('drag-over');
      var id = e.dataTransfer ? e.dataTransfer.getData('text/plain') : '';
      var st = col.getAttribute('data-status') || '';
      if (form && idInput && statusInput && id && st) {
        idInput.value = String(parseInt(id,10));
        statusInput.value = st;
        fetch('my_task.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'action=update_status&task_id='+encodeURIComponent(id)+'&new_status='+encodeURIComponent(st)+'<?php echo $projectId ? ('&project_id='.((int)$projectId)) : ''; ?>'
        }).then(function(){
          var item = document.querySelector('.kanban-item[data-id="'+id+'"]');
          if (item) {
            item.setAttribute('data-status', st);
            var prog = (st==='todo'?25:(st==='in-progress'?50:(st==='review'?75:100)));
            var bar = item.querySelector('.task-progress .bar'); if (bar) bar.style.width = String(prog)+'%';
            col.appendChild(item);
            updateCounts();
          }
        }).catch(function(){ form.submit(); });
      }
    });
  });
  document.addEventListener('click', function(e){
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;
    var action = t.getAttribute('data-action');
    if (action === 'move' && form && idInput && statusInput) {
      var id = t.getAttribute('data-taskid') || '';
      var st = t.getAttribute('data-status') || '';
      idInput.value = id;
      statusInput.value = st;
      fetch('my_task.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=update_status&task_id='+encodeURIComponent(id)+'&new_status='+encodeURIComponent(st)+'<?php echo $projectId ? ('&project_id='.((int)$projectId)) : ''; ?>'
      }).then(function(){
        var item = document.querySelector('.kanban-item[data-id="'+id+'"]');
        var targetCol = document.querySelector('.kanban-column[data-status="'+st+'"] .kanban-body');
        if (item && targetCol) {
          item.setAttribute('data-status', st);
          var prog = (st==='todo'?25:(st==='in-progress'?50:(st==='review'?75:100)));
          var bar = item.querySelector('.task-progress .bar'); if (bar) bar.style.width = String(prog)+'%';
          targetCol.appendChild(item);
          updateCounts();
        }
      }).catch(function(){ form.submit(); });
    } else if (action === 'preview') {
      var item = t.closest('.kanban-item');
      if (!item) return;
      var modalEl = previewModalEl;
      var titleEl = previewTitle;
      var projEl = previewProj;
      var prioEl = previewPrio;
      var dueEl = previewDue;
      var descEl = previewDesc;
      var tid = item.getAttribute('data-id') || '';
      if (titleEl) titleEl.textContent = item.getAttribute('data-title') || '';
      if (projEl) projEl.textContent = item.getAttribute('data-projectname') || '';
      var p = item.getAttribute('data-priority') || 'medium';
      if (prioEl) { prioEl.textContent = p; prioEl.className = 'badge-priority ' + (p === 'critical' ? 'critical' : (p === 'high' ? 'priority-high' : (p === 'low' ? 'priority-low' : 'priority-medium'))); }
      if (dueEl) dueEl.textContent = item.getAttribute('data-due') || '';
      if (descEl) descEl.textContent = item.getAttribute('data-description') || '';
      if (previewStatus) previewStatus.textContent = item.getAttribute('data-status') || '';
      if (commentTaskId) commentTaskId.value = tid;
      if (commentsBox) { commentsBox.innerHTML = '<div class="text-muted">Loading comments...</div>'; fetch('my_task.php?ajax=comments&task_id=' + encodeURIComponent(tid)).then(function(r){ return r.json(); }).then(function(j){ if (!j || !j.ok) { commentsBox.innerHTML = '<div class="text-danger">Failed to load comments</div>'; return; } var frag = document.createDocumentFragment(); j.comments.forEach(function(c){ var div = document.createElement('div'); div.className = 'mb-2 p-2 border rounded'; div.innerHTML = '<div class="small text-muted">User #' + (c.user_id || '') + '</div><div>' + (c.body || '') + '</div>'; frag.appendChild(div); }); commentsBox.innerHTML=''; commentsBox.appendChild(frag); }).catch(function(){ commentsBox.innerHTML = '<div class="text-danger">Failed to load comments</div>'; }); }
      if (startBtn) { startBtn.onclick = function(){ idInput.value = tid; statusInput.value = 'in-progress'; form.submit(); }; }
      if (completeBtn) { completeBtn.onclick = function(){ idInput.value = tid; statusInput.value = 'done'; form.submit(); }; }
      if (modalEl && window.bootstrap) { var m = new bootstrap.Modal(modalEl); m.show(); }
    } else if (action === 'view-kanban') {
      var projId = t.getAttribute('data-projectid') || '';
      if (projId) { window.location.href = 'my_task.php?project_id=' + encodeURIComponent(projId); }
    } else if (action === 'comment') {
      var taskId = t.getAttribute('data-taskid') || '';
      var cm = document.getElementById('commentModal');
      if (cm && window.bootstrap) {
        var inp = cm.querySelector('input[name="task_id"]');
        if (inp) inp.value = taskId;
        new bootstrap.Modal(cm).show();
      }
    } else if (action === 'time') {
      var tid2 = t.getAttribute('data-taskid') || '';
      var tm = document.getElementById('timeModal');
      if (tm && window.bootstrap) {
        var ti = tm.querySelector('input[name="task_id"]');
        if (ti) ti.value = tid2;
        new bootstrap.Modal(tm).show();
      }
    } else if (action === 'block') {
      var tid3 = t.getAttribute('data-taskid') || '';
      var bm = document.getElementById('blockModal');
      if (bm && window.bootstrap) {
        var bi = bm.querySelector('input[name="task_id"]');
        if (bi) bi.value = tid3;
        new bootstrap.Modal(bm).show();
      }
    } else if (action === 'request-extension') {
      var tid4 = t.getAttribute('data-taskid') || '';
      var em = document.getElementById('extModal');
      if (em && window.bootstrap) {
        var ei = em.querySelector('input[name="task_id"]');
        if (ei) ei.value = tid4;
        new bootstrap.Modal(em).show();
      }
    }
    else if (action === 'upload-asset') {
      var tid5 = t.getAttribute('data-taskid') || '';
      var pid5 = t.getAttribute('data-projectid') || '';
      var um = document.getElementById('uploadAssetModal');
      if (um && window.bootstrap) {
        var ti2 = um.querySelector('input[name="task_id"]');
        var pi2 = um.querySelector('input[name="project_id"]');
        if (ti2) ti2.value = tid5;
        if (pi2) pi2.value = pid5;
        new bootstrap.Modal(um).show();
      }
    }
    if (t.getAttribute('data-open') === 'time') { var m = document.getElementById('timeModal'); if (m && window.bootstrap) { new bootstrap.Modal(m).show(); } }
    if (t.getAttribute('data-open') === 'block') { var m2 = document.getElementById('blockModal'); if (m2 && window.bootstrap) { new bootstrap.Modal(m2).show(); } }
    if (t.getAttribute('data-open') === 'ext') { var m3 = document.getElementById('extModal'); if (m3 && window.bootstrap) { new bootstrap.Modal(m3).show(); } }
  });

  function applyFilters(){
    var q = (searchInput?.value || '').toLowerCase();
    var pr = (prioSel?.value || '').toLowerCase();
    var due = (dueSel?.value || '').toLowerCase();
    document.querySelectorAll('.kanban-item').forEach(function(card){
      var title = (card.getAttribute('data-title') || '').toLowerCase();
      var desc = (card.getAttribute('data-description') || '').toLowerCase();
      var p = (card.getAttribute('data-priority') || '').toLowerCase();
      var dueSpan = card.querySelector('[data-due-class]');
      var dueClass = dueSpan ? dueSpan.getAttribute('data-due-class') || '' : '';
      var matchText = !q || title.includes(q) || desc.includes(q);
      var matchPrio = !pr || p === pr;
      var matchDue = !due || (due === 'soon' && dueClass === 'due-soon') || (due === 'overdue' && dueClass === 'due-overdue');
      card.style.display = (matchText && matchPrio && matchDue) ? '' : 'none';
    });
    updateCounts();
  }
  ['input','change'].forEach(function(ev){ searchInput?.addEventListener(ev, applyFilters); prioSel?.addEventListener(ev, applyFilters); dueSel?.addEventListener(ev, applyFilters); });
  applyFilters();

  function updateCounts(){
    document.querySelectorAll('.kanban-column').forEach(function(c){
      var status = c.getAttribute('data-status');
      var count = Array.from(c.querySelectorAll('.kanban-item')).filter(function(el){ return el.style.display !== 'none'; }).length;
      var pill = c.querySelector('.kanban-counter[data-count-for="'+status+'"]') || document.querySelector('.kanban-counter[data-count-for="'+status+'"]');
      if (pill) pill.textContent = String(count);
    });
  }

  if (commentForm) {
    commentForm.addEventListener('submit', function(e){
      e.preventDefault();
      var tid = commentTaskId?.value || '';
      var input = commentForm.querySelector('input[name="comment_text"]');
      var body = (input?.value || '').trim();
      if (!tid || !body) return;
      fetch('my_task.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=add_comment&task_id='+encodeURIComponent(tid)+'&comment_text='+encodeURIComponent(body)
      }).then(function(){
        var div = document.createElement('div');
        div.className = 'mb-2 p-2 border rounded';
        div.innerHTML = '<div class="small text-muted">You</div><div>'+body+'</div>';
        commentsBox?.prepend(div);
        if (input) input.value = '';
      });
    });
  }

  if (window.bootstrap) {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(el){ return new bootstrap.Tooltip(el); });
  }
});
</script>
<div class="modal fade" id="timeModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="POST" action="my_task.php">
  <input type="hidden" name="action" value="log_time">
  <div class="modal-header"><h5 class="modal-title">Log Time Spent</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="mb-2"><label class="form-label">Task ID</label><input name="task_id" type="number" min="1" class="form-control" required></div>
    <div class="mb-2"><label class="form-label">Hours</label><input name="hours" type="number" step="0.5" min="0" class="form-control" placeholder="e.g., 2.5" required></div>
    <div class="mb-2"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="3"></textarea></div>
  </div>
  <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-primary" type="submit">Log Time</button></div>
</form></div></div>


<div class="modal fade" id="uploadAssetModal" tabindex="-1"><div class="modal-dialog"><form id="uploadAssetForm" class="modal-content" method="POST" action="my_task.php" enctype="multipart/form-data">
  <div class="modal-header"><h5 class="modal-title">Upload Asset</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <input type="hidden" name="action" value="upload_task_asset">
    <input type="hidden" name="task_id" id="uploadAssetTaskId" value="">
    <input type="hidden" name="project_id" id="uploadAssetProjectId" value="">
    <div class="mb-2"><label class="form-label">File</label><input type="file" name="asset_file" id="assetFileInput" class="form-control" required></div>
  </div>
  <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-primary" type="submit">Upload</button></div>
  </form></div></div>


<div class="modal fade" id="blockModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="POST" action="my_task.php">
  <input type="hidden" name="action" value="mark_blocked">
  <div class="modal-header"><h5 class="modal-title">Mark Task as Blocked</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="mb-2"><label class="form-label">Task ID</label><input name="task_id" type="number" min="1" class="form-control" required></div>
    <textarea name="reason" class="form-control" rows="4" placeholder="Describe the blocker..." required></textarea>
  </div>
  <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-danger" type="submit">Mark Blocked</button></div>
</form></div></div>


<div class="modal fade" id="extModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="POST" action="my_task.php">
  <input type="hidden" name="action" value="request_extension">
  <div class="modal-header"><h5 class="modal-title">Request Date Extension</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="mb-2"><label class="form-label">Task ID</label><input name="task_id" type="number" min="1" class="form-control" required></div>
    <div class="mb-2"><label class="form-label">New Due Date</label><input name="requested_due_date" type="date" class="form-control" required /></div>
    <div class="mb-2"><label class="form-label">Reason</label><textarea name="reason" class="form-control" rows="4" placeholder="Explain the need..."></textarea></div>
  </div>
  <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-primary" type="submit">Submit Request</button></div>
</form></div></div>

<?php include 'includes/footer.php'; ?>