<?php
declare(strict_types=1);

$bootstrap = require dirname(__DIR__, 2) . '/bootstrap/api.php';
$router = $bootstrap['router'];
$request = $bootstrap['request'];

require dirname(__DIR__, 2) . '/routes/api_v1.php';

$router->dispatch($request);
