import 'dotenv/config';
import fs from 'node:fs';
import http from 'node:http';
import mysql from 'mysql2/promise';
import qrcode from 'qrcode-terminal';
import pkg from 'whatsapp-web.js';

const { Client, LocalAuth } = pkg;

const config = {
  db: {
    host: process.env.DB_HOST || 'localhost',
    port: Number(process.env.DB_PORT || 3306),
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'sbms',
    ssl: String(process.env.DB_SSL || 'false').toLowerCase() === 'true',
    sslCa: process.env.DB_SSL_CA || '',
  },
  worker: {
    port: Number(process.env.WORKER_PORT || 3100),
    host: process.env.WORKER_HOST || '0.0.0.0',
    authToken: process.env.WORKER_AUTH_TOKEN || '',
  },
  wa: {
    sessionPath: process.env.WHATSAPP_SESSION_PATH || '.wwebjs_auth',
    clientId: process.env.WHATSAPP_CLIENT_ID || 'sbms',
  },
  pollIntervalMs: Number(process.env.POLL_INTERVAL_MS || 5000),
  batchSize: Math.max(1, Math.min(50, Number(process.env.BATCH_SIZE || 10))),
  maxAttempts: Math.max(1, Number(process.env.MAX_ATTEMPTS || 5)),
  backoffBase: Math.max(5, Number(process.env.BACKOFF_BASE_SECONDS || 30)),
  backoffMax: Math.max(60, Number(process.env.BACKOFF_MAX_SECONDS || 3600)),
};

const state = {
  startedAt: new Date().toISOString(),
  whatsappReady: false,
  whatsappState: 'initializing',
  lastQrAt: null,
  lastPollAt: null,
  lastError: null,
  processed: 0,
  sent: 0,
  failed: 0,
};

let pool;
let waClient;
let processing = false;
let shuttingDown = false;

function log(level, msg, extra = undefined) {
  const line = `[${new Date().toISOString()}] [${level}] ${msg}`;
  if (extra !== undefined) console[level === 'error' ? 'error' : 'log'](line, extra);
  else console[level === 'error' ? 'error' : 'log'](line);
}

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

function normalizePhone(raw) {
  const digits = String(raw || '').replace(/\D+/g, '');
  if (!digits) return null;
  if (digits.startsWith('00')) return digits.slice(2);
  return digits;
}

function toChatId(phone) {
  const n = normalizePhone(phone);
  if (!n) throw new Error('Invalid recipient phone');
  return `${n}@c.us`;
}

function backoffSeconds(attempts) {
  const sec = Math.min(config.backoffMax, config.backoffBase * Math.pow(2, Math.max(0, attempts - 1)));
  // small jitter
  return sec + Math.floor(Math.random() * 5);
}

async function createPool() {
  const ssl = config.db.ssl
    ? {
        rejectUnauthorized: true,
        ...(config.db.sslCa && fs.existsSync(config.db.sslCa)
          ? { ca: fs.readFileSync(config.db.sslCa, 'utf8') }
          : {}),
      }
    : undefined;
  pool = mysql.createPool({
    host: config.db.host,
    port: config.db.port,
    user: config.db.user,
    password: config.db.password,
    database: config.db.database,
    waitForConnections: true,
    connectionLimit: 5,
    queueLimit: 0,
    enableKeepAlive: true,
    ...(ssl ? { ssl } : {}),
  });
  await pool.query('SELECT 1');
  log('info', `MySQL connected ${config.db.host}:${config.db.port}/${config.db.database} ssl=${config.db.ssl}`);
}

async function logNotification(job, status, response, errorMsg) {
  try {
    // Compatible with migration_whatsapp_queue.sql: (queue_id, parent_phone, message, status, error_message)
    await pool.query(
      `INSERT INTO notification_logs (queue_id, parent_phone, message, status, error_message) VALUES (?, ?, ?, ?, ?)`,
      [
        job.id,
        job.parent_phone || null,
        job.message ? String(job.message).slice(0, 65535) : null,
        status,
        errorMsg ? String(errorMsg).slice(0, 65535) : null,
      ]
    );
  } catch (e) {
    // fallback for alternate schemas
    try {
      await pool.query(
        `INSERT INTO notification_logs (queue_id, status, provider_response, error_message) VALUES (?, ?, ?, ?)`,
        [job.id, status, response ? String(response).slice(0, 65535) : null, errorMsg ? String(errorMsg).slice(0, 65535) : null]
      );
    } catch (e2) {
      log('error', `Failed to write notification_logs for queue ${job.id}`, e2.message);
    }
  }
}

async function claimJobs() {
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    // Schema compatible with WhatsAppQueueService + migration_whatsapp_queue.sql
    // (parent_phone, next_attempt_at, max_attempts). Tolerate schemas without channel.
    const [rows] = await conn.query(
      `SELECT * FROM notification_queue
       WHERE status IN ('pending','retry')
         AND COALESCE(next_attempt_at, scheduled_at, NOW()) <= NOW()
         AND attempts < COALESCE(max_attempts, ?)
       ORDER BY COALESCE(next_attempt_at, scheduled_at, created_at) ASC, id ASC
       LIMIT ?
       FOR UPDATE SKIP LOCKED`,
      [config.maxAttempts, config.batchSize]
    );
    if (!rows.length) {
      await conn.commit();
      return [];
    }
    const ids = rows.map((r) => r.id);
    await conn.query(
      `UPDATE notification_queue
          SET status = 'processing',
              locked_at = NOW(),
              last_attempt_at = NOW(),
              attempts = attempts + 1
        WHERE id IN (${ids.map(() => '?').join(',')})`,
      ids
    ).catch(async () => {
      // schema without locked_at/last_attempt_at
      await conn.query(
        `UPDATE notification_queue SET status = 'processing', attempts = attempts + 1 WHERE id IN (${ids.map(() => '?').join(',')})`,
        ids
      );
    });
    await conn.commit();
    return rows.map((r) => ({ ...r, attempts: Number(r.attempts || 0) + 1 }));
  } catch (e) {
    await conn.rollback();
    // Fallback for MySQL without SKIP LOCKED or missing cols
    if (String(e.message || '').includes('SKIP LOCKED') || String(e.code) === 'ER_BAD_FIELD_ERROR') {
      return claimJobsFallback();
    }
    throw e;
  } finally {
    conn.release();
  }
}

async function claimJobsFallback() {
  const [rows] = await pool.query(
    `SELECT * FROM notification_queue
     WHERE status IN ('pending','retry')
       AND attempts < ?
     ORDER BY id ASC LIMIT ?`,
    [config.maxAttempts, config.batchSize]
  );
  const claimed = [];
  for (const r of rows) {
    const [res] = await pool.query(
      `UPDATE notification_queue SET status='processing', attempts=attempts+1 WHERE id=? AND status IN ('pending','retry')`,
      [r.id]
    );
    if (res.affectedRows) claimed.push({ ...r, attempts: Number(r.attempts || 0) + 1 });
  }
  return claimed;
}

async function markSent(job, result) {
  const providerMsgId = result?.id?._serialized || result?.id?.id || null;
  const resp = JSON.stringify(result || {}).slice(0, 65535);
  try {
    await pool.query(
      `UPDATE notification_queue SET status='sent', provider='whatsapp_web', provider_message_id=?, provider_response=?, sent_at=NOW(), locked_at=NULL, last_error=NULL, error_message=NULL WHERE id=?`,
      [providerMsgId, resp, job.id]
    );
  } catch {
    await pool.query(
      `UPDATE notification_queue SET status='sent', sent_at=NOW(), last_error=NULL WHERE id=?`,
      [job.id]
    );
  }
  await logNotification(job, 'sent', resp, null);
}

async function markFailure(job, err) {
  const attempts = Number(job.attempts || 1);
  const maxAttempts = Number(job.max_attempts || config.maxAttempts);
  const terminal = attempts >= maxAttempts;
  const msg = String(err?.message || err).slice(0, 2000);
  if (terminal) {
    try {
      await pool.query(
        `UPDATE notification_queue SET status='failed', locked_at=NULL, last_error=?, error_message=? WHERE id=?`,
        [msg, msg, job.id]
      );
    } catch {
      await pool.query(`UPDATE notification_queue SET status='failed', last_error=? WHERE id=?`, [msg, job.id]);
    }
  } else {
    const delay = backoffSeconds(attempts);
    try {
      await pool.query(
        `UPDATE notification_queue SET status='retry', locked_at=NULL, last_error=?, error_message=?, scheduled_at = DATE_ADD(NOW(), INTERVAL ? SECOND), next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id=?`,
        [msg, msg, delay, delay, job.id]
      );
    } catch {
      try {
        await pool.query(
          `UPDATE notification_queue SET status='retry', last_error=?, next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id=?`,
          [msg, delay, job.id]
        );
      } catch {
        await pool.query(`UPDATE notification_queue SET status='pending', last_error=? WHERE id=?`, [msg, job.id]);
      }
    }
  }
  await logNotification(job, terminal ? 'failed' : 'retry', null, msg);
}

async function processJob(job) {
  if (!state.whatsappReady) {
    throw new Error('WhatsApp client not ready');
  }
  const rawPhone = job.parent_phone || job.recipient_phone || job.phone || '';
  const chatId = toChatId(rawPhone);
  // getNumberId helps avoid sending to non-WhatsApp numbers; fall back to direct chat id.
  let target = chatId;
  try {
    const num = await waClient.getNumberId(chatId.replace(/@c\.us$/, ''));
    if (num && num._serialized) target = num._serialized;
  } catch {
    // ignore lookup failures
  }
  const result = await waClient.sendMessage(target, String(job.message || ''));
  return result;
}

async function pollOnce() {
  if (processing || shuttingDown) return;
  processing = true;
  state.lastPollAt = new Date().toISOString();
  try {
    const jobs = await claimJobs();
    if (!jobs.length) return;
    for (const job of jobs) {
      state.processed += 1;
      try {
        const result = await processJob(job);
        await markSent(job, result);
        state.sent += 1;
        log('info', `Sent queue=${job.id} to=${job.parent_phone || job.recipient_phone}`);
      } catch (e) {
        state.failed += 1;
        state.lastError = String(e?.message || e);
        await markFailure(job, e);
        log('error', `Failed queue=${job.id} attempt=${job.attempts}`, e?.message || e);
      }
    }
  } catch (e) {
    state.lastError = String(e?.message || e);
    log('error', 'Poll error', e?.message || e);
  } finally {
    processing = false;
  }
}

async function queueCounts() {
  const [rows] = await pool.query(
    `SELECT status, COUNT(*) AS n FROM notification_queue GROUP BY status`
  );
  const counts = { pending: 0, processing: 0, retry: 0, sent: 0, failed: 0, cancelled: 0 };
  for (const r of rows) counts[r.status] = Number(r.n || 0);
  return counts;
}

function startHttp() {
  const server = http.createServer(async (req, res) => {
    const url = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);
    if (config.worker.authToken) {
      const auth = req.headers.authorization || '';
      if (auth !== `Bearer ${config.worker.authToken}`) {
        res.writeHead(401, { 'content-type': 'application/json' });
        res.end(JSON.stringify({ ok: false, error: 'unauthorized' }));
        return;
      }
    }
    if (url.pathname === '/health' || url.pathname === '/') {
      let counts = null;
      try { counts = pool ? await queueCounts() : null; } catch { /* ignore */ }
      res.writeHead(200, { 'content-type': 'application/json' });
      res.end(JSON.stringify({
        ok: true,
        service: 'sbms-whatsapp-worker',
        whatsappReady: state.whatsappReady,
        whatsappState: state.whatsappState,
        startedAt: state.startedAt,
        lastQrAt: state.lastQrAt,
        lastPollAt: state.lastPollAt,
        lastError: state.lastError,
        processed: state.processed,
        sent: state.sent,
        failed: state.failed,
        queue: counts,
        time: new Date().toISOString(),
      }));
      return;
    }
    res.writeHead(404, { 'content-type': 'application/json' });
    res.end(JSON.stringify({ ok: false, error: 'not_found' }));
  });
  server.listen(config.worker.port, config.worker.host, () => {
    log('info', `Health endpoint http://${config.worker.host}:${config.worker.port}/health`);
  });
}

function initWhatsApp() {
  waClient = new Client({
    authStrategy: new LocalAuth({ clientId: config.wa.clientId, dataPath: config.wa.sessionPath }),
    puppeteer: {
      headless: true,
      args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
      executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
    },
  });

  waClient.on('qr', (qr) => {
    state.whatsappReady = false;
    state.whatsappState = 'qr';
    state.lastQrAt = new Date().toISOString();
    log('info', 'Scan this QR with WhatsApp (Linked Devices):');
    qrcode.generate(qr, { small: true });
  });

  waClient.on('ready', () => {
    state.whatsappReady = true;
    state.whatsappState = 'ready';
    log('info', 'WhatsApp client ready');
  });

  waClient.on('authenticated', () => {
    state.whatsappState = 'authenticated';
    log('info', 'WhatsApp authenticated');
  });

  waClient.on('auth_failure', (m) => {
    state.whatsappReady = false;
    state.whatsappState = 'auth_failure';
    state.lastError = String(m);
    log('error', 'WhatsApp auth failure', m);
  });

  waClient.on('disconnected', (reason) => {
    state.whatsappReady = false;
    state.whatsappState = `disconnected:${reason}`;
    log('error', 'WhatsApp disconnected', reason);
    if (!shuttingDown) {
      setTimeout(() => {
        log('info', 'Reinitializing WhatsApp client...');
        try { waClient.initialize(); } catch (e) { log('error', 'Reinit failed', e?.message); }
      }, 5000);
    }
  });

  waClient.initialize();
}

async function shutdown(signal) {
  if (shuttingDown) return;
  shuttingDown = true;
  log('info', `Shutting down (${signal})`);
  try { if (waClient) await waClient.destroy(); } catch { /* ignore */ }
  try { if (pool) await pool.end(); } catch { /* ignore */ }
  process.exit(0);
}

process.on('SIGINT', () => shutdown('SIGINT'));
process.on('SIGTERM', () => shutdown('SIGTERM'));

async function main() {
  await createPool();
  startHttp();
  initWhatsApp();
  log('info', `Polling every ${config.pollIntervalMs}ms batch=${config.batchSize}`);
  setInterval(pollOnce, config.pollIntervalMs);
  // initial poll
  setTimeout(pollOnce, 2000);
}

main().catch((e) => {
  console.error('Fatal:', e);
  process.exit(1);
});
