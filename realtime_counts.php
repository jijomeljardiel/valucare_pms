<?php /* Ajax endpoint: real-time counts para sa header badges. */
ini_set('session.cookie_httponly', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
  ini_set('session.cookie_secure', '1');
}
if (session_status() === PHP_SESSION_NONE) { session_start(); }

header('Content-Type: application/json');

require_once __DIR__ . '/config/database.php';

try {
  $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
  if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
  }

  $pdo = getDBConnection();

  $notifCount = 0;
  $st = $pdo->prepare('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0');
  $st->execute([$userId]);
  $row = $st->fetch();
  if ($row && isset($row['c'])) { $notifCount = (int)$row['c']; }

  $baseline = null;
  $st2 = $pdo->prepare('SELECT COALESCE(last_login, NOW() - INTERVAL 1 DAY) AS last_login FROM users WHERE id = ?');
  $st2->execute([$userId]);
  $row2 = $st2->fetch();
  if ($row2 && isset($row2['last_login'])) { $baseline = $row2['last_login']; }

  $msgCount = 0;
  if ($baseline) {
    $st3 = $pdo->prepare('SELECT COUNT(*) AS c FROM messages WHERE created_at > ? AND user_id <> ?');
    $st3->execute([$baseline, $userId]);
    $row3 = $st3->fetch();
    if ($row3 && isset($row3['c'])) { $msgCount = (int)$row3['c']; }
  }

  echo json_encode([
    'notifications' => $notifCount,
    'messages' => $msgCount,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'server_error']);
}
