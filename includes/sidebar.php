<?php 

if (!function_exists('base_url')) {
    function base_url($uri = '') {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $path = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
        return $protocol . $host . $path . ltrim($uri, '/');
    }
}

if (!function_exists('esc')) { 
    function esc($v) { 
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); 
    } 
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$login_user = $_SESSION['login_user'] ?? null;
$current_page = strtolower(basename($_SERVER['PHP_SELF']));
$role = strtolower($_SESSION['role'] ?? 'guest');

$isProjectManager = ($role === 'manager'); 
$isSystemDev = ($role === 'systemdev'); 
$isAdmin = ($role === 'admin'); 

$dashPage = $isAdmin ? 'dashboard.php' : ($isSystemDev ? 'systemdev_dashboard.php' : 'projectmanager_dashboard.php');
?>

<style>
    :root {
        --side-width: 280px;
        --side-collapsed-width: 85px;
        --primary-grad: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
        --side-bg: #ffffff;
        --transition-speed: 0.25s; 
    }

    .sidebar {
        width: var(--side-width);
        height: 100vh;
        background: var(--side-bg);
        border-right: 1px solid #eef2f6;
        display: flex;
        flex-direction: column;
        transition: width var(--transition-speed) ease-in-out;
        position: fixed;
        left: 0;
        top: 0;
        z-index: 1050 !important;
    }

    /* Collapsed State Logic */
    body.sidebar-collapsed .sidebar { width: var(--side-collapsed-width); }
    
    body.sidebar-collapsed .logo-text,
    body.sidebar-collapsed .sidebar-section-title,
    body.sidebar-collapsed .nav-link span,
    body.sidebar-collapsed .user-info-text {
        display: none !important;
        opacity: 0;
    }

    body.sidebar-collapsed .sidebar-header { justify-content: center; padding: 1.5rem 0; }
    body.sidebar-collapsed .nav-link { justify-content: center; padding: 0.85rem; }
    body.sidebar-collapsed .sidebar-footer { padding: 1.2rem 0.5rem; }
    body.sidebar-collapsed .user-card { justify-content: center; border: none; background: transparent; padding: 5px; }
    body.sidebar-collapsed .logout-btn-footer { display: none !important; }

    .sidebar-header {
        padding: 1.5rem;
        display: flex;
        align-items: center;
        gap: 15px;
        height: 75px;
    }

    .logo-image {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        object-fit: cover;
        box-shadow: 0 4px 10px rgba(99, 102, 241, 0.2);
    }

    .logo-title {
        font-size: 1.1rem;
        font-weight: 800;
        color: #1e293b;
        margin: 0;
        line-height: 1;
    }

    .logo-subtitle {
        font-size: 0.6rem;
        color: #94a3b8;
        text-transform: uppercase;
        font-weight: 700;
    }

    .sidebar-content {
        flex: 1;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 1rem;
    }

    .sidebar-section-title {
        font-size: 0.65rem;
        font-weight: 700;
        color: #cbd5e1;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin: 1.2rem 0 0.5rem 0.5rem;
    }

    .nav-link {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 0.8rem 1.1rem;
        color: #64748b;
        text-decoration: none;
        font-size: 0.9rem;
        font-weight: 500;
        border-radius: 12px;
        margin-bottom: 4px;
        transition: all 0.2s;
        white-space: nowrap;
    }

    .nav-link:hover { background: #f8fafc; color: #6366f1; }

    .nav-link i { font-size: 1.2rem; min-width: 25px; text-align: center; }

    .nav-link.active {
        background: var(--primary-grad);
        color: #fff;
        box-shadow: 0 8px 15px -3px rgba(99, 102, 241, 0.3);
    }

    .sidebar-footer {
        padding: 1.2rem;
        background: #fcfdfe;
        border-top: 1px solid #f1f5f9;
    }

    .user-card {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px;
        border-radius: 14px;
        background: #ffffff;
        border: 1px solid #edf2f7;
    }

    .user-avatar-small {
        width: 35px; height: 35px;
        border-radius: 10px;
        background: var(--primary-grad);
        color: white;
        display: flex; align-items: center; justify-content: center;
        font-weight: 800; font-size: 0.8rem;
    }

    .user-name { font-size: 0.8rem; font-weight: 700; color: #1e293b; display: block; }

    .user-role { font-size: 0.7rem; color: #94a3b8; }

    .logout-btn-footer {
        margin-left: auto;
        color: #f87171;
        background: none;
        border: none;
        padding: 5px;
        transition: transform 0.2s;
    }
    
    .logout-btn-footer:hover { color: #ef4444; transform: scale(1.1); }
</style>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <img src="<?php echo esc(base_url('assets/images/ICT.png')); ?>" alt="Logo" class="logo-image">
        <div class="logo-text">
            <h2 class="logo-title">ValueCare</h2>
            <span class="logo-subtitle">PMS Suite</span>
        </div>
    </div>

    <div class="sidebar-content">
        <h5 class="sidebar-section-title">Menu</h5>
        
        <a href="<?php echo $dashPage; ?>" class="nav-link <?php echo ($current_page === $dashPage) ? 'active' : ''; ?>">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <?php if ($isProjectManager || $isAdmin): ?>
        <a href="project_task.php" class="nav-link <?php echo ($current_page === 'project_task.php') ? 'active' : ''; ?>">
            <i class="bi bi-stack"></i>
            <span>Project Tasks</span>
        </a>
        <?php endif; ?>

        <?php if ($isSystemDev): ?>
        <a href="my_task.php" class="nav-link <?php echo ($current_page === 'my_task.php') ? 'active' : ''; ?>">
            <i class="bi bi-clipboard-check-fill"></i>
            <span>My Tasks</span>
        </a>
        <?php endif; ?>

        <a href="team_collaboration.php" class="nav-link <?php echo ($current_page === 'team_collaboration.php') ? 'active' : ''; ?>">
            <i class="bi bi-chat-dots-fill"></i>
            <span>Team Collaboration</span>
        </a>

        <?php if ($isProjectManager || $isAdmin): ?>
        <a href="team_management.php" class="nav-link <?php echo ($current_page === 'team_management.php') ? 'active' : ''; ?>">
            <i class="bi bi-people-fill"></i>
            <span>Team Management</span>
        </a>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
        <h5 class="sidebar-section-title">Admin</h5>
        <a href="manage_users.php" class="nav-link <?php echo ($current_page === 'manage_users.php') ? 'active' : ''; ?>">
            <i class="bi bi-shield-lock-fill"></i>
            <span>User Control</span>
        </a>
        <?php endif; ?>
    </div>

    <div class="sidebar-footer">
        <div class="user-card shadow-sm">
            <div class="user-avatar-small">
                <?php echo strtoupper(substr($login_user['first_name'] ?? 'U', 0, 1)); ?>
            </div>
            <div class="user-info-text">
                <span class="user-name"><?php echo esc($login_user['first_name'] ?? 'User'); ?></span>
                <span class="user-role"><?php echo ucfirst($role); ?></span>
            </div>
            <button class="logout-btn-footer" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal">
                <i class="bi bi-power"></i>
            </button>
        </div>
    </div>
</aside>