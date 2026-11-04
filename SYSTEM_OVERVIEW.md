# SBMS System Overview — How It Works

**System:** Student Behavior Management System (SBMS)  
**Type:** Web-based, role-based school behavior management platform  
**Language:** PHP 8 (backend), JavaScript/Chart.js (frontend), MySQL/MariaDB (database)  
**Date:** September 2026 | Version: 1.0

---

## 1. What the System Does

SBMS is a **merit-based behavior management system**. Every student starts each term with **40 conduct marks**. Staff deduct marks for behavioral infractions. The system tracks each student's running score, automatically assesses their risk level, notifies parents, and generates reports — all in real time.

Core flow:

```
Incident Logged → Marks Deducted → Risk Reassessed → Notifications Sent → Reports Generated
```

---

## 2. How It Works — Step by Step

### 2.1 User Logs In
1. User visits `/auth/login.php`
2. `AuthController::login()` verifies email/username + password against `users` table
3. On success, a secure session is created (`SBMSSESSID` cookie) with fingerprint validation
4. User is redirected to the dashboard at `/index.php`

**Key files:** `auth/login.php`, `auth/AuthController.php`, `auth/session.php`

### 2.2 Teacher Logs an Incident (Deduct Marks)
1. Teacher visits `/discipline/deduct.php` or uses the quick log
2. Selects student, reason, and number of marks to deduct
3. `DisciplineService::deduct()` is called which:
   - Locks the student's marks row (`SELECT ... FOR UPDATE`)
   - Creates a record in `deductions_history` (audit trail)
   - Updates `student_marks` (marks go down, capped at 0–40)
   - Triggers `RiskEngineService::assessStudent()` automatically
   - Sends notifications to class teacher + admin roles via `Notification::createBulk()`
   - If marks fall below threshold, auto-generates a parent letter via `LetterService`
4. All actions are logged in `audit_logs` with IP address

**Key files:** `app/Services/DisciplineService.php`, `app/Services/RiskEngineService.php`, `models/Deduction.php`

### 2.3 Risk Engine Assessment (Automatic)
After every mark change, the system evaluates the student's risk:

1. `RiskEngineService::assessStudent()` gathers features:
   - Number of incidents this term
   - Total marks deducted / rewarded
   - Average deductions per week
   - Current marks score
   - Lowest marks ever recorded
2. Classification rules run:
   - **Score ≤ 19** → `High Risk` (strict policy override)
   - **Score ≤ 29** → `Medium Risk`
   - **Incidents ≥ 8 OR deductions ≥ 40** → `Critical`
   - **Incidents ≥ 3 OR deductions ≥ 15** → `Warning`
   - Otherwise → `Excellent`
3. Result is stored in `student_risk_profiles` table
4. If Critical/High Risk: admin users receive a bulk notification
5. If score below threshold: parent invitation letter auto-generated

**Key files:** `app/Services/RiskEngineService.php`, `app/Services/AiRiskService.php`

### 2.4 Parent Notification (Automatic)
When a deduction occurs:
1. `Notification::resolveDeductionRecipients()` finds who should be notified:
   - The student's class teacher
   - All admin/headteacher/discipline officer users
2. `Notification::createBulk()` inserts notifications for each recipient
3. `ParentNotificationService` sends messages via:
   - **Email** (PHP `mail()` function — SMTP configurable)
   - **SMS** (Twilio API)
   - **WhatsApp** (Twilio API)
4. All delivery attempts are logged in `parent_notifications_log`
5. Parent contact preferences are stored in `parent_contacts` table

**Key files:** `app/Services/ParentNotificationService.php`, `models/Notification.php`

### 2.5 Dashboard (Real-time Overview)
When any user visits `/index.php`:
1. System checks authentication and role
2. `get_dashboard_stats()` runs optimized COUNT queries:
   - Total students, classes, incidents this term, high-risk students
3. Results are cached for 60 seconds (static variable)
4. For admins: unread notifications are fetched and grouped (Today/Yesterday/Older)
5. High-risk, blacklisted, and expelled students are listed
6. Chart.js renders risk distribution and class comparison charts

**Key files:** `index.php`, `dashboard/metrics.php`, `dashboard/notifications.php`

### 2.6 Reports
Reports are generated from `/reports/`:
- **Yearly reports** (`reports/yearly.php`) — full-year overview
- **Term reports** (`reports/term.php`) — term-by-term analysis
- **Student reports** (`reports/student.php`) — individual conduct history
- **High-risk reports** (`reports/high_risk.php`) — students needing intervention
- **Class term reports** (`reports/class_term.php`) — per-class breakdown

**Key files:** `reports/*.php`, `app/Services/ReportService.php`

---

## 3. User Roles & Permissions

| Role | Permissions | Where in Code |
|------|------------|---------------|
| **Admin** | Full access — all actions, settings, user management | `role_id` linked to "Admin" role |
| **Headteacher** | Full access — approve expulsions, view all | `userHasPrivilegedRole()` returns true |
| **Teacher** | Deduct marks, view students, view discipline history | `canDeduct()` checks permission/role |
| **Deputy Headteacher (Discipline)** | Deduct marks + blacklist management | `$is_discipline_deputy` flag |
| **IT** | Full platform access | Included in privileged roles |

Permission check flow:
```
User action → require_auth() → require_permission('deduct_marks') 
→ User model hasPermission() → SQL: role_permissions + permissions JOIN
```

**Key files:** `auth/check.php`, `models/User.php`, `database/core_schema.sql` (role_permissions table)

---

## 4. Database Architecture

### Core Tables (from `database/core_schema.sql`)

```
users ←→ roles ←→ permissions (via role_permissions)
students ←→ classes ←→ categories
student_marks (one row per student per term, score 0–40)
deductions_history (every mark change, with reason + timestamp)
student_risk_profiles (assessed risk level per student)
notifications (user notifications with status/priority)
audit_logs (immutable record of all actions)
settings (key-value system configuration)
school_years (academic year/term management)
parent_contacts (parent/guardian info for WhatsApp/SMS/email)
student_letters (auto-generated parent invitation letters)
blacklist (students blocked from system actions)
expulsions (formally expelled students)
remember_tokens (persistent login tokens)
password_resets (password reset tokens)
stored_files (uploaded evidence/files)
```

### Data Flow for a Single Incident

```
POST /discipline/deduct.php
  ↓
DisciplineService::deduct()
  ├── DisciplineRepository::getStudentTermForUpdate() [SELECT ... FOR UPDATE]
  ├── DisciplineRepository::createHistory() [INSERT deductions_history]
  ├── DisciplineRepository::deductMarks() [UPDATE student_marks]
  ├── RiskEngineService::assessStudent() [INSERT/UPDATE student_risk_profiles]
  ├── Notification::createBulk() [INSERT notifications]
  ├── ParentNotificationService::notifyParentsOfIncident() [email/SMS/WhatsApp]
  ├── LetterService::generateParentInvitationLetter() [INSERT student_letters]
  ├── AuditLog::create() [INSERT audit_logs]
  └── Return processed student data
```

---

## 5. Security Mechanisms

| Mechanism | Implementation | File |
|-----------|---------------|------|
| **Session security** | HttpOnly, SameSite=Lax, Secure cookies; fingerprint validation; 30-minute timeout | `auth/session.php` |
| **CSRF protection** | Random token per session, verified on every POST | `auth/csrf.php` |
| **Rate limiting** | File-based counter on login page (max 30 attempts/hour) | `auth/login.php` |
| **Password hashing** | `password_hash()` / `password_verify()` with bcrypt | `auth/AuthController.php` |
| **SQL injection** | PDO prepared statements with named parameters throughout | All models |
| **XSS prevention** | `e()` helper = `htmlspecialchars()` on all output | `auth/session.php` |
| **Remember me** | Selector:validator token (selector stored, validator hashed) | `auth/AuthController.php` |
| **Role-based access** | Checked at PHP level before any action | `auth/check.php` |

---

## 6. API Architecture

### REST API (v1)
Base URL: `/api/v1/`

**Bootstrap:** `bootstrap/api.php` → loads `routes/features.php`

**Router:** `app/Core/Router.php` — supports GET, POST, PATCH, DELETE with path parameters and middleware

**Request/Response:** `app/Core/Request.php`, `app/Core/Response.php`

**Routes registered** (from `routes/features.php`):
- `/students/:id/achievements` — Rewards
- `/classes/:id/leaderboard` — Gamification
- `/leaderboards/build` — Build monthly leaderboard
- `/incidents/:id/details` — Collaborative incident details
- `/incidents/:id/comments` — Add comments
- `/incidents/:id/actions` — Create follow-up actions
- `/calendar/events` — Calendar CRUD
- `/analytics/dashboard` — Dashboard metrics
- `/analytics/incidents` — Incident analytics
- `/reports/term` — Generate term report
- `/reports/class-progress` — Generate class progress report

### Legacy API
- `api/v1/index.php` — Legacy API entry point
- `api/bootstrap.php` — API bootstrap

---

## 7. Frontend Architecture

### How Pages Load
Each PHP page follows this pattern:
```php
1. Load config/db.php → Database connection
2. Load auth/session.php → Start secure session
3. Load header.php → HTML head + navigation
4. Render page content (PHP + HTML)
5. Load footer.php → Scripts + closing tags
```

### Key JavaScript Files (built via Vite)
- `assets/js/dashboard.js` — Dashboard interactivity, Chart.js charts, AJAX
- `assets/js/auth.js` — Login form handling, QR code generation
- `assets/js/theme.js` — Dark/light mode toggle
- `assets/js/discipline-engine.js` — Discipline page logic
- `assets/js/student-management.js` — Student CRUD
- `assets/js/settings.js` — Settings page
- `assets/js/assistant.js` — AI assistant integration
- `assets/js/notifications.js` — Notification feed updates

### Build Command
```bash
npm run dev    # Development server
npm run build  # Production build (outputs to assets/dist/)
```

---

## 8. Services Layer (app/Services/)

| Service | Purpose | Key Methods |
|---------|---------|------------|
| **DisciplineService** | Process deductions, mark updates, risk reassessment | `deduct()`, `canDeduct()` |
| **RiskEngineService** | Assess student risk levels | `assessStudent()`, `assessAllStudents()` |
| **AiRiskService** | Connect to external AI prediction API | `predict()` |
| **ParentNotificationService** | Send email/SMS/WhatsApp to parents | `notifyParentsOfIncident()`, `registerParent()` |
| **LetterService** | Generate parent invitation letters | `generateParentInvitationLetter()`, `syncLowMarksLettersForTerm()` |
| **DashboardService** | Aggregate dashboard metrics | `metrics()` |
| **ReportService** | Generate reports and analytics | `getDashboardAnalytics()`, `generateTermReport()` |
| **RewardsService** | Achievement badges, points, leaderboards | `awardAchievement()`, `buildMonthlyLeaderboard()` |
| **CollaborativeService** | Incident comments and follow-up actions | `addComment()`, `createAction()` |
| **CalendarService** | Schedule events | `createEvent()`, `getMonthView()` |

---

## 9. Configuration & Environment

### Environment Variables (`.env`)
```env
SBMS_DB_HOST=localhost       # Database host
SBMS_DB_NAME=sbms            # Database name
SBMS_DB_USER=root            # Database user
SBMS_DB_PASS=                # Database password
SBMS_BASE_URL=http://localhost/sbms  # Application base URL
MAIL_HOST=localhost          # SMTP host
TWILIO_ACCOUNT_SID=          # Twilio SID for SMS/WhatsApp
TWILIO_AUTH_TOKEN=           # Twilio auth token
TWILIO_PHONE_NUMBER=         # Twilio phone number
TWILIO_WHATSAPP_NUMBER=      # Twilio WhatsApp number
SBMS_PRIVILEGED_ROLE_NAMES=admin,headteacher,it,...  # Roles with full access
```

### Settings Stored in Database (`settings` table)
Key values include: `current_term`, `selected_school_year`, `max_marks_per_term` (default 40), `low_marks_threshold` (default 20), `low_marks_letter_enabled`, `low_marks_letter_template`, school name, app logo, etc.

**Key file:** `models/Settings.php`

---

## 10. Directory Structure

```
sbms/
├── index.php                 # Main dashboard entry point
├── auth/                     # Authentication (login, register, session, CSRF)
├── models/                   # Data models (User, Student, Settings, Notification...)
├── controllers/              # PHP controllers (Student, User, Discipline...)
├── app/
│   ├── Services/             # Business logic services
│   ├── Repositories/         # Data access layer
│   ├── Core/                 # Router, Request, Response, Middleware
│   ├── Http/                 # HTTP middleware (Tenant, JWT, RateLimit, Security)
│   └── Controllers/          # API controllers
├── routes/                   # Route definitions (features.php, api_v1.php)
├── bootstrap/                # App bootstrap (api.php)
├── config/                   # DB connection, environment
├── database/                 # SQL schemas, setup scripts
├── dashboard/                # Dashboard pages (analytics, leaderboard, calendar)
├── discipline/               # Discipline pages (deduct, quick-log, history)
├── students/                 # Student management pages
├── reports/                  # Report pages
├── parents/                  # Parent portal
├── payments/                 # Payment processing (webhook, callback)
├── notifications/            # Notifications pages
├── users/                    # User management
├── settings/                 # System settings
├── rules/                    # Rules configuration
├── api/                      # API endpoints
├── assets/                   # CSS, JS, images (built via Vite)
├── storage/                  # Logs, temporary files
├── uploads/                  # File uploads
└── workers/                  # Background workers (cron jobs)
```

---

## 11. Background Processing

Scheduled tasks (cron jobs) for ongoing maintenance:

```bash
# Monthly leaderboard rebuild (1st of each month)
0 0 1 * * /usr/bin/php /var/www/sbms/workers/build_leaderboards.php

# Email digests (Friday 9 AM)
0 9 * * 5 /usr/bin/php /var/www/sbms/workers/send_digests.php

# Risk predictions (Daily 6 AM)
0 6 * * * /usr/bin/php /var/www/sbms/workers/predict_risks.php

# Retry failed notifications (Every 30 min)
*/30 * * * * /usr/bin/php /var/www/sbms/workers/retry_notifications.php
```

---

## Summary

**SBMS works by:**
1. **Authenticating** users through secure sessions with role-based access
2. **Tracking behavior** through mark deductions (0–40 per term per student)
3. **Automatically assessing** risk levels after every change
4. **Notifying** staff and parents via email/SMS/WhatsApp
5. **Generating** letters, reports, and analytics for decision-making
6. **Logging** every action for audit and compliance

All data flows through prepared SQL queries (safe from injection), all outputs are escaped (safe from XSS), and every write operation is logged in the audit trail.
