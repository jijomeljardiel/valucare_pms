<?php /* User profile page: details at updates ng account. */

require_once 'includes/auth_guard.php';
require_once 'config/database.php';
$message = null;
$userRow = null;
  try {
    $pdo = getDBConnection();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0) {
    $stmt = $pdo->prepare('SELECT id, username, email, first_name, last_name, department, position, phone, hire_date FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $userRow = $stmt->fetch();
    try { $ps = $pdo->prepare('SELECT presence FROM users WHERE id = ?'); $ps->execute([$uid]); $pr = $ps->fetch(); if ($pr && isset($pr['presence'])) { $_SESSION['presence'] = $pr['presence']; } } catch (Throwable $e) {}
    }
  } catch (Throwable $e) { $userRow = null; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='update_profile') {
  try {
    $pdo = isset($pdo) ? $pdo : getDBConnection();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $first = trim($_POST['firstName'] ?? '');
    $last = trim($_POST['lastName'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $presence = strtolower(trim($_POST['presence'] ?? ''));
    $allowedPresence = ['online','away','busy','offline'];
    if (!in_array($presence, $allowedPresence, true)) { $presence = ''; }
    if ($uid > 0) {
      $stmt = $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?');
      $stmt->execute([$first, $last, $email, $phone, $uid]);
      if ($presence !== '') { try { $stp = $pdo->prepare('UPDATE users SET presence = ? WHERE id = ?'); $stp->execute([$presence, $uid]); $_SESSION['presence'] = $presence; } catch (Throwable $e) {} }
      $message = "Profile updated successfully.";
      $stmt = $pdo->prepare('SELECT id, username, email, first_name, last_name, department, position, phone, hire_date FROM users WHERE id = ?');
      $stmt->execute([$uid]);
      $userRow = $stmt->fetch();
    }
  } catch (Throwable $e) { $message = 'Failed to update profile.'; }
}

$user = [
  'firstName' => $userRow['first_name'] ?? '',
  'lastName' => $userRow['last_name'] ?? '',
  'email' => $userRow['email'] ?? '',
  'phone' => $userRow['phone'] ?? '',
  'department' => $userRow['department'] ?? 'ICT',
  'position' => $userRow['position'] ?? '',
  'role' => strtolower($_SESSION['role'] ?? ($_SESSION['login_user']['role'] ?? '')),
  'hireDate' => $userRow['hire_date'] ?? '',
  'location' => '',
  'bio' => '',
  'presence' => strtolower($_SESSION['presence'] ?? 'offline')
];
?>
<?php include 'includes/header.php'; ?>
<style>
  html, body { height: auto; overflow-y: auto !important; }
  .app-content { min-height: calc(100vh - var(--header-height, 64px)); overflow-y: auto; }
  .container, .container-fluid { min-height: calc(100vh - 120px); overflow-y: auto; padding-bottom: 2rem; }
</style>
<div class="container py-4">
  <h3>My Profile</h3>
  <p class="text-muted mb-4">Manage your personal information, preferences, and security settings</p>

  <?php if (!empty($message)): ?>
  <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <ul class="nav nav-tabs" id="accountTabs">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#profile">Profile</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#preferences">Preferences</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#security">Security</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#notifications">Notifications</a></li>
  </ul>

  <div class="tab-content">
    
    <div class="tab-pane fade show active" id="profile">
      <form method="POST" class="card mt-3 p-4">
        <input type="hidden" name="action" value="update_profile">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">First Name</label>
            <input name="firstName" value="<?= $user['firstName'] ?>" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Last Name</label>
            <input name="lastName" value="<?= $user['lastName'] ?>" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" value="<?= $user['email'] ?>" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Phone</label>
            <input name="phone" value="<?= $user['phone'] ?>" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Department</label>
            <input value="<?= $user['department'] ?>" class="form-control" disabled>
          </div>
          <div class="col-md-6">
            <label class="form-label">Position</label>
            <input value="<?= $user['position'] ?>" class="form-control" disabled>
          </div>
          <div class="col-md-6">
            <label class="form-label">Location</label>
            <input name="location" value="<?= $user['location'] ?>" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Hire Date</label>
            <input value="<?= $user['hireDate'] ?>" class="form-control" disabled>
          </div>
          <div class="col-12">
            <label class="form-label">Bio</label>
            <textarea name="bio" rows="3" class="form-control"><?= $user['bio'] ?></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">Status</label>
            <div class="btn-group" role="group" aria-label="Presence">
              <input type="radio" class="btn-check" name="presence" id="presenceOnline" value="online" <?= $user['presence']==='online'?'checked':'' ?>>
              <label class="btn btn-outline-primary" for="presenceOnline">Online</label>
              <input type="radio" class="btn-check" name="presence" id="presenceAway" value="away" <?= $user['presence']==='away'?'checked':'' ?>>
              <label class="btn btn-outline-primary" for="presenceAway">Away</label>
              <input type="radio" class="btn-check" name="presence" id="presenceBusy" value="busy" <?= $user['presence']==='busy'?'checked':'' ?>>
              <label class="btn btn-outline-primary" for="presenceBusy">Busy</label>
              <input type="radio" class="btn-check" name="presence" id="presenceOffline" value="offline" <?= $user['presence']==='offline'?'checked':'' ?>>
              <label class="btn btn-outline-primary" for="presenceOffline">Offline</label>
            </div>
          </div>
        </div>
        <div class="mt-4 text-end">
          <button class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>

    
    <div class="tab-pane fade" id="preferences">
      <div class="card mt-3 p-4">
        <h5>Application Preferences</h5>
        <p class="text-muted">Customize your experience</p>
        <div class="mb-3">
          <label class="form-label">Default View</label>
          <select class="form-select">
            <option>Kanban Board</option><option>List View</option><option>Calendar View</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Time Zone</label>
          <select class="form-select">
            <option>WAT (UTC+1)</option><option>GMT (UTC+0)</option><option>EST (UTC-5)</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Language</label>
          <select class="form-select">
            <option>English</option><option>French</option><option>Yoruba</option>
          </select>
        </div>
      </div>
    </div>

    
    <div class="tab-pane fade" id="security">
      <div class="card mt-3 p-4">
        <h5>Password & Security</h5>
        <p class="text-muted">Manage your password and 2FA</p>
        <div class="row g-3 mb-3">
          <div class="col-md-4"><input type="password" placeholder="Current password" class="form-control"></div>
          <div class="col-md-4"><input type="password" placeholder="New password" class="form-control"></div>
          <div class="col-md-4"><input type="password" placeholder="Confirm new password" class="form-control"></div>
        </div>
        <button class="btn btn-primary mb-4">Update Password</button>

        <h6>Two-Factor Authentication</h6>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="twofaSwitch">
          <label class="form-check-label" for="twofaSwitch">Enable 2FA</label>
        </div>

        <hr>
        <h6>Active Session</h6>
        <div class="p-3 border rounded">
          <p class="mb-1 small fw-semibold">Current Session</p>
          <span class="text-muted small">Lagos, Nigeria • Chrome on Windows</span>
        </div>
      </div>
    </div>

    
    <div class="tab-pane fade" id="notifications">
      <div class="card mt-3 p-4">
        <h5>Notification Settings</h5>
        <p class="text-muted">Manage how you receive alerts</p>

        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" checked> 
          <label class="form-check-label">Email Notifications</label>
        </div>
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" checked> 
          <label class="form-check-label">Push Notifications</label>
        </div>
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" checked> 
          <label class="form-check-label">Task Reminders</label>
        </div>
        <div class="form-check form-switch mb-4">
          <input class="form-check-input" type="checkbox"> 
          <label class="form-check-label">Weekly Digest</label>
        </div>

        <h6>Email Preferences</h6>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" checked><label class="form-check-label">Task assignments</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" checked><label class="form-check-label">Project updates</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox"><label class="form-check-label">Team mentions</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" checked><label class="form-check-label">Deadline reminders</label>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
