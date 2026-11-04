# SBMS Backend Setup Guide - PlanetScale + InfinityFree

Complete guide to set up the School Business Management System backend using PlanetScale for database and InfinityFree for hosting.

## Step 1: Create PlanetScale MySQL Database

### 1.1 Sign Up for PlanetScale
1. Go to https://app.planetscale.com/register
2. Sign up with email or GitHub
3. Verify your email

### 1.2 Create a New Database
1. Click "Create a database"
2. Database name: `sbms`
3. Region: Choose closest to your location
4. Click "Create database"

### 1.3 Get Connection Credentials
1. In PlanetScale dashboard, click your `sbms` database
2. Click "Connect" button
3. Select "Node.js" from dropdown
4. Copy the connection string - it will look like:
   ```
   mysql://[username]:[password]@[host]/sbms?sslaccept=strict
   ```
5. Extract these values:
   - **Host:** `[host]` (e.g., `aws.connect.psdb.cloud`)
   - **User:** `[username]`
   - **Password:** `[password]`
   - **Database:** `sbms`
   - **Port:** `3306`

### 1.4 Create Additional Branch (Optional but Recommended)
1. In database dashboard, click "Branches"
2. Click "New Branch"
3. Name it: `staging`
4. This creates a separate copy for testing

---

## Step 2: Prepare Database Schema

### 2.1 Export Current Database Schema
```bash
# On your local machine, export the current database:
mysqldump -h localhost -u root -p sbms --no-data > schema.sql
```

### 2.2 Create Tables in PlanetScale
1. Go to PlanetScale dashboard → Click your database
2. Click "Console"
3. Copy-paste your SQL schema from `database/schema.sql`
4. Execute it

Alternatively, connect via MySQL client:
```bash
mysql -h aws.connect.psdb.cloud -u [username] -p[password] sbms < database/schema.sql
```

---

## Step 3: Set Up InfinityFree Account

### 3.1 Create Free Hosting Account
1. Go to https://www.infinityfree.net
2. Click "Sign up"
3. Fill in details and create account
4. Verify email

### 3.2 Create New Website
1. Go to Control Panel
2. Click "Create New Account"
3. Choose domain (or use InfinityFree subdomain)
4. Wait for setup (usually 5-10 minutes)

### 3.3 Get FTP Credentials
1. In Control Panel, find your account
2. Click "Manage Account"
3. Scroll to FTP Accounts
4. Note down:
   - **FTP Host:** Usually provided in welcome email
   - **FTP User:** Username
   - **FTP Password:** Password
   - **FTP Port:** Usually 21 or 2121

---

## Step 4: Configure SBMS for PlanetScale

### 4.1 Update Configuration Files
Create `.env.infinityfree` in your local SBMS directory:

```env
# Database Configuration
DB_HOST=aws.connect.psdb.cloud
DB_PORT=3306
DB_USER=your_planetscale_username
DB_PASSWORD=your_planetscale_password
DB_NAME=sbms

# Application Settings
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Africa/Kigali
APP_URL=https://your-infinityfree-domain.com

# CORS Settings (for Vercel frontend)
ALLOWED_ORIGINS=https://sbms-ten.vercel.app

# Email Configuration (Use free service or skip for now)
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=465
MAIL_FROM=noreply@yourdomain.com

# Twilio (Optional - for WhatsApp/SMS)
TWILIO_ACCOUNT_SID=your_twilio_sid
TWILIO_AUTH_TOKEN=your_twilio_token
TWILIO_PHONE_NUMBER=+1234567890
TWILIO_WHATSAPP_NUMBER=+1234567890
```

### 4.2 Update PHP Configuration Files
Edit `config/database.php` or create it:

```php
<?php
return [
    'driver' => 'mysql',
    'host' => getenv('DB_HOST') ?: 'localhost',
    'port' => getenv('DB_PORT') ?: 3306,
    'database' => getenv('DB_NAME') ?: 'sbms',
    'username' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
];
?>
```

---

## Step 5: Update SBMS PHP Files

### 5.1 Create Universal Database Connection
Create `config/Connection.php`:

```php
<?php
declare(strict_types=1);

class DatabaseConnection {
    private static $connection = null;
    
    public static function getConnection() {
        if (self::$connection === null) {
            $host = getenv('DB_HOST') ?: 'localhost';
            $user = getenv('DB_USER') ?: 'root';
            $password = getenv('DB_PASSWORD') ?: '';
            $database = getenv('DB_NAME') ?: 'sbms';
            $port = (int)(getenv('DB_PORT') ?: 3306);
            
            try {
                self::$connection = new PDO(
                    "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
                    $user,
                    $password,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
            } catch (PDOException $e) {
                error_log('Database connection error: ' . $e->getMessage());
                http_response_code(500);
                die(json_encode(['error' => 'Database connection failed']));
            }
        }
        
        return self::$connection;
    }
}
?>
```

### 5.2 Create API Entry Point
Create `api/config.php`:

```php
<?php
// Load environment variables
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            [$key, $value] = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

// Set headers for API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . (getenv('ALLOWED_ORIGINS') ?: '*'));
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', getenv('APP_DEBUG') === 'true' ? '1' : '0');
?>
```

### 5.3 Update Existing API Routes
Make sure all API files include the config:

```php
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../config/Connection.php';

// Your existing API code here
$db = DatabaseConnection::getConnection();
?>
```

---

## Step 6: Prepare Files for Upload

### 6.1 Use the Ready Package
This repository includes a ready-to-upload bundle in `deploy/infinityfree-backend.zip`.

If you do not want to create the package yourself, extract `deploy/infinityfree-backend.zip` and upload the extracted files to InfinityFree.

The package contains the full PHP backend plus the built Vite assets at `assets/dist/`.

### 6.2 Files included in the deployment bundle
```
/
├── .env                     (with PlanetScale credentials)
├── .htaccess                (protects .env and routes /api/*)
├── api/
│   ├── index.php            (API entry point)
│   ├── bootstrap.php
│   ├── test-connection.php  (database verification)
│   └── ...
├── config/
│   ├── db.php
│   ├── Database.php
│   └── ...
├── assets/
│   ├── dist/                (built Vite assets and manifest)
│   └── ...
├── auth/
├── controllers/
├── includes/
├── models/
├── reports/
├── rules/
├── settings/
├── students/
├── storage/                 (logs and runtime files)
├── uploads/
└── index.php                (SBMS PHP application entry point)
```

### 6.3 Root index.php behavior
By default, `index.php` serves the SBMS PHP app from InfinityFree. If you prefer to host the frontend on Vercel and only use InfinityFree as an API backend, you can replace `index.php` with a redirect page that sends web traffic to your Vercel frontend while preserving API routes.

Example optional `index.php` for API-only deployment:

```php
<?php
$request_uri = $_SERVER['REQUEST_URI'];
if (strpos($request_uri, '/api/') === 0) {
    require __DIR__ . '/api/index.php';
}

header('Location: https://sbms-ten.vercel.app' . $request_uri);
exit;
```

```php
<?php
// Redirect to Vercel frontend for most requests
$request_uri = $_SERVER['REQUEST_URI'];

// API requests go to backend
if (strpos($request_uri, '/api/') === 0) {
    require __DIR__ . '/api/index.php';
} else {
    // Everything else redirects to frontend
    header('Location: https://sbms-ten.vercel.app' . $request_uri);
    exit;
}
?>
```

---

## Step 7: Deploy to InfinityFree

### 7.1 Using FTP (FileZilla or similar)
1. Open FTP client
2. Connect with InfinityFree credentials:
   - Host: Your FTP host
   - Username: Your FTP username
   - Password: Your FTP password
   - Port: 21 or 2121

3. Navigate to `htdocs/` or your public folder
4. Upload all SBMS files

### 7.2 Upload .env File
1. In FTP, right-click and create new file: `.env`
2. Copy contents from `.env.infinityfree.example`
3. Replace placeholders with real credentials

### 7.3 Set File Permissions
- PHP files: 644
- Directories: 755
- `.env`: 600 (for security)

---

## Step 8: Test Database Connection

### 8.1 Use the Built-In API Health Endpoint
This repository now includes an API router at `api/index.php`.
You can validate the backend quickly by visiting:

- `https://your-infinityfree-domain.com/api/health`
- `https://your-infinityfree-domain.com/api/test`
- `https://your-infinityfree-domain.com/api/test-connection`

If the backend is configured correctly and can reach the database, the `/api/test` endpoint will return a JSON response with the database host, database name, environment, and a success message.

### 8.2 Configure Vercel Frontend Backend URL
If you are keeping the Vercel landing page, set the Vercel environment variable `VITE_BACKEND_URL` to your InfinityFree domain:

```text
VITE_BACKEND_URL=https://your-infinityfree-domain.com
```

This will make the landing page link point to the actual SBMS backend and help visitors access the live application.

### 8.3 Confirm `.env` Variables
Make sure your InfinityFree `.env` file defines the database connection and application URLs. The backend accepts both legacy SBMS_* names and newer DB_* names:

```text
DB_HOST=aws.connect.psdb.cloud
DB_PORT=3306
DB_USER=your_planetscale_username
DB_PASSWORD=your_planetscale_password
DB_NAME=sbms

# Optional legacy values for older SBMS code paths
SBMS_DB_HOST=aws.connect.psdb.cloud
SBMS_DB_PORT=3306
SBMS_DB_USER=your_planetscale_username
SBMS_DB_PASS=your_planetscale_password
SBMS_DB_NAME=sbms
```

This repository also includes a dedicated connection verification script at `api/test-connection.php`, which returns a JSON success response when the database is reachable.

    ]);
}
?>
```

### 8.2 Test in Browser
Visit: `https://your-infinityfree-domain.com/api/test-connection.php`

Should return:
```json
{
  "status": "success",
  "message": "Database connection successful",
  "database": "sbms",
  "host": "aws.connect.psdb.cloud"
}
```

---

## Step 9: Link Frontend to Backend

### 9.1 Update Frontend API Endpoints
In Vercel project settings or JavaScript files:

```javascript
// Change API base URL from:
const API_BASE = '/api/';

// To:
const API_BASE = 'https://your-infinityfree-domain.com/api/';
```

### 9.2 Create Proxy (Optional)
In Vercel `vercel.json`, add rewrites:

```json
{
  "rewrites": [
    {
      "source": "/api/:path*",
      "destination": "https://your-infinityfree-domain.com/api/:path*"
    }
  ]
}
```

---

## Step 10: Final Checklist

- ✅ PlanetScale database created and populated
- ✅ InfinityFree hosting account set up
- ✅ SBMS files uploaded via FTP
- ✅ `.env` file configured with correct credentials
- ✅ Database connection test passing
- ✅ API endpoints accessible
- ✅ Frontend configured to use backend API
- ✅ CORS settings allowing Vercel frontend

---

## Troubleshooting

### Database Connection Failed
- Verify host/user/password in `.env`
- Check PlanetScale database is not deleted
- Ensure IP is not blocked (PlanetScale allows all by default)

### FTP Upload Issues
- Use Passive Mode in FTP client
- Check file permissions (644 for files, 755 for folders)
- Ensure .env is not ignored by FTP client

### API Not Responding
- Check `api/config.php` is being included
- Verify error logs in InfinityFree control panel
- Test with `api/test-connection.php`

### CORS Errors from Frontend
- Update `ALLOWED_ORIGINS` in .env
- Add Vercel domain to CORS headers
- Clear browser cache and hard refresh

---

## Security Notes

- ✅ Keep `.env` private (don't commit to git)
- ✅ Use strong passwords for PlanetScale
- ✅ Enable SSL/HTTPS (InfinityFree provides free SSL)
- ✅ Validate all API inputs
- ✅ Hash passwords with bcrypt
- ✅ Use prepared statements to prevent SQL injection
- ✅ Implement CSRF protection

---

## Support Resources

- **PlanetScale Docs:** https://docs.planetscale.com
- **InfinityFree Help:** https://forum.infinityfree.net
- **PHP PDO:** https://www.php.net/manual/en/book.pdo.php
- **CORS Guide:** https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS

---

**Last Updated:** 2026-07-26
**Status:** Ready for implementation
