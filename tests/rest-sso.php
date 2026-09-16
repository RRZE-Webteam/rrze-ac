<?php
// phpcs:ignoreFile -- Standalone CLI regression test; not a WordPress plugin runtime file

/**
 * Run: php wp-content/plugins/rrze-ac/tests/rest-sso.php [WordPress root] [--http]
 * Uses real WordPress REST dispatch and AC access checks. Storage, identities,
 * endpoint data and SimpleSAML are fixtures; no database, HTTP or SSO access.
 * Run both modes: REST_REQUEST cannot be changed within a PHP process.
 */
namespace {
    if (PHP_SAPI !== 'cli' || defined('ABSPATH')) {
        exit(1);
    }
    $arguments = array_slice($argv, 1);
    $http = in_array('--http', $arguments, true);
    $roots = array_values(array_filter($arguments, static fn($arg) => $arg !== '--http'));
    define('ABSPATH', rtrim($roots[0] ?? dirname(__DIR__, 4), '/') . '/');
    define('WPINC', 'wp-includes');
    if ($http) {
        define('REST_REQUEST', true);
    }
}

namespace SimpleSAML {
    class Session
    {
        public static function getSessionFromRequest() { return new self(); }
        public function cleanup() {}
    }
}

namespace RRZE\AccessControl {
    function permissions() { return $GLOBALS['permissions']; }
}

namespace {
    foreach (['plugin.php', 'load.php', 'functions.php', 'formatting.php',
        'class-wp-list-util.php', 'class-wp-error.php', 'class-wp-http-response.php',
        'rest-api/class-wp-rest-response.php', 'rest-api/class-wp-rest-request.php',
        'rest-api/class-wp-rest-server.php', 'rest-api.php'] as $file) {
        require ABSPATH . WPINC . '/' . $file;
    }
    foreach (['Config', 'Permissions', 'Access', 'Post'] as $class) {
        require dirname(__DIR__) . '/includes/' . $class . '.php';
    }
    function __($text, $domain = '') { return $text; }
    function is_user_logged_in() { return false; }
    function is_super_admin() { return false; }
    function get_post_type($id) { return (int) $id === 20 ? 'attachment' : 'page'; }
    add_filter('pre_option_permalink_structure', static fn() => '/%postname%/');

    class SamlFixture
    {
        public bool $authenticated = false;
        public int $loginCalls = 0;
        public array $attributes = [];
        public function isAuthenticated() { return $this->authenticated; }
        public function requireAuth() { $this->loginCalls++; }
        public function getAttributes() { return $this->attributes; }
    }

    class PermissionsFixture extends \RRZE\AccessControl\Permissions
    {
        public array $postPermissions = [2 => 'logged-in', 3 => 'sso', 4 => 'staff', 5 => 'password-or-sso', 20 => 'logged-in'];
        public bool $passwordValid = false;
        public array $definitions = [
            'logged-in' => ['active' => 1],
            'sso' => ['active' => 1, 'sso_logged_in' => 1],
            'staff' => ['active' => 1, 'sso_logged_in' => 1, 'affiliation' => ['staff'], 'entitlement' => ['allowed']],
            'password-or-sso' => ['active' => 1, 'sso_logged_in' => 1, 'password' => 'test-password'],
        ];
        public function __construct() {
            $this->options = ['automatic_sso_authentication' => 1];
            $this->simplesamlAuth = new SamlFixture();
        }
        public function setAutomatic($enabled) { $this->options['automatic_sso_authentication'] = $enabled; }
        public function simplesamlAuth() { return true; }
        public function ssoPluginIsAvailableAndActive(): bool { return true; }
        public function getThePermission($postId) { return $this->postPermissions[$postId] ?? ''; }
        public function getThePermissions() { return $this->definitions; }
        public function checkPrivilegedAccess() { return false; }
        public function checkAuthorPermission($postId) { return false; }
        public function checkPassword($postId, $allowedPassword = '') { return $this->passwordValid; }
        public function getRemoteIpAddress() { return '192.0.2.23'; }
        public function logInfo($data) {}
    }

    // Only metadata storage is replaced; Post::restFilter and Access::try are real.
    $wpdb = new class {
        public string $postmeta = 'fixture_postmeta';
        public string $posts = 'fixture_posts';
        public function prepare($query, ...$args) { return $query; }
        public function get_results($query) {
            $rows = [];
            foreach ($GLOBALS['permissions']->postPermissions as $id => $permission) {
                $rows[] = (object) ['post_id' => $id, 'meta_value' => $permission, 'post_type' => get_post_type($id)];
            }
            return $rows;
        }
    };
    $permissions = new PermissionsFixture();
    $permissions->loaded();
    $wp_rest_server = new WP_REST_Server();
    $collectionArgs = ['post_type' => 'page'];
    $wp_rest_server->register_route('wp/v2', '/wp/v2/pages', [[
        'methods' => 'GET',
        'permission_callback' => static fn() => true,
        'callback' => static function () use (&$collectionArgs) {
            expect(wp_is_rest_endpoint(), 'Core did not detect active REST dispatch');
            $args = \RRZE\AccessControl\Post::restFilter($collectionArgs);
            $ids = array_values(array_filter([1, 2, 3, 4, 5], static function ($id) use ($args) {
                if (!empty($args['post__in'])) {
                    return in_array($id, $args['post__in'], true);
                }
                return !in_array($id, $args['post__not_in'] ?? [], true);
            }));
            return ['ids' => $ids];
        },
    ]]);
    foreach (['pages', 'media'] as $resource) {
        $wp_rest_server->register_route('wp/v2', '/wp/v2/' . $resource . '/(?P<id>\d+)', [[
            'methods' => 'GET',
            'permission_callback' => static fn() => true,
            'callback' => static fn() => ['permitted' => true],
        ]]);
    }
    $checks = 0;
    function expect($condition, $message) {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
        $GLOBALS['checks']++;
    }
    function request($route, $method = 'GET') {
        $response = rest_do_request(new WP_REST_Request($method, $route));
        expect($GLOBALS['permissions']->simplesamlAuth->loginCalls === 0, 'REST started interactive SSO: ' . $route);
        expect(!$GLOBALS['wp_rest_server']->is_dispatching(), 'REST dispatch state leaked');
        return $response;
    }

    foreach (['GET', 'HEAD'] as $method) {
        $response = request('/wp/v2/pages', $method);
        expect($response->get_status() === 200 && $response->get_data()['ids'] === [1], 'Protected pages leaked from collection');
        foreach ([2, 3, 4, 5] as $id) {
            $response = request('/wp/v2/pages/' . $id, $method);
            expect($response->get_status() === 401 && $response->get_data()['code'] === 'rest_cannot_access', 'Protected single page was not denied');
        }
        expect(request('/wp/v2/pages/1', $method)->get_status() === 200, 'Public page was denied');
        expect(request('/wp/v2/media/20', $method)->get_status() === 401, 'Protected attachment was not denied');
    }
    $collectionArgs['post__in'] = [2, 3];
    expect(request('/wp/v2/pages')->get_data()['ids'] === [], 'Denied include IDs exposed collection');
    $collectionArgs['post__in'] = [1, 2];
    expect(request('/wp/v2/pages')->get_data()['ids'] === [1], 'Mixed include selection exposed protected page');
    unset($collectionArgs['post__in']);

    $permissions->simplesamlAuth->authenticated = true;
    $permissions->simplesamlAuth->attributes = ['eduPersonAffiliation' => ['student']];
    expect(request('/wp/v2/pages')->get_data()['ids'] === [1, 2, 3, 5], 'SSO session or attribute restriction ignored');
    expect(request('/wp/v2/pages/4')->get_status() === 401, 'Wrong SSO attributes granted access');
    expect(request('/wp/v2/media/20')->get_status() === 200, 'Existing SSO session could not access attachment');
    $permissions->simplesamlAuth->attributes = ['eduPersonAffiliation' => ['staff']];
    expect(request('/wp/v2/pages/4')->get_status() === 200, 'Matching affiliation denied');
    $permissions->simplesamlAuth->attributes = ['eduPersonEntitlement' => ['allowed']];
    expect(request('/wp/v2/pages/4')->get_status() === 200, 'Matching entitlement denied');
    $permissions->simplesamlAuth->authenticated = false;
    $permissions->passwordValid = true;
    expect(request('/wp/v2/pages/5')->get_status() === 200, 'Valid password alternative denied');
    $permissions->passwordValid = false;

    if ($http) {
        expect(wp_is_rest_endpoint(), 'HTTP REST context missing outside dispatch');
        expect(!$permissions->checkSSOLoggedIn(true), 'Anonymous HTTP request gained access');
        expect($permissions->simplesamlAuth->loginCalls === 0, 'HTTP REST check outside dispatch started SSO');
    } else {
        expect(!defined('REST_REQUEST'), 'Internal REST test unexpectedly has HTTP flag');
        expect(!wp_is_rest_endpoint(), 'Internal REST context leaked into frontend');
        foreach ([2, 3] as $id) {
            $permissions->simplesamlAuth->loginCalls = 0;
            \RRZE\AccessControl\Access::try($id);
            expect($permissions->simplesamlAuth->loginCalls === 1, 'Frontend automatic SSO stopped working');
        }
        $permissions->simplesamlAuth->loginCalls = 0;
        \RRZE\AccessControl\Access::try(5);
        expect($permissions->simplesamlAuth->loginCalls === 0, 'Frontend password alternative started SSO');
        $permissions->checkSSOLoggedIn();
        expect($permissions->simplesamlAuth->loginCalls === 0, 'Passive session check started SSO');
        $permissions->setAutomatic(false);
        \RRZE\AccessControl\Access::try(2);
        expect($permissions->simplesamlAuth->loginCalls === 0, 'Disabled automatic SSO started login');
    }
    echo 'Passed ' . $checks . ' checks (' . ($http ? 'HTTP REST context' : 'internal REST dispatch and frontend') . "; isolated, no database or network).\n";
}
