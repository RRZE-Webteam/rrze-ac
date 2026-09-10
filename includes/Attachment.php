<?php

namespace RRZE\AccessControl;

defined('ABSPATH') || exit;

use RRZE\AccessControl\Media\Files;

class Attachment
{
    public static function init()
    {
        add_action('add_meta_boxes', [__CLASS__, 'metabox'], 10, 2);
        add_action('load-media-new.php', [__CLASS__, 'loadMediaNew']);
        add_action('load-upload.php', [__CLASS__, 'loadUpload']);
        add_filter('image_downsize', [__CLASS__, 'imageDownsizePlaceholder'], 999, 3);
        add_filter('attachment_fields_to_edit', [__CLASS__, 'attachmentFieldsToEdit'], 10, 2);
        add_filter('attachment_fields_to_save', [__CLASS__, 'saveAttachmentEditFields'], 10, 2);
        add_action('edit_attachment', [__CLASS__, 'saveAttachmentData']);
    }

    public static function metabox($postType = '', $post = null)
    {
        if ($post && !permissions()->currentUserCanViewContentPermission($post->ID)) {
            return;
        }

        add_filter('postbox_classes_attachment_attachment-protection-metabox', [__CLASS__, 'metaboxClasses']);

        add_meta_box(
            'attachment-protection-metabox',
            __("Access Restriction", 'rrze-ac'),
            [__CLASS__, 'renderMetabox'],
            'attachment',
            'side',
            'high'
        );
    }

    public static function metaboxClasses($classes)
    {
        $classes[] = 'rrze-ac';

        return array_unique($classes);
    }

    public static function renderMetabox($post)
    {
        if (!permissions()->currentUserCanViewContentPermission($post->ID)) {
            return;
        }

        wp_nonce_field('attachment_protection_metabox', 'attachment_protection_metabox_nonce');

        $permission = get_post_meta($post->ID, Post::accessPermissionMetaKey(), true);

        $permissions = permissions()->getThePermissions();

        if (empty($permission) || !isset($permissions[$permission]) || !$permissions[$permission]['active']) {
            $permission = permissions()->getDefaultPermission();
        } ?>
        <div class="rrze-ac">
            <?php if (!permissions()->currentUserCanChangeContentPermission($post->ID)) : ?>
                <p><strong><?php esc_html_e("Permission", 'rrze-ac'); ?>:</strong><br>
                    <?php echo esc_html(sanitize_text_field($permissions[$permission]['select'])); ?>
                </p>
            <?php else : ?>
                <input type="hidden" name="access_protection_toggle" value="off">
                <input type="checkbox" id="access-protection-toggle" name="access_protection_toggle" <?php checked(Files::isAttachmentProtected($post->ID)); ?>>
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
                                <?php echo esc_html(sanitize_text_field($data['select'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function loadMediaNew()
    {
        add_action('post-upload-ui', [__CLASS__, 'media_new_upload_ui']);
        add_action('pre-plupload-upload-ui', [__CLASS__, 'mediaNewUploadUINotice']);
    }

    public static function loadUpload()
    {
        add_filter('mediaRowActions', [__CLASS__, 'mediaRowActions'], 10, 2);
        add_filter('manage_upload_columns', [__CLASS__, 'manage_upload_columns']);
        add_action('manage_media_custom_column', [__CLASS__, 'manage_media_custom_column'], 10, 2);
        add_action('restrict_manage_posts', [__CLASS__, 'restrictManagePosts']);
        add_action('pre_get_posts', [__CLASS__, 'preGetPostsList']);
        add_action('admin_footer-upload.php', [__CLASS__, 'mediaBulkActionsJS']);
        add_action('admin_notices', [__CLASS__, 'mediaAdminNotices']);

        self::bulkActions();
    }

    public static function imageDownsizePlaceholder($img, $attachmentId, $size)
    {
        $uploadDir = wp_upload_dir();

        if (isset($img[0]) && 0 !== strpos(ltrim($img[0], $uploadDir['baseurl']), Files::protectedUploadDir('/', true))) {
            return $img;
        }

        if (Access::try($attachmentId)) {
            return $img;
        }

        if (!Files::isAttachmentProtected($attachmentId)) {
            remove_filter('image_downsize', [__CLASS__, 'imageDownsizePlaceholder'], 999, 3);

            $placeholder = wp_get_attachment_image_src($attachmentId, $size);

            add_filter('image_downsize', [__CLASS__, 'imageDownsizePlaceholder'], 999, 3);

            return $placeholder;
        } else {
            list($width, $height) = image_constrain_size_for_editor(1024, 1024, $size);

            return [
                plugin()->getUrl('images') . 'media-placeholder.jpg',
                $width,
                $height,
                false
            ];
        }
    }

    public static function media_new_upload_ui()
    {
        if (!permissions()->currentUserCanChangeContentPermission()) {
            return;
        }

        $screen = get_current_screen();
        if ('media' == $screen->base && 'add' == $screen->action) :
        ?>
            <div class="rrze-ac">
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
            </div>
        <?php
        endif;
    }

    public static function mediaNewUploadUINotice()
    {
        if (!permissions()->currentUserCanChangeContentPermission()) {
            return;
        }

        $screen = get_current_screen();
        if (isset($screen->base) && 'media' == $screen->base && 'add' == $screen->action) {
            echo '<div class="rrze-ac rrze-ac-upload-notice">';
            echo '<span aria-role="hidden" class="dashicons dashicons-shield"></span>';
            esc_html_e("New files are protected", 'rrze-ac');
            echo '</div>';
        }
    }

    public static function attachmentFieldsToEdit($formFields, $post)
    {
        if (!is_null(get_current_screen())) {
            return $formFields;
        }

        if (!permissions()->currentUserCanViewContentPermission($post->ID)) {
            return $formFields;
        }

        $permission = get_post_meta($post->ID, Post::accessPermissionMetaKey(), true);

        $permissions = permissions()->getThePermissions();

        if (empty($permission) || !isset($permissions[$permission])) {
            $permission = permissions()->getDefaultPermission();
        }

        ob_start(); ?>
        <tr id="access-attachment-fields" class="rrze-ac">
            <th class="label" scope="row">
                <label for="attachments-<?php echo esc_attr($post->ID); ?>">
                    <span class="alignleft"><?php esc_html_e("Access Restriction", 'rrze-ac'); ?></span>
                    <br class="clear">
                </label>
            </th>
            <td class="field">
                <?php if (!permissions()->currentUserCanChangeContentPermission($post->ID)) : ?>
                    <p><strong><?php esc_html_e("Permission", 'rrze-ac'); ?>:</strong><br>
                        <?php echo esc_html(sanitize_text_field($permissions[$permission]['select'])); ?>
                    </p>
                <?php else : ?>
                <input type="hidden" name="attachments[<?php echo esc_attr($post->ID); ?>][access_protection_toggle]" value="off">
                <input class="radio access-protection-toggle" type="checkbox" id="attachments[<?php echo esc_attr($post->ID); ?>][access_protection_toggle]" name="attachments[<?php echo esc_attr($post->ID); ?>][access_protection_toggle]" <?php checked(Files::isAttachmentProtected($post->ID)); ?>>
                <p id="access-attachment-permissions-field">
                    <label for="attachments[<?php echo esc_attr($post->ID); ?>][access_permission_select]"><?php esc_html_e("Permission", 'rrze-ac'); ?></label><br>
                    <select class="access-permission-select" id="attachments[<?php echo esc_attr($post->ID); ?>][access_permission_select]" name="attachments[<?php echo esc_attr($post->ID); ?>][access_permission_select]">
                        <?php foreach ($permissions as $key => $data) : ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($permission, $key); ?>>
                                <?php echo esc_html(sanitize_text_field($data['select'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <script>
                    jQuery(document).ready(function($) {
                        $('#access-attachment-fields').trigger('accessLoaded', <?php echo absint($post->ID); ?>);
                    });
                </script>
                <?php endif; ?>
            </td>
        </tr>
    <?php
        $formFields['access_permission_fields']['tr'] = ob_get_clean();

        return $formFields;
    }

    public static function saveAttachmentEditFields($post, $attachment)
    {
        if (!isset($attachment['access_protection_toggle'])) {
            return $post;
        }

        $attachmentId = $post['ID'];

        if (!permissions()->currentUserCanChangeContentPermission($attachmentId)) {
            return $post;
        }

        switch ($attachment['access_protection_toggle']) {

            case 'off':
                remove_action('edit_attachment', [__CLASS__, 'saveAttachmentData']);

                $moveAttachment = Files::moveAttachmentFromProtected($attachmentId);

                add_action('edit_attachment', [__CLASS__, 'saveAttachmentData']);

                if (is_wp_error($moveAttachment)) {
                    return $post;
                }

                delete_post_meta($attachmentId, Post::accessPermissionMetaKey());

                return $post;

            case 'on':
                remove_action('edit_attachment', [__CLASS__, 'saveAttachmentData']);

                $moveAttachment = Files::moveAttachmentToProtected($attachmentId);

                add_action('edit_attachment', [__CLASS__, 'saveAttachmentData']);

                if (is_wp_error($moveAttachment)) {
                    return $post;
                }

                if (empty($attachment['access_permission_select'])) {
                    return $post;
                }

                $permissions = permissions()->getThePermissions();

                if (!isset($permissions[$attachment['access_permission_select']])) {
                    delete_post_meta($attachmentId, Post::accessPermissionMetaKey());
                } else {
                    update_post_meta($attachmentId, Post::accessPermissionMetaKey(), $attachment['access_permission_select']);
                }

                return $post;

            default:
                return $post;
        }
    }

    public static function saveAttachmentData($attachmentId)
    {
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || (defined('DOING_AJAX') && DOING_AJAX) || isset($_REQUEST['bulk_edit'])) {
            return;
        }

        if (!isset($_POST['attachment_protection_metabox_nonce']) || !wp_verify_nonce($_POST['attachment_protection_metabox_nonce'], 'attachment_protection_metabox')) {
            return;
        }

        if (!current_user_can('edit_post', $attachmentId)) {
            return;
        }

        if (!permissions()->currentUserCanChangeContentPermission($attachmentId)) {
            return;
        }

        if (!isset($_POST['access_protection_toggle'])) {
            return;
        }

        switch ($_POST['access_protection_toggle']) {

            case 'off':
                remove_action('edit_attachment', [__CLASS__, 'saveAttachmentData']);

                $moveAttachment = Files::moveAttachmentFromProtected($attachmentId);

                add_action('edit_attachment', [__CLASS__, 'saveAttachmentData']);

                if (is_wp_error($moveAttachment)) {
                    return;
                }

                delete_post_meta($attachmentId, Post::accessPermissionMetaKey());

                break;

            case 'on':
                remove_action('edit_attachment', [__CLASS__, 'saveAttachmentData']);

                $moveAttachment = Files::moveAttachmentToProtected($attachmentId);

                add_action('edit_attachment', [__CLASS__, 'saveAttachmentData']);

                if (is_wp_error($moveAttachment)) {
                    return;
                }

                if (!isset($_POST['access_permission_select']) || empty($_POST['access_permission_select'])) {
                    return;
                }

                $permissions = permissions()->getThePermissions();

                if (!isset($permissions[$_POST['access_permission_select']])) {
                    delete_post_meta($attachmentId, Post::accessPermissionMetaKey());
                } else {
                    update_post_meta($attachmentId, Post::accessPermissionMetaKey(), $_POST['access_permission_select']);
                }

                break;

            default:
                return;
        }
    }

    public static function mediaRowActions($actions, $post)
    {
        if (!Access::try($post->ID)) {
            return array(esc_html__('You do not have sufficient permissions to access the file.', 'rrze-ac'));
        }

        return $actions;
    }

    public static function manage_upload_columns($columns)
    {
        if (self::accessFilter() == 'unprotected') {
            unset($columns['access_info']);
            return $columns;
        }

        $columns['access_info'] = esc_html__('Access Control', 'rrze-ac');
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
        $title = $description;
        if ($error) {
            $title = sprintf(
                /* translators: 1: Error message, 2: Default permission. */
                __('An error has occurred: %1$s and has been replaced by the default permission %2$s.', 'rrze-ac'),
                $error,
                $description
            );
        }

        printf(
            '<span class="rrze-ac rrze-ac-access-info"><span title="%1$s" class="%2$s dashicons dashicons-shield"></span><span class="access-permission-name">%3$s</span></span>',
            esc_attr($title),
            esc_attr($error ? 'access-error-icon' : $class),
            esc_html($description)
        );
    }

    public static function restrictManagePosts($postType = '')
    {
        if ($postType != 'attachment') {
            return;
        }

        $selected = self::accessFilter(); ?>
        <label class="screen-reader-text" for="rrze-ac-media-access-filter"><?php esc_html_e('Filter by access control', 'rrze-ac'); ?></label>
        <select name="rrze_ac_access_filter" id="rrze-ac-media-access-filter">
            <option value=""><?php esc_html_e('Access Control', 'rrze-ac'); ?></option>
            <option value="unprotected" <?php selected($selected, 'unprotected'); ?>>
                <?php esc_html_e('Without Access Control', 'rrze-ac'); ?>
            </option>
            <option value="<?php echo esc_attr(Post::protectedPostStatus()); ?>" <?php selected($selected, Post::protectedPostStatus()); ?>>
                <?php esc_html_e('With Access Control', 'rrze-ac'); ?>
            </option>
        </select>
    <?php
    }

    public static function preGetPostsList($query)
    {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') != 'attachment') {
            return $query;
        }

        $filter = self::accessFilter();
        if (empty($filter)) {
            return $query;
        }

        $protectedUploadDir = ltrim(Files::protectedUploadDir('/'), '/');
        $metaQuery = [
            'relation' => 'OR',
            [
                'key' => Post::accessPermissionMetaKey(),
                'compare' => 'EXISTS'
            ],
            [
                'key' => '_wp_attached_file',
                'value' => $protectedUploadDir,
                'compare' => 'LIKE'
            ]
        ];

        if ($filter == 'unprotected') {
            $metaQuery = [
                'relation' => 'AND',
                [
                    'key' => Post::accessPermissionMetaKey(),
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key' => '_wp_attached_file',
                    'value' => $protectedUploadDir,
                    'compare' => 'NOT LIKE'
                ]
            ];
        }

        $query->set('meta_query', $metaQuery);

        return $query;
    }

    private static function accessFilter()
    {
        if (empty($_GET['rrze_ac_access_filter'])) {
            return '';
        }

        return sanitize_key(wp_unslash($_GET['rrze_ac_access_filter']));
    }

    public static function mediaBulkActionsJS()
    {
        if (!permissions()->currentUserCanChangeContentPermission() && !permissions()->currentUserCanManageContentPermissions()) {
            return;
        }

        $bulkActions = [];
        if (!isset($_GET['access-show-protected']) && permissions()->currentUserCanChangeContentPermission()) {
            $bulkActions['access-protect'] = esc_html__("Enable permission", 'rrze-ac');
        }
        if (!isset($_GET['access-show-unprotected']) && permissions()->currentUserCanManageContentPermissions()) {
            $bulkActions['access-unprotect'] = esc_html__("Remove permission", 'rrze-ac');
        } ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $.each(<?php echo json_encode($bulkActions); ?>, function(index, value) {
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

    public static function mediaAdminNotices()
    {
        $screen = get_current_screen();
        if ('upload' === $screen->id) {
            if (isset($_REQUEST['access-protected']) && (int) $_REQUEST['access-protected']) {
                $message = sprintf(
                    /* translators: %s: number of media files. */
                    _n(
                        "%s media file is now protected.",
                        "%s media files are now protected.",
                        $_REQUEST['access-protected'],
                        'rrze-ac'
                    ),
                    number_format_i18n($_REQUEST['access-protected'])
                );
                echo '<div class="rrze-ac updated"><p>' . esc_html($message) . '</p></div>';
                $_SERVER['REQUEST_URI'] = remove_query_arg('access-protected', $_SERVER['REQUEST_URI']);
            }

            if (isset($_REQUEST['access-unprotected']) && (int) $_REQUEST['access-unprotected']) {
                $message = sprintf(
                    /* translators: %s: number of media files. */
                    _n(
                        "Data protection on %s Media file has been removed.",
                        "Data protection on %s Media files has been removed.",
                        $_REQUEST['access-unprotected'],
                        'rrze-ac'
                    ),
                    number_format_i18n($_REQUEST['access-unprotected'])
                );
                echo '<div class="rrze-ac updated"><p>' . esc_html($message) . '</p></div>';
                $_SERVER['REQUEST_URI'] = remove_query_arg('access-unprotected', $_SERVER['REQUEST_URI']);
            }
        }
    }

    public static function bulkActions()
    {
        $wpListTable = _get_list_table('WP_Media_List_Table');
        $action = $wpListTable->current_action();

        $allowedActions = [
            'access-protect',
            'access-unprotect'
        ];
        if (!in_array($action, $allowedActions)) {
            return;
        }

        check_admin_referer('bulk-media');

        if (isset($_REQUEST['media'])) {
            $mediaIds = array_map('intval', $_REQUEST['media']);
        }

        if (empty($mediaIds)) {
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

        $pagenum = $wpListTable->get_pagenum();
        if ($pagenum > 1) {
            $location = add_query_arg('paged', $pagenum, $location);
        }

        switch ($action) {

            case 'access-protect':
                if (!permissions()->currentUserCanChangeContentPermission()) {
                    wp_die(
                        esc_html__('You are not allowed to add media files to the protected directory.', 'rrze-ac'),
                        esc_html__('Forbidden', 'rrze-ac'),
                        [
                            'response' => '403',
                            'back_link' => true
                        ]
                    );
                }

                $protected = 0;
                foreach ((array) $mediaIds as $media_id) {
                    if (!permissions()->currentUserCanChangeContentPermission($media_id)) {
                        continue;
                    }

                    if (Files::isAttachmentProtected($media_id)) {
                        continue;
                    }

                    $moveAttachment = Files::moveAttachmentToProtected($media_id);

                    if (is_wp_error($moveAttachment)) {
                        wp_die(
                            esc_html__('An error has occurred while moving the media files in the protected directory.', 'rrze-ac') . '<br>' . esc_html($moveAttachment->get_error_message()),
                            esc_html__('Internal Server Error', 'rrze-ac'),
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
                    'ids' => join(',', $mediaIds)
                ), $location);
                break;

            case 'access-unprotect':
                if (!permissions()->currentUserCanManageContentPermissions()) {
                    wp_die(
                        esc_html__('You are not allowed to remove media files from the protected directory.', 'rrze-ac'),
                        esc_html__('Forbidden', 'rrze-ac'),
                        [
                            'response' => '403',
                            'back_link' => true
                        ]
                    );
                }

                $unprotected = 0;
                foreach ((array) $mediaIds as $media_id) {
                    if (!current_user_can('edit_post', $media_id)) {
                        continue;
                    }

                    if (!Files::isAttachmentProtected($media_id)) {
                        continue;
                    }

                    $moveAttachment = Files::moveAttachmentFromProtected($media_id);

                    if (is_wp_error($moveAttachment)) {
                        wp_die(
                            esc_html__('An error has occurred while removing the media files from the protected directory.', 'rrze-ac') . '<br>' . esc_html($moveAttachment->get_error_message()),
                            esc_html__('Internal Server Error', 'rrze-ac'),
                            [
                                'response' => '500',
                                'back_link' => true
                            ]
                        );
                    }

                    delete_post_meta($media_id, Post::accessPermissionMetaKey());

                    $unprotected++;
                }

                $location = add_query_arg(array(
                    'access-unprotected' => $unprotected,
                    'ids' => join(',', $mediaIds)
                ), $location);
                break;

            default:
                return;
        }

        $location = remove_query_arg(array('action', 'action2', 'media'), $location);

        wp_safe_redirect($location);
        exit();
    }
}
