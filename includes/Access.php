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
        $allowed = apply_filters('rrze_ac_access_allowed', $allowed);

        if (!$allowed) {
            do_action(
                'rrze.log.info',
                [
                    'plugin' => 'rrze-ac',
                    'method' => __METHOD__,
                    'postID' => $postId,
                    'permission' => $permission,
                    'status' => 'Access denied'
                ]
            );
        }

        return $allowed;
    }

    public static function permissionMessage($postId, $options)
    {
        $message = '';

        $postType = get_post_type($postId);

        if ($postType == 'attachment' && !wp_attachment_is_image($postId)) {
            $permalink = wp_get_attachment_url($postId);
        } else {
            $permalink = get_permalink($postId);
        }

        $login_url = wp_login_url($permalink);

        if (permissions()->getPermissionStatus(permissions()->user_isnt_logged_in)) {
            $message .= '<h3>' . esc_html($options['user_isnt_logged_in_title']) . '</h3>';
            $message .= wpautop(esc_html($options['user_isnt_logged_in_msg']));
            $message .= wpautop('<a href="' . $login_url . '">' . esc_html($options['user_isnt_logged_in_link_txt']) . '</a>');
            $message .= self::getContact($options);
            return $message;
        }

        if (permissions()->getPermissionStatus(permissions()->user_isnt_sso_logged_in) && permissions()->simplesamlAuth) {
            $login_url = permissions()->simplesamlAuth->getLoginURL();
            $message .= '<h3>' . esc_html($options['user_isnt_sso_logged_in_title']) . '</h3>';
            $message .= wpautop(esc_html($options['user_isnt_sso_logged_in_msg']));
            $message .= wpautop('<a href="' . $login_url . '">' . esc_html($options['user_isnt_sso_logged_in_link_txt']) . '</a>');
            $message .= self::getContact($options);
            return $message;
        }

        $message .= '<h3>' . esc_html($options['access_denied_default_title']) . '</h3>';

        if (permissions()->getPermissionStatus(permissions()->wrong_password)) {
            $message .= wpautop(esc_html($options['access_denied_password_msg'])) . PHP_EOL;

            $fieldName = 'rrze_ac_password_' . $postId;
            $message .= '<form method="post">' . PHP_EOL;
            $message .= wp_nonce_field('rrze_ac_submit_password_wpnonce', '_wpnonce', true, false) . PHP_EOL;
            $message .= '<input type="password" name="' . $fieldName . '" value="" style="padding: 0 8px; min-height: 23px;">' . PHP_EOL;
            $message .= '<input type="submit" name="rrze_ac_submit_password" id="submit" class="button button-primary" value="' . __('Send password', 'rrze-ac') . '"></p>' . PHP_EOL;
            $message .= '</form>' . PHP_EOL;
        }

        $message .= wpautop(esc_html($options['access_denied_default_msg']));
        $message .= wpautop('<a href="' . $login_url . '">' . esc_html($options['user_isnt_logged_in_link_txt']) . '</a>');
        $message .= self::getContact($options);

        return $message;
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
