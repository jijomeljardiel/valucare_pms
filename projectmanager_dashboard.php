<?php 
/* Project Manager Dashboard: Timeline at Team Workload Focus */

require_once 'includes/auth_guard.php';
require_once 'config/database.php';

date_default_timezone_set('Asia/Manila');

// --- CHECK FOR ROLES ---
$user_role = strtolower($_SESSION['role_slug'] ?? ($_SESSION['role'] ?? ''));
$canManageTeam = in_array($user_role, ['admin', 'project_manager', 'manager'], true);

if (!$canManageTeam) {
    header('Location: index.php');
    exit;
}

$pdo = getDBConnection();

// Initialize default values
$metrics = ['active' => 0, 'tasks' => 0, 'overdue' => 0, 'team' => 0];
$projects = [];
$workload = [];

// Action Handling (Status Toggles)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'toggle_status' && $id > 0) {
        $st = $pdo->prepare('SELECT status FROM users WHERE id = ?');
        $st->execute([$id]);
        $cur = $st->fetchColumn();
        $next = (strtolower($cur ?? '') === 'active') ? 'inactive' : 'active';
        $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$next, $id]);
        header('Location: projectmanager_dashboard.php'); 
        exit;
    }
}

// Data Fetching
try {
    $metrics['active'] = (int)($pdo->query("SELECT COUNT(*) FROM projects WHERE project_status_id IN (SELECT id FROM project_statuses WHERE `key` IN ('active', 'in-progress'))")->fetchColumn() ?: 0);
    $metrics['tasks'] = (int)($pdo->query("SELECT COUNT(*) FROM tasks WHERE task_status_id NOT IN (SELECT id FROM task_statuses WHERE `key` = 'done')")->fetchColumn() ?: 0);
    $metrics['overdue'] = (int)($pdo->query("SELECT COUNT(*) FROM tasks WHERE due_date < CURDATE() AND task_status_id NOT IN (SELECT id FROM task_statuses WHERE `key` = 'done')")->fetchColumn() ?: 0);
    $metrics['team'] = (int)($pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn() ?: 0);

    $projects = $pdo->query("
        SELECT p.*, ps.name as status_name, ps.key as status_key 
        FROM projects p 
        LEFT JOIN project_statuses ps ON p.project_status_id = ps.id 
        ORDER BY p.progress ASC LIMIT 6
    ")->fetchAll();

    $workload = $pdo->query("
        SELECT u.first_name, u.last_name, COUNT(t.id) as task_count 
        FROM users u 
        LEFT JOIN tasks t ON u.id = t.assigned_to 
        WHERE u.status = 'active'
        GROUP BY u.id LIMIT 5
    ")->fetchAll();

} catch (Exception $e) {
    error_log($e->getMessage());
}

$page_title = 'Projectmanager Dashboard';
include 'includes/header.php';
?>

<style>
    :root {
        --pm-primary: #6366f1;
        --pm-secondary: #a855f7;
        --pm-gradient: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
        --surface: #ffffff;
        --background: #f4f7fe;
    }

    body { background-color: var(--background); color: #2d3748; }

    /* Fix for the "Putol" Layout */
    .pm-dashboard-header {
        background: var(--pm-gradient);
        padding: 40px 0 100px 0; /* Reduced bottom padding */
        margin-bottom: -60px; /* Reduced negative margin to prevent cutting content */
        position: relative;
        border-bottom-left-radius: 40px; 
        border-bottom-right-radius: 40px;
        box-shadow: 0 10px 30px rgba(99, 102, 241, 0.15);
    }

    /* Professional Card Styling */
    .dashboard-card {
        background: var(--surface);
        border: 1px solid rgba(226, 232, 240, 0.8);
        border-radius: 1.25rem;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
        transition: all 0.3s ease;
        height: 100%;
        padding: 1.5rem;
    }
    .dashboard-card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0, 0, 0, 0.06); }

    /* Stat Blocks */
    .stat-card {
        display: flex;
        align-items: center;
        gap: 1.25rem;
    }
    .icon-box {
        width: 50px; height: 50px;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }

    /* Progress Indicators */
    .progress-bar-container {
        height: 8px; background: #edf2f7; border-radius: 10px; overflow: hidden;
    }
    .progress-bar-fill {
        height: 100%; background: var(--pm-gradient); border-radius: 10px;
        transition: width 1s ease-in-out;
    }

    /* Member Initial Avatar */
    .member-initial {
        width: 40px; height: 40px;
        background: #f1f5f9; color: #475569;
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; border: 1px solid #e2e8f0;
    }

    .table thead th {
        font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;
        color: #94a3b8; border-bottom: 2px solid #f8fafc; padding: 1rem 0.5rem;
    }
    
    .badge-status {
        padding: 6px 12px; border-radius: 8px; font-weight: 600; font-size: 0.7rem;
    }
</style>

<div class="pm-dashboard-header">
    <div class="container-fluid px-lg-4">
        <div class="row align-items-center">
            <div class="col-12">
                <h2 class="text-white fw-bold mb-1">Executive Overview</h2>
                <p class="text-white-50 mb-0">Managing <?= $metrics['active'] ?> active projects with <?= $metrics['team'] ?> active personnel.</p>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid px-lg-4" style="position: relative; z-index: 10;">
    <div class="row g-4 mb-4">
        <?php 
        $blocks = [
            ['title' => 'Active Projects', 'val' => $metrics['active'], 'icon' => 'bi-briefcase', 'bg' => '#e0e7ff', 'color' => '#4338ca'],
            ['title' => 'Tasks Queue', 'val' => $metrics['tasks'], 'icon' => 'bi-clipboard-data', 'bg' => '#fef3c7', 'color' => '#b45309'],
            ['title' => 'Overdue Items', 'val' => $metrics['overdue'], 'icon' => 'bi-clock-history', 'bg' => '#fee2e2', 'color' => '#b91c1c'],
            ['title' => 'Total Workforce', 'val' => $metrics['team'], 'icon' => 'bi-people', 'bg' => '#ccfbf1', 'color' => '#0f766e']
        ];
        foreach($blocks as $b): ?>
        <div class="col-sm-6 col-xl-3">
            <div class="dashboard-card stat-card">
                <div class="icon-box" style="background: <?= $b['bg'] ?>; color: <?= $b['color'] ?>;">
                    <i class="bi <?= $b['icon'] ?>"></i>
                </div>
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.65rem;"><?= $b['title'] ?></div>
                    <div class="h3 fw-bold mb-0" style="color: #1e293b;"><?= $b['val'] ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="dashboard-card">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Project Velocity</h5>
                    <a href="project_task.php" class="btn btn-sm btn-light rounded-pill px-3" style="font-size: 0.75rem;">View Detailed Report</a>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle border-0">
                        <thead>
                            <tr>
                                <th class="border-0 ps-0">Project Detail</th>
                                <th class="border-0">Operational Status</th>
                                <th class="border-0">Completion</th>
                                <th class="border-0 text-end pe-0">Timeline</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($projects)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-5 text-muted small">
                                        <i class="bi bi-folder2-open d-block mb-2 fs-2"></i>
                                        No project data found for current cycle.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($projects as $p): ?>
                                <tr>
                                    <td class="ps-0">
                                        <div class="fw-bold text-dark" style="font-size: 0.9rem;"><?= htmlspecialchars($p['name']) ?></div>
                                        <div class="text-muted" style="font-size: 0.7rem;">ID: #<?= $p['id'] ?> | ValueCare Suite</div>
                                    </td>
                                    <td>
                                        <span class="badge-status" style="background: <?= ($p['status_key'] == 'at-risk') ? '#fff1f2' : '#f0f9ff' ?>; color: <?= ($p['status_key'] == 'at-risk') ? '#e11d48' : '#0369a1' ?>;">
                                            <?= strtoupper($p['status_name'] ?? 'STABLE') ?>
                                        </span>
                                    </td>
                                    <td style="min-width: 140px;">
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress-bar-container flex-grow-1">
                                                <div class="progress-bar-fill" style="width: <?= $p['progress'] ?>%"></div>
                                            </div>
                                            <span class="fw-bold small" style="font-size: 0.75rem;"><?= $p['progress'] ?>%</span>
                                        </div>
                                    </td>
                                    <td class="text-end pe-0">
                                        <div class="fw-bold small text-dark" style="font-size: 0.8rem;"><?= isset($p['due_date']) ? date('M d, Y', strtotime($p['due_date'])) : '---' ?></div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="dashboard-card">
                <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-person-check me-2 text-primary"></i>Personnel Workload</h5>
                <?php if(empty($workload)): ?>
                    <div class="text-center py-5 text-muted small">
                        <i class="bi bi-people mb-2 d-block fs-3"></i>
                        No active personnel found.
                    </div>
                <?php else: ?>
                    <?php foreach($workload as $w): ?>
                    <div class="mb-4">
                        <div class="d-flex align-items-center mb-2">
                            <div class="member-initial me-3"><?= strtoupper(substr($w['first_name'], 0, 1)) ?></div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-dark" style="font-size: 0.85rem;"><?= htmlspecialchars($w['first_name'] . ' ' . $w['last_name']) ?></span>
                                    <span class="badge rounded-pill bg-light text-muted border small fw-normal" style="font-size: 0.65rem;"><?= $w['task_count'] ?> active</span>
                                </div>
                                <div class="progress-bar-container">
                                    <div class="progress-bar-fill" style="width: <?= min((($w['task_count'])/8)*100, 100) ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <div class="mt-4 p-4 rounded-4 text-center" style="background: #f8fafc; border: 2px dashed #e2e8f0;">
                    <p class="small text-muted mb-3">Optimize your team structure by managing task allocations.</p>
                    <a href="team_management.php" class="btn btn-primary btn-sm rounded-pill px-4 py-2 fw-bold shadow-sm" style="font-size: 0.8rem;">Enter Team Portal</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>