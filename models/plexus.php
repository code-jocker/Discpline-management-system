<?php
declare(strict_types=1);

/**
 * Renders the shared CSS/Head elements for the Plexus UI
 */
function render_plexus_head(): void {
    $v = time();
    echo '<script src="' . e(app_base_url()) . '/assets/js/theme.js?v=' . $v . '"></script>';
    echo '<link rel="stylesheet" href="' . e(app_base_url()) . '/assets/plexus/plexus.css?v=' . $v . '">';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap">';
}

/**
 * Renders the animated background container
 */
function render_plexus_background(): void {
    echo '<div class="plexus-bg-container" aria-hidden="true"><div id="plexus-canvas"></div></div>';
}