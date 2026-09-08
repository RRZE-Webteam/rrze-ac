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
        $loginMethods = '';

        $postType = get_post_type($postId);

        if ($postType == 'attachment' && !wp_attachment_is_image($postId)) {
            $permalink = wp_get_attachment_url($postId);
        } else {
            $permalink = get_permalink($postId);
        }

        $login_url = wp_login_url($permalink);

        if (permissions()->getPermissionStatus(permissions()->user_isnt_logged_in)) {
            $loginMethods .= '<div class="wordpress-login">';
            $loginMethods .= '<svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M480-120v-80h280v-560H480v-80h280q33 0 56.5 23.5T840-760v560q0 33-23.5 56.5T760-120H480Zm-80-160-55-58 102-102H120v-80h327L345-622l55-58 200 200-200 200Z"/></svg>';
            $loginMethods .= '<br/><span class="label label--recommended">' . __('Recommended', 'rrze-private-site') . '</span>';
            $loginMethods .= '<h3>' . esc_html($options['user_isnt_logged_in_title']) . '</h3>';
            $loginMethods .= wpautop(esc_html($options['user_isnt_logged_in_msg']));
            $loginMethods .= wpautop('<a class="wp-link" href="' . esc_url($login_url) . '">' . esc_html($options['user_isnt_logged_in_link_txt']) . '</a>');
            $loginMethods .= '</div>';
        }

        if (permissions()->getPermissionStatus(permissions()->user_isnt_sso_logged_in) && permissions()->simplesamlAuth) {
            $login_url = permissions()->simplesamlAuth->getLoginURL();
            $loginMethods .= '<div class="sso-login">';
            $loginMethods .= '<svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M480-120v-80h280v-560H480v-80h280q33 0 56.5 23.5T840-760v560q0 33-23.5 56.5T760-120H480Zm-80-160-55-58 102-102H120v-80h327L345-622l55-58 200 200-200 200Z"/></svg>';
            $loginMethods .= '<br/><span class="label label--recommended">' . __('Recommended', 'rrze-private-site') . '</span>';
            $loginMethods .= '<h3>' . esc_html($options['user_isnt_sso_logged_in_title']) . '</h3>';
            $loginMethods .= wpautop(esc_html($options['user_isnt_sso_logged_in_msg']));
            $loginMethods .= wpautop('<a class="sso-link" href="' . esc_url($login_url) . '">' . esc_html($options['user_isnt_sso_logged_in_link_txt']) . '</a>');
            $loginMethods .= '</div>';
        }

        $message .= '<h3>' . esc_html($options['access_denied_default_title']) . '</h3>';

        if (permissions()->getPermissionStatus(permissions()->wrong_password)) {
            $loginMethods .= '<div class="password">';
            $loginMethods .= '<svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M80-200v-80h800v80H80Zm46-242-52-30 34-60H40v-60h68l-34-58 52-30 34 58 34-58 52 30-34 58h68v60h-68l34 60-52 30-34-60-34 60Zm320 0-52-30 34-60h-68v-60h68l-34-58 52-30 34 58 34-58 52 30-34 58h68v60h-68l34 60-52 30-34-60-34 60Zm320 0-52-30 34-60h-68v-60h68l-34-58 52-30 34 58 34-58 52 30-34 58h68v60h-68l34 60-52 30-34-60-34 60Z"/></svg>';
            $loginMethods .= '<h3>' . __('Login via Access Password', 'rrze-ac') . '</h3>';
            $loginMethods .= wpautop(esc_html($options['access_denied_password_msg'])) . PHP_EOL;

            $fieldName = 'rrze_ac_password_' . $postId;
            $loginMethods .= '<form method="post">' . PHP_EOL;
            $loginMethods .= wp_nonce_field('rrze_ac_submit_password_wpnonce', '_wpnonce', true, false) . PHP_EOL;
            $loginMethods .= '<input type="password" name="' . esc_attr($fieldName) . '" value="" style="padding: 0 8px; min-height: 23px;">' . PHP_EOL;
            $loginMethods .= '<input type="submit" name="rrze_ac_submit_password" id="submit" class="button button-primary" value="' . esc_attr__('Send password', 'rrze-ac') . '"></p>' . PHP_EOL;
            $loginMethods .= '</form>' . PHP_EOL;
            $loginMethods .= '</div>';
        }

        if ($loginMethods) {
            $message .= '<div class="login-methods">';
            $message .= $loginMethods;
            $message .= '<div>';
            $message .= wpautop(esc_html($options['access_denied_default_msg']));
            $message .= self::getContact($options);
            $message .= '</div>';
            $message .= '</div>';
            $message .= '<style>' . self::getDeniedMessageCss() . '</style>';
            return $message;
        }

        $message .= wpautop(esc_html($options['access_denied_default_msg']));
        $message .= wpautop('<a class="wp-link" href="' . esc_url($login_url) . '">' . esc_html($options['user_isnt_logged_in_link_txt']) . '</a>');
        $message .= self::getContact($options);
        $message .= '<style>' . self::getDeniedMessageCss() . '</style>';

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

    /**
     * Returns the Inline-CSS required for the wp_die message
     */
    protected static function getDeniedMessageCss(): string
    {
        $colors = [
            '#4a148c',
            '#880e4f',
            '#b71c1c',
            '#311b92',
            '#1a237e',
            '#0d47a1',
            '#01579b',
            '#006064',
            '#004d40',
            '#1b5e20',
            '#33691e'
        ];

        $patterns = [
            "data:image/svg+xml;utf8," .
            '<svg xmlns="http://www.w3.org/2000/svg" width="140" height="140" viewBox="0 0 140 140">' .
            '<g opacity="0.82">' .
            '<path d="M0 60 L60 0 M-20 100 L80 0 M20 140 L120 40" fill="none" stroke="rgba(255,255,255,0.9)" stroke-width="1"/>' .
            '<path d="M0 20 L20 0 M40 60 L100 0 M80 100 L140 40" fill="none" stroke="rgba(255,255,255,0.5)" stroke-width="0.7"/>' .
            '</g>' .
            '</svg>',

            "data:image/svg+xml;utf8," .
            '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160" viewBox="0 0 160 160">' .
            '<g opacity="0.80">' .
            '<path d="M0 80 L80 0 M-40 120 L80 0 M40 160 L160 40" fill="none" stroke="rgba(255,255,255,0.9)" stroke-width="1" stroke-dasharray="6 10"/>' .
            '<path d="M0 40 L40 0 M40 120 L120 40 M80 160 L160 80" fill="none" stroke="rgba(255,255,255,0.5)" stroke-width="0.7" stroke-dasharray="4 12"/>' .
            '</g>' .
            '</svg>',

            "data:image/svg+xml;utf8," .
            '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 120 120">' .
            '<g opacity="0.86">' .
            '<path d="M-10 40 L40 -10 M0 80 L80 0 M40 120 L120 40" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="0.9"/>' .
            '<path d="M-10 100 L100 -10 M0 140 L140 0" fill="none" stroke="rgba(255,255,255,0.5)" stroke-width="0.6" stroke-dasharray="2 8"/>' .
            '</g>' .
            '</svg>',

            "data:image/svg+xml;utf8," .
            '<svg xmlns="http://www.w3.org/2000/svg" width="140" height="140" viewBox="0 0 140 140">' .
            '<g opacity="0.7">' .
            '<circle cx="20" cy="20" r="1.4" fill="rgba(255,255,255,0.85)"/>' .
            '<circle cx="60" cy="20" r="1.2" fill="rgba(255,255,255,0.65)"/>' .
            '<circle cx="100" cy="20" r="1.4" fill="rgba(255,255,255,0.85)"/>' .

            '<circle cx="20" cy="60" r="1.2" fill="rgba(255,255,255,0.65)"/>' .
            '<circle cx="60" cy="60" r="1.4" fill="rgba(255,255,255,0.9)"/>' .
            '<circle cx="100" cy="60" r="1.2" fill="rgba(255,255,255,0.65)"/>' .

            '<circle cx="20" cy="100" r="1.4" fill="rgba(255,255,255,0.85)"/>' .
            '<circle cx="60" cy="100" r="1.2" fill="rgba(255,255,255,0.65)"/>' .
            '<circle cx="100" cy="100" r="1.4" fill="rgba(255,255,255,0.85)"/>' .
            '</g>' .
            '</svg>',

            "data:image/svg+xml;utf8," .
            '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160" viewBox="0 0 160 160">' .
            '<g opacity="0.8" stroke="rgba(255,255,255,0.9)" stroke-width="1">' .

            '<line x1="20" y1="12" x2="20" y2="28" />' .
            '<line x1="12" y1="20" x2="28" y2="20" />' .

            '<line x1="80" y1="12" x2="80" y2="28" />' .
            '<line x1="72" y1="20" x2="88" y2="20" />' .

            '<line x1="50" y1="70" x2="50" y2="90" />' .
            '<line x1="40" y1="80" x2="60" y2="80" />' .

            '<line x1="20" y1="132" x2="20" y2="148" />' .
            '<line x1="12" y1="140" x2="28" y2="140" />' .

            '<line x1="120" y1="112" x2="120" y2="128" />' .
            '<line x1="112" y1="120" x2="128" y2="120" />' .
            '</g>' .
            '</svg>',

            "data:image/svg+xml;utf8," .
            '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 120 120">' .
            '<g opacity="0.75" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="0.9">' .
            '<rect x="10" y="10" width="30" height="18" rx="4" ry="4"/>' .
            '<rect x="50" y="10" width="30" height="18" rx="6" ry="6" stroke="rgba(255,255,255,0.6)"/>' .
            '<rect x="90" y="10" width="20" height="18" rx="3" ry="3"/>' .

            '<rect x="10" y="45" width="24" height="18" rx="5" ry="5" stroke="rgba(255,255,255,0.6)"/>' .
            '<rect x="44" y="45" width="36" height="18" rx="4" ry="4"/>' .
            '<rect x="88" y="45" width="22" height="18" rx="5" ry="5" stroke="rgba(255,255,255,0.6)"/>' .

            '<rect x="18" y="80" width="26" height="18" rx="4" ry="4"/>' .
            '<rect x="54" y="80" width="28" height="18" rx="6" ry="6" stroke="rgba(255,255,255,0.6)"/>' .
            '<rect x="90" y="80" width="18" height="18" rx="4" ry="4"/>' .
            '</g>' .
            '</svg>',
        ];

        $pattern = $patterns[ array_rand( $patterns ) ];
        $color = $colors[ array_rand( $colors ) ];

        return <<<CSS
        .label {
            display: inline-flex;
            align-items: center;
            padding: 0.15rem 0.5rem;
            font-size: 0.75rem;
            line-height: 1.4;
            border-radius: 999px;
            border: 1px solid transparent;
            font-weight: 500;
            white-space: nowrap;
            vertical-align: middle;
            box-sizing: border-box;
        }
        
        .label--recommended {
            color: #1a7f37;
            background-color: #dafbe1;
            border-color: #1a7f37;
        }
        
        html {
          background: $color;
        }
        
        body#error-page{
          max-width: 1100px;
          border-radius: 5px;
        }
        
        body#error-page h2{
          font-size: 2rem;
        }
        
        h1,h2,h3 {
          color: #000;
        }
        
        p {
          max-width: 65ch;
        }
        
        input {
          border-radius: 0;
          padding: 1ch !important;
          border: 1px solid lightgrey;
          border-radius: 5px 0 0 5px;
        }
        
        input[type="submit"] {
          height: fit-content;
          border-radius: 0;
          margin-left: -5px;
          background: none;
        }
        
        div.login-methods svg {
          height: 50px;
          width: 50px;
        }
        
        div.login-methods{
          display: grid;
          grid-template-columns: repeat(12, 1fr);
          grid-gap: .5rem;
        }
        
        div.password, div.wordpress-login, div.sso-login{
          grid-column: span 4;
          background: #f7f7f7;
          border-radius: 10px;
          padding: 1rem;
        }
        
        div.login-methods > :nth-child(3){    
          background: transparent;
          border-left: 1px solid #000;
          border-radius: 0;
          padding-left: 2rem;
          grid-column: span 6;
        }
        
        div.login-methods > :first-child{
          grid-column: span 12;
        }
        
        div.login-methods > :nth-child(2){
          grid-column: span 6;
          background: transparent;
        }
        
        div.login-methods > :first-child svg path {
          fill: $color;
        }
        
        div.login-methods > :nth-child(2) svg path,
        div.login-methods > :nth-child(3) svg path {
          fill: lightgrey;
        }

        a.sso-link, a.wp-link {
            text-decoration: none;
            padding: 1ch;
            border-radius: 5px;
            background: $color;
            color: #fff;
            margin-top: 1rem;
            padding-inline: 1rem;
            display: inline-block;
            border: 1px solid transparent;
        }

        a.sso-link:hover,
        a.sso-link:focus,
        a.wp-link:hover,
        a.wp-link:focus{
            color: $color;
            background: transparent;
            border: 1px solid $color;
            transition: .2s ease-in all;
        }
        
        input[type="submit"]{
            line-height: 1.5;
            box-shadow: none;
            padding-bottom: 12px !important;
        }
        
        a.wp-link{
          border: 1px solid $color;
          background: transparent;
          color: $color;
        }

        a.wp-link:hover,
        a.wp-link:focus{
          background: $color;
          color: #fff;
          border: 1px solid transparent;
        }
        
        html::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            opacity: 0.25;
            background-repeat: repeat;
            background-size: 140px 140px;
            mix-blend-mode: screen;
            background-image: url('{$pattern}');
        }
        CSS;
    }
}
