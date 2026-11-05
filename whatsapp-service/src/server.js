'use strict';

require('dotenv').config();

const express = require('express');
const pool = require('./config/database');
const { client, initialize, destroy, getState } = require('./whatsapp/client');
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
  if (req.get('x-health-token') === HEALTH_TOKEN) return next();
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
