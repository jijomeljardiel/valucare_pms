/* File script.js: bahagi ng ValuCare PMS app. */



document.addEventListener('DOMContentLoaded', function() {
  const sidebar = document.getElementById('sidebar');
  const sidebarToggle = document.getElementById('sidebarToggle');
  let sidebarOverlay = document.getElementById('sidebarOverlay') || document.querySelector('.sidebar-overlay');
  const mainContent = document.querySelector('.main-content');
  const header = document.querySelector('.header');

  const isMobile = () => window.innerWidth < 992;

  const toggleSidebar = () => {
    if (sidebar) sidebar.classList.toggle('show');
    if (sidebarOverlay) sidebarOverlay.classList.toggle('show');
    document.body.classList.toggle('sidebar-open');

    if (!isMobile()) {
      document.body.classList.toggle('sidebar-collapsed');
    }
  };

  const closeSidebar = () => {
    if (sidebar && sidebar.classList.contains('show')) {
      sidebar.classList.remove('show');
      if (sidebarOverlay) sidebarOverlay.classList.remove('show');
      document.body.classList.remove('sidebar-open');
      document.body.classList.remove('sidebar-collapsed');
    } else if (sidebarOverlay && sidebarOverlay.classList.contains('show')) {
      sidebarOverlay.classList.remove('show');
      document.body.classList.remove('sidebar-open');
      document.body.classList.remove('sidebar-collapsed');
    }
  };

  const handleResize = () => {
    if (!isMobile()) {
      if (sidebar) sidebar.classList.remove('show');
      if (sidebarOverlay) sidebarOverlay.classList.remove('show');
      document.body.classList.remove('sidebar-open');
      document.body.classList.remove('sidebar-collapsed'); // Ensure it's not collapsed on desktop by default

      if (mainContent) mainContent.style.removeProperty('margin-left');
      if (header) header.style.removeProperty('left');
    } else {

      if (mainContent) mainContent.style.removeProperty('margin-left');
      if (header) header.style.removeProperty('left');
    }
  };

  if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
  if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', closeSidebar);
  } else if (sidebar) {
    const overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    overlay.id = 'sidebarOverlay';
    document.body.appendChild(overlay);
    sidebarOverlay = overlay;
    overlay.addEventListener('click', closeSidebar);
  }

  document.addEventListener('click', function(event) {
    if (
      isMobile() && sidebar && sidebar.classList.contains('show') &&
      !sidebar.contains(event.target) &&
      (!sidebarToggle || (event.target !== sidebarToggle && !sidebarToggle.contains(event.target)))
    ) {
      closeSidebar();
      document.body.classList.remove('sidebar-collapsed');
    }
  });

  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape' && isMobile() && sidebar && sidebar.classList.contains('show')) {
      closeSidebar();
    }
  });

  window.addEventListener('resize', handleResize);
  handleResize();

  const currentPage = window.location.pathname.split('/').pop() || 'index.php';
  const navLinks = document.querySelectorAll('.nav-link');
  navLinks.forEach(link => {
    const linkHref = link.getAttribute('href');
    if (linkHref === currentPage) link.classList.add('active');
  });

  if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
      return new bootstrap.Tooltip(tooltipTriggerEl);
    });
  }
});