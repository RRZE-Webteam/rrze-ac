<?php
require __DIR__ . '/bootstrap.php';

set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$testOptions['rrze_ac']['permissions']['test-ip']['ip_address'] = ['192.0.2.23'];
$testPermissions = new TestPermissions();
$request = new WP_REST_Request('GET', '/wp/v2/pages/42');
$_SERVER['REMOTE_ADDR'] = '198.51.100.23';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.23';
expect($testPermissions->restRequestBeforeCallbacks(null, [], $request) instanceof WP_Error, 'Forged visitor IP bypassed protected REST resource');
expect($testPermissions->getRemoteIpAddress(['192.0.2.23']) === '198.51.100.23', 'Legacy method argument grants proxy trust');
expect(!$testPermissions->checkIpAddressRange(['192.0.2.23']), 'Single-IP allowlist grants access to outside client');

unset($_SERVER['HTTP_X_FORWARDED_FOR']);
$_SERVER['REMOTE_ADDR'] = '192.0.2.23';
expect($testPermissions->restRequestBeforeCallbacks(null, [], $request) === null, 'Authorized direct visitor denied');

$proxyFilter = static fn() => ['203.0.113.0/24'];
add_filter('rrze_trusted_proxies', $proxyFilter);
$_SERVER['REMOTE_ADDR'] = '203.0.113.2';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.23, 203.0.113.1';
expect($testPermissions->restRequestBeforeCallbacks(null, [], $request) === null, 'Authorized visitor behind proxies denied');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.23, 198.51.100.23, 203.0.113.1';
expect($testPermissions->restRequestBeforeCallbacks(null, [], $request) instanceof WP_Error, 'Client-controlled prefix bypassed protected resource');

unset($_SERVER['HTTP_X_FORWARDED_FOR']);
expect(!$testPermissions->checkIpAddressRange(['203.0.113.2']), 'Trusted transport IP became visitor fallback');
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'invalid';
expect(!$testPermissions->checkIpAddressRange(['0.0.0.0/0']), 'Invalid forwarded client passed broad visitor range');
remove_filter('rrze_trusted_proxies', $proxyFilter);
restore_error_handler();
echo "Passed $checks IP access checks (isolated).\n";
