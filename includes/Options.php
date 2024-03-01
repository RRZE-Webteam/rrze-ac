<?php

namespace RRZE\AccessControl;

defined('ABSPATH') || exit;

class Options
{
    private static $optionName = 'rrze_ac';

    private static $enabledOptionName = 'rrze_ac_enabled';

    /*
     * Default options.
     * @return array
     */
    private static function defaultOptions()
    {
        $options = [
            'permissions' => [
                'public' =>  [
                    'permission_key' => 'public',
                    'description'    => __('Public', 'rrze-ac'),
                    'select'         => __('Public', 'rrze-ac'),
                    'logged_in'      => 0,
                    'sso_logged_in'  => 0,
                    'affiliation'    => '',
                    'entitlement'    => '',
                    'domain'         => '',
                    'ip_address'     => '',
                    'password'       => '',
                    'siteimprove'    => 0,
                    'core'           => 1,
                    'active'         => 1
                ],
                'logged-in' => [
                    'permission_key' => 'logged-in',
                    'description'    => __('Logged-in user', 'rrze-ac'),
                    'select'         => __('Logged-in user', 'rrze-ac'),
                    'logged_in'      => 1,
                    'sso_logged_in'  => 0,
                    'affiliation'    => '',
                    'entitlement'    => '',
                    'domain'         => '',
                    'ip_address'     => '',
                    'password'       => '',
                    'siteimprove'    => 0,
                    'core'           => 1,
                    'active'         => 1
                ]
            ],
            'default_permission' => 'logged-in',
            'automatic_sso_authentication' => 1,
            'contact_admin_name' => '',
            // user_isnt_logged_in
            'user_isnt_logged_in_title' => __('Log in with your IdM ID', 'rrze-ac'),
            'user_isnt_logged_in_msg' => __('Access to this resource is only available to members of this website.', 'rrze-ac'),
            'user_isnt_logged_in_link_txt' => __('Login', 'rrze-ac'),
            // user_isnt_sso_logged_in
            'user_isnt_sso_logged_in_title' => __("Log in with your IdM ID", 'rrze-ac'),
            'user_isnt_sso_logged_in_msg' => __('Access to this resource is only possible for registered users.', 'rrze-ac'),
            'user_isnt_sso_logged_in_link_txt' => __('Login through Single Sign-On', 'rrze-ac'),
            // access_denied_default
            'access_denied_default_title' => __('Access Denied', 'rrze-ac'),
            'access_denied_default_msg' => __('You do not have sufficient permissions to access this resource. If you believe you should have access to this resource, please get in touch with the contact person of the website.', 'rrze-ac'),
            // access_denied_password
            'access_denied_password_msg' => __('If you have a password to access this resource, please enter it in the following field.', 'rrze-ac')
        ];

        return $options;
    }

    /*
     * Default permission.
     * @return array
     */
    private static function defaultPermission()
    {
        $permission = [
            'permission_key' => '',
            'description'    => '',
            'select'         => '',
            'logged_in'      => 0,
            'sso_logged_in'  => 0,
            'affiliation'    => '',
            'entitlement'    => '',
            'domain'         => '',
            'ip_address'     => '',
            'password'       => '',
            'siteimprove'    => 0,
            'core'           => 0,
            'active'         => 0
        ];

        return $permission;
    }

    /*
     * Get options.
     * @return array
     */
    public static function getOptions()
    {
        $defaults = self::defaultOptions();
        $defaultPermission = self::defaultPermission();
        $options = (array) get_option(self::$optionName);

        $options = wp_parse_args($options, $defaults);
        $options = array_intersect_key($options, $defaults);

        $options['permissions'] = wp_parse_args($options['permissions'], $defaults['permissions']);
        foreach ($options['permissions'] as $key => $permission) {
            if ($key === 'all') {
                unset($options['permissions'][$key]);
                continue;
            }
            $options['permissions'][$key] = self::combineAtts($defaultPermission, $permission);
        }

        return $options;
    }

    private static function combineAtts($defaultAtts, $atts)
    {
        $atts = (array) $atts;
        $combineAtts = [];
        foreach ($defaultAtts as $key => $default) {
            if (array_key_exists($key, $atts)) {
                $combineAtts[$key] = $atts[$key];
            } else {
                $combineAtts[$key] = $default;
            }
        }

        return $combineAtts;
    }

    public static function getOptionName()
    {
        return self::$optionName;
    }

    public static function getEnabledOptionName()
    {
        return self::$enabledOptionName;
    }
}
