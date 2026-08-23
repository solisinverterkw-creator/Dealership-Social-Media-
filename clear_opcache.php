<?php
header('Content-Type: text/plain');
if (function_exists('opcache_reset')) {
    $result = opcache_reset();
    echo $result ? "OPcache cleared successfully.\n" : "opcache_reset() returned false — opcache may be disabled or already empty.\n";
} else {
    echo "opcache_reset() function does not exist — OPcache extension may not be loaded.\n";
}
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    echo "\nOPcache enabled: " . (($status['opcache_enabled'] ?? false) ? 'yes' : 'no') . "\n";
}
echo "\nDELETE THIS FILE FROM THE SERVER AFTER RUNNING IT ONCE.\n";
