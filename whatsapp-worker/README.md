# SBMS WhatsApp Worker

Node service that sends SBMS notifications through **WhatsApp Web** using `whatsapp-web.js`. It reads pending jobs from the shared MySQL `notification_queue` table, sends them, and records outcomes in `notification_logs`.

This replaces the previous Twilio integration. SBMS (PHP) **only enqueues** messages; this worker performs delivery.

## Requirements
- Node.js 20+
- Access to the same MySQL database as SBMS (Aiven or local)
- A phone with WhatsApp to link (scan QR once; session persists via `LocalAuth`)

## Setup
```bash
cd whatsapp-worker
cp .env.example .env
# edit .env: DB_*, optional DB_SSL / DB_SSL_CA for Aiven
npm install
npm start
```

On first start, scan the QR printed in the terminal with WhatsApp → Linked Devices. Session data is stored in `.wwebjs_auth/` (keep it private/persistent).

## Health
`GET http://HOST:WORKER_PORT/health` returns JSON with WhatsApp readiness and queue counts. If `WORKER_AUTH_TOKEN` is set, send `Authorization: Bearer <token>`.

Configure SBMS with `WHATSAPP_WORKER_URL` (and optional `WHATSAPP_WORKER_TOKEN`) so the admin queue page can show worker health.

## Queue contract
- Claims rows from `notification_queue` where `channel='whatsapp'`, `status IN ('pending','retry')`, `scheduled_at <= NOW()`, `attempts < MAX_ATTEMPTS`.
- Sets `status='processing'` while sending; on success `status='sent'` + `provider='whatsapp_web'`; on failure `retry` with exponential backoff or `failed` when attempts exhausted.
- Writes per-attempt rows to `notification_logs`.

## Production notes
- Run under a process manager (pm2/systemd/Docker) with a persistent volume for `.wwebjs_auth/`.
- WhatsApp Web automation must comply with WhatsApp terms/policies; use responsibly and expect session/link constraints.
- For Aiven MySQL over TLS, set `DB_SSL=true` and `DB_SSL_CA` to the CA file path if verification requires it.
