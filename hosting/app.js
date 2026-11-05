(function () {
    'use strict';

    var config = window.SBMS_CONFIG || {};
    var backend = String(config.backendUrl || '').replace(/\/+$/, '');
    var backendReady = /^https?:\/\//i.test(backend);
    var timer = null;

    var els = {
        app: document.getElementById('open-app'),
        queue: document.getElementById('open-queue'),
        queueFooter: document.getElementById('open-queue-footer'),
        help: document.getElementById('open-help'),
        setup: document.getElementById('setup-hint'),
        updated: document.getElementById('status-updated'),
        appPill: document.getElementById('app-pill'),
        appDetail: document.getElementById('app-detail'),
        waPill: document.getElementById('wa-pill'),
        waDetail: document.getElementById('wa-detail')
    };

    function backendUrl(path) {
        return backend + (path || '');
    }

    function setPill(el, state, label) {
        if (!el) return;
        el.dataset.state = state;
        el.textContent = label;
    }

    function setLinks() {
        var queueUrl = backendReady ? backendUrl(config.queuePath) : '';
        var helpUrl = backendReady ? backendUrl(config.helpPath) : '';

        if (backendReady) {
            els.app.href = backend;
            els.queue.href = queueUrl;
            els.queueFooter.href = queueUrl;
            els.help.href = helpUrl;
        } else {
            [els.app, els.queue, els.queueFooter, els.help].forEach(function (link) {
                if (link) {
                    link.href = '#';
                    link.setAttribute('aria-disabled', 'true');
                }
            });
            if (els.setup) els.setup.hidden = false;
        }
    }

    async function fetchJson(url, timeoutMs) {
        var controller = new AbortController();
        var timeout = setTimeout(function () {
            controller.abort();
        }, timeoutMs || 6000);

        try {
            var response = await fetch(url, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                signal: controller.signal
            });
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return await response.json();
        } finally {
            clearTimeout(timeout);
        }
    }

    function stamp() {
        if (!els.updated) return;
        els.updated.textContent = 'Checked ' + new Intl.DateTimeFormat(undefined, {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        }).format(new Date());
    }

    async function refresh() {
        if (!backendReady) {
            setPill(els.appPill, 'idle', 'Not configured');
            setPill(els.waPill, 'idle', 'Not configured');
            if (els.appDetail) els.appDetail.textContent = 'Set backendUrl in config.js';
            if (els.waDetail) els.waDetail.textContent = 'Set backendUrl in config.js';
            if (els.updated) els.updated.textContent = 'Waiting for configuration';
            return;
        }

        var healthTask = fetchJson(backendUrl(config.healthPath), 5000).catch(function () {
            return null;
        });
        var whatsappTask = fetchJson(backendUrl(config.statusPath), 6000).catch(function () {
            return null;
        });

        var results = await Promise.all([healthTask, whatsappTask]);
        var health = results[0];
        var whatsapp = results[1];

        if (health && health.status === 'ok') {
            setPill(els.appPill, 'ok', 'Online');
            if (els.appDetail) {
                els.appDetail.textContent = health.environment
                    ? 'PHP API · ' + health.environment
                    : 'PHP API';
            }
        } else {
            setPill(els.appPill, 'down', 'Offline');
            if (els.appDetail) els.appDetail.textContent = 'Backend did not respond';
        }

        if (!whatsapp) {
            setPill(els.waPill, 'down', 'Offline');
            if (els.waDetail) els.waDetail.textContent = 'Status endpoint unavailable';
        } else if (whatsapp.state === 'ready') {
            setPill(els.waPill, 'ok', 'Linked');
            if (els.waDetail) els.waDetail.textContent = 'School phone is connected';
        } else if (whatsapp.state === 'waiting') {
            setPill(els.waPill, 'warn', 'Waiting');
            if (els.waDetail) els.waDetail.textContent = 'Worker online · scan the QR code';
        } else if (whatsapp.state === 'unconfigured') {
            setPill(els.waPill, 'idle', 'Not configured');
            if (els.waDetail) els.waDetail.textContent = 'Set SBMS_WHATSAPP_URL on the backend';
        } else {
            setPill(els.waPill, 'down', 'Offline');
            if (els.waDetail) els.waDetail.textContent = 'WhatsApp worker is not reachable';
        }

        stamp();
    }

    function schedule() {
        if (timer) clearInterval(timer);
        var interval = Math.max(10000, Number(config.statusIntervalMs) || 30000);
        timer = setInterval(refresh, interval);
    }

    async function initAnalytics() {
        try {
            // Firebase Hosting serves the project's public web config here, so no
            // API key is committed and local previews simply skip analytics.
            var configResponse = await fetch('/__/firebase/init.json', { cache: 'no-store' });
            if (!configResponse.ok) return;

            var firebaseConfig = await configResponse.json();
            var appModule = await import('https://www.gstatic.com/firebasejs/13.2.0/firebase-app.js');
            var analyticsModule = await import('https://www.gstatic.com/firebasejs/13.2.0/firebase-analytics.js');
            var app = appModule.initializeApp(firebaseConfig);
            await analyticsModule.getAnalytics(app);
        } catch (error) {
            // Analytics is optional; never block the entry page on it.
            console.warn('[sbms] Firebase Analytics unavailable', error);
        }
    }

    setLinks();
    refresh();
    schedule();

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            if (timer) clearInterval(timer);
            timer = null;
        } else {
            refresh();
            schedule();
        }
    });

    initAnalytics();
})();
