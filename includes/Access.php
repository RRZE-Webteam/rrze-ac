<?php

namespace RRZE\AccessControl;

defined('ABSPATH') || exit;

class Access
{
    /**
     * Try To Access
     * @param int $postId The post ID.
     */
    public static function try($postId = 0)
    {
        if (is_super_admin()) {
            return true;
        }

        if (empty($postId)) {
            return false;
        }

        if (!$permission = permissions()->getThePermission($postId)) {
            return true;
        }

        if (permissions()->checkPrivilegedAccess()) {
            return true;
        }

        if (permissions()->checkAuthorPermission($postId)) {
            return true;
        }

        $permissions = permissions()->getThePermissions();

        // Set permission to default permission if not exist or not active.
        if (!isset($permissions[$permission]) || !$permissions[$permission]['active']) {
            $permission = permissions()->getDefaultPermission();
        }

        $allowed = false;

        if ($permission == 'public') {
            $allowed = true;
        }

        if ($permission == 'logged-in' && is_user_logged_in()) {
            $allowed = true;
        }

        // Check if permission is set to domain.
        if (!$allowed && !empty($permissions[$permission]['domain'])) {
            if (!permissions()->checkRemoteDomain($permissions[$permission]['domain'])) {
                permissions()->set_permission_status(permissions()->user_domain_not_allowed);
            } else {
                $allowed = true;
            }
        }

        // Check if permission is set to ip address.
        if (!$allowed && !empty($permissions[$permission]['ip_address'])) {
            if (!permissions()->checkIpAddressRange($permissions[$permission]['ip_address'])) {
                permissions()->set_permission_status(permissions()->user_ip_isnt_in_range);
            } else {
                $allowed = true;
            }
        }

        // Check if permission is set to password.
        if (!$allowed && !empty($permissions[$permission]['password'])) {
            if (!permissions()->checkPassword($postId, $permissions[$permission]['password'])) {
                permissions()->set_permission_status(permissions()->wrong_password);
            } else {
                $allowed = true;
            }
        }

        // Check if permission is set to siteimprove (crawler).
        if (!$allowed && !empty($permissions[$permission]['siteimprove'])) {
            if (permissions()->checkSiteimprove()) {
                $allowed = true;
            }
        }

        // Check if permission is set to be logged in.
        if (!$allowed && !empty($permissions[$permission]['logged_in'])) {
            if (!permissions()->isUserMember()) {
                permissions()->set_permission_status(permissions()->user_isnt_logged_in);
            } else {
                $allowed = true;
            }
        }

        // Check if permission is set to be sso logged in.
        $ssoLoggedIn = false;
        if (!$allowed && !empty($permissions[$permission]['sso_logged_in'])) {
            if (!permissions()->checkSSOLoggedIn()) {
                permissions()->set_permission_status(permissions()->user_isnt_sso_logged_in);
            } else {
                $ssoLoggedIn = true;
                $allowed = true;
            }
        }

        // Require person affiliation OR person entitlement.
        if (
            $ssoLoggedIn
            && !is_null(permissions()->personAttributes)
            && (!empty($permissions[$permission]['affiliation']) || !empty($permissions[$permission]['entitlement']))
        ) {
            $allowed_person_affiliation = false;
            $allowed_person_entitlement = false;

            // Check if permission is set to person affiliation.
            if (!empty($permissions[$permission]['affiliation'])) {
                if (!permissions()->checkPersonAffiliation($permissions[$permission]['affiliation'])) {
                    permissions()->set_permission_status(permissions()->user_hasnt_affiliation);
                } else {
                    $allowed_person_affiliation = true;
                }
            }

            // Check if permission is set to person entitlement.
            if (!empty($permissions[$permission]['entitlement'])) {
                if (!permissions()->checkPersonEntitlement($permissions[$permission]['entitlement'])) {
                    permissions()->set_permission_status(permissions()->user_hasnt_entitlement);
                } else {
                    $allowed_person_entitlement = true;
                }
            }

            if (!$allowed_person_affiliation && !$allowed_person_entitlement) {
                $allowed = false;
            }
        }

        // Allow reading or modifying the access status.
        $allowed = apply_filters('rrze_ac_access_allowed', $allowed, $postId, $permission, $permissions[$permission]);

        if (!$allowed) {
            permissions()->logInfo([
                'plugin' => 'rrze-ac',
                'method' => __METHOD__,
                'postID' => $postId,
                'permission' => $permission,
                'message' => 'Access denied.'
            ]);
        }

        return $allowed;
    }

    public static function permissionMessage($postId, $options)
    {
        return self::templatePermissionMessage($postId, $options);
    }

    protected static function templatePermissionMessage($postId, $options)
    {
        $postType = get_post_type($postId);
        $permalink = $postType == 'attachment' && !wp_attachment_is_image($postId)
            ? wp_get_attachment_url($postId)
            : get_permalink($postId);
        $loginUrl = wp_login_url($permalink);
        $loginMethods = [];

        if (permissions()->getPermissionStatus(permissions()->user_isnt_logged_in)) {
            $loginMethods[] = [
                'type' => 'login',
                'class' => 'wordpress-login',
                'title' => $options['user_isnt_logged_in_title'],
                'message' => $options['user_isnt_logged_in_msg'],
                'link_url' => $loginUrl,
                'link_text' => $options['user_isnt_logged_in_link_txt']
            ];
        }

        if (permissions()->getPermissionStatus(permissions()->user_isnt_sso_logged_in) && permissions()->simplesamlAuth) {
            $loginMethods[] = [
                'type' => 'sso',
                'class' => 'sso-login',
                'title' => $options['user_isnt_sso_logged_in_title'],
                'message' => $options['user_isnt_sso_logged_in_msg'],
                'link_url' => permissions()->simplesamlAuth->getLoginURL(),
                'link_text' => $options['user_isnt_sso_logged_in_link_txt']
            ];
        }

        if (permissions()->getPermissionStatus(permissions()->wrong_password)) {
            $loginMethods[] = [
                'type' => 'password',
                'class' => 'password',
                'title' => __('Login via Access Password', 'rrze-ac'),
                'message' => $options['access_denied_password_msg'],
                'field_name' => 'rrze_ac_password_' . $postId,
                'link_text' => __('Send password', 'rrze-ac')
            ];
        }

        $styles = self::frontendStyles();
        $defaultLogin = [
            'link_url' => $loginUrl,
            'link_text' => $options['user_isnt_logged_in_link_txt']
        ];
        $defaultMessage = $options['access_denied_default_msg'];
        $defaultTitle = $options['access_denied_default_title'];
        $contact = self::getContact($options);
        $template = plugin()->getPath('template') . 'permission-message.php';

        ob_start();
        include $template;
        return ob_get_clean();
    }

    protected static function frontendStyles()
    {
        wp_enqueue_style(
            'rrze-ac-frontend',
            plugins_url('build/rrze-ac.css', plugin()->getBasename()),
            [],
            Config::get('version')
        );

        ob_start();
        wp_print_styles(['rrze-ac-frontend']);
        return ob_get_clean();
    }

    protected static function getContact($options)
    {
        $output = '';
        $contact = [];

        if (!$siteAdminName = $options['contact_admin_name']) {
            $blogId = get_current_blog_id();
            $admins = get_users([
                'role' => 'administrator',
                'blog_id' => $blogId
            ]);
            if (!empty($admins)) {
                foreach ($admins as $user) {
                    $contact[] = sprintf(
                        '<a href="mailto:%1$s">%2$s</a>',
                        Utils::encodeEmail($user->data->user_email),
                        $user->data->display_name
                    );
                }
            }
        } else {
            $siteAdminEmail = get_option('admin_email', '');
            $contact[] = sprintf(
                '<a href="mailto:%1$s">%2$s</a>',
                Utils::encodeEmail($siteAdminEmail),
                $siteAdminName
            );
        }

        if ($contact) {
            $output .= '<p>' . __('Contact:', 'rrze-ac') . '<br>';
            $output .= implode('<br>', $contact) . '</p>';
        }

        return $output;
    }
}
