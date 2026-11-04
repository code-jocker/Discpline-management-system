<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';

// Allow any authenticated user
require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    assert_csrf_token($_POST['csrf_token'] ?? null);
    
    $lang = $_POST['lang'] ?? 'en';
    
    // Validate against allowed languages
    $allowedLanguages = ['en', 'es', 'fr', 'rw'];
    if (in_array($lang, $allowedLanguages, true)) {
        $_SESSION['sbms_lang'] = $lang;
        setcookie('sbms_lang', $lang, time() + (86400 * 30), "/"); // 30 days
    }
    
    // Redirect back
    $referer = $_SERVER['HTTP_REFERER'] ?? app_base_url() . '/index.php';
    header('Location: ' . $referer);
    exit;
}

// Fallback if not POST
header('Location: ' . app_base_url() . '/index.php');
exit;
