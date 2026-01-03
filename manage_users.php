<?php /* User management: list/add/edit/delete ng users at roles. */
require_once 'includes/auth_guard.php';
require_once 'config/database.php';
require_once 'includes/activity_logger.php';

$page_title = 'Manage Users';
$current_page = basename(__FILE__);

$pdo = null;
try { $pdo = getDBConnection(); } catch (Exception $e) { $pdo = null; }
$role = strtolower($_SESSION['role'] ?? ($_SESSION['login_user']['role'] ?? ''));
if ($role !== 'admin') { http_response_code(403); echo 'Forbidden'; exit; }

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'create_user') {
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $r = trim($_POST['role'] ?? 'admin');
    if (strtolower($r) === 'manager') { $r = 'project_manager'; }
    $allowed_roles = ['admin','project_manager','systemdev'];
    if (!in_array($r, $allowed_roles, true)) { $r = 'admin'; }
    $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    if ($first !== '' && ($email !== '' || $last !== '')) {
      $username = $email !== '' ? explode('@', $email)[0] : (strtolower(preg_replace('/\s+/', '', ($first.$last))) ?: ('user'.time()));
      $password = password_hash('changeme', PASSWORD_DEFAULT);
      try {
        $st = $pdo->prepare('INSERT INTO users (username, email, password, first_name, last_name, role, department, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$username, $email, $password, $first, $last, $r, 'ICT', 'active']);
        $newId = (int)$pdo->lastInsertId();
        log_activity('user_create', 'user', $newId, ['username' => $username], $pdo);
      } catch (Exception $e) {}
    }
    header('Location: manage_users.php'); exit;
  } elseif ($action === 'update_role') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $r = trim($_POST['role'] ?? 'admin');
    if (strtolower($r) === 'manager') { $r = 'project_manager'; }
    $allowed_roles = ['admin','project_manager','systemdev'];
    if (!in_array($r, $allowed_roles, true)) { $r = 'admin'; }
    $s = trim(strtolower($_POST['status'] ?? ''));
    $allowed_statuses = ['active','inactive'];
    if (!in_array($s, $allowed_statuses, true)) { $s = ''; }
    if ($id > 0) {
      try {
        if ($s !== '') { $st = $pdo->prepare('UPDATE users SET role = ?, status = ? WHERE id = ?'); $st->execute([$r, $s, $id]); }
        else { $st = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?'); $st->execute([$r, $id]); }
        log_activity('user_update_role', 'user', $id, ['role' => $r, 'status' => $s ?: null], $pdo);
      } catch (Exception $e) {}
    }
    header('Location: manage_users.php'); exit;
  } elseif ($action === 'update_password') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $new = trim($_POST['new_password'] ?? '');
    $confirm = trim($_POST['confirm_password'] ?? '');
    $ok = false;
    if ($id > 0 && $new !== '' && $new === $confirm && strlen($new) >= 8) {
      try { $hashed = password_hash($new, PASSWORD_DEFAULT); $st = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?'); $st->execute([$hashed, $id]); $ok = true; log_activity('user_update_password', 'user', $id, null, $pdo); } catch (Exception $e) { $ok = false; }
    }
    if (!$ok) { log_activity('user_update_password_failed', 'user', $id, null, $pdo); }
    header('Location: manage_users.php?pwd=' . ($ok ? 'ok' : 'fail')); exit;
  } elseif ($action === 'delete_user') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id > 0) {
      try { $st = $pdo->prepare('DELETE FROM users WHERE id = ?'); $st->execute([$id]); log_activity('user_delete', 'user', $id, null, $pdo); } catch (Exception $e) {}
    }
    header('Location: manage_users.php'); exit;
  }
}

$rows = [];
try { $rows = $pdo ? $pdo->query('SELECT id, first_name, last_name, email, role, status FROM users ORDER BY first_name ASC LIMIT 1000')->fetchAll() : []; } catch (Exception $e) { $rows = []; }
foreach ($rows as &$u) { if (isset($u['role']) && strtolower($u['role']) === 'manager') { $u['role'] = 'project_manager'; } } unset($u);
$js_users = json_encode($rows ?: [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
?>
<?php include 'includes/header.php'; ?>
<style>
  .ua-card { border:1px solid var(--border-color); border-radius: var(--radius-lg); transition: var(--transition-fast); }
  .ua-card:hover { border-color: var(--violet-100); }
  .ua-avatar { width:36px; height:36px; border-radius:8px; background: var(--violet-50); display:flex; align-items:center; justify-content:center; font-weight:700; color: var(--primary-dark); }
  .ua-pill { border:1px solid var(--border-color); background:#fff; color: var(--primary-dark); border-radius: 9999px; padding: 6px 12px; }
  .ua-pill.status-active { background:#e9f9ee; border-color:#3fb950; color:#05603a; }
  .ua-pill.status-inactive { background:#f1f3f5; border-color:#c9cfd6; color:#495057; }
  .ua-tabs { display:inline-flex; gap:8px; }
  .ua-tab { border:1px solid var(--border-color); background:#fff; color: var(--primary-dark); border-radius: 9999px; padding:6px 12px; cursor:pointer; }
  .ua-tab.active { background: var(--violet-50); border-color: var(--violet-200); }
  .ua-actions .btn { border-radius:9999px; }
  .ua-stat-card .h5 { font-weight:600; }
</style>
<div class="container py-4 ua-page">
  <div class="d-flex justify-content-between align-items-start mb-2">
    <div>
      <h2 class="mb-0">Manage Users</h2>
      <p class="text-muted">Admin-only</p>
    </div>
    <button id="btnAddUser" class="btn btn-primary">Add User</button>
  </div>

  <?php if (isset($_GET['pwd'])) { $ok = ($_GET['pwd'] === 'ok'); ?>
    <div class="alert <?= $ok ? 'alert-success' : 'alert-danger' ?>">
      <?= $ok ? 'Password updated successfully.' : 'Failed to update password. Ensure both fields match and are at least 8 characters.' ?>
    </div>
  <?php } ?>

  <div class="row mb-3 g-3 ua-filters">
    <div class="col-md-8">
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input id="searchInput" class="form-control" placeholder="Search by name or email">
      </div>
    </div>
    <div class="col-md-4 d-flex gap-2">
      <select id="roleFilter" class="form-select form-select-sm">
        <option value="all">All Roles</option>
        <option value="admin">Admin</option>
        <option value="project_manager">Project Manager</option>
        <option value="systemdev">SystemDev</option>
      </select>
      <select id="statusFilter" class="form-select form-select-sm">
        <option value="all">All Status</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
      </select>
    </div>
  </div>

  <div class="mb-3 d-flex justify-content-between align-items-center">
    <div class="ua-tabs">
      <button id="viewGrid" class="ua-tab active">Grid</button>
      <button id="viewTable" class="ua-tab">Table</button>
    </div>
    <div id="statsSummary" class="text-muted"></div>
  </div>

  <div id="statsCards" class="row g-3 mb-3 ua-stats"></div>

  <div id="gridContainer" class="row g-3">
    <?php foreach ($rows as $u): $nm = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? '')); $avatar = strtoupper(substr($u['first_name'] ?? 'U',0,1).substr($u['last_name'] ?? 'U',0,1)); ?>
      <div class="col-md-4 col-lg-3">
        <div class="card h-100 ua-card">
          <div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-2">
              <div class="ua-avatar"><?= htmlspecialchars($avatar) ?></div>
              <div>
                <div class="fw-medium"><?= htmlspecialchars($nm ?: 'User') ?></div>
                <div class="small text-muted"><?= htmlspecialchars($u['email'] ?? '') ?></div>
              </div>
            </div>
            <div class="small text-muted"><?= htmlspecialchars($u['role'] ?? '') ?></div>
            <div class="mt-2 d-flex gap-1 ua-actions">
              <span class="ua-pill status-<?= htmlspecialchars(($u['status'] ?? '') ? strtolower($u['status']) : '') ?>"><?= htmlspecialchars($u['status'] ?? '') ?></span>
              <button class="btn btn-sm btn-outline-secondary ms-auto" data-action="edit-role" data-id="<?= (int)$u['id'] ?>" data-role="<?= htmlspecialchars($u['role'] ?? '') ?>" data-status="<?= htmlspecialchars(($u['status'] ?? '') ? strtolower($u['status']) : '') ?>" title="Edit Role" data-bs-toggle="tooltip"><i class="bi bi-pencil"></i></button>
              <button class="btn btn-sm btn-outline-secondary" data-action="change-password" data-id="<?= (int)$u['id'] ?>" title="Change Password" data-bs-toggle="tooltip"><i class="bi bi-key"></i></button>
              <button class="btn btn-sm btn-outline-danger" data-action="delete" data-id="<?= (int)$u['id'] ?>" title="Delete User" data-bs-toggle="tooltip"><i class="bi bi-trash"></i></button>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div id="tableContainer" class="table-responsive d-none ua-table">
    <table class="table table-striped mb-0">
      <thead><tr><th>User</th><th>Role</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody id="tableBody">
        <?php foreach ($rows as $u): $nm = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? '')); ?>
          <tr>
            <td><?= htmlspecialchars($nm ?: 'User') ?><br><small class="text-muted"><?= htmlspecialchars($u['email'] ?? '') ?></small></td>
            <td><?= htmlspecialchars($u['role'] ?? '') ?></td>
            <td><span class="ua-pill status-<?= htmlspecialchars(($u['status'] ?? '') ? strtolower($u['status']) : '') ?>"><?= htmlspecialchars($u['status'] ?? '') ?></span></td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-secondary" data-action="edit-role" data-id="<?= (int)$u['id'] ?>" data-role="<?= htmlspecialchars($u['role'] ?? '') ?>" data-status="<?= htmlspecialchars(($u['status'] ?? '') ? strtolower($u['status']) : '') ?>" title="Edit Role" data-bs-toggle="tooltip"><i class="bi bi-pencil"></i></button>
              <button class="btn btn-sm btn-outline-secondary" data-action="change-password" data-id="<?= (int)$u['id'] ?>" title="Change Password" data-bs-toggle="tooltip"><i class="bi bi-key"></i></button>
              <button class="btn btn-sm btn-outline-danger" data-action="delete" data-id="<?= (int)$u['id'] ?>" title="Delete User" data-bs-toggle="tooltip"><i class="bi bi-trash"></i></button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="addUserModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="POST">
  <div class="modal-header"><h5 class="modal-title">Add User</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <input type="hidden" name="action" value="create_user">
    <div class="mb-2"><label class="form-label">First Name</label><input name="first_name" class="form-control" required></div>
    <div class="mb-2"><label class="form-label">Last Name</label><input name="last_name" class="form-control"></div>
    <div class="mb-2"><label class="form-label">Email</label><input name="email" type="email" class="form-control"></div>
    <div class="mb-2"><label class="form-label">Role</label>
      <select name="role" class="form-select">
        <option value="admin">Admin</option>
        <option value="project_manager">Project Manager</option>
        <option value="systemdev">SystemDev</option>
      </select>
    </div>
  </div>
  <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-primary" type="submit">Create</button></div>
</form></div></div>

<div class="modal fade" id="editRoleModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="POST">
  <div class="modal-header"><h5 class="modal-title">Edit Role</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <input type="hidden" name="action" value="update_role">
    <input type="hidden" id="roleUserId" name="id">
    <div class="mb-2"><label class="form-label">Role</label>
      <select id="roleSelect" name="role" class="form-select">
        <option value="admin">Admin</option>
        <option value="project_manager">Project Manager</option>
        <option value="systemdev">SystemDev</option>
      </select>
    </div>
    <div class="mb-2"><label class="form-label">Status</label>
      <select id="statusSelect" name="status" class="form-select">
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
      </select>
    </div>
  </div>
  <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
</form></div></div>

<div class="modal fade" id="changePasswordModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="POST">
  <div class="modal-header"><h5 class="modal-title">Change Password</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <input type="hidden" name="action" value="update_password">
    <input type="hidden" id="pwdUserId" name="id">
    <div class="mb-2"><label class="form-label">New Password</label><input name="new_password" type="password" class="form-control" required minlength="8" autocomplete="new-password"><div class="form-text">At least 8 characters</div></div>
    <div class="mb-2"><label class="form-label">Confirm Password</label><input name="confirm_password" type="password" class="form-control" required minlength="8" autocomplete="new-password"></div>
  </div>
  <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-primary" type="submit">Update</button></div>
</form></div></div>

<form id="deleteForm" method="POST" style="display:none"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" id="deleteId"></form>

<script>
  const USERS = <?= $js_users ?>;
  const roleFilter = document.getElementById('roleFilter');
  const statusFilter = document.getElementById('statusFilter');
  const searchInput = document.getElementById('searchInput');
  const btnViewGrid = document.getElementById('viewGrid');
  const btnViewTable = document.getElementById('viewTable');
  const gridContainer = document.getElementById('gridContainer');
  const tableContainer = document.getElementById('tableContainer');
  const statsEl = document.getElementById('statsSummary');
  const statsCards = document.getElementById('statsCards');
  document.getElementById('btnAddUser').addEventListener('click', ()=>{ const m = new bootstrap.Modal(document.getElementById('addUserModal')); m.show(); });
  function applyFilters(list){ const roleVal=roleFilter.value; const statusVal=statusFilter.value; const q=searchInput.value.trim().toLowerCase(); return list.filter(u=>{ const matchRole=roleVal==='all'||(u.role||'').toLowerCase()===roleVal; const matchStatus=statusVal==='all'||(u.status||'').toLowerCase()===statusVal; const text=`${u.first_name||''} ${u.last_name||''} ${u.email||''}`.toLowerCase(); const matchQ=!q||text.includes(q); return matchRole&&matchStatus&&matchQ; }); }
  function renderStats(list){ const total=list.length; const active=list.filter(u=>(u.status||'').toLowerCase()==='active').length; const inactive=list.filter(u=>(u.status||'').toLowerCase()==='inactive').length; const admins=list.filter(u=>(u.role||'').toLowerCase()==='admin').length; statsEl.textContent=`${total} total, ${active} active`; statsCards.innerHTML=`<div class="col-md-3"><div class="card ua-stat-card"><div class="card-body"><div class="text-muted small">Total</div><div class="h5 mb-0">${total}</div></div></div></div><div class="col-md-3"><div class="card ua-stat-card"><div class="card-body"><div class="text-muted small">Active</div><div class="h5 mb-0">${active}</div></div></div></div><div class="col-md-3"><div class="card ua-stat-card"><div class="card-body"><div class="text-muted small">Inactive</div><div class="h5 mb-0">${inactive}</div></div></div></div><div class="col-md-3"><div class="card ua-stat-card"><div class="card-body"><div class="text-muted small">Admins</div><div class="h5 mb-0">${admins}</div></div></div></div>`; }
  function renderGrid(list){ gridContainer.innerHTML=''; list.forEach(u=>{ const nm=`${u.first_name||''} ${u.last_name||''}`.trim()||'User'; const avatar=((u.first_name||'U')[0]+(u.last_name||'U')[0]).toUpperCase(); const statusCls=(u.status||'').toLowerCase(); const col=document.createElement('div'); col.className='col-md-4 col-lg-3'; col.innerHTML=`<div class="card h-100 ua-card"><div class="card-body"><div class="d-flex align-items-center gap-2 mb-2"><div class="ua-avatar">${avatar}</div><div><div class="fw-medium">${nm}</div><div class="small text-muted">${u.email||''}</div></div></div><div class="small text-muted">${u.role||''}</div><div class="mt-2 d-flex gap-1 ua-actions"><span class="ua-pill ${statusCls?('status-'+statusCls):''}">${u.status||''}</span><button class="btn btn-sm btn-outline-secondary ms-auto" data-action="edit-role" data-id="${u.id}" data-role="${u.role||'admin'}" data-status="${(u.status||'active').toLowerCase()}" title="Edit Role" data-bs-toggle="tooltip"><i class="bi bi-pencil"></i></button><button class="btn btn-sm btn-outline-secondary" data-action="change-password" data-id="${u.id}" title="Change Password" data-bs-toggle="tooltip"><i class="bi bi-key"></i></button><button class="btn btn-sm btn-outline-danger" data-action="delete" data-id="${u.id}" title="Delete User" data-bs-toggle="tooltip"><i class="bi bi-trash"></i></button></div></div></div>`; gridContainer.appendChild(col); }); }
  function renderTable(list){ const tbody=document.getElementById('tableBody'); tbody.innerHTML=''; list.forEach(u=>{ const nm=`${u.first_name||''} ${u.last_name||''}`.trim()||'User'; const statusCls=(u.status||'').toLowerCase(); const tr=document.createElement('tr'); tr.innerHTML=`<td>${nm}<br><small class="text-muted">${u.email||''}</small></td><td>${u.role||''}</td><td><span class="ua-pill ${statusCls?('status-'+statusCls):''}">${u.status||''}</span></td><td class="text-end"><button class="btn btn-sm btn-outline-secondary" data-action="edit-role" data-id="${u.id}" data-role="${u.role||'admin'}" data-status="${(u.status||'active').toLowerCase()}" title="Edit Role" data-bs-toggle="tooltip"><i class="bi bi-pencil"></i></button><button class="btn btn-sm btn-outline-secondary" data-action="change-password" data-id="${u.id}" title="Change Password" data-bs-toggle="tooltip"><i class="bi bi-key"></i></button><button class="btn btn-sm btn-outline-danger" data-action="delete" data-id="${u.id}" title="Delete User" data-bs-toggle="tooltip"><i class="bi bi-trash"></i></button></td>`; tbody.appendChild(tr); }); }
  function render(list){ renderStats(list); renderGrid(list); renderTable(list); }
  [roleFilter,statusFilter,searchInput].forEach(el=>{ el.addEventListener('input', ()=>render(applyFilters(USERS))); el.addEventListener('change', ()=>render(applyFilters(USERS))); });
  btnViewGrid.addEventListener('click', ()=>{ btnViewGrid.classList.add('active'); btnViewTable.classList.remove('active'); gridContainer.classList.remove('d-none'); tableContainer.classList.add('d-none'); });
  btnViewTable.addEventListener('click', ()=>{ btnViewTable.classList.add('active'); btnViewGrid.classList.remove('active'); tableContainer.classList.remove('d-none'); gridContainer.classList.add('d-none'); });
  document.addEventListener('click', (e)=>{ const btn=e.target.closest('button[data-action]'); if(!btn) return; const id=parseInt(btn.getAttribute('data-id')||'0',10); const action=btn.getAttribute('data-action'); if(action==='edit-role'){ document.getElementById('roleUserId').value=String(id); const r=btn.getAttribute('data-role')||'admin'; document.getElementById('roleSelect').value=r; const s=(btn.getAttribute('data-status')||'active').toLowerCase(); const statusEl=document.getElementById('statusSelect'); if(statusEl){ statusEl.value=(s==='inactive'?'inactive':'active'); } const m=new bootstrap.Modal(document.getElementById('editRoleModal')); m.show(); } else if(action==='change-password'){ document.getElementById('pwdUserId').value=String(id); const m=new bootstrap.Modal(document.getElementById('changePasswordModal')); m.show(); } else if(action==='delete'){ if(confirm('Delete this user?')){ document.getElementById('deleteId').value=String(id); document.getElementById('deleteForm').submit(); } } });
  render(applyFilters(USERS));
  Array.from(document.querySelectorAll('[data-bs-toggle="tooltip"]')).forEach(el=>{ new bootstrap.Tooltip(el); });
</script>
<?php include 'includes/footer.php'; ?>
