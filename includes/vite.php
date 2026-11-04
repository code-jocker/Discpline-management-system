<?php
declare(strict_types=1);

function vite_asset(string $entry): string {
    $manifestPath = dirname(__DIR__) . '/assets/dist/.vite/manifest.json';
    if (!file_exists($manifestPath)) {
        // Fallback to original path in development if manifest doesn't exist
        return app_base_url() . '/' . ltrim($entry, '/');
    }

    $manifest = json_decode(file_get_contents($manifestPath), true);
    
    if (isset($manifest[$entry])) {
        return app_base_url() . '/assets/dist/' . $manifest[$entry]['file'];
    }

    return app_base_url() . '/' . ltrim($entry, '/');
}

function vite_tags(array $entries): void {
    foreach ($entries as $entry) {
        $url = vite_asset($entry);
        if (str_ends_with($entry, '.css')) {
            echo '<link rel="stylesheet" href="' . htmlspecialchars($url) . '">' . "\n";
        } elseif (str_ends_with($entry, '.js')) {
            echo '<script src="' . htmlspecialchars($url) . '"></script>' . "\n";
        }
    }
}
