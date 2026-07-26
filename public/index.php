<?php
/**
 * Publisher — front controller (on the Sidecar Kit).
 * Hard allowlist gate before any app code; then the shared Kernel boots and dispatches.
 */

if (php_sapi_name() === 'cli-server') {
    $f = __DIR__ . urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if (is_file($f)) return false;   // dev static passthrough
}

$cfg      = @parse_ini_file(dirname(__DIR__) . '/conf/config.ini', true) ?: [];
$coreRoot = rtrim($cfg['sidecar']['core_root'] ?? '/var/www/html/default/tiknix', '/');

// Core's autoloader: the Sidecar Kit + core's shared classes (PublishRegistry, drivers).
require $coreRoot . '/vendor/autoload.php';

app\Sidecar\Kernel::guard(['', 'sso', 'index', 'publish', 'error']);

$kernel = new app\Sidecar\Kernel(dirname(__DIR__), [
    'index'   => 'Index',
    'sso'     => 'Sso',
    'publish' => 'Publish',
]);

// REQUIRED. Core's absolute URL, for views linking to routes CORE owns. Those are not
// routes of this sidecar, so a leading-slash href resolves against THIS host and 404s.
Flight::set('sidecar.core_url', rtrim((string) ($cfg['sidecar']['core_url'] ?? ''), '/'));

$kernel->run();
