<?php

namespace RRZE\AccessControl;

defined('ABSPATH') || exit;

class Post
{
    public static function accessPermissionMetaKey()
    {
        return Config::get('access_permission_meta_key');
    }

    public static function protectedPostStatus()
    {
        return Config::get('protected_post_status');
    }

    public static function emptyPermissionKey()
    {
        return Config::get('empty_permission_key');
    }

    public static function init()
    {
        add_action('init', [__CLASS__, 'registerPostStatus']);

        // Anpassung des Abfrageobjekts
        add_filter('pre_get_posts', [__CLASS__, 'preGetPostsSingle']);
        add_filter('pre_get_posts', [__CLASS__, 'preGetPostsList']);
        add_filter('views_edit-page', [__CLASS__, 'viewsEdit']);
        add_action('restrict_manage_posts', [__CLASS__, 'restrictManagePosts']);

        // Metadaten registrieren
        add_action('init', [__CLASS__, 'registerPostMetas']);

        // WP-REST-API
        add_filter('rest_page_query', [__CLASS__, 'restFilter']);
        add_filter('rest_attachment_query', [__CLASS__, 'restFilter']);

        add_action('add_meta_boxes', [__CLASS__, 'metabox'], 10, 2);

        add_action("save_post_page", [__CLASS__, 'savePost'], 10, 2);
        add_action('updated_postmeta', [__CLASS__, 'updatePostMeta'], 10, 4);

        add_action("manage_edit-page_columns", [__CLASS__, 'managePagesColumn']);
        add_filter("manage_page_posts_custom_column", [__CLASS__, 'managePagesCustomColumn'], 10, 2);

        add_filter('rrze_menu_walker_nav_menu_edit', [__CLASS__, 'walkerNavMenuEdit'], 10, 5);

        // Menüelemente die geschützte Objekte verlinken sind abgeschlossen
        // add_filter('wp_nav_menu_objects', [__CLASS__, 'navMenuObjects'], 10, 1);        

        /* Enqueue Block Editor Assets */
        add_action('enqueue_block_editor_assets', [__CLASS__, 'enqueueBlockEditorAssets']);
    }

    public static function registerPostStatus()
    {
        register_post_status(self::protectedPostStatus(), [
            'label'                     => __('Protected', 'rrze-ac'),
            'public'                    => false,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => false,
            'show_in_admin_status_list' => false,
            'label_count'               => _n_noop(
                /* translators: %s: label count */
                'Protected <span class="count">(%s)</span>',
                'Protected <span class="count">(%s)</span>',
                'rrze-ac'
            ),
        ]);
    }

    public static function preGetPostsSingle($query)
    {
        if (is_admin() || !$query->is_main_query() || $query->is_singular) {
            return $query;
        }

        $postNotIn = [];
        $permissions = permissions()->getThePermissions();
        $permissionMetas = self::getPermissionMetas();

        foreach ($permissionMetas as $pm) {
            if (isset($permissions[$pm->meta_value]) && $permissions[$pm->meta_value]['active'] && !permissions()->checkAuthorPermission($pm->post_id)) {
                $postNotIn[] = $pm->post_id;
            }
        }

        if (!empty($postNotIn)) {
            $query->set('post__not_in', $postNotIn);
        }

        return $query;
    }

    public static function getPermissionMetas($postType = '')
    {
        global $wpdb;

        $pt_query = [
            'page' => "p.post_type = 'page'",
            'attachment' => "p.post_type = 'attachment'"
        ];

        switch ($postType) {
            case 'page':
                unset($pt_query['attachment']);
                break;
            case 'attachment':
                unset($pt_query['page']);
                break;
            default:
                break;
        }

        $query = "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '%s'
            AND p.post_status = 'publish'
            AND (" . implode(' OR ', $pt_query) . ")";

        return $wpdb->get_results($wpdb->prepare($query, self::accessPermissionMetaKey()));
    }

    public static function preGetPostsList($query)
    {
        if (!is_admin() || !$query->is_main_query()) {
            return $query;
        }

        $postType = self::currentPostType();

        if (!in_array($postType, Config::get('restricted_post_types'))) {
            return $query;
        }

        $filterByView = isset($query->query_vars['post_status']) && $query->query_vars['post_status'] == self::protectedPostStatus();
        $filterByDropdown = self::accessFilter();

        if (!$filterByView && !$filterByDropdown) {
            return $query;
        }

        if ($filterByView || $filterByDropdown == self::protectedPostStatus()) {
            $query->set('post_status', ['publish', 'pending', 'draft', 'future', 'private', 'inherit', self::protectedPostStatus()]);

            $meta_query = [
                [
                    'key' => self::accessPermissionMetaKey(),
                    'compare' => 'EXISTS'
                ]
            ];

            $query->set('meta_query', $meta_query);
        } elseif ($filterByDropdown == 'unprotected') {
            $meta_query = [
                [
                    'key' => self::accessPermissionMetaKey(),
                    'compare' => 'NOT EXISTS'
                ]
            ];

            $query->set('meta_query', $meta_query);
        }

        return $query;
    }

    public static function viewsEdit($views)
    {
        global $wp_query;

        $postType = self::currentPostType();

        if (!in_array($postType, Config::get('restricted_post_types'))) {
            return $views;
        }

        $query = new \WP_Query(
            [
                'post_type'  => $postType,
                'post_status' => ['publish', 'pending', 'draft', 'future', 'private', 'inherit', self::protectedPostStatus()],
                'meta_query' => [
                    [
                        'key' => self::accessPermissionMetaKey(),
                        'compare' => 'EXISTS'
                    ]
                ]
            ]
        );

        $count = $query->found_posts;
        $class = isset($wp_query->query['post_status']) && $wp_query->query['post_status'] == self::protectedPostStatus() ? ' class="current"' : '';

        $views[self::protectedPostStatus()] = sprintf(
            '<a href="%s"%s>%s</a>',
            admin_url(sprintf('edit.php?post_status=%s&post_type=%s', self::protectedPostStatus(), $postType)),
            $class,
            sprintf(translate_nooped_plural(_n_noop('With Access Control <span class="count">(%s)</span>', 'With Access Control <span class="count">(%s)</span>'), $count, 'rrze-ac'), $count)
        );

        return $views;
    }

    public static function restrictManagePosts($postType = '')
    {
        $postType = $postType ?: self::currentPostType();

        if (!in_array($postType, Config::get('restricted_post_types'))) {
            return;
        }

        $selected = self::accessFilter(); ?>
        <label class="screen-reader-text" for="rrze-ac-access-filter"><?php esc_html_e('Filter by access control', 'rrze-ac'); ?></label>
        <select name="rrze_ac_access_filter" id="rrze-ac-access-filter">
            <option value=""><?php esc_html_e('Access Control', 'rrze-ac'); ?></option>
            <option value="unprotected" <?php selected($selected, 'unprotected'); ?>>
                <?php esc_html_e('Without Access Control', 'rrze-ac'); ?>
            </option>
            <option value="<?php echo esc_attr(self::protectedPostStatus()); ?>" <?php selected($selected, self::protectedPostStatus()); ?>>
                <?php esc_html_e('With Access Control', 'rrze-ac'); ?>
            </option>
        </select>
    <?php
    }

    private static function currentPostType()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!empty($screen->post_type)) {
            return $screen->post_type;
        }

        if (!empty($_GET['post_type'])) {
            return sanitize_key(wp_unslash($_GET['post_type']));
        }

        return 'post';
    }

    private static function accessFilter()
    {
        if (empty($_GET['rrze_ac_access_filter'])) {
            return '';
        }

        return sanitize_key(wp_unslash($_GET['rrze_ac_access_filter']));
    }

    public static function metabox($postType, $post = null)
    {
        if ($postType != 'page') {
            return;
        }

        if ($post && !permissions()->currentUserCanViewContentPermission($post->ID)) {
            return;
        }

        add_meta_box(
            'rrze_ac_metabox',
            __('Access Restriction', 'rrze-ac'),
            [__CLASS__, 'renderMetabox'],
            'page',
            'side',
            'high',
            [
                '__back_compat_meta_box' => true,
            ]
        );
    }

    public static function renderMetabox($post)
    {
        if (!permissions()->currentUserCanViewContentPermission($post->ID)) {
            return;
        }

        $permission = get_post_meta($post->ID, self::accessPermissionMetaKey(), true);

        $permissions = permissions()->getThePermissions();
        $permissions = array_merge([
            self::emptyPermissionKey() => [
                'select' => __("--NONE--", 'rrze-ac'),
                'active' => 1
            ]
        ], $permissions);

        if (empty($permission) || !isset($permissions[$permission]) || !$permissions[$permission]['active']) {
            $permission = self::emptyPermissionKey();
        }

        wp_nonce_field('rrze_ac_post_metabox', 'rrze_ac_post_metabox_nonce');

        if (!permissions()->currentUserCanChangeContentPermission($post->ID)) {
            echo '<p><strong>', esc_html__('Permission', 'rrze-ac'), ':</strong><br>';
            echo esc_html(sanitize_text_field($permissions[$permission]['select']));
            echo '</p>';
            return;
        }

        echo '<select id="access-permission-select" name="access_permission_select">';
        foreach ($permissions as $key => $data) :
            if (!$data['active']) {
                continue;
            }
            echo '<option value="', esc_attr($key), '" ', selected($permission, $key), '>';
            echo sanitize_text_field($data['select']);
            echo '</option>';
        endforeach;
        echo '</select>';
    }

    public static function savePost($postId, $post)
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }

        if (wp_is_post_revision($post) || wp_is_post_autosave($post)) {
            return;
        }

        if ($post->post_type !== 'page') {
            return;
        }

        if (!isset($_POST['rrze_ac_post_metabox_nonce']) || !wp_verify_nonce($_POST['rrze_ac_post_metabox_nonce'], 'rrze_ac_post_metabox')) {
            return;
        }

        if (!permissions()->currentUserCanChangeContentPermission($postId)) {
            return;
        }

        if (!isset($_POST['access_permission_select'])) {
            return;
        }

        $permission = $_POST['access_permission_select'] ?? '';
        $permission = sanitize_text_field($permission);
        $permissions = permissions()->getThePermissions();
        $permissions = array_merge([self::emptyPermissionKey() => []], $permissions);

        if (self::emptyPermissionKey() === $permission) {
            delete_post_meta($postId, self::accessPermissionMetaKey());
        } elseif (isset($permissions[$permission])) {
            update_post_meta($postId, self::accessPermissionMetaKey(), $permission);
        }
    }

    public static function restFilter($args)
    {
        $postNotIn = [];
        $permissions = permissions()->getThePermissions();
        $permissionMetas = Post::getPermissionMetas($args['post_type']);

        foreach ($permissionMetas as $pm) {
            if (isset($permissions[$pm->meta_value]) && $permissions[$pm->meta_value]['active'] && !permissions()->checkAuthorPermission($pm->post_id)) {
                $postNotIn[] = $pm->post_id;
            }
        }

        if (!empty($postNotIn)) {
            $args['post__not_in'] = $postNotIn;
        }

        return $args;
    }

    public static function registerPostMetas()
    {
        $postTypes = Config::get('post_types');

        foreach ($postTypes as $postType) {
            register_post_meta(
                $postType,
                self::accessPermissionMetaKey(),
                [
                    'show_in_rest'  => true,
                    'type'          => 'string',
                    'single'        => true,
                    'auth_callback' => [__CLASS__, 'authAccessPermissionMeta'],
                    'default'       => '',
                ]
            );
        }
    }

    public static function authAccessPermissionMeta($allowed, $metaKey, $postId)
    {
        return permissions()->currentUserCanChangeContentPermission($postId);
    }

    public static function updatePostMeta($metaId, $postId, $metaKey, $metaValue)
    {
        if (self::accessPermissionMetaKey() == $metaKey) {
            if (self::emptyPermissionKey() === $metaValue) {
                delete_post_meta($postId, self::accessPermissionMetaKey());
            }
        }
    }

    public static function managePagesColumn($columns)
    {
        if (self::accessFilter() == 'unprotected') {
            unset($columns['access_info']);
            return $columns;
        }

        $columns['access_info'] = esc_html__('Access Control', 'rrze-ac');
        return $columns;
    }

    public static function managePagesCustomColumn($column_name, $postId)
    {
        if ('access_info' != $column_name) {
            return;
        }

        if (!$permission = permissions()->getThePermission($postId)) {
            return;
        }

        $error = '';
        $permissions = permissions()->getThePermissions();

        if (!isset($permissions[$permission])) {
            $permission = permissions()->getDefaultPermission();
            $error = __("Permission does not exist or has been removed.", 'rrze-ac');
        }

        if (!$permissions[$permission]['active']) {
            $error = __("Permission has been disabled.", 'rrze-ac');
            $permission = permissions()->getDefaultPermission();
        }

        $class = $permission == 'public' ? 'access-all-icon' : 'access-icon';
        $permission = $permissions[$permission];

        $description = isset($permission['description']) && !empty($permission['description']) ? $permission['description'] : $permission['permission_key'];
        $description = !$error ?
            '<span title="' . esc_attr__($description) . '" class="' . $class . ' dashicons dashicons-shield"></span><span class="access-permission-name">' . esc_html($description) . '</span>' :
            '<span title="' . sprintf(
                /* translators: 1: Error message, 2: Default permission. */
                esc_attr__('An error has occurred: %1$s and has been replaced by the default permission %2$s.', 'rrze-ac'),
                $error,
                $description
            ) . '" class="access-error-icon dashicons dashicons-shield"></span><span class="access-permission-name">' . esc_html($description) . '</span>';

        echo $description;
    }

    public static function walkerNavMenuEdit($output, $item, $depth, $args, $id)
    {
        $permission = get_post_meta($item->object_id, self::accessPermissionMetaKey(), true);
        $permissions = permissions()->getThePermissions();
        $pos = strpos($output, '<span class="menu-item-title">');

        if (!empty($permission) && isset($permissions[$permission]) && $pos !== false) {
            $substr = array(
                substr($output, 0, $pos),
                '<span class="access-icon dashicons dashicons-shield"></span>',
                PHP_EOL,
                substr($output, $pos),
            );

            $output = implode('', $substr);
        }

        return $output;
    }

    public static function navMenuObjects($menu_items)
    {
        foreach ($menu_items as $key => $menu_item) {
            if ($menu_item->object == 'page' && !Access::try($menu_item->object_id)) {
                unset($menu_items[$key]);
            }
        }

        return $menu_items;
    }

    public static function countMetaKeys($permissionKey)
    {
        $metas = self::metaValues();
        return array_keys($metas, $permissionKey, true);
    }

    protected static function metaValues()
    {
        global $wpdb;

        $metas = [];

        $result = $wpdb->get_results("
            SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '" . self::accessPermissionMetaKey() . "'
            AND ((p.post_type = 'attachment' AND p.post_status = 'inherit') OR (p.post_type = 'page' AND p.post_status = 'publish'))");

        foreach ($result as $r) {
            $metas[$r->post_id] = $r->meta_value;
        }

        return $metas;
    }

    public static function enqueueBlockEditorAssets()
    {
        global $post;
        if (get_post_type($post) !== 'page') {
            return;
        }

        wp_enqueue_style(
            'rrze-ac-blockeditor',
            plugins_url('build/blockeditor.css', plugin()->getBasename()),
            [],
            plugin()->getVersion()
        );

        $assetFile = include(plugin()->getPath('build') . 'blockeditor.asset.php');

        wp_enqueue_script(
            'rrze-ac-blockeditor',
            plugins_url('build/blockeditor.js', plugin()->getBasename()),
            $assetFile['dependencies'],
            plugin()->getVersion()
        );

        $permission = get_post_meta($post->ID, self::accessPermissionMetaKey(), true);

        $permissions = permissions()->getThePermissions();
        $permissions = array_merge([
            self::emptyPermissionKey() => [
                'select' => __("--NONE--", 'rrze-ac'),
                'active' => 1
            ]
        ], $permissions);

        if (empty($permission) || !isset($permissions[$permission]) || !$permissions[$permission]['active']) {
            $permission = self::emptyPermissionKey();
        }

        $localization = [
            'permissions' => $permissions,
            'permission' => $permission,
            'metaKey' => self::accessPermissionMetaKey(),
            'canView' => permissions()->currentUserCanViewContentPermission($post->ID),
            'canEdit' => permissions()->currentUserCanChangeContentPermission($post->ID),
            'lockedMessage' => __('You are not allowed to change this access restriction.', 'rrze-ac')
        ];

        wp_localize_script(
            'rrze-ac-blockeditor',
            'acObject',
            $localization
        );

        wp_set_script_translations(
            'rrze-ac-blockeditor',
            'rrze-ac',
            plugin()->getPath('languages')
        );
    }
}
