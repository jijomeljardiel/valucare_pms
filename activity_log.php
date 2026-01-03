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
      'details' => $r['entity_type'] ? ($r['entity_type'].' #'.$r['entity_id']) : '',
      'ip' => $r['ip_address'],
      'timestamp' => $r['created_at'],
    ];
  }, $activityRows);
  
  $securityEvents = array_values(array_filter(array_map(function($r){
    $isSecurity = strpos($r['action'], 'login') !== false || strpos($r['action'], 'password') !== false;
    if (!$isSecurity) return null;
    return [
      'risk' => 'medium',
      'type' => $r['action'],
      'status' => 'pending',
      'description' => ($r['entity_type'] ? ($r['entity_type'].' #'.$r['entity_id']) : 'Account event'),
      'user' => (trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ($r['username'] ?? 'Unknown')),
      'ip' => $r['ip_address'],
      'timestamp' => $r['created_at'],
    ];
  }, $activityRows)));
} catch (Throwable $e) {
  $activityRows = [];
  $auditLogs = [];
  $securityEvents = [];
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
      <h3>Audit & Security Log</h3>
      <p class="text-muted mb-0">Monitor system activities and security events</p>
    </div>
    <button class="btn btn-primary" onclick="exportLogs()">Export Logs</button>
  </div>

  
  <ul class="nav nav-tabs" id="logTabs" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#unified">Unified View</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#audit">Audit Logs</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#security">Security Events</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#dashboard">Security Dashboard</button></li>
  </ul>

  <div class="tab-content mt-3">
    
    <div class="tab-pane fade show active" id="unified">
      <div class="card p-3">
        <h5 class="mb-3">Unified Activity & Security Log</h5>
        <div class="table-responsive" style="max-height:500px;overflow:auto;">
          <table class="table table-sm table-striped table-log">
            <thead class="table-light"><tr><th>Type</th><th>User</th><th>Action/Event</th><th>Status</th><th>Priority</th><th>Details</th><th>IP</th><th>Time</th></tr></thead>
            <tbody>
              <?php foreach ($activityRows as $row): 
                $userName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: ($row['username'] ?? 'Unknown');
                $priority = 'low';
                $status = 'success';
                $details = $row['entity_type'] ? ($row['entity_type'] . ' #' . $row['entity_id']) : '';
                ?>
              <tr>
                <td><span class="badge bg-secondary">Audit</span></td>
                <td><?= htmlspecialchars($userName) ?></td>
                <td><?= htmlspecialchars(strtoupper(str_replace('_',' ',$row['action']))) ?></td>
                <td><span class="badge bg-success">Success</span></td>
                <td><span class="badge badge-<?= $priority ?>"><?= ucfirst($priority) ?></span></td>
                <td><?= htmlspecialchars($details) ?></td>
                <td><?= htmlspecialchars($row['ip_address'] ?? '') ?></td>
                <td><?= htmlspecialchars(date('Y-m-d H:i',strtotime($row['created_at']))) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    
    <div class="tab-pane fade" id="audit">
      <div class="card p-3">
        <h5>Audit Logs</h5>
        <p class="text-muted small">System activities and user actions</p>
        <div class="table-responsive" style="max-height:500px;overflow:auto;">
          <table class="table table-striped table-sm">
            <thead class="table-light"><tr><th>User</th><th>Action</th><th>Status</th><th>Severity</th><th>Details</th><th>IP</th><th>Time</th></tr></thead>
            <tbody>
              <?php foreach ($auditLogs as $log): ?>
              <tr>
                <td><?= $log['user'] ?></td>
                <td><?= strtoupper(str_replace('_',' ',$log['action'])) ?></td>
                <td><span class="badge bg-<?= $log['status']=='success'?'success':($log['status']=='failure'?'danger':($log['status']=='warning'?'warning':'secondary')) ?>"><?= ucfirst($log['status']) ?></span></td>
                <td><span class="badge badge-<?= $log['severity'] ?>"><?= ucfirst($log['severity']) ?></span></td>
                <td><?= $log['details'] ?></td>
                <td><?= $log['ip'] ?></td>
                <td><?= date('Y-m-d H:i',strtotime($log['timestamp'])) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    
    <div class="tab-pane fade" id="security">
      <div class="card p-3">
        <h5>Security Events</h5>
        <p class="text-muted small">Incidents and threat detection</p>
        <div class="table-responsive" style="max-height:500px;overflow:auto;">
          <table class="table table-striped table-sm">
            <thead class="table-light"><tr><th>Risk</th><th>Type</th><th>Status</th><th>Description</th><th>User</th><th>IP</th><th>Time</th></tr></thead>
            <tbody>
              <?php foreach ($securityEvents as $event): ?>
              <tr>
                <td><span class="badge badge-<?= $event['risk'] ?>"><?= ucfirst($event['risk']) ?></span></td>
                <td><?= strtoupper(str_replace('_',' ',$event['type'])) ?></td>
                <td><span class="badge bg-<?= $event['status']=='resolved'?'success':($event['status']=='investigating'?'danger':($event['status']=='pending'?'warning':'secondary')) ?>"><?= ucfirst($event['status']) ?></span></td>
                <td><?= $event['description'] ?></td>
                <td><?= $event['user'] ?></td>
                <td><?= $event['ip'] ?></td>
                <td><?= date('Y-m-d H:i',strtotime($event['timestamp'])) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    
    <div class="tab-pane fade" id="dashboard">
      <div class="row g-3">
        <div class="col-lg-6">
          <div class="card p-4">
            <h5>Security Dashboard</h5>
            <p class="text-muted small">Real-time metrics and alerts</p>
            <div class="mb-3">
              <div class="d-flex justify-content-between"><small>Current Threat Level</small><span class="badge bg-warning text-dark">MEDIUM</span></div>
              <div class="progress" style="height:6px;"><div class="progress-bar bg-warning" style="width:60%;"></div></div>
            </div>
            <div class="row text-center g-2 mb-3">
              <div class="col-6 border rounded py-2"><div class="text-danger fs-5">3</div><small>Active Threats</small></div>
              <div class="col-6 border rounded py-2"><div class="text-success fs-5">98.5%</div><small>Security Score</small></div>
              <div class="col-6 border rounded py-2"><div class="text-primary fs-5">24h</div><small>Last Scan</small></div>
              <div class="col-6 border rounded py-2"><div class="text-warning fs-5">5</div><small>Failed Logins</small></div>
            </div>
            <div>
              <h6>Quick Actions</h6>
              <button class="btn btn-outline-secondary btn-sm w-100 mb-2">🔒 Lock All Sessions</button>
              <button class="btn btn-outline-warning btn-sm w-100 mb-2">⚠️ Run Security Scan</button>
              <button class="btn btn-outline-primary btn-sm w-100">🛡 Generate Report</button>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card p-4">
            <h5>Recent Security Alerts</h5>
            <div class="alert alert-danger p-2"><strong>⚠️ Multiple failed login attempts</strong><br><small>203.45.67.89 - 15 min ago</small></div>
            <div class="alert alert-warning p-2"><strong>⚠️ Security patches available</strong><br><small>2 critical vulnerabilities - 2 hrs ago</small></div>
            <div class="alert alert-warning p-2"><strong>⚠️ Unusual database access pattern</strong><br><small>Off-hours access - 4 hrs ago</small></div>
            <div class="alert alert-danger p-2"><strong>⚠️ Suspicious API access attempts</strong><br><small>Restricted endpoints - Yesterday</small></div>
          </div>
        </div>
      </div>
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
