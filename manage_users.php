<?php 

require_once 'includes/auth_guard.php';
require_once 'config/database.php';
require_once 'includes/activity_logger.php';

$page_title = 'User Management';
$current_page = basename(__FILE__);

$pdo = null;
try { $pdo = getDBConnection(); } catch (Exception $e) { $pdo = null; }
$role = strtolower($_SESSION['role'] ?? ($_SESSION['login_user']['role'] ?? ''));
if ($role !== 'admin') { http_response_code(403); echo 'Forbidden'; exit; }

$success_msg = $_GET['success'] ?? '';
$error_msg = $_GET['error'] ?? '';

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $allowed_roles = ['admin', 'manager', 'team lead', 'systemdev'];

    if ($action === 'create_user') {
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password_raw = $_POST['password'] ?? '';
        $r = trim($_POST['role'] ?? 'admin');
        
        $dept = trim($_POST['department'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $emp_id = trim($_POST['employee_id'] ?? '');
        $hire_date = !empty($_POST['hire_date']) ? $_POST['hire_date'] : null;
        $skills = trim($_POST['skills'] ?? '');

        if (!in_array($r, $allowed_roles, true)) { $r = 'admin'; }

        if ($first !== '' && $password_raw !== '') {
            // CHECK FOR DUPLICATE EMPLOYEE ID
            $check = $pdo->prepare("SELECT id FROM users WHERE employee_id = ? AND employee_id != ''");
            $check->execute([$emp_id]);
            if ($check->fetch()) {
                header('Location: manage_users.php?error=duplicate_id'); exit;
            }

            $username = $email !== '' ? explode('@', $email)[0] : (strtolower(preg_replace('/\s+/', '', ($first.$last))) ?: ('user'.time()));
            $password = password_hash($password_raw, PASSWORD_DEFAULT);
            try {
                $pdo->beginTransaction();

                $sql = 'INSERT INTO users (username, email, password, first_name, last_name, role, department, phone, employee_id, hire_date, skills, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $st = $pdo->prepare($sql);
                $st->execute([$username, $email, $password, $first, $last, $r, $dept, $phone, $emp_id, $hire_date, $skills, 'active']);
                
                $newId = (int)$pdo->lastInsertId();
                $role_key = str_replace(' ', '_', $r); 
                $role_stmt = $pdo->prepare("SELECT id FROM roles WHERE `key` = ? OR `name` = ? LIMIT 1");
                $role_stmt->execute([$role_key, $r]);
                $role_data = $role_stmt->fetch();
                
                if($role_data) {
                    $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)")->execute([$newId, $role_data['id']]);
                }

                log_activity('user_create', 'user', $newId, ['username' => $username, 'role' => $r], $pdo);
                $pdo->commit();
                header('Location: manage_users.php?success=created'); exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                header('Location: manage_users.php?error=sql_error'); exit;
            }
        }

    } elseif ($action === 'update_user_full') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $r = trim($_POST['role'] ?? 'admin');
        $s = trim(strtolower($_POST['status'] ?? 'active'));
        $new_pwd = $_POST['password'] ?? '';
        
        $dept = trim($_POST['department'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $emp_id = trim($_POST['employee_id'] ?? '');
        $hire_date = !empty($_POST['hire_date']) ? $_POST['hire_date'] : null;
        $skills = trim($_POST['skills'] ?? '');

        if (!in_array($r, $allowed_roles, true)) { $r = 'admin'; }

        if ($id > 0) {
            // CHECK FOR DUPLICATE EMPLOYEE ID (Exclude current user)
            $check = $pdo->prepare("SELECT id FROM users WHERE employee_id = ? AND id != ? AND employee_id != ''");
            $check->execute([$emp_id, $id]);
            if ($check->fetch()) {
                header('Location: manage_users.php?error=duplicate_id'); exit;
            }

            try {
                $pdo->beginTransaction();
                $sql = "UPDATE users SET first_name = ?, last_name = ?, email = ?, role = ?, status = ?, department = ?, phone = ?, employee_id = ?, hire_date = ?, skills = ?";
                $params = [$first, $last, $email, $r, $s, $dept, $phone, $emp_id, $hire_date, $skills];

                if (!empty($new_pwd)) {
                    $sql .= ", password = ?";
                    $params[] = password_hash($new_pwd, PASSWORD_DEFAULT);
                }

                $sql .= " WHERE id = ?";
                $params[] = $id;

                $pdo->prepare($sql)->execute($params);

                $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$id]);
                $role_key = str_replace(' ', '_', $r);
                $role_stmt = $pdo->prepare("SELECT id FROM roles WHERE `key` = ? OR `name` = ? LIMIT 1");
                $role_stmt->execute([$role_key, $r]);
                $role_data = $role_stmt->fetch();
                
                if($role_data) {
                    $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)")->execute([$id, $role_data['id']]);
                }

                log_activity('user_update_full', 'user', $id, ['role' => $r, 'status' => $s], $pdo);
                $pdo->commit();
                header('Location: manage_users.php?success=updated'); exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                header('Location: manage_users.php?error=sql_error'); exit;
            }
        }
    } elseif ($action === 'delete_user') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            try { 
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]); 
                log_activity('user_delete', 'user', $id, null, $pdo); 
                header('Location: manage_users.php?success=deleted'); exit;
            } catch (Exception $e) {}
        }
    }
}

$rows = [];
try { $rows = $pdo ? $pdo->query('SELECT * FROM users ORDER BY first_name ASC LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC) : []; } catch (Exception $e) {}
$js_users = json_encode($rows ?: [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
?>
<?php include 'includes/header.php'; ?>

<style>
    :root {
        --primary-gradient: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
        --danger-gradient: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);
        --glass: rgba(255, 255, 255, 0.7);
    }
    body { background-color: #f3f4f9; font-family: 'Inter', sans-serif; }
    .ua-card { border: 1px solid rgba(255,255,255,0.3); border-radius: 20px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); background: white; overflow: hidden; }
    .ua-card:hover { transform: translateY(-8px); box-shadow: 0 15px 30px rgba(0,0,0,0.08); }
    .card-accent { height: 4px; background: var(--primary-gradient); width: 100%; }
    .ua-avatar { width: 52px; height: 52px; border-radius: 14px; background: var(--primary-gradient); display: flex; align-items: center; justify-content: center; font-weight: 700; color: white; font-size: 1.3rem; box-shadow: 0 4px 10px rgba(99, 102, 241, 0.3); }
    .ua-pill { padding: 5px 14px; font-size: 0.7rem; font-weight: 700; border-radius: 50px; text-transform: uppercase; letter-spacing: 0.5px; }
    .status-active { background: #ecfdf5; color: #059669; border: 1px solid #10b98133; }
    .status-inactive { background: #fef2f2; color: #dc2626; border: 1px solid #ef444433; }
    .search-container { background: white; border-radius: 15px; padding: 10px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
    .btn-view-toggle { background: #f1f5f9; padding: 5px; border-radius: 12px; }
    .btn-view-toggle .btn { border-radius: 8px; padding: 6px 15px; font-weight: 600; border: none; }
    .btn-view-toggle .btn.active { background: white; box-shadow: 0 2px 4px rgba(0,0,0,0.1); color: #6366f1; }
    .modal-content { border-radius: 24px; border: none; }
    .modal-header { border-bottom: 1px solid #f1f5f9; padding: 1.5rem; }
    .section-title { font-size: 0.7rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-top: 20px; margin-bottom: 12px; display: flex; align-items: center; }
    .section-title::after { content: ""; flex: 1; height: 1px; background: #e2e8f0; margin-left: 10px; }
    .icon-circle { width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; margin: 0 auto 20px; }
    .icon-success { background: #ecfdf5; color: #10b981; }
    .icon-delete { background: #fef2f2; color: #ef4444; }
</style>

<div class="container py-5">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-5 gap-3">
        <div>
            <h1 class="fw-black text-dark mb-1" style="letter-spacing: -1px;">User Directory</h1>
            <p class="text-muted"><i class="bi bi-people-fill me-2"></i>Managing <b id="statsSummary"><?= count($rows) ?></b> team members</p>
        </div>
        <button id="btnAddUser" class="btn btn-primary btn-lg shadow-lg border-0 px-4" style="border-radius: 16px; background: var(--primary-gradient);">
            <i class="bi bi-person-plus-fill me-2"></i>Add New Member
        </button>
    </div>

    <div class="search-container mb-4">
        <div class="row g-3 align-items-center">
            <div class="col-lg-5">
                <div class="input-group input-group-lg border-0">
                    <span class="input-group-text bg-transparent border-0 text-muted"><i class="bi bi-search"></i></span>
                    <input id="searchInput" class="form-control border-0 shadow-none fs-6" placeholder="Find by name, email, department...">
                </div>
            </div>
            <div class="col-lg-3 col-6">
                <select id="roleFilter" class="form-select border-0 bg-light shadow-none">
                    <option value="all">All Roles</option>
                    <option value="admin">Administrator</option>
                    <option value="manager">Manager</option>
                    <option value="team lead">Team Lead</option>
                    <option value="systemdev">System Developer</option>
                </select>
            </div>
            <div class="col-lg-2 col-6">
                <select id="statusFilter" class="form-select border-0 bg-light shadow-none">
                    <option value="all">All Status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="col-lg-2 text-end">
                <div class="btn-view-toggle d-inline-flex">
                    <button id="viewGrid" class="btn btn-sm active"><i class="bi bi-grid-fill"></i></button>
                    <button id="viewTable" class="btn btn-sm"><i class="bi bi-list-task"></i></button>
                </div>
            </div>
        </div>
    </div>

    <div id="gridContainer" class="row g-4"></div>

    <div id="tableContainer" class="card d-none border-0 shadow-sm" style="border-radius: 20px; overflow: hidden;">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4 border-0 py-3 text-uppercase small fw-bold">Team Member</th>
                        <th class="border-0 py-3 text-uppercase small fw-bold">Department</th>
                        <th class="border-0 py-3 text-uppercase small fw-bold">Role</th>
                        <th class="border-0 py-3 text-uppercase small fw-bold">Status</th>
                        <th class="text-end pe-4 border-0 py-3 text-uppercase small fw-bold">Actions</th>
                    </tr>
                </thead>
                <tbody id="tableBody"></tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content" method="POST">
            <div class="modal-header">
                <h5 class="fw-bold mb-0">Create Member Profile</h5>
                <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="action" value="create_user">
                <div class="section-title">Personal Details</div>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">First Name</label><input name="first_name" class="form-control" required></div>
                    <div class="col-md-6"><label class="form-label">Last Name</label><input name="last_name" class="form-control"></div>
                    <div class="col-md-7"><label class="form-label">Email</label><input name="email" type="email" class="form-control"></div>
                    <div class="col-md-5"><label class="form-label">Phone</label><input name="phone" class="form-control"></div>
                </div>
                <div class="section-title">Job Information</div>
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">Employee ID</label><input name="employee_id" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Department</label><input name="department" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Hire Date</label><input name="hire_date" type="date" class="form-control"></div>
                    <div class="col-12"><label class="form-label">Skills & Expertise</label><textarea name="skills" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="section-title">Access Control</div>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Temporary Password</label><input name="password" type="password" class="form-control" required minlength="4"></div>
                    <div class="col-md-6">
                        <label class="form-label">Assign Role</label>
                        <select name="role" class="form-select">
                            <option value="admin">Admin</option>
                            <option value="manager">Manager</option>
                            <option value="team lead">Team Lead</option>
                            <option value="systemdev">SystemDev</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <button class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal" type="button">Discard</button>
                <button class="btn btn-primary rounded-pill px-4" type="submit">Complete Registration</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content" method="POST">
            <div class="modal-header">
                <h5 class="fw-bold mb-0">Update Member Profile</h5>
                <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="action" value="update_user_full">
                <input type="hidden" id="editUserId" name="id">
                <div class="section-title">Personal Details</div>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">First Name</label><input id="editFirst" name="first_name" class="form-control" required></div>
                    <div class="col-md-6"><label class="form-label">Last Name</label><input id="editLast" name="last_name" class="form-control"></div>
                    <div class="col-md-7"><label class="form-label">Email</label><input id="editEmail" name="email" type="email" class="form-control"></div>
                    <div class="col-md-5"><label class="form-label">Phone</label><input id="editPhone" name="phone" class="form-control"></div>
                </div>
                <div class="section-title">Job Information</div>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Department</label><input id="editDept" name="department" class="form-control"></div>
                    <div class="col-md-6"><label class="form-label">Employee ID</label><input id="editEmpId" name="employee_id" class="form-control"></div>
                    <div class="col-md-6"><label class="form-label">Hire Date</label><input id="editHireDate" name="hire_date" type="date" class="form-control"></div>
                    <div class="col-md-6"><label class="form-label">Account Status</label>
                        <select id="editStatus" name="status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="col-12"><label class="form-label">Skills</label><textarea id="editSkills" name="skills" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="section-title">Security Settings</div>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Update Password (Leave blank to keep)</label><input name="password" type="password" class="form-control" placeholder="••••••••"></div>
                    <div class="col-md-6">
                        <label class="form-label">Role</label>
                        <select id="editRole" name="role" class="form-select">
                            <option value="admin">Admin</option>
                            <option value="manager">Manager</option>
                            <option value="team lead">Team Lead</option>
                            <option value="systemdev">SystemDev</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <button class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal" type="button">Cancel</button>
                <button class="btn btn-primary rounded-pill px-4" type="submit">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content text-center p-4">
            <div class="icon-circle icon-delete"><i class="bi bi-exclamation-triangle"></i></div>
            <h4 class="fw-bold">Delete User?</h4>
            <p class="text-muted">This action is permanent. All data for <b id="deleteTargetName"></b> will be erased.</p>
            <div class="d-grid gap-2 mt-3">
                <button class="btn btn-danger rounded-pill py-2 fw-bold" id="confirmDeleteBtn">Delete Anyway</button>
                <button class="btn btn-light rounded-pill py-2" data-bs-dismiss="modal">Keep Member</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content text-center p-4">
            <div id="statusIcon" class="icon-circle"></div>
            <h4 class="fw-bold" id="statusTitle"></h4>
            <p class="text-muted" id="statusText"></p>
            <button class="btn rounded-pill py-2 mt-3 fw-bold w-100" id="statusBtn" data-bs-dismiss="modal">Got it!</button>
        </div>
    </div>
</div>

<form id="deleteForm" method="POST" style="display:none">
    <input type="hidden" name="action" value="delete_user">
    <input type="hidden" name="id" id="deleteIdField">
</form>

<script>
    const USERS = <?= $js_users ?>;
    let pendingDeleteId = null;

    function render(list) {
        const grid = document.getElementById('gridContainer');
        const table = document.getElementById('tableBody');
        grid.innerHTML = ''; table.innerHTML = '';
        list.forEach(u => {
            const name = `${u.first_name || ''} ${u.last_name || ''}`.trim();
            const initial = (u.first_name || 'U')[0].toUpperCase();
            const st = (u.status || 'active').toLowerCase();
            const col = document.createElement('div');
            col.className = 'col-md-6 col-lg-4 col-xl-3';
            col.innerHTML = `
                <div class="ua-card shadow-sm h-100 d-flex flex-column">
                    <div class="card-accent"></div>
                    <div class="p-4 flex-grow-1">
                        <div class="d-flex align-items-start justify-content-between mb-4">
                            <div class="ua-avatar">${initial}</div>
                            <span class="ua-pill status-${st}">${st}</span>
                        </div>
                        <h5 class="fw-bold text-dark mb-1 text-truncate" title="${name}">${name}</h5>
                        <p class="small text-muted mb-3 text-truncate">${u.email || 'No email set'}</p>
                        <div class="bg-light rounded-3 p-3 mb-3">
                            <div class="d-flex justify-content-between mb-1"><span class="small text-muted">Dept:</span><span class="small fw-bold">${u.department || 'N/A'}</span></div>
                            <div class="d-flex justify-content-between"><span class="small text-muted">Role:</span><span class="small fw-bold">${u.role}</span></div>
                        </div>
                    </div>
                    <div class="px-4 py-3 bg-light d-flex justify-content-end gap-2 border-top">
                        <button class="btn btn-sm btn-white bg-white border shadow-sm rounded-circle" onclick="openEdit(${u.id})"><i class="bi bi-pencil-fill text-primary"></i></button>
                        <button class="btn btn-sm btn-white bg-white border shadow-sm rounded-circle" onclick="triggerDelete(${u.id}, '${name}')"><i class="bi bi-trash3-fill text-danger"></i></button>
                    </div>
                </div>`;
            grid.appendChild(col);
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="ps-4 py-3"><div class="d-flex align-items-center gap-3"><div class="ua-avatar" style="width:38px;height:38px;font-size:14px;border-radius:10px;">${initial}</div><div><div class="fw-bold text-dark">${name}</div><div class="small text-muted">${u.email || ''}</div></div></div></td>
                <td><span class="fw-medium">${u.department || '—'}</span></td>
                <td><span class="badge bg-white text-dark border fw-normal p-2 px-3 rounded-pill">${u.role}</span></td>
                <td><span class="ua-pill status-${st}">${st}</span></td>
                <td class="text-end pe-4"><button class="btn btn-sm btn-light rounded-pill px-3" onclick="openEdit(${u.id})">Edit</button><button class="btn btn-sm btn-outline-danger border-0 rounded-circle" onclick="triggerDelete(${u.id}, '${name}')"><i class="bi bi-trash3-fill"></i></button></td>`;
            table.appendChild(tr);
        });
        document.getElementById('statsSummary').textContent = list.length;
    }

    function openEdit(id) {
        const u = USERS.find(x => x.id == id);
        if(!u) return;
        document.getElementById('editUserId').value = u.id;
        document.getElementById('editFirst').value = u.first_name || '';
        document.getElementById('editLast').value = u.last_name || '';
        document.getElementById('editEmail').value = u.email || '';
        document.getElementById('editPhone').value = u.phone || '';
        document.getElementById('editDept').value = u.department || '';
        document.getElementById('editEmpId').value = u.employee_id || '';
        document.getElementById('editHireDate').value = u.hire_date || '';
        document.getElementById('editSkills').value = u.skills || '';
        document.getElementById('editRole').value = u.role;
        document.getElementById('editStatus').value = u.status.toLowerCase();
        new bootstrap.Modal(document.getElementById('editUserModal')).show();
    }

    function triggerDelete(id, name) {
        pendingDeleteId = id;
        document.getElementById('deleteTargetName').innerText = name;
        new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
    }

    document.getElementById('confirmDeleteBtn').onclick = () => {
        if(pendingDeleteId) {
            document.getElementById('deleteIdField').value = pendingDeleteId;
            document.getElementById('deleteForm').submit();
        }
    };

    document.getElementById('viewGrid').onclick = function() {
        this.classList.add('active'); document.getElementById('viewTable').classList.remove('active');
        document.getElementById('gridContainer').classList.remove('d-none'); document.getElementById('tableContainer').classList.add('d-none');
    };
    document.getElementById('viewTable').onclick = function() {
        this.classList.add('active'); document.getElementById('viewGrid').classList.remove('active');
        document.getElementById('tableContainer').classList.remove('d-none'); document.getElementById('gridContainer').classList.add('d-none');
    };

    function applyFilters() {
        const q = document.getElementById('searchInput').value.toLowerCase();
        const r = document.getElementById('roleFilter').value;
        const s = document.getElementById('statusFilter').value;
        const filtered = USERS.filter(u => {
            const matchesSearch = !q || `${u.first_name} ${u.last_name} ${u.email} ${u.department}`.toLowerCase().includes(q);
            const matchesRole = r === 'all' || u.role === r;
            const matchesStatus = s === 'all' || u.status.toLowerCase() === s;
            return matchesSearch && matchesRole && matchesStatus;
        });
        render(filtered);
    }

    document.getElementById('searchInput').oninput = applyFilters;
    document.getElementById('roleFilter').onchange = applyFilters;
    document.getElementById('statusFilter').onchange = applyFilters;
    document.getElementById('btnAddUser').onclick = () => new bootstrap.Modal(document.getElementById('addUserModal')).show();

    window.onload = () => {
        const urlParams = new URLSearchParams(window.location.search);
        const success = urlParams.get('success');
        const error = urlParams.get('error');

        if (success || error) {
            const icon = document.getElementById('statusIcon');
            const title = document.getElementById('statusTitle');
            const text = document.getElementById('statusText');
            const btn = document.getElementById('statusBtn');

            if(success) {
                icon.className = 'icon-circle icon-success';
                icon.innerHTML = '<i class="bi bi-check-lg"></i>';
                btn.className = 'btn btn-success rounded-pill py-2 mt-3 fw-bold w-100';
                if(success === 'created') { title.innerText = 'Success!'; text.innerText = 'New member has been registered.'; }
                if(success === 'updated') { title.innerText = 'Updated!'; text.innerText = 'Profile saved successfully.'; }
                if(success === 'deleted') { title.innerText = 'Deleted!'; text.innerText = 'User removed from system.'; }
            } else {
                icon.className = 'icon-circle icon-delete';
                icon.innerHTML = '<i class="bi bi-x-lg"></i>';
                btn.className = 'btn btn-danger rounded-pill py-2 mt-3 fw-bold w-100';
                if(error === 'duplicate_id') { title.innerText = 'Duplicate ID!'; text.innerText = 'This Employee ID is already assigned to another user.'; }
                if(error === 'sql_error') { title.innerText = 'Error!'; text.innerText = 'Something went wrong with the database.'; }
            }
            new bootstrap.Modal(document.getElementById('statusModal')).show();
            window.history.replaceState({}, document.title, window.location.pathname);
        }
        render(USERS);
    };
</script>
<?php include 'includes/footer.php'; ?>