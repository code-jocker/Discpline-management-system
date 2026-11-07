/**
 * SBMS toast notifications (lightweight, no dependencies)
 */
(function () {
  function ensureContainer() {
    var c = document.querySelector('.toast-container');
    if (!c) {
      c = document.createElement('div');
      c.className = 'toast-container';
      c.setAttribute('aria-live', 'polite');
      c.setAttribute('aria-atomic', 'true');
      document.body.appendChild(c);
    }
    return c;
  }

  function toast(message, type, duration) {
    if (!message) return;
    type = type || 'info';
    duration = typeof duration === 'number' ? duration : 4000;
    var container = ensureContainer();
    var el = document.createElement('div');
    el.className = 'toast toast-' + type;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.textContent = String(message);
    container.appendChild(el);
    requestAnimationFrame(function () { el.classList.add('show'); });
    setTimeout(function () {
      el.classList.remove('show');
      setTimeout(function () { el.remove(); }, 220);
    }, duration);
  }

  function fromQuery() {
    try {
      var params = new URLSearchParams(window.location.search);
      var ok = params.get('success');
      var err = params.get('error');
      if (ok) toast(decodeURIComponent(ok.replace(/\+/g, ' ')), 'success');
      if (err) toast(decodeURIComponent(err.replace(/\+/g, ' ')), 'error');
    } catch (e) {}
  }

  window.sbmsToast = toast;
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fromQuery);
  } else {
    fromQuery();
  }
})();
