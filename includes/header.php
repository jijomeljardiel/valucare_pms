<?php /* Header include: meta tags, assets, at navigation header. */

  function base_url($uri = '') {
    $base_url = '';
    if (function_exists('get_instance')) {
      $CI = get_instance();
      if (isset($CI->config) && is_object($CI->config)) {
        $base_url = $CI->config->item('base_url');
      }
    }
    if (empty($base_url)) {
      $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
      $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
      $base_url = $protocol . $host;
      $base_url .= str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
    }
    return $base_url . ltrim($uri, '/');
  }

  function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
  function app_log($level, $message, array $context = []) {
    $line = '[VC-PMS] ' . strtoupper($level) . ': ' . $message;
    if (!empty($context)) { $line .= ' ' . json_encode($context); }
    error_log($line);
  }

  $login_user = null;
  if (isset($this) && isset($this->session) && method_exists($this->session, 'userdata')) {
    $login_user = $this->session->userdata('login_user');
  } else {
    if (function_exists('get_instance')) {
      $CI = get_instance();
      if (isset($CI->session) && method_exists($CI->session, 'userdata')) {
        $login_user = $CI->session->userdata('login_user');
      }
    }
    if (!$login_user && isset($_SESSION['login_user'])) {
      $login_user = $_SESSION['login_user'];
    }
  }
  if (!$login_user) {
    app_log('warning', 'No login user in session');
  }

  $current_page = basename($_SERVER['PHP_SELF']);
  $page_title = ucwords(str_replace(['.php', '_'], ['', ' '], $current_page));
  if (in_array(strtolower($current_page), ['index.php','dashboard.php'])) { $page_title = 'Dashboard'; }
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo 'VC PMS | ' . esc($page_title); ?></title>
  <meta name="description" content="ValueCare Project Management System - <?php echo esc($page_title); ?>">
  <meta name="theme-color" content="#5b2aa7">
  <link rel="canonical" href="<?php echo esc(base_url($current_page)); ?>">
  <meta property="og:title" content="<?php echo esc($page_title); ?>">
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?php echo esc(base_url($current_page)); ?>">
  <meta property="og:image" content="<?php echo esc(base_url('assets/images/ICT.png')); ?>">
  <meta property="og:site_name" content="VC PMS">
  

  <link rel="icon" href="<?php echo base_url('assets/images/ICT.png'); ?>" type="image/png">
  <link rel="apple-touch-icon" href="<?php echo base_url('assets/images/ICT.png'); ?>">
  

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  

  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  

  <link href="<?php echo base_url('assets/css/style.css'); ?>" rel="stylesheet">
  

</head>
<body>

  <a href="#mainContent" class="skip-link">Skip to content</a>

  <?php
    $sidebar_path = __DIR__ . DIRECTORY_SEPARATOR . 'sidebar.php';
    if (is_file($sidebar_path)) { include_once $sidebar_path; } else { app_log('error', 'Sidebar include missing', ['path' => $sidebar_path]); }
  ?>
  

  <header class="header">
    <div class="header-left">
      <button class="sidebar-toggle-btn" id="sidebarToggle" aria-controls="sidebar" aria-label="Toggle navigation">
        <i class="fas fa-bars"></i>
      </button>
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
          <?php if (strtolower($current_page) != 'dashboard.php'): ?>
            <li class="breadcrumb-item active" aria-current="page"><?php echo esc($page_title); ?></li>
          <?php endif; ?>
        </ol>
      </nav>
    </div>
    
    <div class="header-right">

      <div class="dropdown">
        <a href="#" class="header-action d-none d-sm-inline-flex" title="Notifications" id="headerNotifications" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Open notifications" aria-haspopup="listbox">
          <i class="fas fa-bell"></i>
          <span class="badge-notification" id="badgeNotifications">0</span>
        </a>
        <div class="dropdown-menu dropdown-menu-end p-0" aria-labelledby="headerNotifications" style="min-width: 320px;">
          <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
            <div class="fw-medium">Notifications</div>
            <div class="d-flex gap-2">
              <button class="btn btn-sm btn-link" id="notifMarkAllBtn">Mark all read</button>
            </div>
          </div>
          <div id="notificationsMenuList" role="listbox" class="py-2" style="max-height: 320px; overflow:auto;">
            <div class="px-3 small text-muted">Loading…</div>
          </div>
          <div class="border-top px-3 py-2">
            <a href="team_collaboration.php" class="small">View all</a>
          </div>
        </div>
      </div>
      <a href="team_collaboration.php" class="header-action d-none d-sm-inline-flex" title="Messages" data-bs-toggle="tooltip" data-bs-placement="bottom" id="headerMessages">
        <i class="fas fa-envelope"></i>
        <span class="badge-notification" id="badgeMessages">0</span>
      </a>
      
      <div class="dropdown">
        <a href="#" class="user-profile dropdown-toggle" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
          <img src="<?php echo isset($login_user['picture']) && $login_user['picture'] ? esc($login_user['picture']) : esc(base_url('assets/images/ICT.png')); ?>" alt="" class="user-avatar">
          <div class="user-info">
            <h6 class="user-name"><?php echo isset($login_user['full_name']) ? esc($login_user['full_name']) : 'User'; ?></h6>
            <span class="user-role"><?php echo isset($login_user['role']) ? esc($login_user['role']) : ''; ?></span>
          </div>
        </a>
        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
          <?php $role = strtolower($login_user['role'] ?? ($_SESSION['role'] ?? '')); if ($role === 'admin'): ?>
          <li><a class="dropdown-item" href="activity_log.php"><i class="fas fa-history"></i> Activity Log</a></li>
          <?php endif; ?>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
      </div>
    </div>
  </header>
  

  <main class="main-content" role="main" tabindex="-1" id="mainContent">
            
