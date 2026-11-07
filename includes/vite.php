<?php
declare(strict_types=1);

function vite_manifest(): array {
    static $manifest = null;
    if (is_array($manifest)) {
        return $manifest;
    }
    $manifestPath = dirname(__DIR__) . '/assets/dist/.vite/manifest.json';
    if (!is_file($manifestPath)) {
        $manifest = [];
        return $manifest;
    }
    $decoded = json_decode((string) file_get_contents($manifestPath), true);
    $manifest = is_array($decoded) ? $decoded : [];
    return $manifest;
}

function vite_asset(string $entry): string {
    $base = function_exists('app_base_url') ? rtrim(app_base_url(), '/') : '';
    $entry = ltrim(str_replace('\\', '/', $entry), '/');
    $manifest = vite_manifest();

    $candidates = [$entry, 'assets/' . basename($entry)];
    if (str_starts_with($entry, 'assets/')) {
        $candidates[] = substr($entry, strlen('assets/'));
    }

    foreach ($candidates as $key) {
        if (isset($manifest[$key]['file']) && is_string($manifest[$key]['file'])) {
            $file = ltrim(str_replace('\\', '/', $manifest[$key]['file']), '/');
            // Manifest may store "assets/x.css" while output lives under assets/dist/
            if (str_starts_with($file, 'assets/dist/')) {
                return $base . '/' . $file;
            }
            return $base . '/assets/dist/' . $file;
        }
    }

    // Dev/source fallback
    return $base . '/' . $entry;
}

function vite_tags(array $entries): void {
    static $emitted = [];
    foreach ($entries as $entry) {
        $entry = (string) $entry;
        if ($entry === '' || isset($emitted[$entry])) {
            continue;
        }
        $emitted[$entry] = true;
        $url = vite_asset($entry);
        $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        if (str_ends_with($entry, '.css')) {
            echo '<link rel="stylesheet" href="' . $safe . '">' . "\n";
        } elseif (str_ends_with($entry, '.js')) {
            echo '<script defer src="' . $safe . '"></script>' . "\n";
        }
    }
}
