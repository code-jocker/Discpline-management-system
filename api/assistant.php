<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/models/User.php';

// Ensure user is authenticated
require_auth();

// Set JSON response header
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$message = $input['message'] ?? '';
$clientContext = is_array($input['context'] ?? null) ? $input['context'] : [];
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

if (!verify_csrf_token($csrfToken)) {
    http_response_code(419);
    echo json_encode(['error' => 'Invalid or missing CSRF token. Refresh the page and try again.']);
    exit;
}

if (empty(trim($message))) {
    echo json_encode(['error' => 'Message cannot be empty.']);
    exit;
}

function sbms_site_guide(?array $user = null): array
{
    $roleName = strtolower((string) ($user['role_name'] ?? 'guest'));
    $isAdmin = in_array($roleName, ['admin', 'headteacher', 'it'], true);
    $canReports = $isAdmin || in_array($roleName, ['teacher', 'deputy headteacher discipline'], true);

    return [
        'app_name' => 'SBMS',
        'home' => '/index.php',
        'pages' => [
            ['name' => 'Dashboard', 'path' => '/index.php', 'purpose' => 'overview, alerts, analytics, blacklist, expulsions'],
            ['name' => 'Rules & Regulations', 'path' => '/rules/index.php', 'purpose' => 'read rules in-browser; admin can upload the current document'],
            ['name' => 'Student Module', 'path' => '/students/index.php', 'purpose' => 'manage students, classes, imports, and reports'],
            ['name' => 'Discipline Engine', 'path' => '/discipline/index.php', 'purpose' => 'deduct marks, view discipline history, and assess risk'],
            ['name' => 'Notifications', 'path' => '/notifications/index.php', 'purpose' => 'view and manage notifications; admin view'],
            ['name' => 'Reports', 'path' => '/reports/index.php', 'purpose' => 'term, yearly, class, and high-risk reports'],
            ['name' => 'Profile', 'path' => '/profile/index.php', 'purpose' => 'view personal profile details'],
            ['name' => 'User Management', 'path' => '/users/index.php', 'purpose' => 'admin-only account management'],
            ['name' => 'System Settings', 'path' => '/settings/index.php', 'purpose' => 'admin-only platform settings'],
        ],
        'visible_modules' => [
            'dashboard', 'rules and regulations', 'student module', 'discipline engine', 'profile'
        ],
        'role_notes' => [
            'admin_only' => ['notifications management', 'user management', 'system settings', 'term control'],
            'reports_access' => $canReports ? 'available' : 'limited',
        ],
        'response_rules' => [
            'answer_with_page_names' => true,
            'prefer_short_action_steps' => true,
            'if_feature_missing_say_so' => true,
            'include_navigation_hint_when_useful' => true,
        ],
    ];
}

$currentUser = current_auth_user();
$siteGuide = sbms_site_guide($currentUser);
$siteGuideText = json_encode($siteGuide, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$contextText = trim((string) ($clientContext['pageTitle'] ?? '')) . ' | ' . trim((string) ($clientContext['pagePath'] ?? ''));
$contextText = trim($contextText, ' |');
$currentRoleName = (string) ($currentUser['role_name'] ?? 'guest');
$accuracyGuardrails = implode(' ', [
    'Use only facts supported by the SBMS site guide and the current page context.',
    'Do not invent pages, buttons, counts, permissions, or data.',
    'If the request is outside the available context, say you cannot verify it and point the user to the relevant page or ask a clarifying question.',
    'When answering navigation questions, mention the exact SBMS page name.',
]);

$apiKey = getenv('GEMINI_API_KEY');

if (!$apiKey) {
    // Fallback to Mock AI if no API key is provided
    $messageLower = strtolower($message);
    $reply = "I'm in offline mode, so I'll stay limited to verified SBMS pages and navigation help. Ask me about Dashboard, Rules & Regulations, Student Module, Discipline Engine, Reports, Notifications, Profile, User Management, or System Settings.";

    if (str_contains($messageLower, 'hello') || str_contains($messageLower, 'hi')) {
        $reply = "Hello. I can help with verified SBMS navigation and page guidance in offline mode.";
    } elseif (str_contains($messageLower, 'risk') || str_contains($messageLower, 'critical')) {
        $reply = "To check student risks, please visit the Dashboard's 'Active High Risk Students' section. You can also view the full risk report in the Reports module.";
    } elseif (str_contains($messageLower, 'student') || str_contains($messageLower, 'search')) {
        $reply = "You can manage and search for students using the Student Module from the sidebar.";
    } elseif (str_contains($messageLower, 'discipline') || str_contains($messageLower, 'deduct')) {
        $reply = "To log a discipline issue or deduct marks, please use the Discipline Engine. Make sure you have the correct permissions!";
    } elseif (str_contains($messageLower, 'admin') || str_contains($messageLower, 'role')) {
        $reply = "User roles and administrative settings can be found under the 'Administrative' menu in the sidebar.";
    } elseif (str_contains($messageLower, 'rules') || str_contains($messageLower, 'regulation')) {
        $reply = "Open Rules & Regulations from the sidebar to read the policy inline. Admin users can also upload a replacement document there.";
    } elseif (str_contains($messageLower, 'report')) {
        $reply = "Use the Reports page for term, yearly, class, and high-risk exports. The Dashboard also links to the full report area.";
    } elseif (str_contains($messageLower, 'notification')) {
        $reply = "Notifications are available from the sidebar. Admin users can view the full notification list, while the Dashboard shows unread alerts.";
    } elseif (str_contains($messageLower, 'profile')) {
        $reply = "Open My Profile from the sidebar to view your account details and profile information.";
    }
    
    usleep(800000); // Simulate processing delay
    echo json_encode(['reply' => $reply]);
    exit;
}

// Use Gemini API
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $apiKey;

// System instructions to give context about the SBMS system
$systemPrompt = "You are the SBMS (Student Behavior Management System) AI Assistant. You help users discover and use every part of the SBMS website, including Dashboard, Rules & Regulations, Student Module, Discipline Engine, Notifications, Reports, Profile, User Management, and System Settings. " . $accuracyGuardrails . " Be concise, professional, and practical.\n\nCurrent user role: " . $currentRoleName . "\nCurrent page context: " . ($contextText !== '' ? $contextText : 'unknown') . "\n\nSBMS site guide: " . $siteGuideText;

$data = [
    'contents' => [
        [
            'parts' => [
                ['text' => "System: " . $systemPrompt . "\nUser: " . $message]
            ]
        ]
    ],
    'generationConfig' => [
        'temperature' => 0.3,
        'maxOutputTokens' => 350,
    ]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error || $httpCode !== 200) {
    \Database::logError('Gemini API Error', ['code' => $httpCode, 'error' => $error, 'response' => $response]);
    echo json_encode(['error' => 'Sorry, I am having trouble connecting to my AI brain right now. Please try again later.']);
    exit;
}

$responseData = json_decode($response, true);
$reply = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? 'I could not generate a response.';

// Format the reply simply for HTML (replace newlines with <br> is handled in frontend)
echo json_encode(['reply' => trim($reply)]);
exit;
