<?php

/**
 * Isolated tests: actual plugin classes and WordPress hooks/REST route matching,
 * with storage, identity and SSO availability fixtures. No HTTP or database.
 */
namespace {
    if (PHP_SAPI !== 'cli' || defined('ABSPATH')) {
        exit(1);
    }
    define('ABSPATH', rtrim($argv[1] ?? dirname(__DIR__, 4), '/') . '/');
    require ABSPATH . 'wp-includes/plugin.php';
    require ABSPATH . 'wp-includes/class-wp-error.php';
    require ABSPATH . 'wp-includes/class-wp-http-response.php';
    require ABSPATH . 'wp-includes/rest-api/class-wp-rest-response.php';
    require ABSPATH . 'wp-includes/rest-api/class-wp-rest-request.php';
    require ABSPATH . 'wp-includes/rest-api/class-wp-rest-server.php';
    require ABSPATH . 'wp-includes/rest-api.php';
    spl_autoload_register(static function ($class) {
        $prefix = 'RRZE\\AccessControl\\';
        if (str_starts_with($class, $prefix)) {
            require dirname(__DIR__) . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    });

    $checks = 0;
    $testOptions = [];
    $testTypes = [42 => 'page', 43 => 'page', 44 => 'attachment', 45 => 'attachment', 46 => 'attachment'];
    $testMeta = [
        42 => ['_access_permission' => 'test-ip'],
        44 => ['_access_permission' => 'test-ip', '_wp_attached_file' => '_protected/secret.pdf'],
        45 => ['_wp_attached_file' => '_protected/inherited.pdf'],
    ];
    function get_option($key, $default = false) { return $GLOBALS['testOptions'][$key] ?? $default; }
    function is_user_logged_in() { return false; }
    function is_super_admin() { return false; }
    function current_user_can(...$args) { return false; }
    function get_post_type($id) { return $GLOBALS['testTypes'][(int) $id] ?? false; }
    function get_post_meta($id, $key, $single = false) { return $GLOBALS['testMeta'][(int) $id][$key] ?? ''; }
    function absint($value) { return abs((int) $value); }
    function __($text, $domain = '') { return $text; }
    function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
    function trailingslashit($s) { return rtrim($s, '/') . '/'; }
    function wp_list_filter($items, $args) { return array_filter($items, static fn($item) => !array_diff_assoc($args, $item)); }
    function wp_list_pluck($items, $field) { return array_map(static fn($item) => $item->$field, $items); }
    function wp_is_numeric_array($array) { return array_is_list($array); }
    function expect($condition, $message) {
        if (!$condition) { throw new \RuntimeException($message); }
        $GLOBALS['checks']++;
    }

    class TestPermissions extends \RRZE\AccessControl\Permissions {
        public function ssoPluginIsAvailableAndActive(): bool { return false; }
    }
    class TestRestServer extends \WP_REST_Server {
        public function __construct() {}
        public function match($request) { return $this->match_request_to_handler($request); }
    }
    class TestDatabase {
        public $postmeta = 'wp_postmeta';
        public $posts = 'wp_posts';
        public function prepare($query, ...$args) { return $query; }
        public function esc_like($s) { return $s; }
        public function get_results($query) {
            return [(object) ['post_id' => 42, 'post_type' => 'page', 'meta_value' => 'test-ip'], (object) ['post_id' => 44, 'post_type' => 'attachment', 'meta_value' => 'test-ip']];
        }
        public function get_col($query) { return [44, 45]; }
    }
    $wpdb = new TestDatabase();
    $testOptions['rrze_ac'] = ['default_permission' => 'test-ip', 'permissions' => ['test-ip' => ['active' => 1, 'ip_address' => ['192.0.2.0/24']]]];
    $testPermissions = new TestPermissions();
    $testPermissions->loaded();
    $_SERVER['REMOTE_ADDR'] = '198.51.100.23';
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
}
namespace RRZE\AccessControl {
    function permissions() { return $GLOBALS['testPermissions']; }
}
