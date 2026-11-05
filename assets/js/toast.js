/**
 * SBMS toast notifications.
 *
 * Usage from any page script:
 *   SBMS.toast('Saved', 'success');
 *   SBMS.toast('Something failed', { type: 'error', duration: 8000 });
 *
 * Pages that redirect with ?success= or ?error= get a toast automatically
 * unless they already render an inline flash message.
 */
(function () {
    'use strict';

    var REGION_ID = 'sbmsToastRegion';
    var MAX_VISIBLE = 4;
    var DEFAULT_DURATION = 5000;

    var ICONS = {
        success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/></svg>',
        error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><path d="M12 16.5h.01"/></svg>',
        warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 4.3 2.7 17a2 2 0 0 0 1.7 3h15.2a2 2 0 0 0 1.7-3L13.7 4.3a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
        info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/></svg>'
    };

    function region() {
        var el = document.getElementById(REGION_ID);
        if (!el) {
            el = document.createElement('div');
            el.id = REGION_ID;
            el.className = 'sbms-toast-region';
            el.setAttribute('role', 'region');
            el.setAttribute('aria-label', 'Notifications');
            document.body.appendChild(el);
        }
        return el;
    }

    function dismiss(toast) {
        if (!toast || toast.classList.contains('is-leaving')) {
            return;
        }
        toast.classList.add('is-leaving');
        window.setTimeout(function () {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 180);
    }

    function show(message, options) {
        if (options === undefined || options === null) {
            options = {};
        } else if (typeof options === 'string') {
            options = { type: options };
        }

        var type = options.type || 'info';
        if (!ICONS[type]) {
            type = 'info';
        }
        var duration = typeof options.duration === 'number' ? options.duration : DEFAULT_DURATION;

        var host = region();
        var toast = document.createElement('div');
        toast.className = 'sbms-toast sbms-toast--' + type;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        toast.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');

        var icon = document.createElement('span');
        icon.className = 'sbms-toast__icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = ICONS[type];

        var text = document.createElement('span');
        text.className = 'sbms-toast__message';
        text.textContent = String(message);

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'sbms-toast__close';
        close.setAttribute('aria-label', 'Dismiss notification');
        close.innerHTML = '&times;';
        close.addEventListener('click', function () {
            dismiss(toast);
        });

        toast.appendChild(icon);
        toast.appendChild(text);
        toast.appendChild(close);
        host.appendChild(toast);

        while (host.children.length > MAX_VISIBLE) {
            dismiss(host.firstElementChild);
        }

        if (duration > 0) {
            var timer = window.setTimeout(function () {
                dismiss(toast);
            }, duration);

            toast.addEventListener('mouseenter', function () {
                window.clearTimeout(timer);
            });

            toast.addEventListener('focusin', function () {
                window.clearTimeout(timer);
            });
        }

        return toast;
    }

    window.SBMS = window.SBMS || {};
    window.SBMS.toast = show;
    window.SBMS.dismissToast = dismiss;

    function cleanUrl(keys) {
        if (!window.history || !window.history.replaceState) {
            return;
        }
        var url = new URL(window.location.href);
        keys.forEach(function (key) {
            url.searchParams.delete(key);
        });
        var next = url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') + url.hash;
        window.history.replaceState(null, '', next);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var params = new URLSearchParams(window.location.search);
        var success = (params.get('success') || '').trim();
        var error = (params.get('error') || '').trim();

        if (!success && !error) {
            return;
        }

        // Pages that already render an inline flash keep their own treatment.
        var inline = document.querySelector('.alert, .feedback, .queue-flash, .table-feedback, [data-flash-inline]');
        if (inline) {
            return;
        }

        if (error) {
            show(error, { type: 'error', duration: 8000 });
        } else {
            show(success, { type: 'success' });
        }

        cleanUrl(['success', 'error']);
    });
})();
