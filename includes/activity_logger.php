<?php
require_once __DIR__ . '/../config/database.php';

function log_activity($action, $entity_type = null, $entity_id = null, $metadata = null, $pdo = null) {
    try {
        if (!$pdo) { $pdo = getDBConnection(); }
        $user_id = isset($_SESSION) ? ($_SESSION['user_id'] ?? null) : null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $meta = $metadata !== null ? json_encode($metadata) : null;
        $st = $pdo->prepare('INSERT INTO activity_log (user_id, action, entity_type, entity_id, ip_address, metadata, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $st->execute([$user_id, $action, $entity_type, $entity_id, $ip, $meta]);
    } catch (Throwable $e) {}
}
?>