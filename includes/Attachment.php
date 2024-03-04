<?php

namespace RRZE\AccessControl;

defined('ABSPATH') || exit;

use RRZE\AccessControl\Media\Files;

class Attachment
{
    public static function init()
    {
        add_action('add_meta_boxes', [__CLASS__, 'metabox']);
        add_action('load-media-new.php', [__CLASS__, 'load_media_new']);
        add_action('load-upload.php', [__CLASS__, 'load_upload']);
        add_filter('image_downsize', [__CLASS__, 'image_downsize_placeholder'], 999, 3);
        add_filter('attachment_fields_to_edit', [__CLASS__, 'attachment_fields_to_edit'], 10, 2);
        add_filter('attachment_fields_to_save', [__CLASS__, 'save_attachment_edit_fields'], 10, 2);
        add_action('edit_attachment', [__CLASS__, 'save_attachment_data']);
    }

    public static function metabox()
    {
        add_meta_box(
            'attachment-protection-metabox',
            __("Access Restriction", 'rrze-ac'),
            [__CLASS__, 'renderMetabox'],
            'attachment',
            'side',
            'high'
        );
    }

    public static function renderMetabox($post)
    {
        wp_nonce_field('attachment_protection_metabox', 'attachment_protection_metabox_nonce');

        $permission = get_post_meta($post->ID, Post::ACCESS_PERMISSION_META_KEY, true);

        $permissions = permissions()->getThePermissions();

        if (empty($permission) || !isset($permissions[$permission]) || !$permissions[$permission]['active']) {
            $permission = permissions()->getDefaultPermission();
        } ?>
        <input type="hidden" name="access_protection_toggle" value="off">
        <input type="checkbox" id="access-protection-toggle" name="access_protection_toggle" <?php checked(Files::is_attachment_protected($post->ID)); ?>>
        <label class="access-protection-toggle" for="access-protection-toggle">
            <span aria-role="hidden" class="access-on button button-primary" data-access-content="<?php esc_attr_e("Enable permission", 'rrze-ac'); ?>"></span>
            <span aria-role="hidden" class="access-off" data-access-content="<?php esc_attr_e("Remove permission", 'rrze-ac'); ?>"></span>
        </label>
        <div class="access-permission-select">
            <label for="access-permission-select">
                <span class="description"><?php esc_html_e("Permission", 'rrze-ac'); ?></span>
            </label>
            <select id="access-permission-select" name="access_permission_select">
                <?php foreach ($permissions as $key => $data) : ?>
                    <?php if (!$data['active']) {
                        continue;
                    } ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($permission, $key); ?>>
                        <?php echo sanitize_text_field($data['select']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }

    public static function load_media_new()
    {
        add_action('post-upload-ui', [__CLASS__, 'media_new_upload_ui']);
        add_action('pre-plupload-upload-ui', [__CLASS__, 'media_new_upload_ui_notice']);
    }

    public static function load_upload()
    {
        add_filter('media_row_actions', [__CLASS__, 'media_row_actions'], 10, 2);
        add_filter('manage_upload_columns', [__CLASS__, 'manage_upload_columns']);
        add_action('manage_media_custom_column', [__CLASS__, 'manage_media_custom_column'], 10, 2);
        add_action('admin_head-upload.php', [__CLASS__, 'media_custom_column_styles']);
        add_action('admin_footer-upload.php', [__CLASS__, 'media_bulk_actions_js']);
        add_action('admin_notices', [__CLASS__, 'media_admin_notices']);

        self::bulk_actions();
    }

    public static function image_downsize_placeholder($img, $attachment_id, $size)
    {
        $upload_dir = wp_upload_dir();

        if (isset($img[0]) && 0 !== strpos(ltrim($img[0], $upload_dir['baseurl']), Files::protected_upload_dir('/', true))) {
            return $img;
        }

        if (Access::try($attachment_id)) {
            return $img;
        }

        if (!Files::is_attachment_protected($attachment_id)) {
            remove_filter('image_downsize', [__CLASS__, 'image_downsize_placeholder'], 999, 3);

            $placeholder = wp_get_attachment_image_src($attachment_id, $size);

            add_filter('image_downsize', [__CLASS__, 'image_downsize_placeholder'], 999, 3);

            return $placeholder;
        } else {
            list($width, $height) = image_constrain_size_for_editor(1024, 1024, $size);

            return array(
                plugin()->getUrl('images') . 'media-placeholder.jpg',
                $width,
                $height,
                false
            );
        }
    }

    public static function media_new_upload_ui()
    {
        $screen = get_current_screen();
        if ('media' == $screen->base && 'add' == $screen->action) :
        ?>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="access_protected">
                                <?php esc_html_e("Access Restriction", 'rrze-ac'); ?>
                            </label>
                        </th>
                        <td>
                            <label for="access_protected">
                                <input type="checkbox" id="access_protected" name="access_protected">
                                <span class="description">
                                    <?php esc_html_e("Activate", 'rrze-ac'); ?>
                                </span>
                            </label>
                        </td>
                    </tr>
                </tbody>
            </table>
        <?php
        endif;
    }

    public static function media_new_upload_ui_notice()
    {
        $screen = get_current_screen();
        if (isset($screen->base) && 'media' == $screen->base && 'add' == $screen->action) {
            echo '<div class="access-tag">';
            echo '<span aria-role="hidden" class="dashicons dashicons-shield"></span>';
            _e("New files are protected", 'rrze-ac');
            echo '</div>';
        }
    }

    public static function attachment_fields_to_edit($form_fields, $post)
    {
        if (!is_null(get_current_screen())) {
            return $form_fields;
        }

        $permission = get_post_meta($post->ID, Post::ACCESS_PERMISSION_META_KEY, true);

        $permissions = permissions()->getThePermissions();

        if (empty($permission) || !isset($permissions[$permission])) {
            $permission = permissions()->getDefaultPermission();
        }

        ob_start(); ?>
        <tr id="access-attachment-fields">
            <th class="label" scope="row">
                <label for="attachments-<?php echo $post->ID ?>">
                    <span class="alignleft"><?php esc_html_e("Access Restriction", 'rrze-ac'); ?></span>
                    <br class="clear">
                </label>
            </th>
            <td class="field">
                <input type="hidden" name="attachments[<?php echo $post->ID ?>][access_protection_toggle]" value="off">
                <input class="radio access-protection-toggle" type="checkbox" id="attachments[<?php echo $post->ID; ?>][access_protection_toggle]" name="attachments[<?php echo $post->ID; ?>][access_protection_toggle]" <?php checked(Files::is_attachment_protected($post->ID)); ?>>
                <p id="access-attachment-permissions-field">
                    <label for="attachments[<?php echo $post->ID; ?>][access_permission_select]"><?php esc_html_e("Permission", 'rrze-ac'); ?></label><br>
                    <select class="access-permission-select" id="attachments[<?php echo $post->ID; ?>][access_permission_select]" name="attachments[<?php echo $post->ID; ?>][access_permission_select]">
                        <?php foreach ($permissions as $key => $data) : ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($permission, $key); ?>>
                                <?php echo sanitize_text_field($data['select']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <script>
                    jQuery(document).ready(function($) {
                        $('#access-attachment-fields').trigger('accessLoaded', <?php echo $post->ID; ?>);
                    });
                </script>
            <td>
        </tr>
    <?php
        $form_fields['access_permission_fields']['tr'] = ob_get_clean();

        return $form_fields;
    }

    public static function save_attachment_edit_fields($post, $attachment)
    {
        if (!isset($attachment['access_protection_toggle'])) {
            return $post;
        }

        $attachment_id = $post['ID'];

        switch ($attachment['access_protection_toggle']) {

            case 'off':
                remove_action('edit_attachment', [__CLASS__, 'save_attachment_data']);

                $move_attachment = Files::move_attachment_from_protected($attachment_id);

                add_action('edit_attachment', [__CLASS__, 'save_attachment_data']);

                if (is_wp_error($move_attachment)) {
                    return $post;
                }

                delete_post_meta($attachment_id, Post::ACCESS_PERMISSION_META_KEY);

                return $post;

            case 'on':
                remove_action('edit_attachment', [__CLASS__, 'save_attachment_data']);

                $move_attachment = Files::move_attachment_to_protected($attachment_id);

                add_action('edit_attachment', [__CLASS__, 'save_attachment_data']);

                if (is_wp_error($move_attachment)) {
                    return $post;
                }

                if (empty($attachment['access_permission_select'])) {
                    return $post;
                }

                $permissions = permissions()->getThePermissions();

                if (!isset($permissions[$attachment['access_permission_select']])) {
                    delete_post_meta($attachment_id, Post::ACCESS_PERMISSION_META_KEY);
                } else {
                    update_post_meta($attachment_id, Post::ACCESS_PERMISSION_META_KEY, $attachment['access_permission_select']);
                }

                return $post;

            default:
                return $post;
        }
    }

    public static function save_attachment_data($attachment_id)
    {
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || (defined('DOING_AJAX') && DOING_AJAX) || isset($_REQUEST['bulk_edit'])) {
            return;
        }

        if (!isset($_POST['attachment_protection_metabox_nonce']) || !wp_verify_nonce($_POST['attachment_protection_metabox_nonce'], 'attachment_protection_metabox')) {
            return;
        }

        if (!current_user_can('edit_post', $attachment_id)) {
            return;
        }

        if (!isset($_POST['access_protection_toggle'])) {
            return;
        }

        switch ($_POST['access_protection_toggle']) {

            case 'off':
                remove_action('edit_attachment', [__CLASS__, 'save_attachment_data']);

                $move_attachment = Files::move_attachment_from_protected($attachment_id);

                add_action('edit_attachment', [__CLASS__, 'save_attachment_data']);

                if (is_wp_error($move_attachment)) {
                    return;
                }

                delete_post_meta($attachment_id, Post::ACCESS_PERMISSION_META_KEY);

                break;

            case 'on':
                remove_action('edit_attachment', [__CLASS__, 'save_attachment_data']);

                $move_attachment = Files::move_attachment_to_protected($attachment_id);

                add_action('edit_attachment', [__CLASS__, 'save_attachment_data']);

                if (is_wp_error($move_attachment)) {
                    return;
                }

                if (!isset($_POST['access_permission_select']) || empty($_POST['access_permission_select'])) {
                    return;
                }

                $permissions = permissions()->getThePermissions();

                if (!isset($permissions[$_POST['access_permission_select']])) {
                    delete_post_meta($attachment_id, Post::ACCESS_PERMISSION_META_KEY);
                } else {
                    update_post_meta($attachment_id, Post::ACCESS_PERMISSION_META_KEY, $_POST['access_permission_select']);
                }

                break;

            default:
                return;
        }
    }

    public static function media_row_actions($actions, $post)
    {
        if (!Access::try($post->ID)) {
            return array(esc_html__('You do not have sufficient permissions to access the file.', 'rrze-ac'));
        }

        return $actions;
    }

    public static function manage_upload_columns($columns)
    {
        $columns['access_info'] = '<span title="' . esc_attr__("Access Restriction", 'rrze-ac') . '" class="dashicons dashicons-shield"></span>';
        return $columns;
    }

    public static function manage_media_custom_column($column_name, $postId)
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
            $error = __("The permission has been disabled.", 'rrze-ac');
            $permission = permissions()->getDefaultPermission();
        }

        $class = $permission == 'public' ? 'access-all-icon' : 'access-icon';
        $permission = $permissions[$permission];

        $description = isset($permission['description']) && !empty($permission['description']) ? $permission['description'] : $permission['permission_key'];
        $description = !$error ?
            '<span title="' . esc_attr__($description) . '" class="' . $class . ' dashicons dashicons-shield"></span>' :
            '<span title="' . sprintf(esc_attr__('An error has occurred: %1$s and has been replaced by the default permission %2$s.', 'rrze-ac'), $error, $description) . '" class="access-error-icon dashicons dashicons-shield"></span>';

        echo $description;
    }

    public static function media_custom_column_styles()
    {
    ?>

        <style type="text/css">
            .column-access_info {
                width: 120px;
            }
        </style>

    <?php
    }

    public static function media_bulk_actions_js()
    {
        if (!current_user_can('edit_posts')) {
            return;
        }

        $bulk_actions = array();
        if (!isset($_GET['access-show-protected'])) {
            $bulk_actions['access-protect'] = esc_html__("Enable permission", 'rrze-ac');
        }
        if (!isset($_GET['access-show-unprotected'])) {
            $bulk_actions['access-unprotect'] = esc_html__("Remove permission", 'rrze-ac');
        } ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $.each(<?php echo json_encode($bulk_actions); ?>, function(index, value) {
                    $('<option>')
                        .val(index)
                        .text(value)
                        .appendTo('select[name="action"]')
                        .clone()
                        .appendTo('select[name="action2"]');
                });
            });
        </script>
<?php
    }

    public static function media_admin_notices()
    {
        $screen = get_current_screen();
        if ('upload' === $screen->id) {
            if (isset($_REQUEST['access-protected']) && (int) $_REQUEST['access-protected']) {
                $message = sprintf(
                    _n(
                        /* translators: %s: number of media files */
                        "%s media file is now protected.",
                        "%s media files are now protected.",
                        $_REQUEST['access-protected'],
                        'rrze-ac'
                    ),
                    number_format_i18n($_REQUEST['access-protected'])
                );
                echo '<div class="updated"><p>' . esc_html($message) . '</p></div>';
                $_SERVER['REQUEST_URI'] = remove_query_arg('access-protected', $_SERVER['REQUEST_URI']);
            }

            if (isset($_REQUEST['access-unprotected']) && (int) $_REQUEST['access-unprotected']) {
                $message = sprintf(
                    _n(
                        /* translators: %s: number of media files */
                        "Data protection on %s Media file has been removed.",
                        "Data protection on %s Media files has been removed.",
                        $_REQUEST['access-unprotected'],
                        'rrze-ac'
                    ),
                    number_format_i18n($_REQUEST['access-unprotected'])
                );
                echo '<div class="updated"><p>' . esc_html($message) . '</p></div>';
                $_SERVER['REQUEST_URI'] = remove_query_arg('access-unprotected', $_SERVER['REQUEST_URI']);
            }
        }
    }

    public static function bulk_actions()
    {
        $wp_list_table = _get_list_table('WP_Media_List_Table');
        $action = $wp_list_table->current_action();

        $allowed_actions = array(
            'access-protect',
            'access-unprotect'
        );
        if (!in_array($action, $allowed_actions)) {
            return;
        }

        check_admin_referer('bulk-media');

        if (isset($_REQUEST['media'])) {
            $media_ids = array_map('intval', $_REQUEST['media']);
        }

        if (empty($media_ids)) {
            return;
        }

        $location = 'upload.php';
        if ($referer = wp_get_referer()) {
            if (false !== strpos($referer, 'upload.php')) {
                $location = remove_query_arg(
                    array('access-protected', 'access-unprotected', 'trashed', 'untrashed', 'deleted', 'message', 'ids', 'posted'),
                    $referer
                );
            }
        }

        $pagenum = $wp_list_table->get_pagenum();
        if ($pagenum > 1) {
            $location = add_query_arg('paged', $pagenum, $location);
        }

        switch ($action) {

            case 'access-protect':
                if (!current_user_can('edit_posts')) {
                    wp_die(
                        __('You are not allowed to add media files to the protected directory.', 'rrze-ac'),
                        __('Forbidden', 'rrze-ac'),
                        [
                            'response' => '403',
                            'back_link' => true
                        ]
                    );
                }

                $protected = 0;
                foreach ((array) $media_ids as $media_id) {
                    if (!current_user_can('edit_post', $media_id)) {
                        continue;
                    }

                    if (Files::is_attachment_protected($media_id)) {
                        continue;
                    }

                    $move_attachment = Files::move_attachment_to_protected($media_id);

                    if (is_wp_error($move_attachment)) {
                        wp_die(
                            __('An error has occurred while moving the media files in the protected directory.', 'rrze-ac') . '<br/>' . $move_attachment->get_error_message(),
                            __('Internal Server Error', 'rrze-ac'),
                            [
                                'response' => '500',
                                'back_link' => true
                            ]
                        );
                    }

                    $protected++;
                }

                $location = add_query_arg(array(
                    'access-protected' => $protected,
                    'ids' => join(',', $media_ids)
                ), $location);
                break;

            case 'access-unprotect':
                if (!current_user_can('edit_posts')) {
                    wp_die(
                        __('You are not allowed to remove media files from the protected directory.', 'rrze-ac'),
                        __('Forbidden', 'rrze-ac'),
                        [
                            'response' => '403',
                            'back_link' => true
                        ]
                    );
                }

                $unprotected = 0;
                foreach ((array) $media_ids as $media_id) {
                    if (!current_user_can('edit_post', $media_id)) {
                        continue;
                    }

                    if (!Files::is_attachment_protected($media_id)) {
                        continue;
                    }

                    $move_attachment = Files::move_attachment_from_protected($media_id);

                    if (is_wp_error($move_attachment)) {
                        wp_die(
                            __('An error has occurred while removing the media files from the protected directory.', 'rrze-ac') . '<br/>' . $move_attachment->get_error_message(),
                            __('Internal Server Error', 'rrze-ac'),
                            [
                                'response' => '500',
                                'back_link' => true
                            ]
                        );
                    }

                    delete_post_meta($media_id, Post::ACCESS_PERMISSION_META_KEY);

                    $unprotected++;
                }

                $location = add_query_arg(array(
                    'access-unprotected' => $unprotected,
                    'ids' => join(',', $media_ids)
                ), $location);
                break;

            default:
                return;
        }

        $location = remove_query_arg(array('action', 'action2', 'media'), $location);

        wp_redirect($location);
        exit();
    }
}
