<?php /* File team_collaboration_test.php: bahagi ng ValuCare PMS app. */
require_once __DIR__ . '/../config/database.php';

function out($msg){ echo $msg . PHP_EOL; }

try {
  $pdo = getDBConnection();
  $pdo->exec('SET NAMES utf8mb4');
  $userId = 1; 
  $pdo->beginTransaction();
  try {
    $pdo->prepare('INSERT INTO channels (name,type,created_by) VALUES (?,?,?)')
        ->execute(['[TEST] TeamCollab', 'public', $userId]);
    $cid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO messages (channel_id,user_id,content) VALUES (?,?,?)')
        ->execute([$cid, $userId, '[TEST] Message']);
    $pdo->commit();
    $count = (int)$pdo->query('SELECT COUNT(*) AS c FROM messages WHERE channel_id = ' . $cid)->fetch()['c'];
    out("PASS: Created channel id={$cid} and {$count} message(s)");
    
    $pdo->prepare('DELETE FROM messages WHERE channel_id = ?')->execute([$cid]);
    $pdo->prepare('DELETE FROM channels WHERE id = ?')->execute([$cid]);
    out('PASS: Cleanup complete');
  } catch (Throwable $e) {
    $pdo->rollBack();
    out('FAIL: ' . $e->getMessage());
    exit(1);
  }
} catch (Throwable $e) {
  out('ERROR: DB connection failed');
  exit(1);
}
?>