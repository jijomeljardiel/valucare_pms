<?php /* Team collaboration: updates, comments, at activities. */
require_once 'includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';


date_default_timezone_set('Asia/Manila');
ob_start();


try {
  $pdo = getDBConnection();
  $currentUserId = $_SESSION['user_id'] ?? null;
  function setFlash($type, $message) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
  }
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'send_message') {
      if (!$currentUserId) {
        setFlash('danger', 'You must be logged in to send messages.');
        header('Location: login.php');
        exit;
      }
      $channelId = (int)($_POST['channel_id'] ?? 0);
      $content = trim($_POST['content'] ?? '');
      if ($content !== '') { $content = mb_substr($content, 0, 4000); }
      if (!$channelId) {
        setFlash('danger', 'No channel selected.');
        header('Location: team_collaboration.php');
        exit;
      }
      if ($content === '') {
        setFlash('danger', 'Message cannot be empty.');
        header('Location: team_collaboration.php?channel=' . $channelId);
        exit;
      }
      try {
        $chCheck = $pdo->prepare('SELECT id FROM channels WHERE id = ?');
        $chCheck->execute([$channelId]);
        if (!$chCheck->fetch()) {
          setFlash('danger', 'Selected channel does not exist.');
        } else {
          try {
            $stmt = $pdo->prepare('INSERT INTO messages (channel_id, user_id, content) VALUES (?, ?, ?)');
            $stmt->execute([$channelId, $currentUserId, $content]);
            setFlash('success', 'Message sent');
          } catch (Throwable $e) {
            error_log('[VC-PMS] send_message error: ' . (string)($e->getMessage() ?? 'unknown'));
            try {
              $pdo->exec("CREATE TABLE IF NOT EXISTS `messages` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `channel_id` INT NOT NULL,
                `user_id` INT NOT NULL,
                `content` TEXT NOT NULL,
                `reply_to_id` INT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `edited_at` TIMESTAMP NULL DEFAULT NULL,
                `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_messages_channel_id` (`channel_id`),
                KEY `idx_messages_user_id` (`user_id`),
                KEY `idx_messages_channel_created_at` (`channel_id`,`created_at`)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
              $stmt = $pdo->prepare('INSERT INTO messages (channel_id, user_id, content) VALUES (?, ?, ?)');
              $stmt->execute([$channelId, $currentUserId, $content]);
              setFlash('success', 'Message sent');
            } catch (Throwable $_e) {
              setFlash('danger', 'Failed to send message.');
            }
          }
        }
      } catch (Throwable $e) {
        setFlash('danger', 'Failed to send message.');
      }
      header('Location: team_collaboration.php?channel=' . $channelId);
      exit;
    } elseif ($action === 'create_channel') {
      if (!$currentUserId) {
        setFlash('danger', 'You must be logged in to create channels.');
        header('Location: login.php');
        exit;
      }
      $name = trim($_POST['name'] ?? '');
      if ($name !== '') { $name = mb_substr($name, 0, 160); }
      $slug = trim($_POST['slug'] ?? '');
      $type = $_POST['type'] ?? 'public';
      $type = in_array($type, ['public','private'], true) ? $type : 'public';
      if ($name === '' && $slug !== '') { $name = $slug; }
      if ($name === '') {
        setFlash('danger', 'Channel name is required.');
        header('Location: team_collaboration.php');
        exit;
      }
      $who = $currentUserId;
      try {
        $exists = $pdo->prepare('SELECT id FROM channels WHERE name = ? LIMIT 1');
        $exists->execute([$name]);
        $row = $exists->fetch();
        if ($row && isset($row['id'])) {
          setFlash('success', 'Channel already exists');
          header('Location: team_collaboration.php?channel=' . (int)$row['id']);
          exit;
        }
        try {
          $chk = $pdo->prepare('SELECT id FROM users WHERE id = ?');
          $chk->execute([$currentUserId]);
          if (!$chk->fetch()) { $who = null; }
        } catch (Throwable $_) { $who = $currentUserId; }
        $stmt = $pdo->prepare('INSERT INTO channels (name, type, created_by) VALUES (?, ?, ?)');
        $stmt->execute([$name, $type, $who]);
        $newId = (int)$pdo->lastInsertId();
        setFlash('success', 'Channel created');
        header('Location: team_collaboration.php?channel=' . $newId);
        exit;
      } catch (Throwable $e) {
        error_log('[VC-PMS] create_channel error: ' . (string)($e->getMessage() ?? 'unknown'));
        try {
          $err = (string)($e->getMessage() ?? '');
          $msg = 'Failed to create channel. Please try again.';
          if (stripos($err, 'table') !== false && stripos($err, 'channels') !== false && stripos($err, 'not') !== false && stripos($err, 'found') !== false) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `channels` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `org_id` INT NULL,
              `team_id` INT NULL,
              `name` VARCHAR(160) NOT NULL,
              `type` ENUM('public','private') NOT NULL DEFAULT 'public',
              `created_by` INT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `deleted_at` TIMESTAMP NULL DEFAULT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
            $stmt = $pdo->prepare('INSERT INTO channels (name, type, created_by) VALUES (?, ?, ?)');
            $stmt->execute([$name, $type, $who]);
            $newId = (int)$pdo->lastInsertId();
            setFlash('success', 'Channel created');
            header('Location: team_collaboration.php?channel=' . $newId);
            exit;
          }
          setFlash('danger', $msg);
        } catch (Throwable $_e) {
          setFlash('danger', 'Failed to create channel.');
        }
        header('Location: team_collaboration.php');
        exit;
      }
    } elseif ($action === 'send_announcement') {
      if (!$currentUserId) {
        setFlash('danger', 'You must be logged in to send announcements.');
        header('Location: login.php');
        exit;
      }
      $title = trim($_POST['title'] ?? '');
      $message = trim($_POST['message'] ?? '');
      $recipients = isset($_POST['recipients']) && is_array($_POST['recipients']) ? $_POST['recipients'] : [];
      if ($title !== '' && $message !== '' && !empty($recipients)) {
        try {
          $stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, body, url, is_read) VALUES (?, ?, ?, ?, 0)');
          foreach ($recipients as $uid) {
            $stmt->execute([(int)$uid, $title, $message, null]);
          }
          setFlash('success', 'Announcement sent');
        } catch (Throwable $e) {
          setFlash('danger', 'Failed to send announcement.');
        }
      } else {
        setFlash('danger', 'Title, message and recipients are required.');
      }
      header('Location: team_collaboration.php');
      exit;
    } elseif ($action === 'schedule_meeting') {
      if (!$currentUserId) {
        setFlash('danger', 'You must be logged in to schedule meetings.');
        header('Location: login.php');
        exit;
      }
      $title = trim($_POST['title'] ?? '');
      $description = trim($_POST['description'] ?? '');
      $date = $_POST['date'] ?? '';
      $time = $_POST['time'] ?? '';
      $duration = trim($_POST['duration'] ?? '60');
      $type = $_POST['type'] ?? 'virtual';
      $location = trim($_POST['location'] ?? '');
      $attendees = isset($_POST['attendees']) && is_array($_POST['attendees']) ? $_POST['attendees'] : [];
      if ($title !== '' && $date !== '' && $time !== '' && !empty($attendees)) {
        $body = $description . "\nDate: " . $date . " " . $time . "\nDuration: " . $duration . " mins\nType: " . $type . ($type === 'in-person' && $location ? ("\nLocation: " . $location) : '');
        try {
          $stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, body, url, is_read) VALUES (?, ?, ?, ?, 0)');
          foreach ($attendees as $uid) {
            $stmt->execute([(int)$uid, 'Meeting: ' . $title, $body, null]);
          }
          setFlash('success', 'Meeting scheduled');
        } catch (Throwable $e) {
          setFlash('danger', 'Failed to schedule meeting.');
        }
      } else {
        setFlash('danger', 'Please complete the required meeting details.');
      }
      header('Location: team_collaboration.php');
      exit;
    } elseif ($action === 'delete_channel') {
      if (!$currentUserId) {
        setFlash('danger', 'You must be logged in to delete channels.');
        header('Location: login.php');
        exit;
      }
      $channelId = (int)($_POST['channel_id'] ?? 0);
      if (!$channelId) {
        setFlash('danger', 'No channel selected.');
        header('Location: team_collaboration.php');
        exit;
      }
      try {
        $chk = $pdo->prepare('SELECT id FROM channels WHERE id = ?');
        $chk->execute([$channelId]);
        if (!$chk->fetch()) {
          setFlash('danger', 'Selected channel does not exist.');
        } else {
          try {
            $delMsg = $pdo->prepare('DELETE FROM messages WHERE channel_id = ?');
            $delMsg->execute([$channelId]);
          } catch (Throwable $_e) {
            try {
              $pdo->exec("CREATE TABLE IF NOT EXISTS `messages` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `channel_id` INT NOT NULL,
                `user_id` INT NOT NULL,
                `content` TEXT NOT NULL,
                `reply_to_id` INT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `edited_at` TIMESTAMP NULL DEFAULT NULL,
                `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_messages_channel_id` (`channel_id`),
                KEY `idx_messages_user_id` (`user_id`),
                KEY `idx_messages_channel_created_at` (`channel_id`,`created_at`)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
              $delMsg = $pdo->prepare('DELETE FROM messages WHERE channel_id = ?');
              $delMsg->execute([$channelId]);
            } catch (Throwable $__e) {}
          }
          try {
            $delCh = $pdo->prepare('DELETE FROM channels WHERE id = ?');
            $delCh->execute([$channelId]);
            setFlash('success', 'Channel deleted');
          } catch (Throwable $e) {
            error_log('[VC-PMS] delete_channel error: ' . (string)($e->getMessage() ?? 'unknown'));
            setFlash('danger', 'Failed to delete channel.');
          }
        }
      } catch (Throwable $_) {
        setFlash('danger', 'Failed to delete channel.');
      }
      header('Location: team_collaboration.php');
      exit;
    } elseif ($action === 'mark_read') {
      if (!$currentUserId) {
        setFlash('danger', 'You must be logged in.');
        header('Location: login.php');
        exit;
      }
      $nid = (int)($_POST['id'] ?? 0);
      if ($nid) {
        try {
          $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
          $stmt->execute([$nid, $currentUserId]);
          setFlash('success', 'Notification marked as read');
        } catch (Throwable $e) {
          setFlash('danger', 'Failed to mark notification as read.');
        }
      }
      header('Location: team_collaboration.php');
      exit;
    } elseif ($action === 'clear_read') {
      if (!$currentUserId) {
        setFlash('danger', 'You must be logged in.');
        header('Location: login.php');
        exit;
      }
      try {
        $stmt = $pdo->prepare('DELETE FROM notifications WHERE user_id = ? AND is_read = 1');
        $stmt->execute([$currentUserId]);
        setFlash('success', 'Read notifications cleared');
      } catch (Throwable $e) {
        setFlash('danger', 'Failed to clear notifications.');
      }
      header('Location: team_collaboration.php');
      exit;
    } elseif ($action === 'presence_set') {
      if (!$currentUserId) { header('Content-Type: application/json'); echo json_encode(['error'=>'unauthorized']); exit; }
      $val = strtolower(trim($_POST['value'] ?? ''));
      $allowed = ['online','away','busy','offline'];
      if (!in_array($val, $allowed, true)) { header('Content-Type: application/json'); echo json_encode(['error'=>'invalid']); exit; }
      try {
        $st = $pdo->prepare('UPDATE users SET presence = ? WHERE id = ?');
        $st->execute([$val, $currentUserId]);
        header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
      } catch (Throwable $e) {
        try {
          $pdo->exec("ALTER TABLE `users` ADD COLUMN `presence` ENUM('online','away','busy','offline') NULL DEFAULT 'offline'");
          $st = $pdo->prepare('UPDATE users SET presence = ? WHERE id = ?');
          $st->execute([$val, $currentUserId]);
          header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
        } catch (Throwable $_e) {
          header('Content-Type: application/json'); echo json_encode(['error'=>'server_error']); exit;
        }
      }
    }
  }
} catch (Throwable $e) {
  if (session_status() === PHP_SESSION_NONE) { session_start(); }
  $_SESSION['flash'] = ['type' => 'danger', 'message' => 'A server error occurred.'];
}




function fmtTime($iso) {
    try {
        $dt = new DateTime($iso);
        return $dt->format('M j, Y \a\t H:i');
    } catch (Exception $e) {
        return $iso;
    }
}


$js_teamMembers = '[]';
$js_channels = '[]';
$js_messages = '[]';
$js_notifications = '[]';

$onlineCount = 0;
$awayCount = 0;
$busyCount = 0;
$offlineCount = 0;
$teamMembers = [];
$channels = [];
$msgs = [];
try {
  $pdo = getDBConnection();
  $selectedChannelId = isset($_GET['channel']) ? (int)$_GET['channel'] : null;
  
  $rows = [];
  try {
    $rows = $pdo->query("SELECT id, username, first_name, last_name, role, department, position, email, phone, presence FROM users ORDER BY first_name ASC LIMIT 500")->fetchAll();
  } catch (Throwable $e) {
    try { $rows = $pdo->query("SELECT id, username, first_name, last_name, role, department, position, email, phone FROM users ORDER BY first_name ASC LIMIT 500")->fetchAll(); }
    catch (Throwable $_) { $rows = []; }
  }
  $teamMembers = array_map(function($u){
    $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['username'] ?? 'User '.$u['id']);
    $avatar = ($u['first_name'] && $u['last_name']) ? ($u['first_name'][0] . $u['last_name'][0]) : (substr($name,0,2));
    return [
      'id'=> (string)$u['id'], 'name'=>$name, 'role'=>$u['position'] ?? $u['role'] ?? 'Staff',
      'department'=>$u['department'] ?? 'ICT', 'status'=> strtolower($u['presence'] ?? 'online'), 'avatar'=>$avatar,
      'email'=>$u['email'] ?? null, 'phone'=>$u['phone'] ?? null
    ];
  }, $rows ?: []);
  
  $onlineCount = 0; $awayCount = 0; $busyCount = 0; $offlineCount = 0;
  foreach ($teamMembers as $tm) {
    $st = $tm['status'] ?? 'offline';
    if ($st === 'online') { $onlineCount++; }
    elseif ($st === 'away') { $awayCount++; }
    elseif ($st === 'busy') { $busyCount++; }
    else { $offlineCount++; }
  }
  $js_teamMembers = json_encode($teamMembers, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);

  
  $chs = [];
  try {
    $chs = $pdo->query("SELECT id, name, type FROM channels ORDER BY id ASC")->fetchAll();
  } catch (Throwable $e) {
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS `channels` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `org_id` INT NULL,
        `team_id` INT NULL,
        `name` VARCHAR(160) NOT NULL,
        `type` ENUM('public','private') NOT NULL DEFAULT 'public',
        `created_by` INT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `deleted_at` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      $chs = $pdo->query("SELECT id, name, type FROM channels ORDER BY id ASC")->fetchAll();
    } catch (Throwable $_) {}
  }
  if (!$chs || empty($chs)) {
    try {
      $stSeed = $pdo->prepare('INSERT INTO channels (name, type, created_by) VALUES (?, ?, ?)');
      $stSeed->execute(['General', 'public', null]);
      $chs = $pdo->query("SELECT id, name, type FROM channels ORDER BY id ASC")->fetchAll();
    } catch (Throwable $_) {}
  }
  $channels = array_map(function($c){ return ['id'=>(int)$c['id'], 'name'=>$c['name'], 'description'=>($c['type']==='private'?'Private':'Public')]; }, $chs ?: []);
  
  if (!$selectedChannelId && !empty($channels)) { $selectedChannelId = (int)$channels[0]['id']; }
  $js_channels = json_encode($channels, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);

  
  if ($selectedChannelId) {
    try {
      $st = $pdo->prepare("SELECT m.id, m.channel_id AS channel, m.content, m.created_at AS timestamp, u.first_name, u.last_name, u.username FROM messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.channel_id = ? ORDER BY m.id ASC LIMIT 400");
      $st->execute([$selectedChannelId]);
      $rows = $st->fetchAll();
      $msgs = array_map(function($m){
        $name = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?: ($m['username'] ?? 'User');
        return [
          'id'=>(int)$m['id'], 'channel'=>(int)$m['channel'], 'content'=>$m['content'], 'timestamp'=>$m['timestamp'], 'type'=>'message', 'sender'=>$name
        ];
      }, $rows ?: []);
    } catch (Throwable $_) { $msgs = []; }
  }
  $js_messages = json_encode($msgs, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);

  
  $userId = $_SESSION['user_id'] ?? null;
  $notifs = [];
  if ($userId) {
    try {
      $st = $pdo->prepare("SELECT id, title, body AS description, created_at AS timestamp, is_read AS read FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 50");
      $st->execute([$userId]);
      $notifs = $st->fetchAll();
    } catch (Throwable $_) { $notifs = []; }
  }
  $js_notifications = json_encode($notifs ?: [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
} catch (Throwable $e) {
  if (session_status() === PHP_SESSION_NONE) { session_start(); }
  $_SESSION['flash'] = $_SESSION['flash'] ?? ['type' => 'danger', 'message' => 'Failed to load collaboration data.'];
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'presence') {
  header('Content-Type: application/json');
  $counts = ['online'=>0,'away'=>0,'busy'=>0,'offline'=>0];
  $members = [];
  try {
    $pdo = getDBConnection();
    try {
      $rows = $pdo->query("SELECT id, presence FROM users ORDER BY id ASC LIMIT 500")->fetchAll();
    } catch (Throwable $e) {
      try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `presence` ENUM('online','away','busy','offline') NULL DEFAULT 'offline'");
        $rows = $pdo->query("SELECT id, presence FROM users ORDER BY id ASC LIMIT 500")->fetchAll();
      } catch (Throwable $_) { $rows = []; }
    }
    foreach (($rows ?: []) as $u) {
      $st = strtolower($u['presence'] ?? 'offline');
      if ($st === 'online') { $counts['online']++; }
      elseif ($st === 'away') { $counts['away']++; }
      elseif ($st === 'busy') { $counts['busy']++; }
      else { $counts['offline']++; }
      $members[] = ['id'=>(int)$u['id'], 'status'=>$st];
    }
  } catch (Throwable $_) {}
  echo json_encode(['counts'=>$counts,'members'=>$members]);
  exit;
}

?>
<?php 
  
  $page_title = 'Team Collaboration';
  $current_page = basename(__FILE__);
  include 'includes/header.php'; 
?>


<style>
  body { background:#f8fafc; color:#0f172a; padding:18px; overflow-y: auto !important; }
  .muted { color:#6b7280; }
  .card-hover { transition: all .15s ease; }
  .card-hover:hover { transform: translateY(-6px); box-shadow: 0 10px 30px rgba(15,23,42,0.06); }
  .avatar { width:38px;height:38px;border-radius:8px;background:#eef2ff;display:flex;align-items:center;justify-content:center;font-weight:700;color:#0f172a; }
  .status-dot { width:10px;height:10px;border-radius:999px; display:inline-block; margin-right:6px; }
  .status-online { background:#16a34a; }
  .status-away { background:#f59e0b; }
  .status-busy { background:#ef4444; }
  .status-offline { background:#9ca3af; }
  .scroll-area { max-height:360px; overflow:auto; }
  .message-bubble { background:#ffffff; border-radius:8px; padding:10px; }
  .message-meta { font-size:0.8rem; color:#6b7280; }
  .small-muted { color:#6b7280; font-size:.85rem; }
  .pill { padding:4px 8px; border-radius:999px; font-size:.75rem; }
  .modal-header-accent { background: linear-gradient(135deg, #8ea1e2 0%, #6b7ed6 100%); color:#ffffff; }
  .btn-gradient { background: linear-gradient(135deg, #8e2de2 0%, #5b2aa7 100%); color:#ffffff; border:none; }
  .btn-gradient:hover { filter: brightness(1.03); }
  .input-soft { background:#f8fafc; }
</style>

<div class="container-fluid">
  
  <div class="d-flex flex-column flex-md-row justify-content-between align-items-start mb-4">
    <div>
      <h2 class="mb-0">Team Collaboration</h2>
      <div class="muted">Real-time communication and centralized dialogues for IT staff</div>
    </div>
    <div class="mt-3 mt-md-0 d-flex gap-2">
      <button class="btn btn-outline-primary" id="startMeetingBtn"><i class="bi bi-camera-video me-1"></i> Start Meeting</button>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createChannelModal"><i class="bi bi-plus-lg me-1"></i> New Channel</button>
    </div>
  </div>

  <div class="row g-3">
    
    <div class="col-lg-3">
      <div class="card card-hover mb-3">
        <div class="card-body">
          <ul class="nav nav-tabs" id="leftTabs" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active" id="team-tab" data-bs-toggle="tab" data-bs-target="#team" type="button">Team</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" id="channels-tab" data-bs-toggle="tab" data-bs-target="#channels" type="button">Channels</button>
            </li>
          </ul>

          <div class="tab-content mt-3">
            
            <div class="tab-pane fade show active" id="team">
              <div class="mb-3">
                <div class="input-group">
                  <span class="input-group-text"><i class="bi bi-search"></i></span>
                  <input id="teamSearch" type="text" class="form-control form-control-sm" placeholder="Search team or role">
                </div>
              </div>
              <div class="scroll-area" id="teamList">
                <?php if (!empty($teamMembers)): foreach ($teamMembers as $m): ?>
                  <div class="d-flex align-items-center gap-2 mb-2 p-2 rounded" data-user-id="<?= htmlspecialchars($m['id']) ?>">
                    <div class="avatar"><?= htmlspecialchars(mb_strtoupper(substr(($m['avatar'] ?? 'UU'), 0, 2))) ?></div>
                    <div class="flex-grow-1">
                      <div class="fw-medium"><span class="status-dot status-<?= htmlspecialchars($m['status'] ?? 'offline') ?>"></span><?= htmlspecialchars($m['name'] ?? 'User') ?></div>
                      <div class="small-muted"><?= htmlspecialchars($m['role'] ?? 'Staff') ?></div>
                    </div>
                    <div class="text-end small">
                      <div><?= htmlspecialchars($m['department'] ?? '') ?></div>
                      <div class="small-muted"><?= htmlspecialchars($m['email'] ?? '') ?></div>
                    </div>
                  </div>
                <?php endforeach; else: ?>
                  <div class="small-muted">No team members found.</div>
                <?php endif; ?>
              </div>
            </div>

            
            <div class="tab-pane fade" id="channels">
              <div id="channelsList" class="list-group">
                <?php if (!empty($channels)): foreach ($channels as $ch): $active = ((int)$ch['id'] === (int)($selectedChannelId ?? 0)); ?>
                  <a class="list-group-item list-group-item-action<?= $active ? ' active' : '' ?>" href="team_collaboration.php?channel=<?= urlencode($ch['id']) ?>">
                    <div class="d-flex justify-content-between">
                      <div><i class="bi bi-chat-dots me-2"></i><?= htmlspecialchars($ch['name']) ?></div>
                      <small class="text-muted"><?= htmlspecialchars($ch['description']) ?></small>
                    </div>
                  </a>
                <?php endforeach; else: ?>
                  <div class="small-muted">No channels.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      

      
      <div class="card card-hover">
        <div class="card-body">
          <h6>Quick Actions</h6>
          <div class="d-grid gap-2 mt-2">
            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#scheduleMeetingModal"><i class="bi bi-calendar-event me-1"></i> Schedule Meeting</button>
            <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#announcementModal"><i class="bi bi-megaphone me-1"></i> Send Announcement</button>
            <button class="btn btn-outline-success" id="openContactsBtn"><i class="bi bi-people me-1"></i> View Contacts</button>
          </div>
        </div>
      </div>
    </div>

    
    <div class="col-lg-7">
      <div class="card card-hover h-100">
        <div class="card-body d-flex flex-column">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
              <?php
                $selectedCh = null;
                if (isset($channels) && !empty($channels)) {
                  foreach ($channels as $c) { if ((int)$c['id'] === (int)($selectedChannelId ?? 0)) { $selectedCh = $c; break; } }
                }
              ?>
              <h5 id="channelTitle" class="mb-0">#<?= htmlspecialchars($selectedCh['name'] ?? 'channel') ?></h5>
              <div id="channelDesc" class="small-muted"><?= htmlspecialchars($selectedCh['description'] ?? 'Channel discussions') ?></div>
            </div>
            <div class="d-flex gap-2">
              <div class="small-muted" id="unreadCount">0 unread</div>
              <a class="btn btn-sm btn-outline-secondary" href="team_collaboration.php?channel=<?= urlencode($selectedChannelId ?? 0) ?>"><i class="bi bi-arrow-clockwise"></i></a>
              <form method="POST" action="team_collaboration.php" onsubmit="return confirm('Delete this channel?');">
                <input type="hidden" name="action" value="delete_channel">
                <input type="hidden" name="channel_id" value="<?= htmlspecialchars($selectedChannelId ?? 0) ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
              </form>
            </div>
          </div>

          <div class="flex-grow-1 scroll-area mb-3" id="messagesList" style="min-height:300px">
            <?php if (!empty($msgs)): foreach ($msgs as $m): ?>
              <div class="d-flex gap-2 mb-3">
                <div><div class="avatar"><?= htmlspecialchars(mb_strtoupper(substr(($m['sender'] ?? 'U'), 0, 2))) ?></div></div>
                <div class="flex-grow-1">
                  <div class="d-flex justify-content-between">
                    <div class="fw-medium"><?= htmlspecialchars($m['sender'] ?? 'User') ?> <span class="small-muted ms-2 message-meta"><?= fmtTime($m['timestamp']) ?></span></div>
                    <div></div>
                  </div>
                  <div class="message-bubble mt-1"><?= nl2br(htmlspecialchars($m['content'])) ?></div>
                </div>
              </div>
            <?php endforeach; else: ?>
              <div class="small-muted">No messages yet.</div>
            <?php endif; ?>
          </div>

          <div class="d-flex gap-2">
            <form method="POST" action="team_collaboration.php" class="d-flex gap-2 w-100">
              <input type="hidden" name="action" value="send_message">
              <input type="hidden" name="channel_id" value="<?= htmlspecialchars($selectedChannelId ?? 0) ?>">
              <input name="content" id="messageInput" type="text" class="form-control" placeholder="Type a message..." required>
              <button class="btn btn-primary" type="submit"><i class="bi bi-send"></i></button>
            </form>
          </div>
        </div>
      </div>
    </div>

    
    <div class="col-lg-2">
      <div class="card card-hover mb-3">
        <div class="card-body">
          <h6>Channels</h6>
          <div id="channelsSnapshot" class="list-group mb-3">
            <?php if (!empty($channels)): foreach ($channels as $ch): ?>
              <a href="team_collaboration.php?channel=<?= urlencode($ch['id']) ?>" class="list-group-item small"><?= htmlspecialchars($ch['name']) ?></a>
            <?php endforeach; else: ?>
              <div class="small-muted">No channels.</div>
            <?php endif; ?>
          </div>
          <h6>Team Status</h6>
          <div class="mt-2 small-muted">
            <div class="d-flex justify-content-between"><div><span class="status-dot status-online"></span> Online</div><div id="onlineCount"><?= (int)$onlineCount ?></div></div>
            <div class="d-flex justify-content-between"><div><span class="status-dot status-away"></span> Away</div><div id="awayCount"><?= (int)$awayCount ?></div></div>
            <div class="d-flex justify-content-between"><div><span class="status-dot status-busy"></span> Busy</div><div id="busyCount"><?= (int)$busyCount ?></div></div>
            <div class="d-flex justify-content-between"><div><span class="status-dot status-offline"></span> Offline</div><div id="offlineCount"><?= (int)$offlineCount ?></div></div>
          </div>
        </div>
      </div>

      
    </div>
  </div>
</div>




<div class="modal fade" id="createChannelModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">
      <form id="createChannelForm" method="POST" action="team_collaboration.php">
        <div class="modal-header modal-header-accent">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-hash fs-5"></i>
            <h5 class="modal-title mb-0">Create Channel</h5>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" value="create_channel">
          <div class="mb-2">
            <label class="form-label">Channel ID</label>
            <input id="newChannelId" name="slug" class="form-control input-soft" placeholder="e.g., channel-id" maxlength="32" pattern="[a-z0-9-]+" title="Lowercase letters, numbers, and dashes only">
            <div class="small-muted mt-1">You can type a custom ID (lowercase, numbers, dashes)</div>
          </div>
          <div class="mb-2">
            <label class="form-label">Name</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-chat-dots"></i></span>
              <input id="newChannelName" name="name" class="form-control" placeholder="Channel name" required>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label">Description</label>
            <input id="newChannelDesc" class="form-control input-soft" placeholder="Short description" disabled>
          </div>
          <div class="mb-2">
            <label class="form-label">Type</label>
            <div class="btn-group" role="group" aria-label="Channel type">
              <input type="radio" class="btn-check" name="type" id="typePublic" value="public" checked>
              <label class="btn btn-outline-primary" for="typePublic">Public</label>
              <input type="radio" class="btn-check" name="type" id="typePrivate" value="private">
              <label class="btn btn-outline-primary" for="typePrivate">Private</label>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-gradient">Create</button>
        </div>
      </form>
    </div>
  </div>
</div>


<div class="modal fade" id="announcementModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <form id="announcementForm" method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Send Announcement</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" value="send_announcement">
          <div class="mb-2">
            <label class="form-label">Title *</label>
            <input id="annTitle" name="title" class="form-control" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Message *</label>
            <textarea id="annMessage" name="message" class="form-control" rows="5" required></textarea>
          </div>
          <div class="mb-2">
            <label class="form-label">Priority</label>
            <select id="annPriority" class="form-select" disabled>
              <option value="normal">Normal</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label">Recipients</label>
            <div id="annRecipients" class="border rounded p-2" style="max-height:160px; overflow:auto;">
              <?php if (!empty($teamMembers)) { foreach ($teamMembers as $m) { $cid = 'rec-'.$m['id']; ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" value="<?= htmlspecialchars($m['id']) ?>" id="<?= htmlspecialchars($cid) ?>" name="recipients[]">
                  <label class="form-check-label" for="<?= htmlspecialchars($cid) ?>"><?= htmlspecialchars($m['name']) ?> <small class="text-muted">(<?= htmlspecialchars($m['role']) ?>)</small></label>
                </div>
              <?php } } else { echo '<div class="small-muted">No team members found.</div>'; } ?>
            </div>
            <div class="small-muted mt-1" id="annRecipientCount">0 recipients selected</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Send Announcement</button>
        </div>
      </form>
    </div>
  </div>
</div>


<div class="modal fade" id="scheduleMeetingModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <form id="meetingForm" method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Schedule Meeting</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" value="schedule_meeting">
          <div class="mb-2">
            <label class="form-label">Title *</label>
            <input id="meetingTitle" name="title" class="form-control" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Description</label>
            <textarea id="meetingDesc" name="description" class="form-control" rows="3"></textarea>
          </div>
          <div class="row g-2 mb-2">
            <div class="col">
              <label class="form-label">Date *</label>
              <input id="meetingDate" name="date" type="date" class="form-control" min="<?= date('Y-m-d'); ?>" required>
            </div>
            <div class="col">
              <label class="form-label">Time *</label>
              <input id="meetingTime" name="time" type="time" class="form-control" required>
            </div>
            <div class="col">
              <label class="form-label">Duration (minutes)</label>
              <select id="meetingDuration" name="duration" class="form-select">
                <option value="15">15</option>
                <option value="30">30</option>
                <option value="60" selected>60</option>
                <option value="90">90</option>
                <option value="120">120</option>
              </select>
            </div>
          </div>

          <div class="mb-2">
            <label class="form-label">Type</label>
            <select id="meetingType" name="type" class="form-select">
              <option value="virtual" selected>Virtual</option>
              <option value="in-person">In-person</option>
            </select>
          </div>

          <div class="mb-2" id="meetingLocationWrap" style="display:none;">
            <label class="form-label">Location</label>
            <input id="meetingLocation" name="location" class="form-control" placeholder="Conference Room A">
          </div>

          <div class="mb-2">
            <label class="form-label">Attendees (optional)</label>
            <div id="meetingAttendees" class="border rounded p-2" style="max-height:160px; overflow:auto;">
              <?php if (!empty($teamMembers)) { foreach ($teamMembers as $m) { $aid = 'att-'.$m['id']; ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" value="<?= htmlspecialchars($m['id']) ?>" id="<?= htmlspecialchars($aid) ?>" name="attendees[]">
                  <label class="form-check-label" for="<?= htmlspecialchars($aid) ?>"><?= htmlspecialchars($m['name']) ?> <small class="text-muted">(<?= htmlspecialchars($m['role']) ?>)</small></label>
                </div>
              <?php } } else { echo '<div class="small-muted">No team members found.</div>'; } ?>
            </div>
            <div class="small-muted mt-1" id="meetingAttendeeCount">0 attendees selected</div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Schedule</button>
        </div>
      </form>
    </div>
  </div>
</div>


<div class="position-fixed bottom-0 end-0 p-3" style="z-index:2000">
  <div id="toast" class="toast align-items-center text-bg-dark border-0" role="alert" aria-live="polite" aria-atomic="true">
    <div class="d-flex">
      <div id="toastBody" class="toast-body">Saved.</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>



<script>
// Minimal page helpers only; all content is server-rendered.
  document.addEventListener('DOMContentLoaded', function(){
  // Announcements recipients count helper
  const recContainer = document.getElementById('annRecipients');
  if (recContainer) {
    recContainer.addEventListener('change', function(){
      const checked = recContainer.querySelectorAll('input:checked').length;
      const info = document.getElementById('annRecipientCount');
      if (info) info.textContent = `${checked} recipient${checked!==1 ? 's' : ''} selected`;
    });
  }

  // Meeting attendees count helper
  const attContainer = document.getElementById('meetingAttendees');
  if (attContainer) {
    attContainer.addEventListener('change', function(){
      const count = attContainer.querySelectorAll('input:checked').length;
      const el = document.getElementById('meetingAttendeeCount');
      if (el) el.textContent = `${count} attendee${count !== 1 ? 's' : ''} selected`;
    });
  }

  // Team search filter
  const search = document.getElementById('teamSearch');
  const list = document.getElementById('teamList');
  if (search && list) {
    search.addEventListener('input', function(){
      const q = search.value.trim().toLowerCase();
      Array.from(list.children).forEach(function(item){
        const text = item.textContent.toLowerCase();
        item.style.display = !q || text.includes(q) ? '' : 'none';
      });
    });
  }

  // Meeting location toggle
  const meetingType = document.getElementById('meetingType');
  if (meetingType) {
    meetingType.addEventListener('change', function(e){
      const wrap = document.getElementById('meetingLocationWrap');
      if (wrap) wrap.style.display = e.target.value === 'in-person' ? '' : 'none';
    });
  }

  // Quick-open create channel modal from contacts button
  const contactsBtn = document.getElementById('openContactsBtn');
  if (contactsBtn) {
    contactsBtn.addEventListener('click', function(){
      const modalEl = document.getElementById('createChannelModal');
      if (modalEl && typeof bootstrap !== 'undefined') {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
      }
    });
  }
  const startMeetingBtn = document.getElementById('startMeetingBtn');
  if (startMeetingBtn) {
    startMeetingBtn.addEventListener('click', function(){
      window.location.href = 'https://www.youtube.com/watch?v=smLpTbFTt9Q&list=RDsmLpTbFTt9Q&start_radio=1';
    });
  }
  
  
  // Open header notifications dropdown from page button
  
  // Show flash toast if available
  (function(){
    try {
      const flash = <?php
        $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
        echo json_encode($flash ?: null, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
      ?>;
      if (flash && typeof bootstrap !== 'undefined') {
        const toastEl = document.getElementById('toast');
        const toastBody = document.getElementById('toastBody');
        if (toastEl && toastBody) {
          toastBody.textContent = flash.message || 'Done.';
          toastEl.classList.remove('text-bg-dark','text-bg-danger','text-bg-success');
          toastEl.classList.add(flash.type === 'success' ? 'text-bg-success' : 'text-bg-danger');
          const toast = new bootstrap.Toast(toastEl);
          toast.show();
        }
      }
    } catch (_) {}
  })();
  const nameInput = document.getElementById('newChannelName');
  const idInput = document.getElementById('newChannelId');
  const descInput = document.getElementById('newChannelDesc');
  let slugManuallyEdited = false;
  function slugify(s){
    return (s||'').toLowerCase().trim().replace(/[^a-z0-9\s-]/g,'').replace(/\s+/g,'-').replace(/-+/g,'-').slice(0,32);
  }
  function updateSlug(){ if (idInput && nameInput && !slugManuallyEdited) { idInput.value = slugify(nameInput.value); } }
  function updateDesc(){ if (descInput) { const priv = document.getElementById('typePrivate'); descInput.value = (priv && priv.checked) ? 'Private channel (members only)' : 'Public channel (visible to all)'; } }
  if (nameInput) { nameInput.addEventListener('input', updateSlug); }
  if (idInput) { idInput.addEventListener('input', function(){ slugManuallyEdited = true; }); }
  const typePublic = document.getElementById('typePublic');
  const typePrivate = document.getElementById('typePrivate');
  [typePublic, typePrivate].forEach(function(el){ if (el) el.addEventListener('change', updateDesc); });
  const createModal = document.getElementById('createChannelModal');
  if (createModal && typeof bootstrap !== 'undefined') { createModal.addEventListener('shown.bs.modal', function(){ slugManuallyEdited = false; if (nameInput) { nameInput.focus(); } updateSlug(); updateDesc(); }); }
  const onlineEl = document.getElementById('onlineCount');
  const awayEl = document.getElementById('awayCount');
  const busyEl = document.getElementById('busyCount');
  const offlineEl = document.getElementById('offlineCount');
  function refreshPresence(){
    try {
      fetch('team_collaboration.php?ajax=presence', { headers: { 'Accept':'application/json' }, cache: 'no-store' })
        .then(function(res){ return res.ok ? res.json() : null; })
        .then(function(d){
          if (!d) return;
          const c = d.counts || {};
          if (onlineEl) onlineEl.textContent = String(c.online || 0);
          if (awayEl) awayEl.textContent = String(c.away || 0);
          if (busyEl) busyEl.textContent = String(c.busy || 0);
          if (offlineEl) offlineEl.textContent = String(c.offline || 0);
          const list = document.getElementById('teamList');
          if (list && Array.isArray(d.members)) {
            d.members.forEach(function(m){
              const row = list.querySelector('[data-user-id="'+ String(m.id) +'"]');
              if (!row) return;
              const dot = row.querySelector('.status-dot');
              if (!dot) return;
              dot.classList.remove('status-online','status-away','status-busy','status-offline');
              const cls = 'status-' + String(m.status || 'offline');
              dot.classList.add(cls);
            });
          }
        }).catch(function(){});
    } catch (_) {}
  }
  function setPresence(val, opts){
    try {
      const form = new FormData();
      form.append('action','presence_set');
      form.append('value', String(val || 'online'));
      fetch('team_collaboration.php', { method: 'POST', body: form, keepalive: !!(opts && opts.keepalive) })
        .then(function(res){ return res.ok ? res.json() : null; })
        .then(function(d){ if (d && d.ok) { refreshPresence(); } })
        .catch(function(){});
    } catch (_) {}
  }
  setPresence('online');
  refreshPresence();
  setInterval(refreshPresence, 10000);
  document.addEventListener('visibilitychange', function(){ if (document.visibilityState === 'hidden') { setPresence('away'); } else { setPresence('online'); } });
  window.addEventListener('beforeunload', function(){ setPresence('offline', { keepalive: true }); });
});
</script>
<?php include 'includes/footer.php'; ?>
