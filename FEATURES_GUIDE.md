# SBMS Enhanced Features Implementation Guide

**Version:** 2.0  
**Date:** July 25, 2026  
**Status:** Ready for Implementation

## Recommended Product Alignment

The app should now be aligned around the highest-value workflows first:

1. Teacher quick log
2. Attendance tracking
3. Parent portal
4. Smart alerts and automation
5. Advanced analytics and reporting

Current implementation focus:
- The quick log screen should be the main fast-entry path for teachers.
- Discipline actions must continue to reuse existing audit logging, notification delivery, and risk reassessment.
- Analytics and reporting should remain management-facing decision tools.
- Parent-facing and attendance features should be added after the core teacher workflow is stable.

## Table of Contents

1. [Features Overview](#features-overview)
2. [Database Setup](#database-setup)
3. [Core Services](#core-services)
4. [API Endpoints](#api-endpoints)
5. [UI Pages](#ui-pages)
6. [Quick Wins](#quick-wins)
7. [Configuration](#configuration)
8. [Deployment](#deployment)

---

## Features Overview

### 1. **Behavioral Rewards & Gamification** ✅
Students earn achievement badges and points for positive behavior:
- **Files:**
  - `app/Services/RewardsService.php`
  - `app/Controllers/RewardsController.php`
  - `dashboard/leaderboard.php`

- **Features:**
  - Award achievements (badges, milestones, improvements)
  - Track cumulative reward points
  - Generate monthly class leaderboards
  - Display top performers and badges

- **Database Tables:**
  - `achievements` - Student badges earned
  - `student_rewards` - Point tracking
  - `monthly_leaderboards` - Ranking cache

### 2. **Collaborative Notes & Follow-up Actions** ✅
Multi-teacher collaboration on incidents:
- **Files:**
  - `app/Services/CollaborativeService.php`
  - `app/Controllers/CollaborativeController.php`

- **Features:**
  - Add comments to incidents (internal/public)
  - Create follow-up actions with assignments
  - Track action status (pending→in_progress→completed)
  - Automatic calendar event generation for due dates

- **Database Tables:**
  - `incident_comments` - Discussion threads
  - `incident_actions` - Follow-up tasks

### 3. **Calendar & Event Scheduling** ✅
Centralized scheduling interface:
- **Files:**
  - `app/Services/CalendarService.php`
  - `app/Controllers/CalendarController.php`
  - `dashboard/calendar.php`

- **Features:**
  - Monthly calendar view
  - Event types (letter follow-up, parent meeting, counseling, detention, custom)
  - Upcoming events dashboard
  - Student-specific event listing

- **Database Tables:**
  - `calendar_events` - Event storage

### 4. **Parent Notifications & Communication** ✅
Automated parent/guardian notifications:
- **Files:**
  - `app/Services/ParentNotificationService.php`
  - `app/Controllers/NotificationController.php`

- **Features:**
  - Register parent/guardian contact information
  - Multi-channel delivery (Email, SMS, WhatsApp - extensible)
  - Incident alerts to parents
  - Achievement notifications
  - Report delivery
  - Notification log & delivery tracking

- **Database Tables:**
  - `parent_contacts` - Guardian information
  - `parent_notifications_log` - Delivery tracking

### 5. **Analytics & Custom Reports** ✅
Advanced reporting and dashboard analytics:
- **Files:**
  - `app/Services/ReportService.php` (enhanced)
  - `app/Controllers/AnalyticsController.php`
  - `dashboard/analytics.php`

- **Features:**
  - Dashboard overview (avg marks, total incidents, top performers)
  - Incident analysis by category
  - Term reports (HTML/PDF/CSV export)
  - Class progress reports
  - Custom report templates
  - Caching for performance

- **Database Tables:**
  - `report_templates` - Custom report definitions
  - `generated_reports` - Report audit trail
  - `analytics_cache` - Performance optimization

---

## Database Setup

### Run Migration

```bash
# Connect to MySQL and run:
mysql -u root -p sbms < database/migration_2026_features.sql

# Or using PHP:
php -r "
    require 'config/db.php';
    \$migration = file_get_contents('database/migration_2026_features.sql');
    foreach (explode(';', \$migration) as \$query) {
        if (trim(\$query)) \$pdo->exec(\$query);
    }
    echo 'Migration complete!';
"
```

### Verify Tables

```sql
SHOW TABLES LIKE '%achievement%';
SHOW TABLES LIKE '%reward%';
SHOW TABLES LIKE '%comment%';
SHOW TABLES LIKE '%action%';
SHOW TABLES LIKE '%calendar%';
SHOW TABLES LIKE '%parent%';
SHOW TABLES LIKE '%report%';
```

---

## Core Services

### RewardsService

```php
$rewards = new \App\Services\RewardsService($pdo, $parentNotificationService);

// Award achievement
$achievementId = $rewards->awardAchievement(
    studentId: 5,
    type: 'marks_milestone',
    title: '🏆 Excellent Marks',
    description: 'Achieved 35+ marks',
    points: 25
);

// Check for milestones after deduction
$rewards->checkAndAwardMilestones($studentId, $currentMarks);

// Get student rewards
$rewards = $rewards->getStudentRewards($studentId);
// Returns: ['total_points' => 150, 'current_streak' => 5]

// Build monthly leaderboard
$rewards->buildMonthlyLeaderboard($classId, '2026-07');

// Get class leaderboard
$leaderboard = $rewards->getClassLeaderboard($classId);
```

### ParentNotificationService

```php
$notifications = new \App\Services\ParentNotificationService($pdo);

// Register parent
$parentId = $notifications->registerParent(
    studentId: 5,
    parentName: 'John Smith',
    relationship: 'father',
    email: 'john@email.com',
    phone: '+250788123456',
    whatsapp: '+250788123456',
    preference: 'email',
    isPrimary: true
);

// Notify parents of incident
$notifications->notifyParentsOfIncident(
    studentId: 5,
    deductionId: 12,
    reason: 'Late to class',
    marksDeducted: 2
);

// Notify of achievement
$notifications->notifyParentsOfAchievement(5, '🏆 Excellent Marks');

// Get parent contacts
$parents = $notifications->getParentContacts($studentId);
```

### CollaborativeService

```php
$collab = new \App\Services\CollaborativeService($pdo);

// Add comment
$commentId = $collab->addComment(
    deductionId: 12,
    userId: 3,
    comment: 'Follow-up needed',
    isInternal: false
);

// Get incident details with comments and actions
$incident = $collab->getIncidentDetails($deductionId);

// Create action
$actionId = $collab->createAction(
    deductionId: 12,
    assignedToId: 5,
    title: 'Contact Parent',
    type: 'parent_contact',
    dueDate: '2026-07-30'
);

// Get pending actions
$actions = $collab->getPendingActions($userId, 'pending');

// Mark action complete
$collab->completeAction($actionId, $userId);
```

### CalendarService

```php
$calendar = new \App\Services\CalendarService($pdo);

// Create event
$eventId = $calendar->createEvent(
    eventType: 'parent_meeting',
    title: 'Parent Meeting - John Doe',
    description: 'Discuss behavioral issues',
    eventDate: '2026-08-01',
    createdBy: 1,
    studentId: 5,
    eventTime: '14:00',
    location: 'Office'
);

// Get month view
$calendar = $calendar->getMonthView(2026, 7, $classId);

// Get upcoming events
$events = $calendar->getUpcomingEvents(10);

// Get student events
$studentEvents = $calendar->getStudentEvents($studentId, '2026-07');
```

### ReportService (Enhanced)

```php
$reports = new \App\Services\ReportService($pdo);

// Get dashboard analytics
$analytics = $reports->getDashboardAnalytics($classId, $term);
// Returns: {class_average_marks, total_students, total_incidents, students, top_performers}

// Get incident analytics
$incidents = $reports->getIncidentAnalytics($classId, $term);
// Returns: [{incident_category, incident_count, total_deductions}, ...]

// Generate term report
$result = $reports->generateTermReport($studentId, $term, $generatedBy, 'pdf');
```

---

## API Endpoints

All endpoints are prefixed with `/api/v1`

### Rewards Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/students/:id/achievements` | Get student achievements |
| GET | `/classes/:id/leaderboard` | Get class leaderboard |
| POST | `/leaderboards/build` | Build monthly leaderboard |

### Collaborative Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/incidents/:id/details` | Get incident with comments/actions |
| POST | `/incidents/:id/comments` | Add comment to incident |
| POST | `/incidents/:id/actions` | Create follow-up action |
| GET | `/users/:id/pending-actions` | Get user's pending actions |
| PATCH | `/actions/:id/complete` | Mark action complete |
| PATCH | `/actions/:id/status` | Update action status |

### Calendar Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/calendar/events` | Get events for date range |
| GET | `/calendar/month` | Get month view |
| GET | `/calendar/upcoming` | Get upcoming events |
| GET | `/students/:id/calendar-events` | Get student events |
| POST | `/calendar/events` | Create event |
| DELETE | `/calendar/events/:id` | Delete event |

### Analytics Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/analytics/dashboard` | Get dashboard analytics |
| GET | `/analytics/incidents` | Get incident analytics |
| POST | `/reports/term` | Generate term report |
| POST | `/reports/class-progress` | Generate class progress report |

---

## UI Pages

### Dashboard Pages

1. **`dashboard/analytics.php`**
   - URL: `/dashboard/analytics.php?class_id=1&term=1`
   - Shows: Class stats, top performers, incident analysis
   - Components: Summary cards, data tables, charts (optional)

2. **`dashboard/leaderboard.php`**
   - URL: `/dashboard/leaderboard.php?class_id=1&month_year=2026-07`
   - Shows: Monthly rankings, achievements, medals
   - Components: Leaderboard table, achievement grid

3. **`dashboard/calendar.php`**
   - URL: `/dashboard/calendar.php?month_year=2026-07`
   - Shows: Calendar grid, upcoming events, event details
   - Components: Calendar grid, event modal, add event form

---

## Quick Wins

### 1. Enhanced Search & Filters
**File:** `api/v1/search.php`

```php
// Save search filter
POST /api/v1/filters/save
{
    "filter_name": "Late Incidents",
    "filter_type": "incident_search",
    "filter_config": {
        "reason": "%late%",
        "min_marks": 0,
        "max_marks": 5
    }
}

// Get saved filters
GET /api/v1/users/:id/filters

// Apply saved filter
GET /api/v1/search?filter_id=1
```

### 2. Dark Mode Toggle Enhancement
**File:** `assets/js/theme-switcher.js`

```javascript
// Improved toggle with smooth transitions
class ThemeSwitcher {
    constructor() {
        this.toggle = document.getElementById('themeToggle');
        this.init();
    }

    init() {
        this.toggle.addEventListener('click', () => this.switch());
        this.loadPreference();
    }

    switch() {
        const isDark = document.body.dataset.theme === 'dark';
        document.body.dataset.theme = isDark ? 'light' : 'dark';
        localStorage.setItem('theme', document.body.dataset.theme);
        this.updateToggleState();
    }

    loadPreference() {
        const saved = localStorage.getItem('theme') || 'light';
        document.body.dataset.theme = saved;
        this.updateToggleState();
    }

    updateToggleState() {
        const isDark = document.body.dataset.theme === 'dark';
        this.toggle.textContent = isDark ? '☀️ Light' : '🌙 Dark';
    }
}

new ThemeSwitcher();
```

### 3. Email Digest Subscriptions
**File:** `dashboard/digest-settings.php`

```php
// User subscribes to weekly digest
$stmt = $pdo->prepare("
    INSERT INTO email_digest_subscriptions 
    (user_id, digest_type, frequency, include_incidents, include_achievements, include_analytics)
    VALUES (?, 'weekly', 'friday', 1, 1, 1)
");
$stmt->execute([$userId]);

// Send digest (cron job)
// php workers/send_digests.php --type=weekly --frequency=friday
```

### 4. Incident Quick Log
**File:** `discipline/quick-log.php`

```html
<!-- Floating button -->
<button class="floating-btn" id="quickLog">⚡ Quick Log</button>

<!-- Quick modal -->
<div id="quickLogModal">
    <form id="quickLogForm">
        <input type="text" name="student_code" placeholder="Student Code" required>
        <select name="reason" required>
            <option>Choose reason...</option>
            <option>Late to class</option>
            <option>Disruptive behavior</option>
            <option>Missing assignment</option>
        </select>
        <input type="number" name="marks" min="1" max="5" value="1" required>
        <button type="submit">Log Incident</button>
    </form>
</div>
```

### 5. Bulk Student Import Template
**File:** `downloads/import-template.php`

```php
// Generate Excel template
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="students-import-template.xlsx"');

// CSV fallback
$csv = "Student Code,Full Name,Class,Parent Phone\n";
$csv .= "STU001,John Doe,JSS 1A,+250788123456\n";
$csv .= "STU002,Jane Smith,JSS 1A,+250788123457\n";

echo $csv;
```

---

## Configuration

### Environment Variables

```bash
# .env or deploy/.env
MAIL_HOST=localhost
MAIL_PORT=1025
MAIL_FROM=noreply@sbms.local
MAIL_ENCRYPTION=tls

# ════════════════════════════════════════════════════════════
# TWILIO CONFIGURATION - WhatsApp & SMS Messaging (NEW!)
# ════════════════════════════════════════════════════════════
TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_AUTH_TOKEN=axxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_PHONE_NUMBER=+1415xxxxxxx
TWILIO_WHATSAPP_NUMBER=+1415xxxxxxx

# Report generation
PDF_LIBRARY=dompdf  # or mPDF, TCPDF
```

### WhatsApp & SMS Setup (New!)

Parents can now receive incident alerts and achievement notifications via **WhatsApp** and **SMS** using Twilio integration.

**Quick Setup:**

1. **Create Twilio Account**
   ```
   → Visit https://www.twilio.com and sign up (free trial available)
   ```

2. **Get Credentials**
   ```
   → Copy Account SID, Auth Token, Phone Number from Twilio Console
   → Add to .env file above
   ```

3. **Test Integration**
   ```bash
   php test_whatsapp.php
   ```

4. **Register Parents with WhatsApp**
   - Parents set WhatsApp as contact preference
   - Notifications sent via WhatsApp (fallback to SMS → Email)

**Full Setup Instructions:** See [WHATSAPP_SETUP.md](WHATSAPP_SETUP.md)
- Detailed step-by-step guide
- Twilio account creation
- Free Sandbox vs Paid options
- Testing and troubleshooting
- Cost estimation (~$0.03-0.05/message)

### Database Indexes (Performance)

```sql
-- Add these indexes for better query performance
CREATE INDEX idx_achievements_student_created ON achievements(student_id, created_at);
CREATE INDEX idx_comments_deduction_created ON incident_comments(deduction_id, created_at);
CREATE INDEX idx_actions_assigned_status ON incident_actions(assigned_to_id, status);
CREATE INDEX idx_calendar_date_class ON calendar_events(event_date, class_id);
CREATE INDEX idx_parent_notifications_status ON parent_notifications_log(delivery_status, created_at);
```

---

## Deployment

### 1. Pre-deployment Checklist

- [ ] Database migration run successfully
- [ ] All new tables created
- [ ] Indexes added for performance
- [ ] Services instantiated in bootstrap
- [ ] Routes included in API configuration
- [ ] UI pages tested in all breakpoints
- [ ] Email/SMS credentials configured

### 2. Bootstrap Integration

Add to `bootstrap/api.php`:

```php
// Initialize new services
$parentNotifications = new \App\Services\ParentNotificationService($pdo);
$rewards = new \App\Services\RewardsService($pdo, $parentNotifications);
$collaborative = new \App\Services\CollaborativeService($pdo);
$calendar = new \App\Services\CalendarService($pdo);

// Include feature routes
include __DIR__ . '/../routes/features.php';
```

### 3. Cron Jobs (Background Tasks)

```bash
# Build monthly leaderboards (1st of each month)
0 0 1 * * /usr/bin/php /var/www/sbms/workers/build_leaderboards.php

# Send email digests (Friday 9 AM)
0 9 * * 5 /usr/bin/php /var/www/sbms/workers/send_digests.php

# Check for risk predictions (Daily 6 AM)
0 6 * * * /usr/bin/php /var/www/sbms/workers/predict_risks.php

# Retry failed notifications (Every 30 minutes)
*/30 * * * * /usr/bin/php /var/www/sbms/workers/retry_notifications.php
```

### 4. Testing

```bash
# Test API endpoints
curl -X GET http://127.0.0.1:8000/api/v1/analytics/dashboard?class_id=1&term=1

# Test services
php -r "
    require 'config/db.php';
    \$rewards = new \App\Services\RewardsService(\$pdo, null);
    echo 'Services initialized successfully!';
"
```

---

## Next Steps

### Phase 1 (Current) ✅
- Database schema
- Core services
- API endpoints
- Basic UI pages

### Phase 2 (Recommended)
- Implement SMS/WhatsApp integration (Twilio)
- Add PDF export (DOMPDF)
- Build parent portal
- Add predictive analytics

### Phase 3 (Advanced)
- Mobile app integration
- Real-time notifications (WebSocket)
- Advanced analytics with AI
- Custom dashboards per role

---

## Support & Troubleshooting

### Common Issues

**Issue:** Parent notifications not sending
```
Solution: Check MAIL_HOST configuration, verify email address format
```

**Issue:** Leaderboard not updating
```
Solution: Run `POST /api/v1/leaderboards/build` after month changes
```

**Issue:** Calendar events not showing
```
Solution: Verify timezone in PHP.ini and database
```

---

## License & Attribution

SBMS Feature Enhancement v2.0  
Copyright © 2026 | All Rights Reserved

