/* Main JS behaviors: interactivity at UI handlers. */

(function(){
  const initApp = () => {
  const sidebar = document.getElementById('sidebar');
  const sidebarToggle = document.getElementById('sidebarToggle');
  const mainContent = document.querySelector('.main-content');
  const header = document.querySelector('.header');
  const skipLink = document.querySelector('.skip-link');
  if (skipLink && mainContent) { skipLink.addEventListener('click', () => { mainContent.focus(); }); }

  if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    document.documentElement.style.scrollBehavior = 'smooth';
  }

  const isMobile = () => window.matchMedia('(max-width: 991.98px)').matches;

  let sidebarOverlay = document.getElementById('sidebarOverlay');
  if (!sidebarOverlay) {
    sidebarOverlay = document.createElement('div');
    sidebarOverlay.id = 'sidebarOverlay';
    sidebarOverlay.className = 'sidebar-overlay';
    document.body.appendChild(sidebarOverlay);
  }

  const toggleSidebar = () => {
    if (!sidebar) return;
    if (isMobile()) {
      const willShow = !sidebar.classList.contains('show');
      sidebar.classList.toggle('show', willShow);
      sidebarOverlay.classList.toggle('show', willShow);
      document.body.classList.toggle('sidebar-open', willShow);
      sidebarToggle?.setAttribute('aria-expanded', willShow ? 'true' : 'false');
    } else {
      const willCollapse = !document.body.classList.contains('sidebar-collapsed');
      document.body.classList.toggle('sidebar-collapsed', willCollapse);
      sidebarToggle?.setAttribute('aria-expanded', willCollapse ? 'true' : 'false');
    }
  };

  const closeSidebar = () => {
    if (!sidebar) return;
    sidebar.classList.remove('show');
    sidebarOverlay.classList.remove('show');
    document.body.classList.remove('sidebar-open');
  };

  const handleResize = () => {
    if (!mainContent || !header) return;
    if (!isMobile()) {

      sidebar.classList.remove('show');
      sidebarOverlay.classList.remove('show');
      document.body.classList.remove('sidebar-open');
    }
  };

  if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
  if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);



  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && isMobile() && sidebar?.classList.contains('show')) {
      closeSidebar();
    }
  });

  document.addEventListener('hidden.bs.modal', () => {
    if (!document.querySelector('.modal.show')) {
      document.body.classList.remove('modal-open');
      document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
    }
  });

  let resizeScheduled = false;
  window.addEventListener('resize', () => {
    if (resizeScheduled) return;
    resizeScheduled = true;
    requestAnimationFrame(() => { handleResize(); resizeScheduled = false; });
  });
  handleResize();

  setTimeout(() => {
    const anyModal = document.querySelector('.modal.show');
    if (!anyModal) {
      document.body.classList.remove('modal-open');
      document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
    }
  }, 0);

  const currentPage = window.location.pathname.split('/').pop() || 'index.php';
  document.querySelectorAll('.nav-link').forEach(link => {
    if (link.getAttribute('href') === currentPage) {
      link.classList.add('active');
    }
  });

  if (window.bootstrap?.Tooltip) {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
      new bootstrap.Tooltip(el);
    });
  }

  const badgeNotif = document.getElementById('badgeNotifications');
  const badgeMsg = document.getElementById('badgeMessages');
  const updateBadge = (el, count) => {
    if (!el) return;
    const n = Number(count) || 0;
    el.textContent = String(n);
    el.style.display = n > 0 ? '' : 'none';
  };
  const fetchCounts = async () => {
    try {
      const res = await fetch('realtime_counts.php', { headers: { 'Accept': 'application/json' } });
      if (!res.ok) return;
      const data = await res.json();
      updateBadge(badgeNotif, data?.notifications ?? 0);
      updateBadge(badgeMsg, data?.messages ?? 0);
    } catch (_) { }
  };
  fetchCounts();
  setInterval(fetchCounts, 15000);

  const notifToggle = document.getElementById('headerNotifications');
  const notifList = document.getElementById('notificationsMenuList');
  const notifMarkAllBtn = document.getElementById('notifMarkAllBtn');
  const renderNotifications = (items) => {
    if (!notifList) return;
    if (!items || items.length === 0) {
      notifList.innerHTML = '<div class="px-3 small text-muted">No notifications.</div>';
      return;
    }
    const rows = items.map(n => {
      const unread = !n.read;
      const safeTitle = (n.title || '').replace(/[<>]/g, '');
      const safeDesc = (n.description || '').replace(/[<>]/g, '');
      const safeTime = (n.timestamp || '').replace(/[<>]/g, '');
      return `
        <div class="px-3 py-2 ${unread ? 'bg-info bg-opacity-10' : ''}" role="option" tabindex="-1" data-notif-id="${n.id}">
          <div class="d-flex justify-content-between align-items-start">
            <div class="me-2" style="min-width:0">
              <div class="fw-medium text-truncate">${safeTitle}</div>
              <div class="small text-muted text-truncate">${safeDesc}</div>
              <div class="small text-muted">${safeTime}</div>
            </div>
            ${unread ? `<button class="btn btn-sm btn-link" data-action="mark-read" data-id="${n.id}">Mark read</button>` : '<span class="small text-muted">Read</span>'}
          </div>
        </div>
      `;
    }).join('');
    notifList.innerHTML = rows;
  };
  const fetchNotifications = async () => {
    if (!window.fetch) return;
    try {
      if (notifList) { notifList.innerHTML = '<div class="px-3 small text-muted">Loading…</div>'; }
      const res = await fetch('notifications.php?limit=20', { headers: { 'Accept': 'application/json' } });
      if (!res.ok) throw new Error('load_failed');
      const data = await res.json();
      renderNotifications(Array.isArray(data) ? data : []);
    } catch (err) {
      if (notifList) { notifList.innerHTML = '<div class="px-3 small text-danger">Failed to load notifications.</div>'; }
    }
  };
  const markRead = async (id) => {
    try {
      const form = new FormData();
      form.append('action', 'mark_read');
      form.append('id', String(id));
      const res = await fetch('notifications.php', { method: 'POST', body: form });
      if (!res.ok) throw new Error('mark_failed');
      await fetchNotifications();
      await fetchCounts();
    } catch (_) {}
  };
  const clearRead = async () => {
    try {
      const form = new FormData();
      form.append('action', 'clear_read');
      const res = await fetch('notifications.php', { method: 'POST', body: form });
      if (!res.ok) throw new Error('clear_failed');
      await fetchNotifications();
      await fetchCounts();
    } catch (_) {}
  };
  if (notifToggle) {
    notifToggle.addEventListener('show.bs.dropdown', fetchNotifications);
    if (notifMarkAllBtn) {
      notifMarkAllBtn.addEventListener('click', (e) => { e.preventDefault(); clearRead(); });
    }
    document.addEventListener('click', (e) => {
      const t = e.target;
      if (!(t instanceof HTMLElement)) return;
      const action = t.getAttribute('data-action');
      if (action === 'mark-read') {
        e.preventDefault();
        const id = parseInt(t.getAttribute('data-id') || '0', 10);
        if (id) markRead(id);
      }
    });

    notifToggle.addEventListener('shown.bs.dropdown', () => {
      const items = Array.from(notifList?.querySelectorAll('[data-notif-id]') || []);
      let ix = 0;
      const focusItem = () => { items[ix]?.focus(); };
      focusItem();
      const keyHandler = (ev) => {
        if (['ArrowDown','ArrowUp'].includes(ev.key)) {
          ev.preventDefault();
          ix = ev.key === 'ArrowDown' ? Math.min(ix+1, items.length-1) : Math.max(ix-1, 0);
          focusItem();
        } else if (ev.key === 'Enter') {
          const btn = items[ix]?.querySelector('[data-action="mark-read"]');
          btn?.click();
        } else if (ev.key === 'Escape') {
          const dropdown = bootstrap?.Dropdown?.getOrCreateInstance(notifToggle);
          dropdown?.hide();
        }
      };
      notifList?.addEventListener('keydown', keyHandler);
      notifToggle.addEventListener('hidden.bs.dropdown', () => {
        notifList?.removeEventListener('keydown', keyHandler);
      }, { once: true });
    });
  }

  const forms = [
    'newProjectForm','newTaskForm','newRequestForm','commentForm','timeLogForm','blockForm'
  ];
  forms.forEach(id => {
    const form = document.getElementById(id);
    if (!form) return;
    form.addEventListener('submit', function(e){
      if (!form.checkValidity()) {
        e.preventDefault();
        e.stopPropagation();
      }
      form.classList.add('was-validated');
    });
  });

  const setOverlayAria = (visible) => {
    sidebarOverlay.setAttribute('aria-hidden', visible ? 'false' : 'true');
  };
  sidebarOverlay.addEventListener('transitionend', ()=>{
    setOverlayAria(sidebarOverlay.classList.contains('show'));
  });
  setOverlayAria(false);

  const kanbanBoard = document.getElementById('kanbanBoard');
  const colSelector = (st) => document.getElementById(`col-${st}`);
  const moveItemDom = (item, newStatus) => {
    const dest = colSelector(newStatus);
    if (!dest || !item) return;

    const placeholder = dest.querySelector('.text-muted.small');
    if (placeholder && placeholder.textContent?.toLowerCase().includes('no tasks')) {
      placeholder.remove();
    }
    item.setAttribute('data-status', newStatus);
    dest.appendChild(item);
  };
  const showInlineSpinner = (btn) => {
    if (!btn) return () => {};
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Updating…';
    return () => { btn.disabled = false; btn.textContent = orig; };
  };

  document.addEventListener('click', async (e) => {
    const target = e.target;
    if (!(target instanceof HTMLElement)) return;
    const action = target.getAttribute('data-action');
    if (action !== 'move') return;
    const taskId = parseInt(target.getAttribute('data-taskid') || '0', 10);
    const newStatus = target.getAttribute('data-status') || '';
    const item = target.closest('.kanban-item');
    if (!taskId || !newStatus || !item) return;
    if (!window.fetch) return; // allow server POST fallback
    e.preventDefault();
    e.stopPropagation();
    const restore = showInlineSpinner(target);
    try {
      const form = document.getElementById('taskStatusForm');
      const idInput = document.getElementById('taskStatusTaskId');
      const statusInput = document.getElementById('taskStatusNew');
      if (!form || !idInput || !statusInput) throw new Error('no form');
      idInput.value = String(taskId);
      statusInput.value = String(newStatus);
      form.submit();
    } catch (err) {
      alert('Failed to update status. Please try again.');
    } finally {
      restore();
    }
  }, true);

  if (kanbanBoard) {
    kanbanBoard.addEventListener('drop', async (e) => {
      const col = e.target.closest('.kanban-column');
      if (!col) return;
      const newStatus = col.getAttribute('data-status') || '';
      const id = e.dataTransfer?.getData('text/plain');
      if (!id || !newStatus || !window.fetch) return;
      e.preventDefault();

      const item = kanbanBoard.querySelector(`.kanban-item[data-id="${id}"]`);
      try {
        const form = document.getElementById('taskStatusForm');
        const idInput = document.getElementById('taskStatusTaskId');
        const statusInput = document.getElementById('taskStatusNew');
        if (!form || !idInput || !statusInput) return;
        idInput.value = String(parseInt(id, 10));
        statusInput.value = String(newStatus);
        form.submit();
      } catch (err) {
        alert('Failed to update status. Please try again.');
      }
    }, true);
  }
  };
  if (document.readyState !== 'loading') {
    initApp();
  } else {
    document.addEventListener('DOMContentLoaded', initApp);
  }
})();
