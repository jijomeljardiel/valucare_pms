<?php
require_once 'includes/auth_guard.php';
require_once 'config/database.php';
date_default_timezone_set('Asia/Manila');

$role = strtolower($_SESSION['role'] ?? ($_SESSION['login_user']['role'] ?? ''));
$userId = (int)($_SESSION['user_id'] ?? ($_SESSION['login_user']['id'] ?? 0));


if (!in_array($role, ['manager', 'admin', 'project_manager'], true)) { 
    http_response_code(403); 
    echo 'Forbidden: You do not have access to Team Management.'; 
    exit; 
}

$pdo = null;
try { $pdo = getDBConnection(); } catch (Exception $e) { $pdo = null; }

$teamLeads = [];
$activeMembers = [];

if ($pdo) {
  try {
    // Siguraduhin na ang tables ay nag-eexist
    $pdo->exec("CREATE TABLE IF NOT EXISTS `teams` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `name` VARCHAR(200) NOT NULL,
      `description` TEXT NULL,
      `created_by` INT NULL,
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
  } catch (Throwable $e) { /* ignore */ }

  try {
    // Kunin ang mga pwedeng maging Team Lead (Admin at Manager)
    $qLeads = $pdo->query("SELECT id, CONCAT_WS(' ', first_name, last_name) AS name, email, role, department FROM users WHERE role IN ('manager', 'admin', 'project_manager') ORDER BY name ASC");
    $teamLeads = $qLeads ? $qLeads->fetchAll(PDO::FETCH_ASSOC) : [];
  } catch (Exception $e) { $teamLeads = []; }

  try {
    // Kunin ang mga active members
    $qMembers = $pdo->query("SELECT id, CONCAT_WS(' ', first_name, last_name) AS name, email, role, department FROM users WHERE role IN ('systemdev','manager', 'project_manager') ORDER BY name ASC");
    $activeMembers = $qMembers ? $qMembers->fetchAll(PDO::FETCH_ASSOC) : [];
  } catch (Exception $e) { $activeMembers = []; }
}

// Fallback kung walang mahanap na lead
if ((!$teamLeads || count($teamLeads) === 0) && isset($_SESSION['login_user'])) {
  $lu = $_SESSION['login_user'];
  $teamLeads = [[
    'id' => $userId,
    'name' => trim(($lu['first_name'] ?? '') . ' ' . ($lu['last_name'] ?? '')),
    'email' => $lu['email'] ?? '',
    'role' => $role,
    'department' => $lu['department'] ?? ''
  ]];
}

$message = null;
if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['delete_team_id'])) {
    $delTeamId = (int)$_POST['delete_team_id'];
    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM team_members WHERE team_id = ?')->execute([$delTeamId]);
        $pdo->prepare('DELETE FROM teams WHERE id = ?')->execute([$delTeamId]);
        $pdo->commit();
        header('Location: team_management.php?deleted=1'); exit;
    } catch (Exception $e) { if($pdo->inTransaction()) $pdo->rollBack(); }

  } elseif (isset($_POST['update_team_id'])) {
    $updateTeamId = (int)$_POST['update_team_id'];
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $projectManagerId = (int)($_POST['project_manager_id'] ?? $userId);
    $memberIds = isset($_POST['member_ids']) ? array_map('intval', $_POST['member_ids']) : [];

    if ($name !== '') {
      try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE teams SET name = ?, description = ? WHERE id = ?')->execute([$name, $description, $updateTeamId]);
        $pdo->prepare('DELETE FROM team_members WHERE team_id = ?')->execute([$updateTeamId]);
        $pdo->prepare('INSERT INTO team_members (team_id, user_id, role) VALUES (?, ?, ?)')->execute([$updateTeamId, $projectManagerId, 'lead']);
        foreach ($memberIds as $mid) {
          if ($mid !== $projectManagerId) {
            $pdo->prepare('INSERT IGNORE INTO team_members (team_id, user_id, role) VALUES (?, ?, ?)')->execute([$updateTeamId, $mid, 'member']);
          }
        }
        $pdo->commit();
        header('Location: team_management.php?updated=1'); exit;
      } catch (Exception $e) { if($pdo->inTransaction()) $pdo->rollBack(); }
    }
  } elseif (isset($_POST['name'])) {
    // CREATE TEAM
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $projectManagerId = (int)($_POST['project_manager_id'] ?? $userId);
    $memberIds = isset($_POST['member_ids']) ? array_map('intval', $_POST['member_ids']) : [];

    if ($name !== '') {
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('INSERT INTO teams (name, description, created_by) VALUES (?, ?, ?)');
            $st->execute([$name, $description, $userId]);
            $teamId = $pdo->lastInsertId();
            
            $pdo->prepare('INSERT INTO team_members (team_id, user_id, role) VALUES (?, ?, ?)')->execute([$teamId, $projectManagerId, 'lead']);
            foreach ($memberIds as $mid) {
                if ($mid !== $projectManagerId) {
                    $pdo->prepare('INSERT IGNORE INTO team_members (team_id, user_id, role) VALUES (?, ?, ?)')->execute([$teamId, $mid, 'member']);
                }
            }
            $pdo->commit();
            header('Location: team_management.php?created=1'); exit;
        } catch (Exception $e) { if($pdo->inTransaction()) $pdo->rollBack(); }
    }
  }
}

function initials_from_name($name){
    $parts=explode(' ', (string)$name);
    $i = strtoupper(substr($parts[0]??'U',0,1));
    if(isset($parts[1])) $i .= strtoupper(substr($parts[1],0,1));
    return $i;
}

$teamsData = [];
if ($pdo) {
    $qTeams = $pdo->query('SELECT * FROM teams ORDER BY id DESC');
    while ($t = $qTeams->fetch()) {
        $leadStmt = $pdo->prepare("SELECT u.id, CONCAT_WS(' ', u.first_name, u.last_name) AS name, u.email FROM team_members tm JOIN users u ON u.id = tm.user_id WHERE tm.team_id = ? AND tm.role = 'lead'");
        $leadStmt->execute([$t['id']]);
        $lead = $leadStmt->fetch();

        $memStmt = $pdo->prepare("SELECT u.id, CONCAT_WS(' ', u.first_name, u.last_name) AS name, u.email, u.role FROM team_members tm JOIN users u ON u.id = tm.user_id WHERE tm.team_id = ? AND tm.role != 'lead'");
        $memStmt->execute([$t['id']]);
        $mems = $memStmt->fetchAll();

        $membersOut = [];
        if($lead) $membersOut[] = ['id'=>$lead['id'], 'name'=>$lead['name'], 'email'=>$lead['email'], 'role'=>'lead', 'avatar'=>initials_from_name($lead['name']), 'status'=>'active'];
        foreach($mems as $m) $membersOut[] = ['id'=>$m['id'], 'name'=>$m['name'], 'email'=>$m['email'], 'role'=>$m['role'], 'avatar'=>initials_from_name($m['name']), 'status'=>'active'];

        $teamsData[] = [
            'id' => (string)$t['id'],
            'name' => $t['name'],
            'description' => $t['description'],
            'teamLead' => $lead ? $lead['name'] : 'No Lead',
            'members' => $membersOut,
            'projects' => [],
            'createdDate' => date('Y-m-d', strtotime($t['created_at'])),
            'status' => 'active',
            'color' => '#3b82f6'
        ];
    }
}
?>

<?php include 'includes/header.php'; ?>
<style>
    .card-hover { transition: all 0.3s; border-radius: 15px; border: 1px solid #eee; }
    .card-hover:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.05); }
    .avatar { background: var(--primary-grad); color: white; font-weight: bold; }
    .color-dot { width: 12px; height: 12px; border-radius: 50%; }
    .list .row { display: flex; align-items: center; gap: 10px; padding: 10px; border-bottom: 1px solid #f1f5f9; cursor: pointer; }
    .list .row:hover { background: #f8fafc; }
    .list .row.active { background: #eff6ff; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h2 class="fw-bold mb-1">Team Management</h2>
            <div class="text-muted">Create and manage teams for project collaboration</div>
        </div>
        <button class="btn btn-primary shadow-sm px-4" style="border-radius:12px; background:var(--primary-grad); border:none;" onclick="openCreate()">
            <i class="fa-solid fa-plus me-2"></i>Create Team
        </button>
    </div>

    <div class="row g-3 mb-4" id="stats"></div>

    <div class="card card-hover shadow-sm border-0">
        <div class="card-body">
            <div class="input-group mb-4" style="max-width: 400px;">
                <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
                <input class="form-control bg-light border-0" id="search" placeholder="Search teams...">
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Team Name</th>
                            <th>Lead</th>
                            <th>Members</th>
                            <th>Created</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="teams-body"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content border-0 shadow" id="createForm" method="POST" style="border-radius:20px;">
            <div class="modal-header border-0 p-4">
                <h5 class="fw-bold">Create New Team</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 pt-0">
                <div id="memberIdsWrap"></div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Team Name</label>
                    <input class="form-control" name="name" required placeholder="e.g. Web Development Team">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Description</label>
                    <textarea class="form-control" name="description" rows="2"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Select Team Lead</label>
                    <div class="list border rounded" id="create-leads" style="max-height:200px; overflow-y:auto;"></div>
                    <input type="hidden" name="project_manager_id" id="projectManagerInput">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Select Members</label>
                    <div class="list border rounded" id="create-members" style="max-height:200px; overflow-y:auto;"></div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary rounded-pill px-4" style="background:var(--primary-grad); border:none;">Create Team</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content border-0 shadow" id="editForm" method="POST" style="border-radius:20px;">
            <input type="hidden" name="update_team_id" id="editTeamIdInput">
            <input type="hidden" name="project_manager_id" id="editProjectManagerInput">
            <div class="modal-header border-0 p-4">
                <h5 class="fw-bold">Edit Team</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 pt-0">
                <div id="editMemberIdsWrap"></div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Team Name</label>
                    <input class="form-control" name="name" id="edit-team-name" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Description</label>
                    <textarea class="form-control" name="description" id="edit-team-description" rows="2"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Change Lead</label>
                    <div class="list border rounded" id="edit-leads" style="max-height:200px; overflow-y:auto;"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Manage Members</label>
                    <div class="list border rounded" id="edit-members" style="max-height:200px; overflow-y:auto;"></div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary rounded-pill px-4">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<form id="deleteForm" method="POST" style="display:none">
    <input type="hidden" name="delete_team_id" id="deleteTeamId">
</form>

<script>
const teams = <?= json_encode($teamsData) ?>;
const teamLeads = <?= json_encode($teamLeads) ?>;
const activeMembers = <?= json_encode($activeMembers) ?>;
const currentUserId = <?= $userId ?>;

function renderStats() {
    const stats = document.getElementById('stats');
    const cards = [
        {title: 'Total Teams', val: teams.length, icon: 'bi-people'},
        {title: 'Total Personnel', val: activeMembers.length, icon: 'bi-person-badge'},
        {title: 'Active Leads', val: teamLeads.length, icon: 'bi-award'}
    ];
    stats.innerHTML = cards.map(c => `
        <div class="col-md-4">
            <div class="card card-hover border-0 shadow-sm p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-light p-3 rounded-circle text-primary"><i class="bi ${c.icon} fs-4"></i></div>
                    <div><h4 class="fw-bold mb-0">${c.val}</h4><small class="text-muted">${c.title}</small></div>
                </div>
            </div>
        </div>
    `).join('');
}

function renderTable() {
    const body = document.getElementById('teams-body');
    body.innerHTML = teams.map(t => `
        <tr>
            <td>
                <div class="fw-bold text-dark">${t.name}</div>
                <small class="text-muted">${t.description || 'No description'}</small>
            </td>
            <td><span class="badge bg-white text-dark border px-3 rounded-pill">${t.teamLead}</span></td>
            <td>
                <div class="d-flex align-items-center">
                    ${t.members.slice(0,3).map(m => `<div class="avatar rounded-circle d-flex align-items-center justify-content-center me-n2 border border-white" style="width:32px; height:32px; font-size:10px;">${m.avatar}</div>`).join('')}
                    ${t.members.length > 3 ? `<small class="ms-3 text-muted">+${t.members.length - 3} more</small>` : ''}
                </div>
            </td>
            <td>${t.createdDate}</td>
            <td class="text-end">
                <button class="btn btn-sm btn-light rounded-pill px-3" onclick='openEdit(${JSON.stringify(t)})'>Edit</button>
                <button class="btn btn-sm btn-outline-danger border-0 rounded-circle" onclick="deleteTeam(${t.id})"><i class="bi bi-trash"></i></button>
            </td>
        </tr>
    `).join('');
}

function openCreate() {
    populateLeads('create-leads', currentUserId);
    populateMembers('create-members', []);
    new bootstrap.Modal('#createModal').show();
}

function openEdit(team) {
    document.getElementById('editTeamIdInput').value = team.id;
    document.getElementById('edit-team-name').value = team.name;
    document.getElementById('edit-team-description').value = team.description;
    
    // Hanapin ang ID ng kasalukuyang lead base sa pangalan
    const lead = teamLeads.find(l => l.name === team.teamLead);
    const leadId = lead ? lead.id : 0;
    
    populateLeads('edit-leads', leadId);
    populateMembers('edit-members', team.members.map(m => parseInt(m.id)));
    new bootstrap.Modal('#editModal').show();
}

function populateLeads(containerId, selectedId) {
    const cont = document.getElementById(containerId);
    cont.innerHTML = teamLeads.map(l => `
        <div class="row m-0 ${l.id == selectedId ? 'active' : ''}" onclick="selectLead('${containerId}', ${l.id}, this)">
            <input type="radio" name="lead_radio" ${l.id == selectedId ? 'checked' : ''} style="display:none">
            <div class="avatar rounded-circle" style="width:35px; height:35px; display:flex; align-items:center; justify-content:center; font-size:12px;">${initials_from_name(l.name)}</div>
            <div><div class="fw-bold small">${l.name}</div><div style="font-size:10px;">${l.role}</div></div>
        </div>
    `).join('');
    
    // Set hidden input
    const hidden = containerId.includes('create') ? 'projectManagerInput' : 'editProjectManagerInput';
    document.getElementById(hidden).value = selectedId;
}

function selectLead(containerId, id, el) {
    document.querySelectorAll(`#${containerId} .row`).forEach(r => r.classList.remove('active'));
    el.classList.add('active');
    const hidden = containerId.includes('create') ? 'projectManagerInput' : 'editProjectManagerInput';
    document.getElementById(hidden).value = id;
}

function populateMembers(containerId, selectedIds) {
    const cont = document.getElementById(containerId);
    cont.innerHTML = activeMembers.map(m => `
        <div class="row m-0">
            <input type="checkbox" name="member_ids[]" value="${m.id}" ${selectedIds.includes(parseInt(m.id)) ? 'checked' : ''} class="form-check-input me-2">
            <div class="fw-bold small">${m.name}</div>
        </div>
    `).join('');
}

function deleteTeam(id) {
    if(confirm('Delete this team?')) {
        document.getElementById('deleteTeamId').value = id;
        document.getElementById('deleteForm').submit();
    }
}

function initials_from_name(name) {
    return name.split(' ').map(n => n[0]).join('').toUpperCase().substring(0,2);
}

window.onload = () => {
    renderStats();
    renderTable();
};
</script>
<?php include 'includes/footer.php'; ?>