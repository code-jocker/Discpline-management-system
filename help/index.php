<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_auth();

$pageTitle = 'SBMS How it works';
$bodyClass = 'sbms-plexus-body help-body';
$mainClass = 'dashboard-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/auth.css'];
$viteFooterAssets = ['assets/js/theme.js'];

$workflows = [
    [
        'step' => '1',
        'title' => 'Log the moment',
        'text' => 'Use Quick Log on a phone or the full Discipline Engine on a desktop to record an incident, achievement, or mark change.',
        'link' => '/discipline/quick-log.php',
        'cta' => 'Open Quick Log',
    ],
    [
        'step' => '2',
        'title' => 'The record updates itself',
        'text' => 'Marks, risk level, audit history, guardians, and the student profile refresh immediately. No spreadsheets, no double entry.',
        'link' => '/discipline/index.php',
        'cta' => 'Open Discipline Engine',
    ],
    [
        'step' => '3',
        'title' => 'The family hears about it',
        'text' => 'Each parent contact is notified using their preferred channel: email, SMS, or WhatsApp through the school phone.',
        'link' => '/parents/index.php',
        'cta' => 'Manage Parent Contacts',
    ],
    [
        'step' => '4',
        'title' => 'Follow up and review',
        'text' => 'Check the WhatsApp queue, print low-marks letters, review the calendar, and use analytics and reports to spot patterns.',
        'link' => '/parents/whatsapp-queue.php',
        'cta' => 'Open WhatsApp Queue',
    ],
];

$features = [
    ['title' => 'Quick Log', 'text' => 'Four-step mobile form for the most common discipline actions.', 'link' => '/discipline/quick-log.php', 'badge' => 'Daily use'],
    ['title' => 'Students', 'text' => 'Searchable directory, profiles, guardians, ID cards, and history.', 'link' => '/students/index.php', 'badge' => 'Core'],
    ['title' => 'Discipline Engine', 'text' => 'Full incident workflow with marks, reasons, evidence, and audit trail.', 'link' => '/discipline/index.php', 'badge' => 'Core'],
    ['title' => 'Parent Contacts', 'text' => 'Phone, email, and WhatsApp preferences for every guardian.', 'link' => '/parents/index.php', 'badge' => 'Communication'],
    ['title' => 'WhatsApp Queue', 'text' => 'Pending, sent, and failed WhatsApp jobs with retry and cancel.', 'link' => '/parents/whatsapp-queue.php', 'badge' => 'Communication'],
    ['title' => 'Low-Marks Letters', 'text' => 'Generate and print guardian invitation letters when marks drop.', 'link' => '/letters/index.php', 'badge' => 'Communication'],
    ['title' => 'Leaderboard', 'text' => 'Reward points, badges, and class rankings for positive behavior.', 'link' => '/dashboard/leaderboard.php', 'badge' => 'Rewards'],
    ['title' => 'Analytics', 'text' => 'Term trends, risk distribution, and class comparisons.', 'link' => '/dashboard/analytics.php', 'badge' => 'Insight'],
    ['title' => 'Calendar', 'text' => 'Follow-up due dates and scheduled school events in one view.', 'link' => '/dashboard/calendar.php', 'badge' => 'Planning'],
    ['title' => 'Reports', 'text' => 'Exportable summaries for leadership and discipline committees.', 'link' => '/reports/index.php', 'badge' => 'Leadership'],
    ['title' => 'Notifications', 'text' => 'Per-user alerts, read state, and delivery scope.', 'link' => '/notifications/index.php', 'badge' => 'Daily use'],
    ['title' => 'Settings', 'text' => 'School details, terms, marks thresholds, and communication options.', 'link' => '/settings/index.php', 'badge' => 'Admin'],
];

$roles = [
    ['role' => 'Teacher', 'can' => 'Quick Log, view students, record incidents and achievements'],
    ['role' => 'Discipline team', 'can' => 'Everything teachers can do, plus reports, letters, and the WhatsApp queue'],
    ['role' => 'Head teacher / Deputy', 'can' => 'All discipline tools, analytics, calendar, reports, and history management'],
    ['role' => 'Administrator', 'can' => 'Full access including users, school settings, and communication configuration'],
];
?>
<?php require_once dirname(__DIR__) . '/includes/header.php'; ?>

<style>
    .help-hero {
        display: grid;
        gap: 1.25rem;
        grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
        align-items: start;
        margin-bottom: 1.75rem;
    }
    .help-hero h1 { margin: 0 0 0.5rem; font-size: clamp(1.7rem, 4vw, 2.4rem); letter-spacing: -0.02em; }
    .help-hero .lead { margin: 0; max-width: 62ch; color: var(--of-muted, #6b7280); }
    .help-hero-actions { display: flex; flex-wrap: wrap; gap: 0.6rem; margin-top: 1.1rem; }
    .help-side { display: grid; gap: 0.75rem; }
    .help-pill {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        padding: 0.9rem 1rem;
        border-radius: var(--sbms-radius);
        border: 1px solid var(--sbms-line);
        background: var(--sbms-surface);
        box-shadow: var(--sbms-shadow-sm);
        font-size: 0.88rem;
    }
    .help-pill strong { display: block; color: var(--sbms-ink); }
    .help-pill span.dot {
        flex: 0 0 10px;
        width: 10px;
        height: 10px;
        margin-top: 0.35rem;
        border-radius: 50%;
        background: var(--sbms-gold);
        box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.16);
    }
    .help-pill span.dot.is-indigo { background: var(--sbms-indigo); box-shadow: 0 0 0 4px rgba(36, 56, 164, 0.14); }
    .help-pill span.dot.is-green { background: #047857; box-shadow: 0 0 0 4px rgba(4, 120, 87, 0.14); }
    .help-section { margin-bottom: 2rem; }
    .help-section > h2 { margin: 0 0 0.35rem; font-size: 1.25rem; }
    .help-section > p.sub { margin: 0 0 1rem; color: var(--sbms-muted); font-size: 0.92rem; }
    .help-flow { counter-reset: step; }
    .help-step { display: flex; gap: 1rem; }
    .help-step-num {
        flex: 0 0 42px;
        width: 42px;
        height: 42px;
        display: grid;
        place-items: center;
        border-radius: 50%;
        background: var(--sbms-navy);
        color: #fff;
        font-weight: 700;
    }
    .help-step-body { flex: 1; min-width: 0; padding-bottom: 1.35rem; }
    .help-step-body h3 { margin: 0.15rem 0 0.3rem; font-size: 1rem; }
    .help-step-body p { margin: 0 0 0.55rem; color: var(--sbms-muted); font-size: 0.9rem; line-height: 1.55; }
    .help-link { font-size: 0.85rem; font-weight: 600; color: var(--sbms-indigo); text-decoration: none; }
    .help-link:hover { text-decoration: underline; }
    .help-feature { display: flex; flex-direction: column; gap: 0.45rem; }
    .help-feature h3 { margin: 0; font-size: 1rem; }
    .help-feature p { margin: 0; color: var(--sbms-muted); font-size: 0.88rem; line-height: 1.5; }
    .help-feature a { margin-top: auto; }
    .help-code {
        display: block;
        padding: 0.75rem 0.9rem;
        border-radius: var(--sbms-radius-sm);
        background: #0f172a;
        color: #e2e8f0;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 0.82rem;
        line-height: 1.6;
        overflow-x: auto;
        white-space: pre;
    }
    .help-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
    .help-table th, .help-table td { padding: 0.7rem 0.85rem; text-align: left; border-bottom: 1px solid var(--sbms-line); vertical-align: top; }
    .help-table th { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.07em; color: var(--sbms-muted); }
    .help-table tbody tr:last-child td { border-bottom: 0; }
    @media (max-width: 900px) {
        .help-hero { grid-template-columns: 1fr; }
    }
    @media (max-width: 600px) {
        .help-table { display: block; overflow-x: auto; white-space: nowrap; }
        .help-step-num { flex-basis: 36px; width: 36px; height: 36px; font-size: 0.9rem; }
    }
</style>

<section class="help-hero">
    <div>
        <p class="eyebrow">Guide</p>
        <h1>How SBMS works, end to end</h1>
        <p class="lead">
            SBMS connects one action on the discipline floor to the marks, risk level, guardian message,
            and leadership report that follow it. This page is the map: what each area does, who can use it,
            and how the WhatsApp queue fits in.
        </p>
        <div class="help-hero-actions">
            <a class="sbms-btn sbms-btn--primary" href="<?= e(app_base_url()) ?>/discipline/quick-log.php">Start with Quick Log</a>
            <a class="sbms-btn sbms-btn--secondary" href="<?= e(app_base_url()) ?>/parents/whatsapp-queue.php">Check WhatsApp Queue</a>
        </div>
    </div>
    <div class="help-side">
        <div class="help-pill"><span class="dot"></span><div><strong>One entry point</strong>Every discipline action flows through the same audited engine.</div></div>
        <div class="help-pill"><span class="dot is-indigo"></span><div><strong>Guardian preference wins</strong>Email, SMS, or WhatsApp — the contact record decides.</div></div>
        <div class="help-pill"><span class="dot is-green"></span><div><strong>Nothing is lost</strong>Pending WhatsApp jobs wait in the queue until the school phone is online.</div></div>
    </div>
</section>

<section class="help-section" aria-labelledby="help-loop">
    <h2 id="help-loop">The daily loop</h2>
    <p class="sub">Most schools repeat this four-step cycle every day.</p>
    <div class="help-flow">
        <?php foreach ($workflows as $flow): ?>
            <div class="help-step">
                <div class="help-step-num" aria-hidden="true"><?= e($flow['step']) ?></div>
                <div class="help-step-body">
                    <h3><?= e($flow['title']) ?></h3>
                    <p><?= e($flow['text']) ?></p>
                    <a class="help-link" href="<?= e(app_base_url() . $flow['link']) ?>"><?= e($flow['cta']) ?> &rarr;</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="help-section" aria-labelledby="help-features">
    <h2 id="help-features">Every area and where to find it</h2>
    <p class="sub">The same links are in the sidebar; this grid is for orientation on a new device.</p>
    <div class="sbms-grid">
        <?php foreach ($features as $feature): ?>
            <article class="sbms-card help-feature">
                <div class="sbms-card__body">
                    <span class="sbms-badge sbms-badge--neutral"><?= e($feature['badge']) ?></span>
                    <h3><?= e($feature['title']) ?></h3>
                    <p><?= e($feature['text']) ?></p>
                    <a class="help-link" href="<?= e(app_base_url() . $feature['link']) ?>">Open <?= e($feature['title']) ?> &rarr;</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="help-section" aria-labelledby="help-whatsapp">
    <h2 id="help-whatsapp">How WhatsApp delivery works</h2>
    <p class="sub">The PHP app never talks to WhatsApp directly. It writes a job and a separate Node worker sends it.</p>
    <div class="sbms-grid">
        <article class="sbms-card">
            <div class="sbms-card__body">
                <h3 style="margin-top:0; font-size:1rem;">1. PHP queues the message</h3>
                <p style="color:var(--sbms-muted); font-size:0.9rem; line-height:1.55;">
                    Recording an incident or achievement inserts a row into <code>notification_queue</code> when the
                    parent's preferred channel is WhatsApp. The page keeps working even when the phone is offline.
                </p>
            </div>
        </article>
        <article class="sbms-card">
            <div class="sbms-card__body">
                <h3 style="margin-top:0; font-size:1rem;">2. The worker sends it</h3>
                <p style="color:var(--sbms-muted); font-size:0.9rem; line-height:1.55;">
                    The worker claims pending jobs, sends through the linked school phone, and writes the outcome to
                    <code>notification_logs</code>. Failed jobs retry with backoff.
                </p>
            </div>
        </article>
        <article class="sbms-card">
            <div class="sbms-card__body">
                <h3 style="margin-top:0; font-size:1rem;">3. Staff monitor the queue</h3>
                <p style="color:var(--sbms-muted); font-size:0.9rem; line-height:1.55;">
                    The WhatsApp Queue page shows pending, processing, sent, and failed jobs. Staff can retry a failed
                    message or cancel one that is still pending.
                </p>
            </div>
        </article>
    </div>
    <div class="sbms-card" style="margin-top:1rem;">
        <div class="sbms-card__head"><h3 class="sbms-card__title">Starting the worker on the school computer</h3></div>
        <div class="sbms-card__body">
            <code class="help-code">cd whatsapp-service
npm install
npm start</code>
            <p style="margin:0.75rem 0 0; color:var(--sbms-muted); font-size:0.88rem; line-height:1.55;">
                Scan the QR code with the school phone once (WhatsApp &rarr; Linked Devices). The session is reused on
                later starts. If the queue page says <strong>Worker offline</strong>, the Node service is not running.
            </p>
        </div>
    </div>
</section>

<section class="help-section" aria-labelledby="help-roles">
    <h2 id="help-roles">Who can do what</h2>
    <p class="sub">Role names come from the user management screen.</p>
    <div class="sbms-card">
        <div class="sbms-card__body" style="padding:0;">
            <table class="help-table">
                <thead>
                    <tr><th scope="col">Role</th><th scope="col">Typical access</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $role): ?>
                        <tr>
                            <th scope="row"><?= e($role['role']) ?></th>
                            <td><?= e($role['can']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="help-section" aria-labelledby="help-next">
    <h2 id="help-next">Need a reminder later?</h2>
    <p class="sub">The AI assistant can answer questions about any page, and the written docs stay in the project root.</p>
    <div class="sbms-cluster">
        <a class="sbms-btn sbms-btn--ghost" href="<?= e(app_base_url()) ?>/FEATURES_GUIDE.md">Feature guide (Markdown)</a>
        <a class="sbms-btn sbms-btn--ghost" href="<?= e(app_base_url()) ?>/WHATSAPP_SETUP.md">WhatsApp setup</a>
        <a class="sbms-btn sbms-btn--ghost" href="<?= e(app_base_url()) ?>/index.php">Back to Dashboard</a>
    </div>
</section>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
