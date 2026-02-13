<?php /* Sidebar include: navigation menu ng system. */
  if (!function_exists('base_url')) {
    function base_url($uri = '') {
      $base_url = '';
      if (function_exists('get_instance')) {
        $CI = get_instance();
        if (isset($CI->config) && is_object($CI->config)) { $base_url = $CI->config->item('base_url'); }
      }
      if (empty($base_url)) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $base_url = $protocol . $host;
        $base_url .= str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
      }
      return $base_url . ltrim($uri, '/');
    }
  }
  if (!function_exists('esc')) { function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

  $login_user = null;
  if (isset($this) && isset($this->session) && method_exists($this->session, 'userdata')) {
    $login_user = $this->session->userdata('login_user');
  } else {
    if (function_exists('get_instance')) {
      $CI = get_instance();
      if (isset($CI->session) && method_exists($CI->session, 'userdata')) { $login_user = $CI->session->userdata('login_user'); }
    }
    if (!$login_user && isset($_SESSION['login_user'])) { $login_user = $_SESSION['login_user']; }
  }
  if (!$login_user) {  }
  $current_page = strtolower(basename($_SERVER['PHP_SELF']));
  $role_raw = strtolower($login_user['role'] ?? ($_SESSION['role'] ?? 'guest'));
  $role_slug = strtolower($_SESSION['role_slug'] ?? '');
  if ($role_slug === '' || $role_slug === 'manager') {
    $r = str_replace([' ', '-', 'system dev', 'system_dev'], ['_', '_', 'systemdev', 'systemdev'], $role_raw);
    if ($r === 'teamlead' || $r === 'team_lead' || $r === 'manager') { $r = 'project_manager'; }
    $role_slug = $r;
  }
  $role = $role_slug;
?>


<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="logo-container">
      <img src="<?php echo esc(base_url('assets/images/ICT.png')); ?>" alt="VALUSYNC Logo" class="logo-image">
      <div class="logo-text">
        <h2 class="logo-title">ValueCare</h2>
        <span class="logo-subtitle">Project Management System</span>
      </div>
    </div>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-divider"></div>
    <h5 class="sidebar-section-title">Main Navigation</h5>
    <nav class="sidebar-nav" role="navigation" aria-label="Primary">
      <?php $isProjectManagerLocal = ($role === 'project_manager'); $isSystemDevLocal = ($role === 'systemdev'); $dashPage = $isSystemDevLocal ? 'systemdev_dashboard.php' : 'dashboard.php'; ?>
      <?php if (!$isProjectManagerLocal && !$isSystemDevLocal): ?>
        <div class="nav-item">
          <?php $isActive = ($current_page === $dashPage); ?>
          <a href="<?php echo $dashPage; ?>" class="nav-link <?php echo $isActive ? 'active' : ''; ?>" title="Dashboard" data-bs-toggle="tooltip" data-bs-placement="right" aria-current="<?php echo $isActive ? 'page' : 'false'; ?>">
            <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
            <span>Dashboard</span>
          </a>
        </div>
      <?php endif; ?>
      <?php $isProjectManager = ($role === 'project_manager'); $isSystemDev = ($role === 'systemdev'); $isAdmin = ($role === 'admin'); ?>
      <?php if ($isProjectManager): ?>  
        <div class="nav-item">
          <?php $isActive = ($current_page === 'projectmanager_dashboard.php'); ?>
          <a href="projectmanager_dashboard.php" class="nav-link <?php echo $isActive ? 'active' : ''; ?>" title="Project Manager Dashboard" data-bs-toggle="tooltip" data-bs-placement="right" aria-current="<?php echo $isActive ? 'page' : 'false'; ?>">
            <span class="nav-icon"><i class="fas fa-gauge"></i></span>
            <span>Manager Dashboard</span>
          </a>
        </div>
        <div class="nav-item">
          <?php $isActive = ($current_page === 'project_task.php'); ?>
          <a href="project_task.php" class="nav-link <?php echo $isActive ? 'active' : ''; ?>" title="Project Tasks" data-bs-toggle="tooltip" data-bs-placement="right" aria-current="<?php echo $isActive ? 'page' : 'false'; ?>">
            <span class="nav-icon"><i class="fas fa-tasks"></i></span>
            <span>Project Tasks</span>
          </a>
        </div>
        <div class="nav-item">
          <?php $isActive = ($current_page === 'team_collaboration.php'); ?>
          <a href="team_collaboration.php" class="nav-link <?php echo $isActive ? 'active' : ''; ?>" title="Team Collaboration" data-bs-toggle="tooltip" data-bs-placement="right" aria-current="<?php echo $isActive ? 'page' : 'false'; ?>">
            <span class="nav-icon"><i class="fas fa-users"></i></span>
            <span>Team Collaboration</span>
          </a>
        </div>
        
        <div class="nav-item">
          <?php $isActive = ($current_page === 'team_management.php'); ?>
          <a href="team_management.php" class="nav-link <?php echo $isActive ? 'active' : ''; ?>" title="Team Management" data-bs-toggle="tooltip" data-bs-placement="right" aria-current="<?php echo $isActive ? 'page' : 'false'; ?>">
            <span class="nav-icon"><i class="fas fa-users-cog"></i></span>
            <span>Team Management</span>
          </a>
        </div>
      <?php elseif ($isSystemDev): ?>
        <div class="nav-item">
          <?php $isActive = ($current_page === 'systemdev_dashboard.php'); ?>
          <a href="systemdev_dashboard.php" class="nav-link <?php echo $isActive ? 'active' : ''; ?>" title="SystemDev Dashboard" data-bs-toggle="tooltip" data-bs-placement="right" aria-current="<?php echo $isActive ? 'page' : 'false'; ?>">
            <span class="nav-icon"><i class="fas fa-gauge"></i></span>
            <span>Dashboard</span>
          </a>
        </div>
        <div class="nav-item">
          <?php $isActive = ($current_page === 'my_task.php'); ?>
          <a href="my_task.php" class="nav-link <?php echo $isActive ? 'active' : ''; ?>" title="My Tasks" data-bs-toggle="tooltip" data-bs-placement="right" aria-current="<?php echo $isActive ? 'page' : 'false'; ?>">
            <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span>
            <span>My Tasks</span>
          </a>
        </div>
        <div class="nav-item">
          <?php $isActive = ($current_page === 'team_collaboration.php'); ?>
          <a href="team_collaboration.php" class="nav-link <?php echo $isActive ? 'active' : ''; ?>" title="Team Collaboration" data-bs-toggle="tooltip" data-bs-placement="right" aria-current="<?php echo $isActive ? 'page' : 'false'; ?>">
            <span class="nav-icon"><i class="fas fa-users"></i></span>
            <span>Team Collaboration</span>
          </a>
        </div>
      <?php elseif ($isAdmin): ?>
        <div class="nav-item">
          <?php $isActive = ($current_page === 'project_task.php'); ?>
          <a href="project_task.php" class="nav-link <?php echo $isActive ? 'active' : ''; ?>" title="Project Tasks" data-bs-toggle="tooltip" data-bs-placement="right" aria-current="<?php echo $isActive ? 'page' : 'false'; ?>">
            <span class="nav-icon"><i class="fas fa-tasks"></i></span>
            <span>Project Tasks</span>
          </a>
        </div>
        <div class="nav-item">
          <?php $isActive = ($current_page === 'team_collaboration.php'); ?>
          <a href="team_collaboration.php" class="nav-link <?php echo $isActive ? 'active' : ''; ?>" title="Team Collaboration" data-bs-toggle="tooltip" data-bs-placement="right" aria-current="<?php echo $isActive ? 'page' : 'false'; ?>">
            <span class="nav-icon"><i class="fas fa-users"></i></span>
            <span>Team Collaboration</span>
          </a>
        </div>
        <div class="nav-item">
          <?php $isActive = ($current_page === 'team_management.php'); ?>
          <a href="team_management.php" class="nav-link <?php echo $isActive ? 'active' : ''; ?>" title="Team Management" data-bs-toggle="tooltip" data-bs-placement="right" aria-current="<?php echo $isActive ? 'page' : 'false'; ?>">
            <span class="nav-icon"><i class="fas fa-users-cog"></i></span>
            <span>Team Management</span>
          </a>
        </div>
      <?php endif; ?>

    </nav>
  </div>

  <?php if ($role === 'admin'): ?>
  <div class="sidebar-section">
    <div class="sidebar-divider"></div>
    <h5 class="sidebar-section-title">Administration</h5>
    <nav class="sidebar-nav" role="navigation" aria-label="Administration">
      <div class="nav-item">
        <?php $isActive = ($current_page === 'manage_users.php'); ?>
        <a href="manage_users.php" class="nav-link <?php echo $isActive ? 'active' : ''; ?>" title="Manage Users" data-bs-toggle="tooltip" data-bs-placement="right" aria-current="<?php echo $isActive ? 'page' : 'false'; ?>">
          <span class="nav-icon"><i class="fas fa-user-tie"></i></span>
          <span>Manage Users</span>
        </a>
      </div>
    </nav>
  </div>
  <?php endif; ?>

</aside>


