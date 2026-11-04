<?php
declare(strict_types=1);

/**
 * Basic i18n implementation for SBMS.
 */

function get_current_language(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Check session first
    if (isset($_SESSION['sbms_lang'])) {
        return $_SESSION['sbms_lang'];
    }
    
    // Check cookie
    if (isset($_COOKIE['sbms_lang'])) {
        $_SESSION['sbms_lang'] = $_COOKIE['sbms_lang'];
        return $_COOKIE['sbms_lang'];
    }
    
    // Default to English
    return 'en';
}

function load_translations(string $lang): array {
    $langFile = dirname(__DIR__) . '/lang/' . $lang . '.php';
    if (file_exists($langFile)) {
        return require $langFile;
    }
    
    // Fallback to English
    $fallback = dirname(__DIR__) . '/lang/en.php';
    if (file_exists($fallback)) {
        return require $fallback;
    }
    
    return [];
}

$GLOBALS['i18n_translations'] = load_translations(get_current_language());

/**
 * Translate a string based on a given key.
 */
function __(string $key, array $replacements = []): string {
    global $i18n_translations;
    
    $text = $i18n_translations[$key] ?? $key;
    
    foreach ($replacements as $placeholder => $replacement) {
        $text = str_replace(':' . $placeholder, (string)$replacement, $text);
    }
    
    return $text;
}
