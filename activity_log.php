<?php /* Activity log page: ipinapakita ang mga recent actions sa system. */

require_once 'includes/auth_guard.php';
require_once 'config/database.php';

$role = strtolower($_SESSION['role'] ?? ($_SESSION['login_user']['role'] ?? ''));
if ($role !== 'admin') { http_response_code(403); echo 'Forbidden'; exit; }


try {
  $pdo = getDBConnection();
  $stmt = $pdo->query('SELECT a.id, a.user_id, a.action, a.entity_type, a.entity_id, a.ip_address, a.created_at, u.username, u.first_name, u.last_name FROM activity_log a LEFT JOIN users u ON a.user_id = u.id ORDER BY a.id DESC LIMIT 500');
  $activityRows = $stmt->fetchAll();
  
  $auditLogs = array_map(function($r){
    return [
      'user' => (trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ($r['username'] ?? 'Unknown')),
      'action' => $r['action'],
      'status' => 'success',
      'severity' => 'low',
      'timestamp' => $r['created_at'],
    ];
  }, $activityRows);
  
} catch (Throwable $e) {
  $activityRows = [];
  $auditLogs = [];
}
?>
<?php include 'includes/header.php'; ?>
<style>
  html, body { height: auto; overflow-y: auto !important; }
  .app-content { min-height: calc(100vh - var(--header-height, 64px)); overflow-y: auto; }
  .container, .container-fluid { min-height: calc(100vh - 120px); overflow-y: auto; padding-bottom: 2rem; }
</style>
<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
      <h3>Activity Logs</h3>
      <p class="text-muted mb-0">Monitor system activities and user actions</p>
    </div>
    <button class="btn btn-primary" onclick="exportLogs()">Export Logs</button>
  </div>

  <div class="card p-3">
    <div class="table-responsive" style="max-height:600px;overflow:auto;">
      <table class="table table-striped table-sm table-log">
        <thead class="table-light"><tr><th>User</th><th>Action</th><th>Status</th><th>Severity</th><th>Time</th></tr></thead>
        <tbody>
          <?php foreach ($auditLogs as $log): 
            $sevMap = ['low'=>'info', 'medium'=>'warning', 'high'=>'danger', 'critical'=>'danger']; 
            $sevClass = $sevMap[$log['severity']] ?? 'secondary';
          ?>
          <tr>
            <td><?= $log['user'] ?></td>
            <td><?= strtoupper(str_replace('_',' ',$log['action'])) ?></td>
            <td><span class="badge bg-<?= $log['status']=='success'?'success':($log['status']=='failure'?'danger':($log['status']=='warning'?'warning':'secondary')) ?>"><?= ucfirst($log['status']) ?></span></td>
            <td><span class="badge bg-<?= $sevClass ?>"><?= ucfirst($log['severity']) ?></span></td>
            <td><?= date('Y-m-d H:i',strtotime($log['timestamp'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function exportLogs(){
  const table = document.querySelector('.table-log');
  if (!table) return alert('No data to export');
  const rows = Array.from(table.querySelectorAll('tr'));
  const csv = rows.map(tr => Array.from(tr.querySelectorAll('th,td')).map(td => '"' + (td.innerText||'').replace(/"/g,'""') + '"').join(',')).join('\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'activity_log.csv';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}
</script>
<?php include 'includes/footer.php'; ?>
