<?php /* User profile page: details at updates ng account. */

$message = null;
$messageType = null;
require_once 'includes/auth_guard.php';
require_once 'config/database.php';
require_once 'includes/activity_logger.php';
$debug = getenv('VC_APP_DEBUG') ?: getenv('APP_DEBUG');
$isDebug = in_array(strtolower((string)$debug), ['1','true','yes','on']) || in_array(strtolower((string)($_GET['debug'] ?? '')), ['1','true','yes','on']);

$pdo = null;
try { $pdo = getDBConnection(); } catch (Exception $e) { $pdo = null; }

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentUserRole = strtolower($_SESSION['role'] ?? ($_SESSION['login_user']['role'] ?? ''));
$currentUserRole = str_replace([' ', '-', 'system dev', 'system_dev'], ['_', '_', 'systemdev', 'systemdev'], $currentUserRole);
if (in_array($currentUserRole, ['teamlead','team_lead','team lead','team-lead','manager'], true)) { $currentUserRole = 'project_manager'; }

// Determine target user
$targetId = isset($_REQUEST['target_id']) ? (int)$_REQUEST['target_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : $currentUserId);
if ($targetId <= 0) $targetId = $currentUserId;

$userRow = null;
if ($pdo) {
  try {
    // Attempt to fetch user details including role and presence
    $stmt = $pdo->prepare('SELECT id, username, email, first_name, last_name, role, department, position, phone, hire_date, presence FROM users WHERE id = ?');
    $stmt->execute([$targetId]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {}
}

if (!$userRow) {
    // Fallback to current user if target not found
    $targetId = $currentUserId;
    if ($pdo) {
        try {
            $stmt = $pdo->prepare('SELECT id, username, email, first_name, last_name, role, department, position, phone, hire_date, presence FROM users WHERE id = ?');
            $stmt->execute([$targetId]);
            $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }
}

$targetUserRole = strtolower($userRow['role'] ?? '');

// Permission Check
$canEdit = false;
if ($currentUserRole === 'admin') {
    $canEdit = true;
} elseif ($currentUserRole === 'project_manager') {
    // Manager can edit SystemDevs or themselves
    if ($targetUserRole === 'systemdev' || $targetId === $currentUserId) {
        $canEdit = true;
    }
} elseif ($targetId === $currentUserId) {
    // Users can always edit themselves
    $canEdit = true;
}

if (!$canEdit) {
    // If trying to access someone else without permission, redirect to own profile
    if ($targetId !== $currentUserId) {
        header('Location: profile.php');
        exit;
    }
}

// Determine granular permissions
$canChangeRole = ($currentUserRole === 'admin' || ($currentUserRole === 'project_manager' && $targetUserRole === 'systemdev')) && $targetId !== $currentUserId;
$canChangeJobDetails = $canChangeRole; // Admins/Managers can change Dept/Position

// Sync session presence only if viewing own profile
if ($targetId === $currentUserId && isset($userRow['presence'])) {
    $_SESSION['presence'] = $userRow['presence'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='update_profile') {
  if (!$canEdit) {
      $message = 'You do not have permission to edit this profile.';
      $messageType = 'danger';
  } else {
      try {
        $first = trim($_POST['firstName'] ?? '');
        $last = trim($_POST['lastName'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $presence = strtolower(trim($_POST['presence'] ?? ''));
        $allowedPresence = ['online','away','busy','offline'];
        if (!in_array($presence, $allowedPresence, true)) { $presence = ''; }
        
        if (($first !== '' && strlen($first) < 2) || ($last !== '' && strlen($last) < 2)) {
            $message = 'Names must be at least 2 characters.';
            $messageType = 'danger';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $messageType = 'danger';
        } else {
            // Build update query dynamically based on permissions
            $sql = 'UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?';
            $params = [$first, $last, $email !== '' ? $email : null, $phone !== '' ? $phone : null];

            if ($canChangeJobDetails) {
                $sql .= ', department = ?, position = ?';
                $params[] = $department;
                $params[] = $position;
            }

            $sql .= ' WHERE id = ?';
            $params[] = $targetId;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $affected = (int)$stmt->rowCount();

            $newRole = trim($_POST['role'] ?? '');
            if ($newRole !== '' && $canChangeRole) {
                $allowedRoles = ['systemdev', 'project_manager'];
                if ($currentUserRole === 'admin') $allowedRoles[] = 'admin';
                if (in_array($newRole, $allowedRoles, true)) {
                    $stR = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
                    $stR->execute([$newRole, $targetId]);
                    $affected += (int)$stR->rowCount();
                }
            }
            
            if ($presence !== '') { 
                try { 
                    $stp = $pdo->prepare('UPDATE users SET presence = ? WHERE id = ?'); 
                    $stp->execute([$presence, $targetId]); 
                    $affected += (int)$stp->rowCount();
                    // Update session if it's the current user
                    if ($targetId === $currentUserId) {
                        $_SESSION["presence"] = $presence; 
                    }
                } catch (Throwable $e) {} 
            }
            
            $message = $affected > 0 ? "Profile updated successfully." : "No changes to save.";
            $messageType = 'success';
            
            try { log_activity('profile_update_success', 'user', $targetId, ['updated_by'=>$currentUserId, 'first_name'=>$first], $pdo); } catch (Throwable $e) {}
            
            // Refresh data
            $stmt = $pdo->prepare('SELECT id, username, email, first_name, last_name, role, department, position, phone, hire_date, presence FROM users WHERE id = ?');
            $stmt->execute([$targetId]);
            $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
        }
      } catch (PDOException $e) {
        $code = (string)$e->getCode();
        $messageType = 'danger';
        if ($code === '23000') { $message = 'Email is already in use.'; }
        else { $message = 'Failed to update profile: ' . $e->getMessage(); }
        
        try { log_activity('profile_update_failed', 'user', $targetId, ['code'=>$code, 'error'=>$e->getMessage()], isset($pdo)?$pdo:null); } catch (Throwable $ex) {}
      } catch (Throwable $e) { 
          $message = 'Failed to update profile: ' . $e->getMessage(); 
          $messageType = 'danger'; 
      }
  }
}

$user = [
  'firstName' => $userRow['first_name'] ?? '',
  'lastName' => $userRow['last_name'] ?? '',
  'email' => $userRow['email'] ?? '',
  'phone' => $userRow['phone'] ?? '',
  'department' => $userRow['department'] ?? 'ICT',
  'position' => $userRow['position'] ?? '',
  'role' => strtolower($userRow['role'] ?? ''),
  'hireDate' => $userRow['hire_date'] ?? '',
  'location' => '',
  'bio' => '',
  'presence' => strtolower($userRow['presence'] ?? 'offline')
];
?>
<?php include 'includes/header.php'; ?>
<style>
  html, body { height: auto; overflow-y: auto !important; }
  .app-content { min-height: calc(100vh - var(--header-height, 64px)); overflow-y: auto; }
  .container, .container-fluid { min-height: calc(100vh - 120px); overflow-y: auto; padding-bottom: 2rem; }
</style>
<div class="container py-4">
  <h3><?= ($targetId === $currentUserId) ? 'My Profile' : 'User Profile' ?></h3>
  <p class="text-muted mb-4">Manage <?= ($targetId === $currentUserId) ? 'your' : 'user' ?> personal information, preferences, and security settings</p>

  <?php if (!empty($message)): ?>
  <div class="alert alert-<?= $messageType==='danger' ? 'danger' : 'success' ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <ul class="nav nav-tabs" id="accountTabs">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#profile">Profile</a></li>
    <?php if ($targetId === $currentUserId): ?>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#preferences">Preferences</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#security">Security</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#notifications">Notifications</a></li>
    <?php endif; ?>
  </ul>

  <div class="tab-content">
    
    <div class="tab-pane fade show active" id="profile">
      <form method="POST" action="profile.php" class="card mt-3 p-4">
        <input type="hidden" name="action" value="update_profile">
        <input type="hidden" name="target_id" value="<?= $targetId ?>">
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
            <?php if ($canChangeJobDetails): ?>
              <input name="department" value="<?= $user['department'] ?>" class="form-control">
            <?php else: ?>
              <input value="<?= $user['department'] ?>" class="form-control" disabled>
            <?php endif; ?>
          </div>
          <div class="col-md-6">
            <label class="form-label">Position</label>
            <?php if ($canChangeJobDetails): ?>
              <input name="position" value="<?= $user['position'] ?>" class="form-control">
            <?php else: ?>
              <input value="<?= $user['position'] ?>" class="form-control" disabled>
            <?php endif; ?>
          </div>
          <div class="col-md-6">
            <label class="form-label">Role</label>
            <?php if ($canChangeRole): ?>
              <select name="role" class="form-select">
                <option value="systemdev" <?= $user['role']==='systemdev'?'selected':'' ?>>SystemDev</option>
                <option value="project_manager" <?= $user['role']==='project_manager'?'selected':'' ?>>Project Manager</option>
                <?php if ($currentUserRole === 'admin'): ?>
                <option value="admin" <?= $user['role']==='admin'?'selected':'' ?>>Admin</option>
                <?php endif; ?>
              </select>
            <?php else: ?>
              <input value="<?= $user['role'] ?>" class="form-control" disabled>
            <?php endif; ?>
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
            <button type="submit" class="btn btn-primary">Save Changes</button>
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
