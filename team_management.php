<?php
require_once 'includes/auth_guard.php';
require_once 'config/database.php';
date_default_timezone_set('Asia/Manila');
$role = strtolower($_SESSION['role_slug'] ?? ($_SESSION['role'] ?? ($_SESSION['login_user']['role'] ?? '')));
if ($role === 'manager') { $role = 'project_manager'; }
$userId = (int)($_SESSION['user_id'] ?? ($_SESSION['login_user']['id'] ?? 0));
if (!in_array($role, ['project_manager','admin'], true)) { http_response_code(403); echo 'Forbidden'; exit; }
$pdo = null;
try { $pdo = getDBConnection(); } catch (Exception $e) { $pdo = null; }
$teamLeads = [];
$activeMembers = [];
if ($pdo) {
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `teams` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `name` VARCHAR(200) NOT NULL,
      `description` TEXT NULL,
      `created_by` INT NULL,
      `color` VARCHAR(20) NULL DEFAULT '#3b82f6',
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `team_members` (
      `team_id` INT NOT NULL,
      `user_id` INT NOT NULL,
      `role` VARCHAR(20) NOT NULL,
      `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`team_id`,`user_id`),
      KEY `idx_team_members_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // Migration: Add color column if missing
    try {
      $cols = $pdo->query("DESCRIBE teams")->fetchAll(PDO::FETCH_COLUMN);
      if (!in_array('color', $cols)) {
        $pdo->exec("ALTER TABLE teams ADD COLUMN color VARCHAR(20) NULL DEFAULT '#3b82f6'");
      }
      if (!in_array('status', $cols)) {
        $pdo->exec("ALTER TABLE teams ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'");
      }
    } catch (Throwable $e) { /* ignore */ }

  } catch (Throwable $e) { /* ignore */ }
  try {
    $qLeads = $pdo->query("SELECT id, CONCAT_WS(' ', first_name, last_name) AS name, email, role, department FROM users WHERE role IN ('project_manager','admin') ORDER BY name ASC");
    $teamLeads = $qLeads ? $qLeads->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!$teamLeads || count($teamLeads) === 0) {
      $qLeadsAll = $pdo->query("SELECT id, CONCAT_WS(' ', first_name, last_name) AS name, email, role, department FROM users ORDER BY name ASC LIMIT 200");
      $teamLeads = $qLeadsAll ? $qLeadsAll->fetchAll(PDO::FETCH_ASSOC) : [];
    }
  } catch (Exception $e) { $teamLeads = []; }
  try {
    $qMembers = $pdo->query("SELECT id, CONCAT_WS(' ', first_name, last_name) AS name, email, role, department FROM users WHERE role IN ('systemdev','project_manager') ORDER BY name ASC");
    $activeMembers = $qMembers ? $qMembers->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!$activeMembers || count($activeMembers) === 0) {
      $qMembersAll = $pdo->query("SELECT id, CONCAT_WS(' ', first_name, last_name) AS name, email, role, department FROM users ORDER BY name ASC LIMIT 500");
      $activeMembers = $qMembersAll ? $qMembersAll->fetchAll(PDO::FETCH_ASSOC) : [];
    }
  } catch (Exception $e) { $activeMembers = []; }
}
if ((!$teamLeads || count($teamLeads) === 0) && isset($_SESSION['login_user'])) {
  $lu = $_SESSION['login_user'];
  $teamLeads = [[
    'id' => $userId,
    'name' => isset($lu['full_name']) ? $lu['full_name'] : (isset($lu['first_name']) || isset($lu['last_name']) ? trim(($lu['first_name'] ?? '') . ' ' . ($lu['last_name'] ?? '')) : 'User'),
    'email' => $lu['email'] ?? '',
    'role' => $lu['role'] ?? 'project_manager',
    'department' => $lu['department'] ?? ''
  ]];
}
$message = null;
if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['delete_team_id'])) {
    $delTeamId = (int)($_POST['delete_team_id'] ?? 0);
    if ($delTeamId > 0) {
      try {
        $pdo->beginTransaction();
        $d1 = $pdo->prepare('DELETE FROM team_members WHERE team_id = ?');
        $d1->execute([$delTeamId]);
        $d2 = $pdo->prepare('DELETE FROM teams WHERE id = ?');
        $d2->execute([$delTeamId]);
        $pdo->commit();
        header('Location: team_management.php?deleted=1');
        exit;
      } catch (Exception $e) {
        if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
        $message = 'Failed to delete team.';
      }
    }
  } elseif (isset($_POST['toggle_status_id'])) {
    $toggleId = (int)($_POST['toggle_status_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? 'active';
    if ($toggleId > 0 && in_array($newStatus, ['active', 'inactive'])) {
      try {
        $pdo->prepare('UPDATE teams SET status = ? WHERE id = ?')->execute([$newStatus, $toggleId]);
        header('Location: team_management.php?updated=1');
        exit;
      } catch (Exception $e) {
        $message = 'Failed to update status.';
      }
    }
  } elseif (isset($_POST['update_team_id'])) {
    $updateTeamId = (int)($_POST['update_team_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $projectManagerId = (int)($_POST['project_manager_id'] ?? $userId);
    $memberIds = isset($_POST['member_ids']) && is_array($_POST['member_ids']) ? array_filter(array_map('intval', $_POST['member_ids'])) : [];
    if ($updateTeamId > 0 && $name !== '') {
      try {
        $pdo->beginTransaction();
        $u = $pdo->prepare('UPDATE teams SET name = ?, description = ?, color = ? WHERE id = ?');
        $u->execute([$name, $description, $color, $updateTeamId]);
        $clr = $color;
        $pdo->prepare('DELETE FROM team_members WHERE team_id = ?')->execute([$updateTeamId]);
        $pdo->prepare('INSERT IGNORE INTO team_members (team_id, user_id, role) VALUES (?, ?, ?)')->execute([$updateTeamId, $projectManagerId, 'lead']);
        if (!empty($memberIds)) {
          $add = $pdo->prepare('INSERT IGNORE INTO team_members (team_id, user_id, role) VALUES (?, ?, ?)');
          foreach ($memberIds as $mid) {
            if ($mid > 0 && $mid !== $projectManagerId) { $add->execute([$updateTeamId, $mid, 'member']); }
          }
        }
        $pdo->commit();
        header('Location: team_management.php?updated=1');
        exit;
      } catch (Exception $e) {
        if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
        $message = 'Failed to update team.';
      }
    } else {
      $message = 'Name is required.';
    }
  } else {
  $name = trim($_POST['name'] ?? '');
  $description = trim($_POST['description'] ?? '');
  $color = trim($_POST['color'] ?? '');
  $projectManagerId = (int)($_POST['project_manager_id'] ?? $userId);
  $memberIds = isset($_POST['member_ids']) && is_array($_POST['member_ids']) ? array_filter(array_map('intval', $_POST['member_ids'])) : [];
  $members_raw = trim($_POST['members'] ?? '');
  if ($name !== '') {
    try {
      $pdo->beginTransaction();
      $st = $pdo->prepare('INSERT INTO teams (name, description, created_by, color) VALUES (?, ?, ?, ?)');
      $st->execute([$name, $description, $userId, $color ?: '#3b82f6']);
      $teamId = (int)$pdo->lastInsertId();
      $st2 = $pdo->prepare('INSERT IGNORE INTO team_members (team_id, user_id, role) VALUES (?, ?, ?)');
      $st2->execute([$teamId, $projectManagerId, 'lead']);
      if (!empty($memberIds)) {
        $add = $pdo->prepare('INSERT IGNORE INTO team_members (team_id, user_id, role) VALUES (?, ?, ?)');
        foreach ($memberIds as $mid) {
          if ($mid > 0 && $mid !== $projectManagerId) { $add->execute([$teamId, $mid, 'member']); }
        }
      } elseif ($members_raw !== '') {
        $emails = preg_split('/[\n,;]+/', $members_raw);
        $lookup = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $add = $pdo->prepare('INSERT IGNORE INTO team_members (team_id, user_id, role) VALUES (?, ?, ?)');
        foreach ($emails as $em) {
          $email = trim($em);
          if ($email === '') { continue; }
          if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { continue; }
          $lookup->execute([$email]);
          $uid = (int)($lookup->fetch()['id'] ?? 0);
          if ($uid > 0 && $uid !== $projectManagerId) { $add->execute([$teamId, $uid, 'member']); }
        }
      }
      $pdo->commit();
      if (!isset($_SESSION['recent_teams']) || !is_array($_SESSION['recent_teams'])) { $_SESSION['recent_teams'] = []; }
      $_SESSION['recent_teams'][] = ['id' => $teamId, 'name' => $name, 'members' => count($memberIds) + 1, 'color' => $color ?: '#3b82f6'];
      if (count($_SESSION['recent_teams']) > 10) { $_SESSION['recent_teams'] = array_slice($_SESSION['recent_teams'], -10); }
      header('Location: team_management.php?created=1');
      exit;
    } catch (Exception $e) {
      if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
      $message = 'Failed to create team.';
    }
  } else {
    $message = 'Name is required.';
  }
  }
}
function initials_from_name($name){$parts=preg_split('/\s+/', (string)$name);$a=strtoupper(substr($parts[0]??'',0,1).substr($parts[1]??'',0,1));return $a?:'U';}
$teamsData = [];
if ($pdo) {
  try {
    $qTeams = $pdo->query('SELECT id, name, description, created_at, color, status FROM teams ORDER BY id DESC');
    $leadStmt = $pdo->prepare("SELECT u.id, CONCAT_WS(' ', u.first_name, u.last_name) AS name, u.email, u.role FROM team_members tm JOIN users u ON u.id = tm.user_id WHERE tm.team_id = ? AND tm.role = 'lead' LIMIT 1");
    $memberStmt = $pdo->prepare("SELECT u.id, CONCAT_WS(' ', u.first_name, u.last_name) AS name, u.email, u.role, u.status FROM team_members tm JOIN users u ON u.id = tm.user_id WHERE tm.team_id = ? AND tm.role <> 'lead' ORDER BY u.last_name, u.first_name");
    $projStmt = $pdo->prepare("SELECT p.id, p.name, ps.`key` AS status, p.priority FROM projects p JOIN project_statuses ps ON ps.id = p.project_status_id WHERE p.team_id = ?");
    
    foreach ($qTeams as $t) {
      $leadStmt->execute([$t['id']]);
      $lead = $leadStmt->fetch(PDO::FETCH_ASSOC);
      $memberStmt->execute([$t['id']]);
      $membersRows = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
      $membersOut = [];
      if ($lead) {
        $membersOut[] = [
          'id' => (string)$lead['id'],
          'name' => (string)$lead['name'],
          'email' => (string)($lead['email'] ?? ''),
          'role' => (string)($lead['role'] ?? 'project_manager'),
          'avatar' => initials_from_name($lead['name']),
          'status' => 'active',
        ];
      }
      foreach ($membersRows as $m) {
        $membersOut[] = [
          'id' => (string)$m['id'],
          'name' => (string)$m['name'],
          'email' => (string)($m['email'] ?? ''),
          'role' => (string)($m['role'] ?? ''),
          'avatar' => initials_from_name($m['name']),
          'status' => (string)($m['status'] ?? 'active'),
        ];
      }
      $projStmt->execute([$t['id']]);
      $projRows = $projStmt->fetchAll(PDO::FETCH_ASSOC);
      $projOut = [];
      foreach ($projRows as $p) {
        $projOut[] = [
          'id' => (string)$p['id'],
          'name' => (string)$p['name'],
          'status' => (string)$p['status'],
          'priority' => (string)$p['priority']
        ];
      }

      $createdRaw = $t['created_at'] ?? ($t['created_on'] ?? null);
      $createdDate = $createdRaw ? date('Y-m-d', strtotime($createdRaw)) : date('Y-m-d');
      $teamsData[] = [
        'id' => (string)$t['id'],
        'name' => (string)$t['name'],
        'description' => (string)($t['description'] ?? ''),
        'teamLead' => $lead ? (string)$lead['name'] : 'Unassigned',
        'members' => $membersOut,
        'projects' => $projOut,
        'createdDate' => $createdDate,
        'status' => $t['status'] ?? 'active',
        'color' => $t['color'] ?? '#3b82f6',
      ];
    }
  } catch (Exception $e) { $teamsData = []; }
}
?>
<?php include 'includes/header.php'; ?>
<div class="container-fluid">
 
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>

    </div>
    <div>
      <button class="btn btn-outline-secondary" id="open-create" type="button" onclick="openCreate()"><i class="fa-solid fa-plus me-2"></i>Create Team</button>
    </div>
  </div>
  <?php if (!$pdo): ?>
    <div class="alert alert-danger">Database connection is unavailable.</div>
  <?php endif; ?>
  <?php if ($message): ?>
    <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <div class="row g-3 mb-4" id="stats"></div>

  <div class="mb-3">
    <div class="input-group">
      <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
      <input class="form-control" id="search" placeholder="Search teams...">
    </div>
  </div>

  <div class="card card-hover">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div class="fw-medium">Teams</div>
      <div class="text-muted small">Manage and view all teams in the organization</div>
    </div>
    <div class="card-body">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>Team Name</th>
            <th>Project Manager</th>
            <th>Members</th>
            <th>Projects</th>
            <th>Created Date</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody id="teams-body"></tbody>
      </table>
    </div>
  </div>

  <div class="modal fade" id="createModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Create New Team</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form class="modal-body p-4" id="createForm" method="POST" autocomplete="off">
          <input type="hidden" name="color" id="colorInput" value="#3b82f6">
          <input type="hidden" name="project_manager_id" id="projectManagerInput" value="<?= (int)$userId ?>">
          <div id="memberIdsWrap"></div>

          <div class="row">
            <div class="col-md-8 mb-3">
              <label class="form-label fw-bold" for="create-team-name">Team Name <span class="text-danger">*</span></label>
              <input class="form-control" id="create-team-name" name="name" placeholder="Enter team name" required>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label fw-bold">Team Color</label>
              <div class="color-grid d-flex gap-2 flex-wrap" id="create-color-grid"></div>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold" for="create-team-description">Description</label>
            <textarea class="form-control" id="create-team-description" name="description" rows="3" placeholder="Enter team description and purpose"></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold">Project Manager <span class="text-danger">*</span></label>
            <div class="list border rounded p-2 bg-light" id="create-leads" style="max-height: 150px; overflow-y: auto;"></div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold">Team Members <span class="text-danger">*</span></label>
            <div class="text-muted small mb-1">Select at least one member</div>
            <div class="list border rounded p-2" id="create-members" style="max-height: 200px; overflow-y: auto;"></div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold" for="create-members-raw">Invite by Email (Optional)</label>
            <div class="text-muted small mb-1">Enter email addresses separated by commas or newlines</div>
            <textarea class="form-control" name="members" id="create-members-raw" rows="2" placeholder="e.g. user@example.com, another@example.com"></textarea>
          </div>
        </form>
        <div class="modal-footer bg-light">
          <button class="btn btn-outline-secondary" id="create-cancel" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" id="create-confirm" type="submit" form="createForm">Create Team</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Edit Team</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form class="modal-body" id="editForm" method="POST" autocomplete="off">
          <input type="hidden" name="update_team_id" id="editTeamIdInput">
          <input type="hidden" name="project_manager_id" id="editProjectManagerInput">
          <input type="hidden" name="color" id="editColorInput" value="#3b82f6">
          <input type="hidden" name="name" id="editNameInput">
          <input type="hidden" name="description" id="editDescInput">
          <div id="editMemberIdsWrap"></div>
          <label class="label" for="edit-team-name">Team Name *</label>
          <input class="form-control" id="edit-team-name" placeholder="Enter team name">
          <label class="label" for="edit-team-description">Description</label>
          <textarea class="form-control" id="edit-team-description" placeholder="Enter team description and purpose"></textarea>
          <div class="label">Team Color</div>
          <div class="color-grid" id="edit-color-grid"></div>
          <div class="label">Project Manager *</div>
          <div class="list" id="edit-leads"></div>
          <div class="label">Team Members * (Select at least one)</div>
          <div class="list" id="edit-members"></div>
        </form>
        <div class="modal-footer">
          <button class="btn btn-outline" id="edit-cancel" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn" id="edit-confirm" type="button">Update Team</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content border-0 shadow-lg">
        <div class="modal-header bg-light border-bottom-0">
          <h5 class="modal-title fw-bold" id="view-title"></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <!-- Team Header Info -->
          <div class="row g-4 mb-4">
            <div class="col-md-6">
              <div class="text-uppercase text-muted small fw-bold mb-1">Project Manager</div>
              <div class="d-flex align-items-center gap-2">
                <div class="avatar avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:32px;height:32px" id="view-lead-avatar"></div>
                <div class="fw-medium" id="view-lead"></div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="text-uppercase text-muted small fw-bold mb-1">Status</div>
              <div id="view-status"></div>
            </div>
            <div class="col-md-3">
              <div class="text-uppercase text-muted small fw-bold mb-1">Created</div>
              <div class="fw-medium" id="view-date"></div>
            </div>
          </div>

          <div class="mb-4">
            <div class="text-uppercase text-muted small fw-bold mb-1">Description</div>
            <p class="text-secondary mb-0" id="view-desc"></p>
          </div>

          <hr class="my-4 opacity-10">

          <!-- Members Section -->
          <div class="mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
              <h6 class="fw-bold mb-0 text-primary">Team Members</h6>
              <span class="badge bg-light text-dark border" id="view-total"></span>
            </div>
            <div id="view-members" class="d-flex flex-column gap-2"></div>
          </div>

          <!-- Projects Section -->
          <div id="view-projects-wrap" style="display:none">
            <div class="d-flex align-items-center justify-content-between mb-3">
              <h6 class="fw-bold mb-0 text-primary">Assigned Projects</h6>
              <span class="badge bg-light text-dark border" id="view-projects-count"></span>
            </div>
            <div id="view-projects" class="d-flex flex-column gap-2"></div>
          </div>
        </div>
        <div class="modal-footer border-top-0 bg-light">
          <button class="btn btn-secondary px-4" id="view-close" type="button" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Assign Project Modal -->
  <div class="modal fade" id="assignProjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Assign New Project</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form class="modal-body" action="project_task.php" method="POST">
          <input type="hidden" name="action" value="create_project">
          <input type="hidden" name="team_id" id="assign-project-team-id">
          <input type="hidden" name="project_manager_id" value="<?= $userId ?>">
          
          <div class="mb-3">
            <label class="form-label">Project Name *</label>
            <input type="text" name="name" class="form-control" required placeholder="Enter project name">
          </div>
          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="3" placeholder="Project description"></textarea>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Due Date *</label>
              <input type="date" name="due_date" class="form-control" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Priority</label>
              <select name="priority" class="form-select">
                <option value="medium">Medium</option>
                <option value="high">High</option>
                <option value="critical">Critical</option>
                <option value="low">Low</option>
              </select>
            </div>
          </div>
          <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Create & Assign</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="toast-wrap" id="toast-wrap"></div>
  <form id="deleteForm" method="POST" style="display:none"><input type="hidden" name="delete_team_id" id="deleteTeamId"></form>
  <form id="statusForm" method="POST" style="display:none">
    <input type="hidden" name="toggle_status_id" id="statusTeamId">
    <input type="hidden" name="new_status" id="statusNewValue">
  </form>
</div>

<script>
var userRole = <?php echo json_encode($role); ?>;
var teamLeads = <?php echo json_encode($teamLeads, JSON_UNESCAPED_UNICODE); ?>;
var activeMembers = <?php echo json_encode($activeMembers, JSON_UNESCAPED_UNICODE); ?>;
var teamColors = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4','#f97316'];
var teams = <?php echo json_encode($teamsData, JSON_UNESCAPED_UNICODE); ?>;
var searchQuery = '';
var selectedTeam = null;
var createState = { name:'', description:'', lead:'', members:[], color:teamColors[0] };
var editState = { id:'', name:'', description:'', lead:'', members:[], color:teamColors[0] };
function toast(msg,type){var w=document.getElementById('toast-wrap');var t=document.createElement('div');t.className='toast'+(type?' '+type:'');t.textContent=msg;w.appendChild(t);setTimeout(function(){w.removeChild(t)},2500)}
function renderStats(){var totalTeams=teams.length;var activeTeams=teams.filter(function(t){return t.status==='active'}).length;var totalMembers=teams.reduce(function(a,t){return a+t.members.length},0);var activeProjects=teams.reduce(function(a,t){return a+t.projects.length},0);var avgSize=teams.length>0?Math.round(totalMembers/teams.length):0;var s=document.getElementById('stats');s.innerHTML='';var cards=[
  {title:'Total Teams',icon:'fa-users',value:totalTeams,sub:activeTeams+' active'},
  {title:'Total Members',icon:'fa-user',value:totalMembers,sub:'Across all teams'},
  {title:'Active Projects',icon:'fa-circle-check',value:activeProjects,sub:'Assigned to teams'},
  {title:'Avg Team Size',icon:'fa-users',value:avgSize,sub:'Members per team'}
];
cards.forEach(function(c){var col=document.createElement('div');col.className='col-sm-6 col-md-3';var card=document.createElement('div');card.className='card card-hover';var header=document.createElement('div');header.className='card-header d-flex justify-content-between align-items-center';var title=document.createElement('div');title.className='small text-muted';title.textContent=c.title;var icon=document.createElement('i');icon.className='fa-solid '+c.icon+' text-muted';header.appendChild(title);header.appendChild(icon);var body=document.createElement('div');body.className='card-body';var value=document.createElement('div');value.style.fontSize='22px';value.textContent=String(c.value);var sub=document.createElement('div');sub.className='text-muted small';sub.textContent=c.sub;body.appendChild(value);body.appendChild(sub);card.appendChild(header);card.appendChild(body);col.appendChild(card);s.appendChild(col)});}
function filtered(){if(!searchQuery)return teams;var q=searchQuery.toLowerCase();return teams.filter(function(team){return team.name.toLowerCase().includes(q)||team.description.toLowerCase().includes(q)||team.teamLead.toLowerCase().includes(q)})}
function renderTable(){var body=document.getElementById('teams-body');body.innerHTML='';var list=filtered();if(list.length===0){var tr=document.createElement('tr');tr.className='tr';var td=document.createElement('td');td.className='td';td.colSpan=7;td.style.textAlign='center';td.innerHTML='<span class="muted">No teams found</span>';tr.appendChild(td);body.appendChild(tr);return}
list.forEach(function(team){var tr=document.createElement('tr');tr.className='tr';var nameTd=document.createElement('td');nameTd.className='td';var desc=team.description?('<div class="muted" style="font-size:11px">'+team.description+'</div>'):'';nameTd.innerHTML='<div style="display:flex;align-items:center;gap:8px"><div class="color-dot" style="background:'+team.color+'"></div><div><div>'+team.name+'</div>'+desc+'</div></div>';
var leadTd=document.createElement('td');leadTd.className='td';leadTd.textContent=team.teamLead;
  var memTd=document.createElement('td');memTd.className='td';var memWrap=document.createElement('div');memWrap.style.display='flex';memWrap.style.alignItems='center';memWrap.style.gap='6px';var avWrap=document.createElement('div');avWrap.style.display='flex';avWrap.style.alignItems='center';avWrap.style.gap='0';var countShown=0;team.members.slice(0,3).forEach(function(m){var av=document.createElement('div');av.className='avatar';av.textContent=m.avatar;av.style.width='32px';av.style.height='32px';av.style.border='2px solid #fff';av.style.boxShadow='0 0 0 1px #e5e7eb';av.style.borderRadius='9999px';av.style.display='flex';av.style.alignItems='center';av.style.justifyContent='center';av.style.fontSize='12px';av.style.marginLeft=countShown>0?'-8px':'0';avWrap.appendChild(av);countShown++});memWrap.appendChild(avWrap);if(team.members.length>3){var more=document.createElement('span');more.className='muted';more.style.marginLeft='6px';more.textContent='+'+(team.members.length-3)+' more';memWrap.appendChild(more)}memTd.appendChild(memWrap);
var projTd=document.createElement('td');projTd.className='td';projTd.textContent=team.projects.length;
var dateTd=document.createElement('td');dateTd.className='td';dateTd.textContent=(new Date(team.createdDate)).toLocaleDateString();
var statusTd=document.createElement('td');
statusTd.className='td';
var b=document.createElement('span');
b.className='badge '+(team.status==='active'?'bg-success text-white':'bg-secondary text-white');
b.textContent=team.status;
statusTd.appendChild(b);

var actTd=document.createElement('td');actTd.className='td';actTd.style.textAlign='right';var dd=document.createElement('div');dd.className='dropdown';var trigger=document.createElement('button');trigger.className='btn btn-outline-secondary dropdown-toggle';trigger.type='button';trigger.setAttribute('data-bs-toggle','dropdown');trigger.setAttribute('aria-expanded','false');trigger.style.padding='4px 8px';trigger.innerHTML='<i class="fa-solid fa-ellipsis-vertical"></i>';var menu=document.createElement('ul');menu.className='dropdown-menu dropdown-menu-end';var liView=document.createElement('li');var viewBtn=document.createElement('button');viewBtn.type='button';viewBtn.className='dropdown-item';viewBtn.innerHTML='<i class="fa-regular fa-eye me-2"></i>View Details';viewBtn.onclick=function(){openView(team);var el=document.getElementById('viewModal');if(window.bootstrap&&el){window.bootstrap.Modal.getOrCreateInstance(el).show()}};liView.appendChild(viewBtn);menu.appendChild(liView);

var liAssign=document.createElement('li');var assignBtn=document.createElement('button');assignBtn.type='button';assignBtn.className='dropdown-item';assignBtn.innerHTML='<i class="fa-solid fa-list-check me-2"></i>Assign Project';assignBtn.onclick=function(){openAssignProject(team)};liAssign.appendChild(assignBtn);menu.appendChild(liAssign);

var liEmail=document.createElement('li');var emailBtn=document.createElement('button');emailBtn.type='button';emailBtn.className='dropdown-item';emailBtn.innerHTML='<i class="fa-solid fa-envelope me-2"></i>Email Team';emailBtn.onclick=function(){emailTeam(team)};liEmail.appendChild(emailBtn);menu.appendChild(liEmail);

var liExport=document.createElement('li');var exportBtn=document.createElement('button');exportBtn.type='button';exportBtn.className='dropdown-item';exportBtn.innerHTML='<i class="fa-solid fa-file-export me-2"></i>Export Members';exportBtn.onclick=function(){exportMembers(team)};liExport.appendChild(exportBtn);menu.appendChild(liExport);
  
  var liEdit=document.createElement('li');var editBtn=document.createElement('button');editBtn.type='button';editBtn.className='dropdown-item';editBtn.innerHTML='<i class="fa-solid fa-pen me-2"></i>Edit Team';editBtn.onclick=function(){openEdit(team)};liEdit.appendChild(editBtn);menu.appendChild(liEdit);
  
  var liStatus=document.createElement('li');var statusBtn=document.createElement('button');statusBtn.type='button';statusBtn.className='dropdown-item';
  var isAct = team.status==='active';
  statusBtn.innerHTML='<i class="fa-solid '+(isAct?'fa-ban':'fa-check')+' me-2"></i>'+(isAct?'Deactivate Team':'Activate Team');
  statusBtn.onclick=function(){toggleStatus(team)};
  liStatus.appendChild(statusBtn);
  menu.appendChild(liStatus);

  var liDel=document.createElement('li');var delBtn=document.createElement('button');delBtn.type='button';delBtn.className='dropdown-item';delBtn.style.color='#dc3545';delBtn.innerHTML='<i class="fa-solid fa-trash me-2"></i>Delete Team';delBtn.onclick=function(){handleDeleteTeam(team.id)};liDel.appendChild(delBtn);menu.appendChild(liDel);
 
   dd.appendChild(trigger);dd.appendChild(menu);actTd.appendChild(dd);
tr.appendChild(nameTd);tr.appendChild(leadTd);tr.appendChild(memTd);tr.appendChild(projTd);tr.appendChild(dateTd);tr.appendChild(statusTd);tr.appendChild(actTd);body.appendChild(tr)});
}
function populateColors(targetId,state){var grid=document.getElementById(targetId);grid.innerHTML='';teamColors.forEach(function(color){var c=document.createElement('button');c.className='color-choice'+(state.color===color?' active':'');c.style.background=color;c.style.width='24px';c.style.height='24px';c.style.borderRadius='9999px';c.style.border='2px solid '+(state.color===color?'#5b2aa7':'transparent');c.style.cursor='pointer';c.onclick=function(){state.color=color;populateColors(targetId,state)};grid.appendChild(c)})}
function initials(name){var parts=(name||'').trim().split(/\s+/);var a=(parts[0]||'').charAt(0);var b=(parts[1]||'').charAt(0);var v=(a+b).toUpperCase();return v||'U'}
function staffLabel(staff){var wrap=document.createElement('div');wrap.className='row';wrap.setAttribute('data-lead-id', String(staff.id));var radio=document.createElement('input');radio.type='radio';radio.className='radio';var av=document.createElement('div');av.className='avatar';av.textContent=initials(staff.name);var info=document.createElement('div');var name=document.createElement('div');name.textContent=staff.name;var email=document.createElement('div');email.className='email';email.textContent=staff.email||'';info.appendChild(name);info.appendChild(email);var role=document.createElement('span');role.className='badge';role.textContent=String(staff.role||'').replace('_',' ');wrap.appendChild(radio);wrap.appendChild(av);wrap.appendChild(info);wrap.appendChild(role);return {wrap:wrap,control:radio}}
function populateLeads(targetId,state){var list=document.getElementById(targetId);list.innerHTML='';(teamLeads||[]).forEach(function(staff){var item=staffLabel(staff);item.control.name=targetId+'-lead';item.control.checked=state.lead===String(staff.id);item.control.onchange=function(){state.lead=String(staff.id);populateMembers(targetId.replace('leads','members'),state)};list.appendChild(item.wrap)})}
function populateMembers(targetId,state){var list=document.getElementById(targetId);list.innerHTML='';(activeMembers||[]).forEach(function(staff){var wrap=document.createElement('div');wrap.className='row';wrap.setAttribute('data-member-id', String(staff.id));var cb=document.createElement('input');cb.type='checkbox';cb.className='checkbox';cb.checked=state.members.indexOf(String(staff.id))!==-1||String(staff.id)===state.lead;cb.disabled=String(staff.id)===state.lead;cb.onchange=function(){var sid=String(staff.id);var i=state.members.indexOf(sid);if(i!==-1){state.members.splice(i,1)}else{state.members.push(sid)}wrap.classList.toggle('active', cb.checked)};var av=document.createElement('div');av.className='avatar';av.textContent=initials(staff.name);var info=document.createElement('div');var name=document.createElement('div');name.textContent=staff.name;var email=document.createElement('div');email.className='email';email.textContent=staff.email||'';info.appendChild(name);info.appendChild(email);var role=document.createElement('span');role.className='badge';role.textContent=String(staff.role||'').replace('_',' ');wrap.appendChild(cb);wrap.appendChild(av);wrap.appendChild(info);wrap.appendChild(role);wrap.classList.toggle('active', cb.checked);list.appendChild(wrap)})}
function openCreate(){createState={name:'',description:'',lead:String(document.getElementById('projectManagerInput').value||''),members:[],color:teamColors[0]};document.getElementById('create-team-name').value='';document.getElementById('create-team-description').value='';populateColors('create-color-grid',createState);populateLeads('create-leads',createState);populateMembers('create-members',createState);var cm=document.getElementById('create-members');if(cm){cm.style.maxHeight='240px';cm.style.overflowY='auto'}var el=document.getElementById('createModal');if(window.bootstrap&&el){window.bootstrap.Modal.getOrCreateInstance(el).show()}}
function closeCreate(){var el=document.getElementById('createModal');if(window.bootstrap&&el){window.bootstrap.Modal.getOrCreateInstance(el).hide()}}
function updateMemberHiddenInputsFrom(containerId){var memberIdsWrap=document.getElementById('memberIdsWrap');memberIdsWrap.innerHTML='';var ids=[];document.querySelectorAll('#'+containerId+' [data-member-id]').forEach(function(item){var cb=item.querySelector('input[type="checkbox"]');if(cb && cb.checked){ids.push(item.getAttribute('data-member-id'))}});ids.forEach(function(id){var input=document.createElement('input');input.type='hidden';input.name='member_ids[]';input.value=id;memberIdsWrap.appendChild(input)})}
function syncLeadInMembers(){var leadId=document.getElementById('projectManagerInput').value;document.querySelectorAll('#create-members [data-member-id]').forEach(function(item){var id=item.getAttribute('data-member-id');var cb=item.querySelector('input[type="checkbox"]');if(id===String(leadId)){cb.checked=true;cb.disabled=true}else{cb.disabled=false}item.classList.toggle('active', cb.checked)});updateMemberHiddenInputsFrom('create-members')}
function handleCreateTeam(e){if(e){e.preventDefault()}var name=document.getElementById('create-team-name').value.trim();var desc=document.getElementById('create-team-description').value.trim();createState.name=name;createState.description=desc;if(!name){toast('Please enter a team name','error');return}if(!createState.lead && !document.getElementById('projectManagerInput').value){toast('Please select a project manager','error');return}document.getElementById('colorInput').value=createState.color;document.getElementById('projectManagerInput').value=createState.lead||document.getElementById('projectManagerInput').value;updateMemberHiddenInputsFrom('create-members');var checkedCount=0;document.querySelectorAll('#create-members input[type="checkbox"]').forEach(function(cb){if(cb.checked){checkedCount++}});var raw=document.getElementById('create-members-raw')?document.getElementById('create-members-raw').value.trim():'';if(checkedCount<=1 && !raw){toast('Please select at least one team member or provide emails','error');return}document.getElementById('createForm').submit()}
function openEdit(team){editState.id=team.id;editState.name=team.name;editState.description=team.description;var leadMember=(teamLeads||[]).find(function(s){return (s.name||'')===team.teamLead});editState.lead=leadMember?String(leadMember.id):'';editState.members=team.members.map(function(m){return String(m.id)});editState.color=team.color;document.getElementById('edit-team-name').value=editState.name;document.getElementById('edit-team-description').value=editState.description;populateColors('edit-color-grid',editState);populateLeads('edit-leads',editState);populateMembers('edit-members',editState);var em=document.getElementById('edit-members');if(em){em.style.maxHeight='240px';em.style.overflowY='auto'}var el=document.getElementById('editModal');if(window.bootstrap&&el){window.bootstrap.Modal.getOrCreateInstance(el).show()}}
function closeEdit(){var el=document.getElementById('editModal');if(window.bootstrap&&el){window.bootstrap.Modal.getOrCreateInstance(el).hide()}}
function handleEditTeam(){var name=document.getElementById('edit-team-name').value.trim();var desc=document.getElementById('edit-team-description').value.trim();editState.name=name;editState.description=desc;if(!name){toast('Please enter a team name','error');return}if(!editState.lead){toast('Please select a project manager','error');return}if(editState.members.length===0){toast('Please select at least one team member','error');return}var editMemberIdsWrap=document.getElementById('editMemberIdsWrap');editMemberIdsWrap.innerHTML='';editState.members.forEach(function(id){var input=document.createElement('input');input.type='hidden';input.name='member_ids[]';input.value=id;editMemberIdsWrap.appendChild(input)});document.getElementById('editColorInput').value=editState.color;document.getElementById('editProjectManagerInput').value=editState.lead;document.getElementById('editTeamIdInput').value=editState.id;document.getElementById('editNameInput').value=name;document.getElementById('editDescInput').value=desc;document.getElementById('editForm').submit()}
function handleDeleteTeam(id){if(!confirm('Are you sure you want to delete this team?')){return}document.getElementById('deleteTeamId').value=id;document.getElementById('deleteForm').submit()}
function openView(team){
  selectedTeam=team;
  document.getElementById('view-title').innerHTML='<span class="d-flex align-items-center gap-2"><span class="rounded-circle" style="width:12px;height:12px;background:'+team.color+'"></span><span>'+team.name+'</span></span>';
  document.getElementById('view-desc').textContent=team.description||'No description provided.';
  var ld=document.getElementById('view-lead');
  if(team.teamLead && team.teamLead!=='Unassigned'){
    ld.textContent=team.teamLead;
    document.getElementById('view-lead-avatar').textContent=initials(team.teamLead);
  }else{
    ld.textContent='Unassigned';
    document.getElementById('view-lead-avatar').textContent='U';
  }
  
  var st=document.createElement('span');
  st.className='badge '+(team.status==='active'?'bg-success text-white':'bg-secondary text-white');
  st.textContent=team.status;
  var vs=document.getElementById('view-status');vs.innerHTML='';vs.appendChild(st);
  
  document.getElementById('view-date').textContent=(new Date(team.createdDate)).toLocaleDateString();
  document.getElementById('view-total').textContent=team.members.length + ' Members';
  
  var vm=document.getElementById('view-members');vm.innerHTML='';
  team.members.forEach(function(m){
    var row=document.createElement('div');
    row.className='d-flex align-items-center gap-3 p-2 border rounded bg-white hover-shadow-sm transition-all';
    
    var av=document.createElement('div');
    av.className='avatar bg-light text-primary rounded-circle d-flex align-items-center justify-content-center fw-bold border';
    av.style.width='36px';av.style.height='36px';av.style.fontSize='12px';
    av.textContent=m.avatar;
    
    var info=document.createElement('div');
    info.className='flex-grow-1';
    var name=document.createElement('div');
    name.innerHTML='<a href="profile.php?target_id='+m.id+'" class="text-decoration-none text-dark fw-semibold stretched-link">'+m.name+'</a>';
    var email=document.createElement('div');
    email.className='text-muted small';
    email.textContent=m.email;
    info.appendChild(name);info.appendChild(email);
    
    var role=document.createElement('span');
    role.className='badge bg-light text-secondary border';
    role.textContent=m.role.replace('_',' ');
    
    var st2=document.createElement('span');
    st2.className='badge '+(m.status==='active'?'bg-success text-white':'bg-secondary text-white') + ' rounded-pill ms-2';
    st2.style.width='8px';st2.style.height='8px';st2.style.padding='0';
    st2.title=m.status;
    
    row.appendChild(av);row.appendChild(info);row.appendChild(role);row.appendChild(st2);
    vm.appendChild(row);
  });
  
  var pw=document.getElementById('view-projects-wrap');
  var pc=document.getElementById('view-projects-count');
  var pv=document.getElementById('view-projects');
  
  if (team.projects && team.projects.length > 0) {
    pw.style.display='block';
    pc.textContent = team.projects.length + ' Projects';
    pv.innerHTML = '';
    team.projects.forEach(function(p){
      var row = document.createElement('div');
      row.className = 'd-flex align-items-center justify-content-between p-3 border rounded bg-white hover-shadow-sm transition-all';
      
      var info = document.createElement('div');
      var name = document.createElement('div');
      name.innerHTML = '<a href="project_task.php?project_id='+p.id+'" class="text-decoration-none text-primary fw-semibold stretched-link"><i class="fa-solid fa-folder-open me-2"></i>'+p.name+'</a>';
      var meta = document.createElement('div');
      meta.className = 'text-muted small mt-1';
      
      // Priority Badge
      var prioBadge = '';
      var pPrio = (p.priority||'medium').toLowerCase();
      var pColor = pPrio==='critical'?'danger':(pPrio==='high'?'warning text-dark':'info text-dark');
      prioBadge = '<span class="badge bg-'+pColor+' me-2">'+(p.priority||'Medium')+'</span>';
      
      meta.innerHTML = prioBadge + 'Status: <span class="badge bg-secondary text-white">' + (p.status||'Active') + '</span>';
      info.appendChild(name);
      info.appendChild(meta);
      
      var action = document.createElement('div');
      action.innerHTML = '<i class="fa-solid fa-chevron-right text-muted"></i>';
      
      row.appendChild(info);
      row.appendChild(action);
      pv.appendChild(row);
    });
  } else {
    pw.style.display='none';
    pv.innerHTML = '';
  }
  
  var el=document.getElementById('viewModal');
  if(window.bootstrap&&el){window.bootstrap.Modal.getOrCreateInstance(el).show()}
}
function closeView(){var el=document.getElementById('viewModal');if(window.bootstrap&&el){window.bootstrap.Modal.getOrCreateInstance(el).hide()}}
document.getElementById('search').addEventListener('input',function(e){searchQuery=e.target.value;renderTable()});
document.getElementById('create-cancel').addEventListener('click',closeCreate);
var cc2=document.getElementById('create-cancel2');if(cc2){cc2.addEventListener('click',closeCreate)}
document.getElementById('create-confirm').addEventListener('click',function(e){handleCreateTeam(e)});
document.getElementById('edit-cancel').addEventListener('click',closeEdit);
var ec2=document.getElementById('edit-cancel2');if(ec2){ec2.addEventListener('click',closeEdit)}
document.getElementById('edit-confirm').addEventListener('click',handleEditTeam);
document.getElementById('view-close').addEventListener('click',closeView);
var vc2=document.getElementById('view-close2');if(vc2){vc2.addEventListener('click',closeView)}
function openAssignProject(team){
  document.getElementById('assign-project-team-id').value = team.id;
  var el = document.getElementById('assignProjectModal');
  if (window.bootstrap && el) {
    window.bootstrap.Modal.getOrCreateInstance(el).show();
  }
}
function emailTeam(team){
   var emails = [];
   if (team.members) {
       emails = team.members.map(function(m){return m.email}).filter(function(e){return e});
   }
   if (emails.length > 0) {
     window.location.href = 'mailto:' + emails.join(',');
   } else {
     toast('No members with email addresses found.', 'error');
   }
 }
 function exportMembers(team) {
   if (!team.members || team.members.length === 0) {
     toast('No members to export.', 'error');
     return;
   }
   var csv = 'Name,Email,Role,Status\n';
   team.members.forEach(function(m) {
     csv += '"' + (m.name || '') + '","' + (m.email || '') + '","' + (m.role || '') + '","' + (m.status || '') + '"\n';
   });
   var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
   var link = document.createElement('a');
   if (link.download !== undefined) {
     var url = URL.createObjectURL(blob);
     link.setAttribute('href', url);
     link.setAttribute('download', 'team_members_' + team.id + '.csv');
     link.style.visibility = 'hidden';
     document.body.appendChild(link);
     link.click();
     document.body.removeChild(link);
   }
 }
 function toggleStatus(team) {
   if (confirm('Are you sure you want to ' + (team.status === 'active' ? 'deactivate' : 'activate') + ' this team?')) {
     document.getElementById('statusTeamId').value = team.id;
     document.getElementById('statusNewValue').value = (team.status === 'active' ? 'inactive' : 'active');
     document.getElementById('statusForm').submit();
   }
 }
renderStats();renderTable();
var createdFlag = <?php echo json_encode(isset($_GET['created'])); ?>;
var updatedFlag = <?php echo json_encode(isset($_GET['updated'])); ?>;
var deletedFlag = <?php echo json_encode(isset($_GET['deleted'])); ?>;
if (createdFlag) { toast('Team created successfully','success'); }
if (updatedFlag) { toast('Team updated successfully','success'); }
if (deletedFlag) { toast('Team deleted','success'); }
var teamsBody=document.getElementById('teams-body');if(teamsBody){teamsBody.addEventListener('click',function(e){var btn=e.target.closest('.dropdown-menu button');if(!btn)return;var tr=e.target.closest('tr');if(!tr)return;var rows=Array.prototype.slice.call(teamsBody.children);var idx=rows.indexOf(tr);var list=filtered();var team=list[idx];var text=(btn.textContent||'').trim().toLowerCase();if(text.indexOf('view')===0){openView(team);var el=document.getElementById('viewModal');if(window.bootstrap&&el){window.bootstrap.Modal.getOrCreateInstance(el).show()}}else if(text.indexOf('edit')===0){openEdit(team)}else if(text.indexOf('delete')===0){handleDeleteTeam(team.id)}})}
document.addEventListener('click',function(e){var openDropdowns=document.querySelectorAll('.dropdown[open]');if(openDropdowns.length){var inside=false;openDropdowns.forEach(function(d){if(d.contains(e.target)){inside=true}});if(!inside){openDropdowns.forEach(function(d){d.removeAttribute('open')})}}});
document.addEventListener('keydown',function(e){if(e.key==='Escape'){/* handled by bootstrap modal */}});
document.getElementById('open-create').addEventListener('click',openCreate);
var createFormEl=document.getElementById('createForm');if(createFormEl){createFormEl.addEventListener('submit',function(e){handleCreateTeam(e)})}
</script>
<?php include 'includes/footer.php'; ?>