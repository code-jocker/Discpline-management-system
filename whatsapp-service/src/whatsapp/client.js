'use strict';

/**
 * WhatsApp client wrapper around whatsapp-web.js.
 *
 * Exposes the client instance plus readiness state so the HTTP health endpoint
 * and the notification worker can react to the session without polling
 * internals of the library.
 */

const path = require('path');
const { Client, LocalAuth } = require('whatsapp-web.js');
const qrcodeTerminal = require('qrcode-terminal');

// Resolved from this file, never from process.cwd(), so PM2/systemd/nginx
// helpers started from another directory still reuse the same WhatsApp session.
const SERVICE_ROOT = path.resolve(__dirname, '..', '..');
const AUTH_DATA_PATH = path.join(SERVICE_ROOT, '.wwebjs_auth');

// Use an installed Chrome when Puppeteer's bundled browser was skipped, or when
// the school machine manages browser updates centrally.
const CHROME_PATH = (process.env.CHROME_PATH || process.env.PUPPETEER_EXECUTABLE_PATH || '').trim();

/** Mutable snapshot shared with getState(); updated by the event handlers. */
const state = {
  ready: false,
  authenticated: false,
  lastDisconnectedReason: null,
  lastQrAt: null
};

const client = new Client({
  authStrategy: new LocalAuth({
    clientId: 'sbms-school',
    dataPath: AUTH_DATA_PATH
  }),
  puppeteer: {
    headless: true,
    // WhatsApp Web is slow to boot on school hardware; the Puppeteer default
    // (30s) expires while the page is still evaluating, aborting initialization.
    protocolTimeout: Math.max(30000, Number(process.env.PROTOCOL_TIMEOUT_MS) || 180000),
    ...(CHROME_PATH ? { executablePath: CHROME_PATH } : {}),
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  }
});

client.on('qr', (qr) => {
  state.lastQrAt = new Date().toISOString();
  console.log('[whatsapp] Scan this QR code with the school phone:');
  console.log('[whatsapp] WhatsApp > Linked Devices > Link a Device');
  qrcodeTerminal.generate(qr, { small: true });
});

client.on('authenticated', () => {
  state.authenticated = true;
  console.log('[whatsapp] authenticated, downloading session...');
});

client.on('ready', () => {
  state.ready = true;
  state.authenticated = true;
  state.lastDisconnectedReason = null;
  console.log('[whatsapp] client is ready');
});

client.on('auth_failure', (message) => {
  state.ready = false;
  state.authenticated = false;
  console.error('[whatsapp] auth failure:', message);
});

client.on('disconnected', (reason) => {
  state.ready = false;
  state.lastDisconnectedReason = reason || 'unknown';
  console.warn('[whatsapp] disconnected:', state.lastDisconnectedReason);
});

/**
 * @returns {boolean} true once the WhatsApp session is usable for sending.
 */
function isWhatsAppReady() {
  return state.ready === true;
}

/**
 * Small, JSON-safe snapshot for the PHP admin page through GET /health.
 * @returns {{ready: boolean, authenticated: boolean, lastDisconnectedReason: (string|null), lastQrAt: (string|null)}}
 */
function getState() {
  return { ...state };
}

/**
 * Boots the embedded browser session. Safe to call once at startup.
 * @returns {Promise<void>}
 */
async function initialize() {
  await client.initialize();
}

/**
 * Closes the underlying browser during graceful shutdown.
 * @returns {Promise<void>}
 */
async function destroy() {
  try {
    await client.destroy();
  } catch (err) {
    console.warn('[whatsapp] destroy failed:', err.message);
  }
}

module.exports = {
  client,
  initialize,
  destroy,
  isWhatsAppReady,
  getState,
  AUTH_DATA_PATH
};
