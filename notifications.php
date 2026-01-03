<?php /* Notifications page: view ng mga alerts at counts. */

ini_set('session.cookie_httponly', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') { ini_set('session.cookie_secure', '1'); }
if (session_status() === PHP_SESSION_NONE) { session_start(); }

header('Content-Type: application/json');

require_once __DIR__ . '/config/database.php';

function respond($code, $body) {
  http_response_code($code);
  echo json_encode($body);
  exit;
}

try {
  $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
  if (!$userId) respond(401, ['error' => 'unauthorized']);

  $pdo = getDBConnection();

  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
      $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
      if ($limit < 1) $limit = 20;
      if ($limit > 100) $limit = 100;

      $cols = [];
      try {
        $desc = $pdo->query('SHOW COLUMNS FROM notifications')->fetchAll();
        foreach (($desc ?: []) as $c) { $cols[strtolower($c['Field'] ?? '')] = true; }
      } catch (Throwable $_) {}

      $hasTitle = !empty($cols['title']);
      $hasSubject = !empty($cols['subject']);
      $hasBody = !empty($cols['body']);
      $hasMessage = !empty($cols['message']);
      $hasCreatedAt = !empty($cols['created_at']);
      $hasTimestampCol = !empty($cols['timestamp']);
      $hasIsRead = !empty($cols['is_read']);
      $hasReadCol = !empty($cols['read']);

      $titleExpr = $hasTitle ? 'title' : ($hasSubject ? 'subject' : 'CONCAT("Notification ", id)');
      $bodyExpr = $hasBody ? 'body' : ($hasMessage ? 'message' : "''");
      $timeExpr = $hasCreatedAt ? 'created_at' : ($hasTimestampCol ? 'timestamp' : 'NOW()');
      $readExpr = $hasIsRead ? 'is_read' : ($hasReadCol ? 'read' : '0');
      $orderReadCol = $hasIsRead ? 'is_read' : ($hasReadCol ? 'read' : 'id');

      $sql = 'SELECT id, ' . $titleExpr . ' AS title, ' . $bodyExpr . ' AS description, ' . $timeExpr . ' AS timestamp, ' . $readExpr . ' AS read '
           . 'FROM notifications WHERE user_id = ? '
           . 'ORDER BY ' . $orderReadCol . ' ASC, id DESC LIMIT ' . $limit;

      $st = $pdo->prepare($sql);
      $st->execute([$userId]);
      $rows = $st->fetchAll();
      respond(200, $rows ?: []);
    } catch (Throwable $e) {
      error_log('[VC-PMS] notifications GET failed: ' . $e->getMessage());
      respond(200, []);
    }
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
      $nid = isset($_POST['id']) ? (int)$_POST['id'] : 0;
      if (!$nid) respond(400, ['error' => 'invalid_id']);
      $st = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
      $st->execute([$nid, $userId]);
      respond(200, ['ok' => true]);
    } elseif ($action === 'clear_read') {
      $st = $pdo->prepare('DELETE FROM notifications WHERE user_id = ? AND is_read = 1');
      $st->execute([$userId]);
      respond(200, ['ok' => true]);
    } else {
      respond(400, ['error' => 'invalid_action']);
    }
  }

  respond(405, ['error' => 'method_not_allowed']);
} catch (Throwable $e) {
  respond(500, ['error' => 'server_error']);
}
