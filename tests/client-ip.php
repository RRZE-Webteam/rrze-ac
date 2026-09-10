<?php

/**
 * Client IP regression tests for RRZE-AC.
 * Run: php rrze-ac/tests/client-ip.php [WordPress root]
 * No database, HTTP or DNS requests; uses actual resolver and IP classes.
 */
if (PHP_SAPI !== 'cli' || defined('ABSPATH')) {
    exit(1);
}
define('ABSPATH', rtrim($argv[1] ?? dirname(__DIR__, 4), '/') . '/');
require ABSPATH . 'wp-includes/plugin.php';
spl_autoload_register(static function ($class) {
    foreach (['RRZE\\AccessControl\\' => 'rrze-ac'] as $prefix => $plugin) {
        if (str_starts_with($class, $prefix)) {
            require dirname(__DIR__, 2) . '/' . $plugin . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    }
});
set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Label, direct peer, X-Forwarded-For, trusted proxies, resolved client.
$cases = [
    ['direct IPv4', '192.0.2.5', null, [], '192.0.2.5'],
    ['direct IPv6', '2001:db8::5', null, [], '2001:db8::5'],
    ['untrusted spoof', '198.51.100.5', '192.0.2.5', [], '198.51.100.5'],
    ['single IPv4 is not /0', '198.51.100.5', '192.0.2.5', ['192.0.2.5'], '198.51.100.5'],
    ['single IPv4 proxy', '192.0.2.5', '198.51.100.5', ['192.0.2.5'], '198.51.100.5'],
    ['single IPv6 proxy', '2001:db8::5', '198.51.100.5', ['2001:db8::5'], '198.51.100.5'],
    ['single IPv6 is exact', '2001:db8::6', '192.0.2.5', ['2001:db8::5'], '2001:db8::6'],
    ['CIDR IPv4 inside', '192.0.2.5', '198.51.100.5', ['192.0.2.0/24'], '198.51.100.5'],
    ['CIDR IPv4 outside', '192.0.3.5', '198.51.100.5', ['192.0.2.0/24'], '192.0.3.5'],
    ['IPv4 non-byte mask inside', '192.0.2.127', '198.51.100.5', ['192.0.2.0/25'], '198.51.100.5'],
    ['IPv4 non-byte mask outside', '192.0.2.128', '198.51.100.5', ['192.0.2.0/25'], '192.0.2.128'],
    ['IPv6 non-byte mask inside', '2001:db8::7fff:ffff:ffff:ffff', '198.51.100.5', ['2001:db8::/65'], '198.51.100.5'],
    ['IPv6 non-byte mask outside', '2001:db8::8000:0:0:1', '198.51.100.5', ['2001:db8::/65'], '2001:db8::8000:0:0:1'],
    ['family mismatch', '198.51.100.5', '192.0.2.5', ['2001:db8::/32'], '198.51.100.5'],
    ['proxy chain', '192.0.2.2', '198.51.100.5, 192.0.2.1', ['192.0.2.0/24'], '198.51.100.5'],
    ['spoofed left prefix', '192.0.2.2', '203.0.113.9, 198.51.100.5, 192.0.2.1', ['192.0.2.0/24'], '198.51.100.5'],
    ['untrusted intermediate proxy', '192.0.2.2', '203.0.113.9, 198.51.100.5', ['192.0.2.2'], '198.51.100.5'],
    ['invalid left prefix is ignored', '192.0.2.2', 'unknown, 198.51.100.5', ['192.0.2.2'], '198.51.100.5'],
    ['spaces', '192.0.2.2', ' 198.51.100.5 , 192.0.2.1 ', ['192.0.2.0/24'], '198.51.100.5'],
    ['missing peer', null, '198.51.100.5', ['192.0.2.0/24'], ''],
    ['invalid peer', 'invalid', '198.51.100.5', ['192.0.2.0/24'], ''],
    ['non-string peer', [], '198.51.100.5', ['192.0.2.0/24'], ''],
    ['proxy without header', '192.0.2.2', null, ['192.0.2.0/24'], ''],
    ['proxy with empty header', '192.0.2.2', ' ', ['192.0.2.0/24'], ''],
    ['malformed nearest hop', '192.0.2.2', '198.51.100.5, unknown', ['192.0.2.0/24'], ''],
    ['empty nearest hop', '192.0.2.2', '198.51.100.5,', ['192.0.2.0/24'], ''],
    ['non-string header', '192.0.2.2', [], ['192.0.2.0/24'], ''],
    ['all hops are proxies', '192.0.2.2', '192.0.2.1', ['192.0.2.0/24'], ''],
];
foreach (['invalid', [], '', '192.0.2.2/-1', '192.0.2.2/33', '192.0.2.2/24.5', '192.0.2.2/2e1', '192.0.2.2/24/0', '192.0.2.2/', '2001:db8::/129'] as $invalidRange) {
    $cases[] = ['malformed proxy range ' . json_encode($invalidRange), '192.0.2.2', '198.51.100.5', [$invalidRange], '192.0.2.2'];
}

$checks = 0;
foreach ([RRZE\AccessControl\Network\RemoteAddress::class] as $class) {
    foreach ($cases as [$label, $remote, $forwarded, $trusted, $expected]) {
        $_SERVER['REMOTE_ADDR'] = $remote;
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $forwarded;
        $actual = (new $class($trusted))->getIpAddress();
        if ($actual !== $expected) {
            throw new RuntimeException("$class: $label: expected '$expected', got '$actual'");
        }
        $checks++;
    }
    $_SERVER['REMOTE_ADDR'] = '192.0.2.2';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.5';
    foreach ([['192.0.2.2'], false] as $filtered) {
        $filter = static fn() => $filtered;
        add_filter('rrze_trusted_proxies', $filter);
        $expected = is_array($filtered) ? '198.51.100.5' : '192.0.2.2';
        if ((new $class())->getIpAddress() !== $expected || (new $class([]))->getIpAddress() !== '192.0.2.2') {
            throw new RuntimeException("$class: infrastructure filter or explicit override failed");
        }
        $checks += 2;
        remove_filter('rrze_trusted_proxies', $filter);
    }
}
restore_error_handler();
echo "Passed $checks client IP checks for RRZE-AC.\n";
