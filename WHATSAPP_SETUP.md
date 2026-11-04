# WhatsApp & SMS Integration Setup Guide

This guide explains how to set up WhatsApp and SMS messaging through **Twilio** in SBMS.

## Table of Contents

1. [Create Twilio Account](#create-twilio-account)
2. [Get WhatsApp Number](#get-whatsapp-number)
3. [Configure Environment](#configure-environment)
4. [Test Integration](#test-integration)
5. [Send Messages](#send-messages)
6. [Troubleshooting](#troubleshooting)

---

## Create Twilio Account

### Step 1: Sign Up for Twilio

1. Visit [twilio.com](https://www.twilio.com)
2. Click **Sign Up** and create an account
3. Verify your email address
4. Complete your account details

### Step 2: Get Your Credentials

After signing up:

1. Go to **Console Dashboard**: https://www.twilio.com/console
2. You'll see your **Account SID** and **Auth Token**
3. Save these - you'll need them in a moment

**Dashboard Example:**
```
Account SID: ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
Auth Token:  axxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

---

## Get WhatsApp Number

### Option A: Use Twilio Sandbox (Free - for Testing)

1. Go to: **Messaging → Try it out → Send an SMS**
2. Look for **Programmable Messaging** section
3. Click on **Try WhatsApp** or **WhatsApp Sandbox**
4. You'll get a **Sandbox WhatsApp Number** (format: +1415...)
5. This number is free for testing but messages include "[Sandbox]" prefix

**To test:**
```
Send "join <code>" to the Sandbox number to enable your personal WhatsApp
```

### Option B: Request Production WhatsApp Number (Paid)

For production (no sandbox prefix):

1. Go to **Messaging → Manage → Senders**
2. Click **Request WhatsApp Business Account**
3. Follow Twilio's approval process (24-48 hours typically)
4. Once approved, you'll get a **Production WhatsApp Number**

**Cost:** Typically ~$0.005-0.05 per message depending on destination country

---

## Configure Environment

### Step 1: Create/Update `.env` file

Add these variables to your `.env` file in the SBMS root directory:

```bash
# Twilio Credentials
TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_AUTH_TOKEN=axxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_PHONE_NUMBER=+1415xxxxxxx
TWILIO_WHATSAPP_NUMBER=+1415xxxxxxx

# Optional: Email configuration
MAIL_HOST=localhost
MAIL_PORT=1025
```

**On Windows (with xampp):**
Create file: `c:\xampp\htdocs\sbms\.env`

```text
TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_AUTH_TOKEN=axxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_PHONE_NUMBER=+1415xxxxxxx
TWILIO_WHATSAPP_NUMBER=+1415xxxxxxx
MAIL_HOST=localhost
MAIL_PORT=1025
```

### Step 2: Load Environment Variables in PHP

In your `config/db.php` or bootstrap file, add:

```php
// Load .env file
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}
```

Or use a package like `vlucas/phpdotenv`:

```bash
composer require vlucas/phpdotenv
```

```php
require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
```

---

## Test Integration

### Test 1: Verify Credentials Load

Create file `test_twilio.php`:

```php
<?php
// Load environment
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

// Check credentials
echo "Testing Twilio Configuration...\n";
echo "================================\n\n";

$sid = getenv('TWILIO_ACCOUNT_SID');
$token = getenv('TWILIO_AUTH_TOKEN');
$phone = getenv('TWILIO_PHONE_NUMBER');
$whatsapp = getenv('TWILIO_WHATSAPP_NUMBER');

echo "Account SID: " . ($sid ? "✓ Loaded" : "✗ Missing") . "\n";
echo "Auth Token: " . ($token ? "✓ Loaded" : "✗ Missing") . "\n";
echo "Phone Number: " . ($phone ? "✓ {$phone}" : "✗ Missing") . "\n";
echo "WhatsApp Number: " . ($whatsapp ? "✓ {$whatsapp}" : "✗ Missing") . "\n";

if ($sid && $token) {
    echo "\n✓ Configuration looks good!\n";
    echo "\nNext: Register parent contacts with WhatsApp numbers\n";
} else {
    echo "\n✗ Please add missing credentials to .env\n";
}
?>
```

Run: `php test_twilio.php`

### Test 2: Register Parent with WhatsApp

```php
<?php
require 'config/db.php';
require 'app/Services/ParentNotificationService.php';

use App\Services\ParentNotificationService;

$notifications = new ParentNotificationService($pdo);

// Register parent with WhatsApp
$parentId = $notifications->registerParent(
    studentId: 5,
    parentName: 'John Smith',
    relationship: 'father',
    email: 'john@example.com',
    phone: '+250788123456',  // SMS fallback
    whatsapp: '+250788123456', // Primary WhatsApp
    preference: 'whatsapp',   // Prefer WhatsApp
    isPrimary: true
);

echo "Parent registered with ID: {$parentId}\n";
?>
```

### Test 3: Send Test WhatsApp Message

```php
<?php
require 'config/db.php';
require 'app/Services/ParentNotificationService.php';

use App\Services\ParentNotificationService;

$notifications = new ParentNotificationService($pdo);

// Send test message
$success = $notifications->notifyParentsOfAchievement(
    studentId: 5,
    achievementTitle: '🏆 Test Achievement Message'
);

echo $success ? "✓ Message sent!\n" : "✗ Message failed\n";

// Check logs
echo "\nCheck application logs for details:\n";
echo "tail -f logs/error.log\n";
?>
```

---

## Send Messages

### From Dashboard/UI

Parents can now receive notifications via WhatsApp when:

1. **Incident Alert** - Teacher logs a deduction
   ```
   → Sends incident alert to parents with marks deducted
   ```

2. **Achievement Notification** - Student earns badge/achievement
   ```
   → Sends congratulation message to parents
   ```

3. **Report Delivery** - New report generated
   ```
   → Sends notification with link to download report
   ```

### Programmatically

```php
// Send incident notification
$notifications->notifyParentsOfIncident(
    studentId: 5,
    deductionId: 12,
    reason: 'Late to class',
    marksDeducted: 2
);

// Send achievement notification
$notifications->notifyParentsOfAchievement(
    studentId: 5,
    achievementTitle: '🏆 Perfect Attendance'
);

// Send report notification
$notifications->sendReportToParents(
    studentId: 5,
    reportFileId: 1,
    reportType: 'Term Report'
);
```

---

## Troubleshooting

### Problem: "WhatsApp: Credentials not configured"

**Solution:**
1. Check `.env` file exists in project root
2. Verify `TWILIO_ACCOUNT_SID` and `TWILIO_WHATSAPP_NUMBER` are set
3. Check environment variables are loaded in bootstrap/config

```php
// Debug: Check if env vars loaded
echo getenv('TWILIO_ACCOUNT_SID');
```

### Problem: "HTTP 401 - Unauthorized"

**Solution:**
1. Verify `TWILIO_AUTH_TOKEN` is correct (copy from Twilio Console again)
2. Ensure no extra spaces in `.env` file
3. Check Account SID matches the one in Console

### Problem: Message shows "[Sandbox]" prefix

**Solution:**
- You're using Twilio Sandbox (free testing mode)
- Either:
  - Send sandbox WhatsApp join code to enable testing
  - Request production WhatsApp number from Twilio
  - Or add test phone numbers to sandbox list

### Problem: "HTTP 404 - Invalid recipient"

**Solution:**
1. Phone number format is wrong
2. The recipient hasn't enabled WhatsApp on their number
3. In Sandbox: recipient hasn't joined the sandbox (send join code first)

**To join Sandbox:**
```
Send to Twilio Sandbox Number: "join <code>"
Example: "join quick-pink"
```

### Problem: SMS sends but WhatsApp fails

**Solution:**
1. Check `TWILIO_WHATSAPP_NUMBER` is set (different from SMS number in Sandbox)
2. Verify WhatsApp is enabled on recipient's phone
3. Check phone number format (should include country code, e.g., +250...)

### Check Message Logs

View message history in Twilio Console:

1. Go: **Messaging → Logs → Messages**
2. Filter by phone number
3. See delivery status and any error messages

---

## Cost Estimation

### Twilio Pricing (Approximate)

| Channel | Cost | Notes |
|---------|------|-------|
| **SMS** | $0.01-0.02/msg | Outbound to US/Canada |
| **WhatsApp** | $0.005-0.05/msg | Destination-dependent |
| **Sandbox** | FREE | Testing only, includes "[Sandbox]" |
| **Monthly Minimum** | $0 | Pay-as-you-go |

For Rwanda numbers (+250), WhatsApp typically costs ~$0.03-0.05 per message.

**Example:** 100 notifications/month × $0.03 = **$3-5/month**

---

## Best Practices

### 1. **Use Preferences Wisely**

```php
// Let parents choose their preferred channel
$parent['contact_preference'] = 'whatsapp'; // Primary
// Falls back to 'sms' → 'email' if WhatsApp fails
```

### 2. **Message Templates**

Keep messages concise (WhatsApp: 160 chars optimal):

```
Good: "Student John was late to class. Visit portal for details."
Bad: "<html><body><p>We are writing to formally inform you that...</p>"
```

### 3. **Rate Limiting**

Don't spam parents - respect message frequency:

```php
// Limit notifications per student per day
// (Optional: implement in service)
```

### 4. **Test Before Production**

Always test with Sandbox first:
1. Set up Sandbox WhatsApp
2. Test all notification types
3. Verify phone number formats
4. Then upgrade to production

### 5. **Monitor Delivery**

Check `parent_notifications_log` table:

```sql
SELECT * FROM parent_notifications_log 
WHERE delivery_status = 'failed' 
ORDER BY sent_at DESC;
```

---

## Next Steps

1. ✅ Sign up for Twilio
2. ✅ Get WhatsApp Sandbox or Production number
3. ✅ Add credentials to `.env`
4. ✅ Test integration with `test_twilio.php`
5. ✅ Register parent contacts with WhatsApp numbers
6. ✅ Send first test message
7. ✅ Monitor delivery in logs and console

---

## Support

- **Twilio Docs:** https://www.twilio.com/docs/whatsapp
- **SMS Docs:** https://www.twilio.com/docs/sms
- **Twilio Console:** https://www.twilio.com/console
- **API Reference:** https://www.twilio.com/docs/api

