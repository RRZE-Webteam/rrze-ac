<?php
require __DIR__ . '/bootstrap.php';

$server = new TestRestServer();
foreach (['pages', 'media'] as $base) {
    $server->register_route('wp/v2', '/wp/v2/' . $base . '/(?P<id>[\d]+)', [['methods' => ['GET', 'HEAD'], 'callback' => static fn() => 'content']]);
}
foreach (['/rrze-faubox/v1/download', '/foreign/v1/items/(?P<id>[\d]+)'] as $route) {
    $server->register_route(explode('/', $route)[1] . '/v1', $route, [['methods' => ['GET', 'HEAD'], 'callback' => static fn() => 'content']]);
}
$routes = [
    '/wp/v2/pages/42', '/wp/v2/pages/042', '/wp/v2/Pages/42', '/WP/V2/PAGES/00042',
    '/wp/v2/media/44', '/wp/v2/media/044', '/wp/v2/Media/44', '/WP/V2/MEDIA/00044',
    '/wp/v2/media/045', // Protected file without its own permission metadata.
];
foreach (['GET', 'HEAD'] as $method) {
    foreach ($routes as $route) {
        $request = new WP_REST_Request($method, $route);
        $handler = $server->match($request);
        expect(is_array($handler), "$method $route must match WordPress's item route");
        $response = apply_filters('rest_request_before_callbacks', null, $handler[1], $request);
        expect($response instanceof WP_Error && $response->get_error_code() === 'rest_cannot_access', "$method $route bypassed AC");
    }
}
foreach (['/wp/v2/pages/43', '/wp/v2/media/46', '/rrze-faubox/v1/download', '/foreign/v1/items/42'] as $route) {
    $request = new WP_REST_Request('GET', $route);
    $handler = $server->match($request);
    expect(is_array($handler), "$route did not match");
    expect(apply_filters('rest_request_before_callbacks', null, $handler[1], $request) === null, "$route was incorrectly blocked");
}
$prior = new WP_Error('prior_denial', 'Denied', ['status' => 403]);
expect($testPermissions->restRequestBeforeCallbacks($prior, [], new WP_REST_Request('GET', '/wp/v2/pages/42')) === $prior, 'Prior error was replaced');
$_SERVER['REMOTE_ADDR'] = '192.0.2.23';
foreach ($routes as $route) {
    expect($testPermissions->restRequestBeforeCallbacks(null, [], new WP_REST_Request('GET', $route)) === null, "Authorized visitor blocked on $route");
}
echo "Passed $checks resource checks (isolated).\n";
