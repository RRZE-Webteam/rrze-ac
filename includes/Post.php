<?php

namespace RRZE\AccessControl;

defined('ABSPATH') || exit;

class Post
{
    const ACCESS_PERMISSION_META_KEY = '_access_permission';

    public static function init()
    {
        add_action('init', [__CLASS__, 'registerPostMeta']);

        add_action('add_meta_boxes', [__CLASS__, 'metabox']);

        add_action("save_post_page", [__CLASS__, 'savePost'], 10, 2);
        add_action('updated_postmeta', [__CLASS__, 'updatePostMeta'], 10, 4);

        add_action("manage_edit-page_columns", [__CLASS__, 'managePagesColumn']);
        add_filter("manage_page_posts_custom_column", [__CLASS__, 'managePagesCustomColumn'], 10, 2);

        /* Enqueue Block Editor Assets */
        add_action('enqueue_block_editor_assets', [__CLASS__, 'enqueueBlockEditorAssets']);
    }

    public static function metabox($postType)
    {
        if ($postType != 'page') {
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
        $permission = get_post_meta($post->ID, self::ACCESS_PERMISSION_META_KEY, true);

        $permissions = permissions()->getThePermissions();
        $permissions = array_merge([
            '_none_' => [
                'select' => __("--NONE--", 'rrze-ac'),
                'active' => 1
            ]
        ], $permissions);

        if (empty($permission) || !isset($permissions[$permission]) || !$permissions[$permission]['active']) {
            $permission = '_none_';
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

        $permission = $_POST['access_permission_select'] ?? '';
        $permission = sanitize_text_field($permission);
        $permissions = permissions()->getThePermissions();
        $permissions = array_merge(['_none_' => []], $permissions);

        if ('_none_' === $permission) {
            delete_post_meta($postId, self::ACCESS_PERMISSION_META_KEY);
        } elseif (isset($permissions[$permission])) {
            update_post_meta($postId, self::ACCESS_PERMISSION_META_KEY, $permission);
        }
    }

    public static function registerPostMeta()
    {
        register_post_meta(
            'page',
            self::ACCESS_PERMISSION_META_KEY,
            [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
            ]
        );
    }

    public static function updatePostMeta($metaId, $postId, $metaKey, $metaValue)
    {
        if (self::ACCESS_PERMISSION_META_KEY == $metaKey) {
            if ('_none_' === $metaValue) {
                delete_post_meta($postId, self::ACCESS_PERMISSION_META_KEY);
            }
        }
    }

    public static function managePagesColumn($columns)
    {
        $columns['access_info'] = '<span title="' . esc_attr__('Access Restriction', 'rrze-ac') . '" class="dashicons dashicons-shield"></span>';
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
            '<span title="' . esc_attr__($description) . '" class="' . $class . ' dashicons dashicons-shield"></span>' :
            '<span title="' . sprintf(
                /* translators: 1: Error message, 2: Default permission. */
                esc_attr__('An error has occurred: %1$s and has been replaced by the default permission %2$s.', 'rrze-ac'),
                $error,
                $description
            ) . '" class="access-error-icon dashicons dashicons-shield"></span>';

        echo $description;
    }

    public static function enqueueBlockEditorAssets()
    {
        global $post;
        if (get_post_type($post) !== 'page') {
            return;
        }

        wp_enqueue_style(
            'rrze-ac-blockeditor',
            plugins_url('build/blockeditor.style.css', plugin()->getBasename()),
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

        $permission = get_post_meta($post->ID, self::ACCESS_PERMISSION_META_KEY, true);

        $permissions = permissions()->getThePermissions();
        $permissions = array_merge([
            '_none_' => [
                'select' => __("--NONE--", 'rrze-ac'),
                'active' => 1
            ]
        ], $permissions);

        if (empty($permission) || !isset($permissions[$permission]) || !$permissions[$permission]['active']) {
            $permission = '_none_';
        }

        $localization = [
            'permissions' => $permissions,
            'permission' => $permission,
            'metaKey' => self::ACCESS_PERMISSION_META_KEY
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
