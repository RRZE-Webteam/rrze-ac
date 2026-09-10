<?php
require __DIR__ . '/bootstrap.php';

use RRZE\AccessControl\Post;

// Execute the installed WordPress inclusion/exclusion SQL branch, without a DB.
// This verifies the query restriction used for both results and pagination totals.
function queryIdClause($args)
{
    global $wpdb;
    $core = file_get_contents(ABSPATH . 'wp-includes/class-wp-query.php');
    $pattern = '/\} elseif \( \$(?:q|query_vars)\[\'post__in\'\] \) \{/';
    if (!preg_match($pattern, $core, $match, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException('WordPress inclusion branch changed; review this test');
    }
    $start = $match[0][1];
    $after = strpos($core, "['post__not_in'] ) {", $start);
    $end = $after === false ? false : strpos($core, "\n\t\t}", $after);
    if ($end === false) {
        throw new RuntimeException('WordPress exclusion branch changed; review this test');
    }
    $end += strlen("\n\t\t}");
    $branch = 'if' . substr($core, $start + strlen('} elseif'), $end - $start - strlen('} elseif'));
    $q = $query_vars = $args;
    $where = '';
    eval($branch);
    return $where;
}

foreach ([['page', 42, 43], ['attachment', 44, 46], ['attachment', 45, 46]] as [$type, $denied, $allowed]) {
    $args = Post::restFilter(['post_type' => $type, 'post__in' => [$denied]]);
    expect($args['post__in'] === [0], "$type denied-only selection must force no results");
    expect(str_contains(queryIdClause($args), 'ID IN (0)'), "$type denied-only query broadened");

    $args = Post::restFilter(['post_type' => $type, 'post__in' => [(string) $denied, (string) $allowed], 'posts_per_page' => 1, 'paged' => 2]);
    expect($args['post__in'] === [$allowed], "$type mixed selection retains denied ID");
    expect(str_contains(queryIdClause($args), "ID IN ($allowed)"), "$type query includes denied ID");
    expect($args['posts_per_page'] === 1 && $args['paged'] === 2, 'Pagination arguments changed');

    $args = Post::restFilter(['post_type' => $type, 'post__in' => [$denied, $allowed], 'post__not_in' => [$allowed]]);
    expect($args['post__in'] === [0], 'Existing exclusions were ignored with include');

    $args = Post::restFilter(['post_type' => $type, 'post__in' => [], 'post__not_in' => [99]]);
    expect(in_array($denied, $args['post__not_in'], true) && in_array(99, $args['post__not_in'], true), 'Ordinary collection lost exclusions');
    expect(str_contains(queryIdClause($args), 'NOT IN'), 'Ordinary collection no longer excludes IDs');

    $_SERVER['REMOTE_ADDR'] = '192.0.2.23';
    $original = ['post_type' => $type, 'post__in' => [$denied, $allowed]];
    expect(Post::restFilter($original) === $original, 'Authorized include selection changed');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.23';
}
$args = ['post_type' => 'post', 'post__in' => [42]];
expect(Post::restFilter($args) === $args, 'Unmanaged post type was changed');
echo "Passed $checks collection checks (isolated).\n";
