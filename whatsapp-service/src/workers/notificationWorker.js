'use strict';

/**
 * Notification worker: drains notification_queue over WhatsApp.
 *
 * Lifecycle of a job:
 *   pending --(claim)--> processing --(send)--> sent
 *                                       \--> pending (retryable, backoff)
 *                                       \--> failed  (max_attempts reached or permanent error)
 */

const pool = require('../config/database');
const { client, isWhatsAppReady } = require('../whatsapp/client');

const POLL_MS = Math.max(500, Number(process.env.WORKER_POLL_MS) || 3000);
const MAX_PARALLEL = Math.min(4, Math.max(1, Number(process.env.MAX_PARALLEL) || 1));
// notification_queue has no updated_at column, so next_attempt_at (always <= NOW()
// at claim time) is the best available proxy for "when did this become processing".
const STALE_PROCESSING_MINUTES = Math.max(1, Number(process.env.STALE_PROCESSING_MINUTES) || 10);

/** Errors that will never succeed on retry; sending them burns retries for nothing. */
const PERMANENT_ERROR_PATTERNS = [
  /not registered/i,
  /not a whatsapp/i,
  /cannot send to chat/i,
  /unrecognized number/i,
  /invalid number/i,
  /recipient unavailable/i
];

/** @type {Set<number>} queue ids currently being handled (idempotency guard). */
const inFlight = new Set();
/** @type {Set<Promise<void>>} active job promises, awaited on stop(). */
const activeJobs = new Set();

let claimConnection = null;
let running = false;
let stopping = false;
let loopPromise = null;
let notReadyLogged = false;
let wakeLoop = null;

/**
 * @param {number} ms
 * @returns {Promise<void>} resolves early if stop() was requested.
 */
function sleep(ms) {
  return new Promise((resolve) => {
    const timer = setTimeout(() => {
      wakeLoop = null;
      resolve();
    }, ms);
    wakeLoop = () => {
      clearTimeout(timer);
      wakeLoop = null;
      resolve();
    };
  });
}

/**
 * Rows left in 'processing' by a crashed worker would never be picked up again
 * (the claim only looks at 'pending'), so they are handed back to the queue.
 * @returns {Promise<number>} number of recovered rows
 */
async function recoverStaleProcessingJobs() {
  const [result] = await pool.query(
    `UPDATE notification_queue
        SET status = 'pending',
            last_error = CONCAT('recovered from stale processing after ', ?, ' minutes'),
            next_attempt_at = NOW()
      WHERE status = 'processing'
        AND next_attempt_at < NOW() - INTERVAL ? MINUTE`,
    [STALE_PROCESSING_MINUTES, STALE_PROCESSING_MINUTES]
  );
  const recovered = result.affectedRows || 0;
  if (recovered > 0) {
    console.warn(`[worker] recovered ${recovered} job(s) stuck in 'processing' for over ${STALE_PROCESSING_MINUTES} minute(s)`);
  }
  return recovered;
}

/**
 * Claims one due job atomically.
 *
 * SELECT ... FOR UPDATE inside a transaction guarantees two overlapping ticks
 * cannot claim the same row; the flip to 'processing' is committed immediately
 * so the row lock is only held for the claim, never for the WhatsApp send.
 * @returns {Promise<object|null>} the job now owned by this worker, or null
 */
async function claimJob() {
  if (!claimConnection) {
    claimConnection = await pool.getConnection();
  }

  await claimConnection.query('START TRANSACTION');
  try {
    const [rows] = await claimConnection.query(
      `SELECT id, student_id, parent_phone, message, notification_type, status,
              attempts, max_attempts, next_attempt_at
         FROM notification_queue
        WHERE status = 'pending'
          AND next_attempt_at <= NOW()
        ORDER BY id ASC
        LIMIT 1
          FOR UPDATE`
    );

    const job = rows[0];
    if (!job) {
      await claimConnection.query('COMMIT');
      return null;
    }

    await claimConnection.query(
      `UPDATE notification_queue SET status = 'processing' WHERE id = ?`,
      [job.id]
    );
    await claimConnection.query('COMMIT');
    return job;
  } catch (err) {
    try {
      await claimConnection.query('ROLLBACK');
    } catch (_) {
      /* the connection is broken anyway */
    }
    throw err;
  }
}

/**
 * Writes an audit row for every outcome so the PHP side can explain to staff
 * what happened even for jobs that were retried rather than sent.
 * @param {object} job
 * @param {string} status one of sent / pending / failed
 * @param {string|null} [errorMessage]
 * @returns {Promise<void>}
 */
async function writeLog(job, status, errorMessage) {
  await pool.query(
    `INSERT INTO notification_logs (queue_id, parent_phone, message, status, error_message)
     VALUES (?, ?, ?, ?, ?)`,
    [job.id, job.parent_phone, job.message, status, errorMessage || null]
  );
}

/**
 * Normalises a phone number to the digit-only form whatsapp-web.js expects.
 * Accepts the local Rwandan forms stored by PHP: 07XXXXXXXX, 0788100001,
 * +2507XXXXXXXXX and 2507XXXXXXXXX. A leading 0 becomes the 250 country code.
 * @param {string} raw
 * @returns {{ok: true, phone: string} | {ok: false, error: string}}
 */
function normalizePhone(raw) {
  const digits = String(raw == null ? '' : raw).replace(/\D/g, '');
  if (!digits) {
    return { ok: false, error: 'Invalid parent_phone: no digits found' };
  }

  let phone = digits;
  if (phone.startsWith('00')) {
    phone = phone.slice(2); // international dialling prefix, e.g. 002507...
  }
  if (phone.startsWith('0') && phone.length === 10) {
    phone = '250' + phone.slice(1); // local Rwandan form -> country code
  }
  if (phone.length < 10 || phone.length > 15) {
    return {
      ok: false,
      error: `Invalid parent_phone "${raw}": expected 10-15 digits after normalisation, got ${phone.length}`
    };
  }
  return { ok: true, phone };
}

/**
 * @param {Error|string} err
 * @returns {boolean} true when retrying cannot help (bad/unregistered recipient)
 */
function isPermanentError(err) {
  const text = err instanceof Error ? err.message : String(err);
  return PERMANENT_ERROR_PATTERNS.some((re) => re.test(text));
}

/**
 * Marks a job 'failed' without touching attempts: permanent failures must not
 * consume the retry budget, and there is no point rescheduling them.
 * @param {object} job
 * @param {string} errorMessage
 * @returns {Promise<void>}
 */
async function markPermanentFailure(job, errorMessage) {
  await pool.query(
    `UPDATE notification_queue
        SET status = 'failed', last_error = ?
      WHERE id = ?`,
    [errorMessage, job.id]
  );
  await writeLog(job, 'failed', errorMessage);
  console.error(`[worker] job ${job.id} failed permanently: ${errorMessage}`);
}

/**
 * Retryable failure: bump attempts and reschedule with exponential backoff
 * capped at one hour, or give up once the attempts hit max_attempts.
 * @param {object} job
 * @param {string} errorMessage
 * @returns {Promise<void>}
 */
async function scheduleRetry(job, errorMessage) {
  const nextAttempts = job.attempts + 1;
  const nextStatus = nextAttempts >= job.max_attempts ? 'failed' : 'pending';
  // `attempts` is passed as a bound value rather than read from the column, because
  // MySQL would otherwise see the already-incremented value in the same SET list.
  const backoffExponent = Math.min(job.attempts, 16);

  await pool.query(
    `UPDATE notification_queue
        SET status = ?,
            last_error = ?,
            next_attempt_at = NOW() + INTERVAL CAST(LEAST(3600, 60 * POW(2, ?)) AS SIGNED) SECOND,
            attempts = attempts + 1
      WHERE id = ?`,
    [nextStatus, errorMessage, backoffExponent, job.id]
  );
  await writeLog(job, nextStatus, errorMessage);

  if (nextStatus === 'failed') {
    console.error(`[worker] job ${job.id} exhausted its retries (${nextAttempts}/${job.max_attempts}): ${errorMessage}`);
  } else {
    console.warn(
      `[worker] job ${job.id} retry ${nextAttempts}/${job.max_attempts} scheduled in ` +
        `${Math.min(3600, 60 * Math.pow(2, backoffExponent))}s: ${errorMessage}`
    );
  }
}

/**
 * Sends one claimed job. Never throws: every branch ends in a status update.
 * @param {object} job
 * @returns {Promise<void>}
 */
async function processJob(job) {
  try {
    const target = normalizePhone(job.parent_phone);
    if (!target.ok) {
      await markPermanentFailure(job, target.error);
      return;
    }

    const numberId = await client.getNumberId(target.phone);
    if (!numberId) {
      await markPermanentFailure(
        job,
        `Number ${target.phone} is not registered on WhatsApp (permanent, no retry)`
      );
      return;
    }

    const sentMessage = await client.sendMessage(numberId._serialized, job.message);
    await pool.query(
      `UPDATE notification_queue
          SET status = 'sent', sent_at = NOW(), last_error = NULL
        WHERE id = ?`,
      [job.id]
    );
    await writeLog(job, 'sent', null);
    const messageId = sentMessage && sentMessage.id ? sentMessage.id._serialized : 'n/a';
    console.log(`[worker] job ${job.id} sent to ${target.phone} (message ${messageId})`);
  } catch (err) {
    const errorMessage = (err && err.message ? err.message : String(err)).slice(0, 600);
    try {
      if (isPermanentError(err)) {
        await markPermanentFailure(job, errorMessage);
      } else {
        await scheduleRetry(job, errorMessage);
      }
    } catch (dbErr) {
      console.error(`[worker] job ${job.id}: could not persist failure (${dbErr.message}); it stays in 'processing' and will be recovered on next startup`);
    }
  } finally {
    inFlight.delete(job.id);
  }
}

/**
 * One scheduling pass: claim and dispatch as many jobs as MAX_PARALLEL allows.
 * @returns {Promise<boolean>} true if this pass found any work
 */
async function runOnce() {
  let dispatched = false;

  while (activeJobs.size < MAX_PARALLEL) {
    const job = await claimJob();
    if (!job) return dispatched;
    if (inFlight.has(job.id)) continue; // already owned by this process

    inFlight.add(job.id);
    dispatched = true;

    const task = processJob(job).finally(() => activeJobs.delete(task));
    activeJobs.add(task);
    if (MAX_PARALLEL === 1) await task;
  }

  return dispatched;
}

async function loop() {
  while (!stopping) {
    try {
      if (!isWhatsAppReady()) {
        if (!notReadyLogged) {
          console.warn('[worker] WhatsApp is not ready yet, waiting before sending anything');
          notReadyLogged = true;
        }
        await sleep(POLL_MS);
        continue;
      }
      notReadyLogged = false;

      const hadWork = await runOnce();
      if (!hadWork) await sleep(POLL_MS);
    } catch (err) {
      console.error('[worker] tick failed:', err.message);
      await sleep(POLL_MS);
    }
  }
}

/**
 * Starts the worker loop.
 * @returns {Promise<{stop: () => Promise<void>, isRunning: () => boolean}>}
 */
async function startNotificationWorker() {
  if (running) throw new Error('notification worker is already running');

  running = true;
  stopping = false;

  await recoverStaleProcessingJobs();
  loopPromise = loop();
  console.log(`[worker] started (poll ${POLL_MS}ms, max parallel ${MAX_PARALLEL}, stale processing limit ${STALE_PROCESSING_MINUTES}min)`);

  return {
    async stop() {
      if (!running) return;
      stopping = true;
      running = false;
      if (wakeLoop) wakeLoop();
      if (loopPromise) await loopPromise;
      // Let in-flight sends finish so jobs are not left in 'processing'.
      await Promise.allSettled([...activeJobs]);
      if (claimConnection) {
        claimConnection.release();
        claimConnection = null;
      }
      console.log('[worker] stopped');
    },
    isRunning() {
      return running === true;
    }
  };
}

module.exports = {
  startNotificationWorker,
  recoverStaleProcessingJobs,
  normalizePhone,
  isPermanentError
};
