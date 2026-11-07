/**
 * SBMS mobile sidebar toggle
 */
(function () {
  function init() {
    var sidebar = document.querySelector('.sidebar, #sidebar, aside.sidebar');
    var toggle = document.querySelector('[data-sidebar-toggle], .sidebar-toggle, #sidebarToggle');
    if (!sidebar || !toggle) return;

    toggle.addEventListener('click', function (e) {
      e.preventDefault();
      sidebar.classList.toggle('is-open');
      document.body.classList.toggle('sidebar-open');
      toggle.setAttribute('aria-expanded', sidebar.classList.contains('is-open') ? 'true' : 'false');
    });

    document.addEventListener('click', function (e) {
      if (!document.body.classList.contains('sidebar-open')) return;
      if (sidebar.contains(e.target) || toggle.contains(e.target)) return;
      sidebar.classList.remove('is-open');
      document.body.classList.remove('sidebar-open');
      toggle.setAttribute('aria-expanded', 'false');
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
        sidebar.classList.remove('is-open');
        document.body.classList.remove('sidebar-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.focus();
      }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
