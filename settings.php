<?php /* Settings UI: configuration ng app at user preferences. */

require_once 'includes/auth_guard.php';
require_once 'config/database.php';
 

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf_token'];
?>
<?php include 'includes/header.php'; ?>
<style>
  .settings-header{display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1.25rem}
  .settings-grid{display:grid;grid-template-columns:260px 1fr;gap:1.25rem}
  .nav-pills .nav-link{display:flex;align-items:center;gap:.5rem}
  .toast-container{position:fixed;bottom:1rem;right:1rem;z-index:1080}
</style>
<div class="container py-4">
  <div class="settings-header">
    <div>
      <h3>Settings</h3>
      <p class="text-muted mb-0">Configure notifications and system preferences</p>
    </div>
    <button class="btn btn-primary" onclick="saveSettings()"><i class="bi bi-save"></i> Save All</button>
  </div>
  <div class="settings-grid">
    <div>
      <div class="card p-3">
        <div class="nav nav-pills flex-column" id="settingsNav" role="tablist">
          <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-notifications" type="button" role="tab"><i class="bi bi-bell"></i> Notifications</button>
          <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-system" type="button" role="tab"><i class="bi bi-cpu"></i> System</button>
        </div>
      </div>
    </div>
    <div>
      <div class="tab-content">
        <div class="tab-pane fade show active" id="tab-notifications" role="tabpanel">
          <form id="notificationsForm" method="post" action="settings_actions.php" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="save_notifications">
            <div class="col-lg-6">
              <div class="card p-4">
                <h6 class="mb-2">Channels</h6>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="notif_email" checked> <label class="form-check-label">Email</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="notif_push" checked> <label class="form-check-label">Push</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="notif_slack"> <label class="form-check-label">Slack</label></div>
              </div>
            </div>
            <div class="col-lg-6">
              <div class="card p-4">
                <h6 class="mb-2">Frequency</h6>
                <select class="form-select w-auto" name="notif_frequency">
                  <option value="realtime" selected>Real-time</option>
                  <option value="hourly">Hourly Digest</option>
                  <option value="daily">Daily Summary</option>
                </select>
              </div>
            </div>
            <div class="col-12">
              <div class="card p-4">
                <h6 class="mb-2">Types</h6>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="notif_tasks" checked> <label class="form-check-label">Task Assignments</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="notif_projects" checked> <label class="form-check-label">Project Updates</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="notif_system" checked> <label class="form-check-label">System Alerts</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="notif_mentions" checked> <label class="form-check-label">Team Mentions</label></div>
                <div class="text-end mt-3"><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Save Notifications</button></div>
              </div>
            </div>
          </form>
        </div>
        <div class="tab-pane fade" id="tab-system" role="tabpanel">
          <form id="systemForm" method="post" action="settings_actions.php" class="row g-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="save_system">
            <div class="col-lg-6">
              <div class="card p-4">
                <h6 class="mb-2">Database</h6>
                <div class="mb-3">
                  <label class="form-label">Host</label>
                  <input class="form-control" value="<?= defined('DB_HOST')?DB_HOST:'' ?>" readonly>
                </div>
                <div class="mb-3">
                  <label class="form-label">Backup Frequency</label>
                  <select class="form-select" name="backup_frequency">
                    <option value="hourly">Hourly</option>
                    <option value="daily" selected>Daily</option>
                    <option value="weekly">Weekly</option>
                  </select>
                </div>
                <div>
                  <label class="form-label">Backup Retention (days)</label>
                  <input type="number" value="30" class="form-control" name="backup_retention">
                </div>
              </div>
            </div>
            <div class="col-lg-6">
              <div class="card p-4">
                <h6 class="mb-2">Security</h6>
                <div class="form-check form-switch mb-2">
                  <input class="form-check-input" type="checkbox" name="security_2fa" checked> <label class="form-check-label">Two-Factor Authentication</label>
                </div>
                <div class="form-check form-switch mb-3">
                  <input class="form-check-input" type="checkbox" name="security_strong_pw" checked> <label class="form-check-label">Strong Password Policy</label>
                </div>
                <label class="form-label">Session Timeout (minutes)</label>
                <input type="number" value="30" class="form-control" name="session_timeout">
                <div class="text-end mt-3"><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Save System</button></div>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>


<div class="toast-container">
  <div id="saveToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="toast-header">
      <strong class="me-auto">Settings</strong>
      <small>Now</small>
      <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
    <div class="toast-body">Saved successfully.</div>
  </div>
  <div id="errorToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="toast-header">
      <strong class="me-auto">Settings</strong>
      <small>Now</small>
      <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
    <div class="toast-body">Save failed. Please try again.</div>
  </div>
</div>
<script>
async function postForm(form){
  const fd = new FormData(form);
  const res = await fetch('settings_actions.php', { method:'POST', body: fd, headers: { 'Accept':'application/json' } });
  if (!res.ok) throw new Error('request');
  const data = await res.json();
  if (!data.ok) throw new Error('server');
}
async function saveSettings(){
  const toastOk = new bootstrap.Toast(document.getElementById('saveToast'));
  const toastErr = new bootstrap.Toast(document.getElementById('errorToast'));
  try {
    const nf = document.getElementById('notificationsForm');
    const sf = document.getElementById('systemForm');
    if (nf) await postForm(nf);
    if (sf) await postForm(sf);
    toastOk.show();
  } catch (e) {
    toastErr.show();
  }
}
</script>
<?php include 'includes/footer.php'; ?>
