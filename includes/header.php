<?php 

require_once 'includes/functions.php'; 
$login_user = $_SESSION['login_user'] ?? null;
$current_page = basename($_SERVER['PHP_SELF']);
$page_title = ucwords(str_replace(['.php', '_'], ['', ' '], $current_page));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VC PMS | <?php echo esc($page_title); ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --side-width: 280px;
            --side-collapsed-width: 85px;
            --transition-speed: 0.25s;
        }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        
        .header {
            position: fixed;
            top: 0;
            right: 0;
            left: var(--side-width);
            height: 75px;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid #eef2f6;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 2rem;
            z-index: 1040 !important; 
            transition: left var(--transition-speed) ease-in-out;
        }

        body.sidebar-collapsed .header { left: var(--side-collapsed-width); }
        body.sidebar-collapsed .main-content { margin-left: var(--side-collapsed-width); }

        .main-content {
            margin-left: var(--side-width);
            padding: 100px 2rem 2rem;
            transition: margin-left var(--transition-speed) ease-in-out;
            min-height: 100vh;
        }

        .sidebar-toggle-btn {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: #f1f5f9;
            border: none;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: 0.2s;
            position: relative;
            z-index: 1060;
        }

        /* Crucial Fix: Make sure the icon doesn't block the button click */
        .sidebar-toggle-btn * {
            pointer-events: none;
        }

        .sidebar-toggle-btn:hover { background: #6366f1; color: white; }

        .header-right { display: flex; align-items: center; gap: 12px; }

        .header-action {
            width: 38px; height: 38px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 10px;
            color: #64748b;
            text-decoration: none;
            position: relative;
            transition: 0.2s;
        }

        .header-action:hover { background: #f1f5f9; color: #6366f1; }

        .badge-notif {
            position: absolute;
            top: 8px; right: 8px;
            width: 7px; height: 7px;
            background: #ef4444;
            border-radius: 50%;
            border: 2px solid white;
        }

        .user-avatar-header {
            width: 38px; height: 38px;
            border-radius: 10px;
            object-fit: cover;
            border: 2px solid #f1f5f9;
        }
        
        .breadcrumb { margin-bottom: 0; margin-left: 15px; }
        .breadcrumb-item a { text-decoration: none; color: #94a3b8; font-size: 0.85rem; font-weight: 500; }

        /* Logout Modal Icon Styling */
        .logout-modal-icon {
            width: 70px; height: 70px;
            background: #fef2f2;
            color: #ef4444;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 2rem; margin: 0 auto 15px;
        }
    </style>
</head>
<body class="<?= (isset($_COOKIE['sidebar_state']) && $_COOKIE['sidebar_state'] == 'collapsed') ? 'sidebar-collapsed' : '' ?>">

<?php include_once 'sidebar.php'; ?>

<header class="header">
    <div class="header-left d-flex align-items-center">
        <button class="sidebar-toggle-btn" id="sidebarToggle" type="button">
            <i class="bi bi-list-nested" style="font-size: 1.3rem;"></i>
        </button>
        <nav aria-label="breadcrumb" class="d-none d-md-block">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                <?php if (strtolower($current_page) != 'dashboard.php'): ?>
                    <li class="breadcrumb-item active" style="color:#1e293b; font-weight:700;"><?php echo esc($page_title); ?></li>
                <?php endif; ?>
            </ol>
        </nav>
    </div>

    <div class="header-right">
        <a href="#" class="header-action"><i class="bi bi-bell"></i><span class="badge-notif"></span></a>
        <a href="team_collaboration.php" class="header-action"><i class="bi bi-chat-square-dots"></i></a>
        
        <div class="dropdown">
            <a href="#" class="d-flex align-items-center gap-2 text-decoration-none" data-bs-toggle="dropdown">
                <img src="<?php echo esc(base_url('assets/images/ICT.png')); ?>" class="user-avatar-header">
                <div class="d-none d-lg-block me-2">
                    <div class="fw-bold text-dark" style="font-size:0.8rem; line-height:1;"><?php echo esc($login_user['first_name'] ?? 'Account'); ?></div>
                    <span class="text-muted" style="font-size:0.7rem;"><?php echo ucfirst($role); ?></span>
                </div>
            </a>
            <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg p-2" style="border-radius: 12px; min-width: 180px;">
                <li><a class="dropdown-item rounded-3 py-2 small" href="profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
                <li><a class="dropdown-item rounded-3 py-2 small" href="settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><button class="dropdown-item rounded-3 py-2 small text-danger" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal"><i class="bi bi-box-arrow-right me-2"></i>Logout</button></li>
            </ul>
        </div>
    </div>
</header>

<div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-body text-center p-4">
                <div class="logout-modal-icon">
                    <i class="bi bi-exclamation-circle"></i>
                </div>
                <h5 class="fw-bold text-dark">Logout Account?</h5>
                <p class="text-muted small">Are you sure you want to end your session?</p>
                <div class="d-grid gap-2 mt-4">
                    <a href="logout.php" class="btn btn-danger rounded-pill py-2 fw-bold">Yes, Logout</a>
                    <button type="button" class="btn btn-light rounded-pill py-2 text-muted" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>

<main class="main-content" id="mainContent">

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const sidebarToggle = document.getElementById('sidebarToggle');
        const body = document.body;

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                body.classList.toggle('sidebar-collapsed');
                
                const isCollapsed = body.classList.contains('sidebar-collapsed');
                document.cookie = "sidebar_state=" + (isCollapsed ? "collapsed" : "expanded") + "; path=/; max-age=" + (30*24*60*60);
            });
        }
    });
</script>