# SBMS InfinityFree Backend Package

This package is ready for InfinityFree PHP hosting.

## What is included

- `api/` - API router and helper endpoints
- `auth/`, `config/`, `controllers/`, `database/`, `includes/`, `models/`, `settings/`, `students/`, `reports/`, and all PHP application code
- `assets/dist/` with built Vite frontend assets required by the PHP app
- `.htaccess` to protect `.env` and route `/api/*` requests correctly
- `api/.htaccess` to support API routing inside the `api/` directory
- `api/test-connection.php` for backend database connectivity validation
- `.env.infinityfree.example` as the environment file template
- `BACKEND_SETUP_INFINITYFREE.md` with deployment guidance

## How to use

1. Download or extract `deploy/infinityfree-backend.zip`.
2. Upload the extracted files to your InfinityFree `htdocs/` directory.
3. Create a `.env` file in the repository root on the server.
4. Copy values from `.env.infinityfree.example` and fill in your PlanetScale credentials.

### Required `.env` values

```text
DB_HOST=aws.connect.psdb.cloud
DB_PORT=3306
DB_USER=your_planetscale_username
DB_PASSWORD=your_planetscale_password
DB_NAME=sbms

APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Africa/Kigali
APP_URL=https://your-infinityfree-domain.com

FRONTEND_URL=https://sbms-ten.vercel.app
ALLOWED_ORIGINS=https://sbms-ten.vercel.app
```

You can also provide legacy SBMS names if needed:

```text
SBMS_DB_HOST=aws.connect.psdb.cloud
SBMS_DB_PORT=3306
SBMS_DB_USER=your_planetscale_username
SBMS_DB_PASS=your_planetscale_password
SBMS_DB_NAME=sbms
```

## Point the Vercel frontend at your InfinityFree backend
If you keep the Vercel landing page, set the Vercel environment variable:

```text
VITE_BACKEND_URL=https://your-infinityfree-domain.com
```

This will ensure the frontend button opens your live SBMS app.

## Testing the deployment

Visit these URLs after upload:

- `https://your-infinityfree-domain.com/api/health`
- `https://your-infinityfree-domain.com/api/test`
- `https://your-infinityfree-domain.com/api/test-connection.php`

If everything is configured correctly, `/api/test` and `/api/test-connection.php` will return JSON confirming the database connection.

## Notes

- The backend package already includes the built Vite frontend assets in `assets/dist/`.
- Do NOT upload `.env` to GitHub. Keep it private on the server.
- If you change your frontend domain, update `FRONTEND_URL` and `ALLOWED_ORIGINS` accordingly.
