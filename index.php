<?php

use Arrakis\Exphporter\Exphporter;

require __DIR__ . '/vendor/autoload.php';

define('EXPHPORTER_BASE_DIR', __DIR__);

// Always redirect to /metrics
if ($_SERVER['REQUEST_URI'] != '/metrics') {
    header('Location: /metrics');
    http_response_code(301);
    return;
}

(new Exphporter())->run();