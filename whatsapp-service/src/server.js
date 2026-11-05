'use strict';

require('dotenv').config();

const express = require('express');
const pool = require('./config/database');
const { client, initialize, destroy, getState, getQrPayload } = require('./whatsapp/client');
const { startNotificationWorker } = require('./workers/notificationWorker');

const PORT = Number(process.env.PORT) || 3001;
const HEALTH_TOKEN = process.env.HEALTH_TOKEN || '';
const SERVICE_NAME = 'sbms-whatsapp-service';

/** @type {{stop: () => Promise<void>, isRunning: () => boolean}|null} */
let worker = null;
let shuttingDown = false;

const app = express();

/**
 * Health is read by the PHP admin page, so it is gated: the payload exposes
 * parent phone numbers and queue volumes. Unset HEALTH_TOKEN disables the check.
 */
function requireHealthToken(req, res, next) {
  if (!HEALTH_TOKEN) return next();
  // The linking page is opened in a browser, which cannot set custom headers,
  // so it passes the same token in the query string.
  if (req.get('x-health-token') === HEALTH_TOKEN) return next();
  if (req.query.token === HEALTH_TOKEN) return next();
  return res.status(401).json({ error: 'invalid or missing x-health-token header' });
}

app.get('/health', requireHealthToken, async (req, res) => {
  const state = getState();
  let queue = null;
  let dbError = null;

  try {
    const [rows] = await pool.query(
      `SELECT status, COUNT(*) AS count FROM notification_queue GROUP BY status`
    );
    queue = rows.reduce((acc, row) => {
      acc[row.status] = Number(row.count);
      return acc;
    }, {});
  } catch (err) {
    dbError = err.message;
  }

  res.status(state.ready && worker && worker.isRunning() ? 200 : 503).json({
    service: SERVICE_NAME,
    whatsapp: state,
    workerRunning: Boolean(worker && worker.isRunning()),
    queue,
    dbError
  });
});

// Browsers cannot set the x-health-token header on a normal page load, so the
// linking page carries the token in its URL and forwards it to /qr.json.
app.get('/qr', requireHealthToken, (req, res) => {
  const token = typeof req.query.token === 'string' ? req.query.token : '';
  res.set('Cache-Control', 'no-store');
  res.type('html').send(`<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Link WhatsApp | SBMS</title>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1.25rem;
         background: #07111f; color: #eaf7ff; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
  main { width: min(28rem, 100%); background: #0e1c30; border: 1px solid #1e2a78; border-radius: 1.25rem;
         padding: 1.5rem; text-align: center; }
  h1 { margin: 0 0 .35rem; font-size: 1.25rem; }
  p { margin: 0 0 1rem; color: #9fb3c8; font-size: .9rem; line-height: 1.5; }
  #frame { min-height: 20rem; display: grid; place-items: center; background: #fff; border-radius: .9rem; padding: .75rem; }
  #frame img { width: 100%; max-width: 20rem; height: auto; display: block; }
  #msg { color: #9fb3c8; font-size: .9rem; }
  .ok { color: #22c55e; font-weight: 600; }
  .steps { text-align: left; margin: 1rem 0 0; padding-left: 1.2rem; color: #c7d7e6; font-size: .85rem; line-height: 1.7; }
</style>
</head>
<body>
<main>
  <h1>Link the school phone</h1>
  <p>Open WhatsApp on the school phone &rarr; Settings &rarr; Linked Devices &rarr; Link a Device, then scan this code.</p>
  <div id="frame"><span id="msg">Waiting for a QR code...</span></div>
  <ol class="steps">
    <li>Keep this page open; the code refreshes automatically.</li>
    <li>The code expires after about 20 seconds. If it fails, scan the next one.</li>
    <li>Scan it once — the session is saved for future starts.</li>
  </ol>
</main>
<script>
  var token = ${JSON.stringify(token)};
  var lastAt = null;
  var timer = null;
  function refresh() {
    fetch('/qr.json' + (token ? '?token=' + encodeURIComponent(token) : ''), { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.dataUrl) return;
        if (data.at === lastAt) return;
        lastAt = data.at;
        document.getElementById('frame').innerHTML =
          '<img alt="WhatsApp linking QR code" src="' + data.dataUrl + '">';
      })
      .catch(function () {});
  }
  function checkReady() {
    fetch('/health' + (token ? '?token=' + encodeURIComponent(token) : ''), { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.whatsapp && data.whatsapp.authenticated) {
          clearInterval(timer);
          document.getElementById('frame').innerHTML =
            '<span class="ok">Phone linked. You can close this page.</span>';
        }
      })
      .catch(function () {});
  }
  refresh();
  timer = setInterval(function () { refresh(); checkReady(); }, 3000);
</script>
</body>
</html>`);
});

app.get('/qr.json', requireHealthToken, (req, res) => {
  const qr = getQrPayload();
  res.set('Cache-Control', 'no-store');
  if (!qr) return res.status(404).json({ error: 'no QR available yet' });
  return res.json(qr);
});

// No POST /health/ping: nothing on the PHP side uses it, and an unauthenticated
// write endpoint would only add surface area.

/**
 * @returns {Promise<void>}
 */
async function boot() {
  app.listen(PORT, () => {
    console.log(`[http] listening on http://localhost:${PORT}/health`);
  });

  client.once('ready', () => {
    if (worker) return;
    startNotificationWorker()
      .then((handle) => {
        worker = handle;
      })
      .catch((err) => {
        console.error('[worker] failed to start:', err.message);
      });
  });

  await initialize();
}

/**
 * Stops the worker mid-send gracefully, then releases the browser and pool.
 * @returns {Promise<void>}
 */
async function shutdown(signal) {
  if (shuttingDown) return;
  shuttingDown = true;
  console.log(`[http] ${signal} received, shutting down`);

  try {
    if (worker) await worker.stop();
    await destroy();
    await pool.end();
  } catch (err) {
    console.error('[http] shutdown error:', err.message);
  } finally {
    process.exit(0);
  }
}

process.on('SIGINT', () => {
  shutdown('SIGINT');
});
process.on('SIGTERM', () => {
  shutdown('SIGTERM');
});
process.on('unhandledRejection', (reason) => {
  console.error('[http] unhandled rejection:', reason);
});

boot().catch((err) => {
  console.error('[http] failed to boot:', err.message);
  process.exitCode = 1;
});
