# SBMS WhatsApp Service

A small standalone Node.js worker for the legacy SBMS school system. PHP records a
discipline incident and inserts a row into `notification_queue`; this service claims
those rows, sends them to the parent over WhatsApp Web, and writes the outcome back
plus an audit row in `notification_logs`. Because the handoff lives in MySQL, an
incident is never lost just because WhatsApp was offline.

## Install

```bash
npm install
```

This pulls in `whatsapp-web.js`, which downloads a bundled Chromium on first install.
Start the MySQL/MariaDB service (XAMPP) and apply
`../database/migration_whatsapp_queue.sql` from the project root if the two tables do
not exist yet.

## Configure

Copy the example env file and edit the values (database name, port, credentials):

```bash
copy .env.example .env    # Windows
cp .env.example .env      # Linux / macOS
```

`STALE_PROCESSING_MINUTES` controls how long a job may sit in `processing` before a
restart hands it back to the queue. Set `HEALTH_TOKEN` in production: `GET /health`
exposes parent phone numbers and queue volumes and is only protected by that header.
`PROTOCOL_TIMEOUT_MS` (default 180000) is how long Puppeteer waits for WhatsApp Web
to answer on a slow school machine; the library default of 30s is too short.

## Run

```bash
npm start
```

The service listens on `PORT` (default 3001) and polls the queue every
`WORKER_POLL_MS` milliseconds. Run it from this folder:

```bash
cd whatsapp-service
npm start
```

The PHP admin page reads the worker at `SBMS_WHATSAPP_URL` (default
`http://127.0.0.1:3001`) and sends `SBMS_WHATSAPP_HEALTH_TOKEN` when set. Set both in
the main app `.env`. This directory is denied to web requests by `.htaccess`; keep it
out of any public document root in production.

### Link WhatsApp

On the first run the console prints a QR code. Scan it with the school phone:

**WhatsApp > Settings > Linked Devices > Link a Device**

The logged-in session is stored afterwards, so you only scan once. If the QR code
expires, restart the service to get a fresh one.

### Where the session lives

`.wwebjs_auth/` in this project folder (subfolder `session-sbms-school`). It is
absolute-path based, so the session is reused no matter which directory PM2 or
systemd starts the process from. Treat it like a password: it grants access to the
school's WhatsApp account, and it is git-ignored. Delete the folder to force a
re-link.

If the phone was already linked in the old `C:\sbms-whatsapp` folder, copy its
`.wwebjs_auth` folder here instead of scanning again. Never commit it.

## Stop

Press `Ctrl+C` (or send `SIGTERM`). The worker finishes in-flight sends, marks jobs
correctly, then closes the browser and the database pool, so nothing is left stuck in
`processing`.

## Test it

Queue a single manual job (the number is never hardcoded):

```bash
npm run send:test -- 0788100001
```

Check the health endpoint while the worker runs:

```bash
curl -H "x-health-token: your-token" http://localhost:3001/health
```

The PHP app exposes a sanitised version for the public Firebase entry page at
`/api/whatsapp-status`. It returns only `ready`, `reachable` and `workerRunning`,
never phone numbers or message contents. Set `SBMS_WHATSAPP_URL` and
`SBMS_WHATSAPP_HEALTH_TOKEN` on the PHP side to point at this worker.

## Safety notes

- `whatsapp-web.js` is **unofficial** and reverse-engineered. WhatsApp can break it at
  any time, and it can restrict or ban the account used. Run it on a **dedicated school
  number**, never a personal one.
- Message only parents who **consented** to be contacted on WhatsApp.
- **No bulk or unsolicited messaging.** Keep `MAX_PARALLEL=1` and let the built-in
  backoff pace retries; high send volume is what triggers restrictions.
- This service needs a **persistent process** in production (`pm2 start src/server.js
  --name sbms-whatsapp`, or a systemd unit) — a PHP request cannot keep it alive.
- Only this worker may change `notification_queue` rows; PHP should only insert them.
