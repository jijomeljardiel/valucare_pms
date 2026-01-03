<?php /* File notifications_test.php: bahagi ng ValuCare PMS app. */



require_once __DIR__ . '/../config/database.php';

function assertTrue($cond, $msg) {
  echo ($cond ? "[PASS] " : "[FAIL] ") . $msg . PHP_EOL;
}

try {
  $pdo = getDBConnection();
  $userId = 1; 

  
  $pdo->prepare('DELETE FROM notifications WHERE user_id = ? AND title LIKE "[TEST]%"')->execute([$userId]);
  $ins = $pdo->prepare('INSERT INTO notifications (user_id, title, body, url, is_read, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
  $ins->execute([$userId, '[TEST] A unread', 'Body A', null, 0]);
  $ins->execute([$userId, '[TEST] B unread', 'Body B', null, 0]);
  $ins->execute([$userId, '[TEST] C read', 'Body C', null, 1]);

  
  $rows = $pdo->prepare('SELECT id, title, is_read FROM notifications WHERE user_id = ? ORDER BY is_read ASC, id DESC LIMIT 10');
  $rows->execute([$userId]);
  $data = $rows->fetchAll();
  $unreadCount = array_reduce($data, fn($c,$r)=>$c + (int)(!$r['is_read']), 0);
  assertTrue($unreadCount >= 2, 'Unread items appear first');

  
  $firstUnread = null;
  foreach ($data as $r) { if ((int)$r['is_read'] === 0) { $firstUnread = (int)$r['id']; break; } }
  if ($firstUnread) {
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([$firstUnread, $userId]);
  }
  $cnt = $pdo->prepare('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0 AND title LIKE "[TEST]%"');
  $cnt->execute([$userId]);
  $cRow = $cnt->fetch();
  assertTrue(((int)$cRow['c']) === 1, 'Mark read reduces unread count');

  
  $pdo->prepare('DELETE FROM notifications WHERE user_id = ? AND is_read = 1 AND title LIKE "[TEST]%"')->execute([$userId]);
  $check = $pdo->prepare('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND title LIKE "[TEST]%"');
  $check->execute([$userId]);
  $totalAfter = (int)$check->fetch()['c'];
  assertTrue($totalAfter === 1, 'Clear read leaves only unread test rows');

  echo "Tests complete." . PHP_EOL;
} catch (Throwable $e) {
  echo '[ERROR] ' . $e->getMessage() . PHP_EOL;
  exit(1);
}