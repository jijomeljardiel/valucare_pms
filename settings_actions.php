<?php /* Settings actions: server-side handlers ng settings updates. */
require_once 'includes/auth_guard.php';
require_once 'config/database.php';

header('X-Content-Type-Options: nosniff');

function json_response($data, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode($data);
  exit();
}

function require_csrf() {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
  }
  $token = $_POST['csrf_token'] ?? '';
  if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }
}

$is_json = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
$action = $_POST['action'] ?? '';

try {
  $pdo = getDBConnection();
} catch (Throwable $e) {
  if ($is_json) json_response(['ok' => false, 'error' => 'db'], 500);
  header('Location: settings.php');
  exit();
}

switch ($action) {
  case 'rule_create': {
    require_csrf();
    $name = trim($_POST['rule_name'] ?? '');
    $desc = trim($_POST['rule_desc'] ?? '');
    $trigger = trim($_POST['rule_trigger'] ?? '');
    $cond = trim($_POST['rule_condition'] ?? '');
    $actionText = trim($_POST['rule_action'] ?? '');
    if ($name === '' || $trigger === '' || $actionText === '') {
      if ($is_json) json_response(['ok' => false, 'error' => 'validation'], 400);
      header('Location: settings.php');
      exit();
    }
    $stmt = $pdo->prepare('INSERT INTO workflow_rules (name, description, trigger_event, condition_text, action_text, active) VALUES (?,?,?,?,?,1)');
    $stmt->execute([$name, $desc, $trigger, $cond, $actionText]);
    if ($is_json) json_response(['ok' => true]);
    header('Location: settings.php');
    exit();
  }
  case 'rule_reset_defaults': {
    require_csrf();
    $pdo->exec('DELETE FROM workflow_rules');
    $stmt = $pdo->prepare('INSERT INTO workflow_rules (name, description, trigger_event, condition_text, action_text, active) VALUES (?,?,?,?,?,1)');
    $defaults = [
      ['Notify on new task','Email the assigned user when a task is created','Task Created','','Send Email Notification'],
      ['Alert overdue tasks','Email assigned user when task is overdue','Task Overdue','','Send Email Notification']
    ];
    foreach ($defaults as $d) { $stmt->execute($d); }
    if ($is_json) json_response(['ok' => true]);
    header('Location: settings.php');
    exit();
  }
  case 'rule_toggle_active': {
    require_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $active = ($_POST['active'] ?? '') === '1' ? 1 : 0;
    if ($id <= 0) { if ($is_json) json_response(['ok' => false, 'error' => 'validation'], 400); header('Location: settings.php'); exit(); }
    $stmt = $pdo->prepare('UPDATE workflow_rules SET active = ? WHERE id = ?');
    $stmt->execute([$active, $id]);
    if ($is_json) json_response(['ok' => true]);
    header('Location: settings.php');
    exit();
  }
  case 'rule_delete': {
    require_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { if ($is_json) json_response(['ok' => false, 'error' => 'validation'], 400); header('Location: settings.php'); exit(); }
    $stmt = $pdo->prepare('DELETE FROM workflow_rules WHERE id = ?');
    $stmt->execute([$id]);
    if ($is_json) json_response(['ok' => true]);
    header('Location: settings.php');
    exit();
  }
  case 'rule_update': {
    require_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['rule_name'] ?? '');
    $desc = trim($_POST['rule_desc'] ?? '');
    $trigger = trim($_POST['rule_trigger'] ?? '');
    $cond = trim($_POST['rule_condition'] ?? '');
    $actionText = trim($_POST['rule_action'] ?? '');
    if ($id <= 0 || $name === '' || $trigger === '' || $actionText === '') {
      if ($is_json) json_response(['ok' => false, 'error' => 'validation'], 400);
      header('Location: settings.php');
      exit();
    }
    $stmt = $pdo->prepare('UPDATE workflow_rules SET name=?, description=?, trigger_event=?, condition_text=?, action_text=? WHERE id=?');
    $stmt->execute([$name, $desc, $trigger, $cond, $actionText, $id]);
    if ($is_json) json_response(['ok' => true]);
    header('Location: settings.php');
    exit();
  }
  case 'save_notifications': {
    require_csrf();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) { if ($is_json) json_response(['ok' => false, 'error' => 'auth'], 401); header('Location: login.php'); exit(); }
    $email = isset($_POST['notif_email']);
    $push = isset($_POST['notif_push']);
    $slack = isset($_POST['notif_slack']);
    $frequency = in_array($_POST['notif_frequency'] ?? 'realtime', ['realtime','hourly','daily']) ? $_POST['notif_frequency'] : 'realtime';
    $types_tasks = isset($_POST['notif_tasks']);
    $types_projects = isset($_POST['notif_projects']);
    $types_system = isset($_POST['notif_system']);
    $types_mentions = isset($_POST['notif_mentions']);
    $stmt = $pdo->prepare('REPLACE INTO user_notification_settings (user_id,email,push,slack,frequency,types_tasks,types_projects,types_system,types_mentions) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$uid, $email?1:0, $push?1:0, $slack?1:0, $frequency, $types_tasks?1:0, $types_projects?1:0, $types_system?1:0, $types_mentions?1:0]);
    if ($is_json) json_response(['ok' => true]);
    header('Location: settings.php');
    exit();
  }
  case 'save_system': {
    require_csrf();
    $backup_frequency = in_array($_POST['backup_frequency'] ?? 'daily', ['hourly','daily','weekly']) ? $_POST['backup_frequency'] : 'daily';
    $backup_retention = max(1, (int)($_POST['backup_retention'] ?? 30));
    $security_2fa = isset($_POST['security_2fa']);
    $security_strong_pw = isset($_POST['security_strong_pw']);
    $session_timeout = max(5, (int)($_POST['session_timeout'] ?? 30));
    $stmt = $pdo->prepare('REPLACE INTO system_settings (id,backup_frequency,backup_retention_days,security_2fa,security_strong_pw,session_timeout_minutes) VALUES (1,?,?,?,?,?)');
    $stmt->execute([$backup_frequency, $backup_retention, $security_2fa?1:0, $security_strong_pw?1:0, $session_timeout]);
    if ($is_json) json_response(['ok' => true]);
    header('Location: settings.php');
    exit();
  }
  default: {
    if ($is_json) json_response(['ok' => false, 'error' => 'unknown_action'], 400);
    header('Location: settings.php');
    exit();
  }
}
?>
