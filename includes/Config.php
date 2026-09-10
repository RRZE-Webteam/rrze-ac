<?php

namespace RRZE\AccessControl;

defined('ABSPATH') || exit;

class Config
{
    private static array $config = [
        'version' => '3.3.0',
        'option_name' => 'rrze_ac',
        'enabled_option_name' => 'rrze_ac_enabled',
        'access_permission_meta_key' => '_access_permission',
        'protected_post_status' => 'protected',
        'post_types' => [
            'page',
            'attachment'
        ],
        'restricted_post_types' => [
            'page'
        ],
        'empty_permission_key' => '_none_',
        'protected_upload_dirname' => '_protected',
        'password_cookie_name_prefix' => 'rrze_ac_password_',
        'password_cookie_expiration' => 3600,
        'sso_plugin' => 'rrze-sso/rrze-sso.php',
        'sso_plugin_option_name' => 'rrze_sso',
        'settings_error_transient' => 'rrze-ac-settings-error-',
        'settings_error_transient_expiration' => 30,
        'notice_transient' => 'rrze-ac-notice-',
        'notice_transient_expiration' => 30,
        'rewrite_check_error_transient' => 'rrze_ac_rewrite_check_error',
        'rewrite_check_error_transient_expiration' => 300,
        'default_options' => [],
        'default_permission' => []
    ];

    public static function get($key = '')
    {
        self::loadDynamicDefaults();

        if (empty($key)) {
            return self::$config;
        }

        return self::$config[$key] ?? null;
    }

    private static function loadDynamicDefaults()
    {
        if (empty(self::$config['default_options'])) {
            self::$config['default_options'] = self::defaultOptions();
        }

        if (empty(self::$config['default_permission'])) {
            self::$config['default_permission'] = self::defaultPermission();
        }
    }

    private static function defaultOptions()
    {
        return [
            'permissions' => [
                'public' =>  [
                    'permission_key' => 'public',
                    'description'    => __('Publicly accessible', 'rrze-ac'),
                    'select'         => __('Publicly accessible', 'rrze-ac'),
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
                    'description'    => __('Login required', 'rrze-ac'),
                    'select'         => __('Login required', 'rrze-ac'),
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
            'permission_editor_role' => 'editor',
            'automatic_sso_authentication' => 1,
            'log_info_messages' => 0,
            'contact_admin_name' => '',
            'use_default_access_denied_texts' => 1,
            'user_isnt_logged_in_title' => __('Log in with the WordPress login', 'rrze-ac'),
            'user_isnt_logged_in_msg' => __('Access to this resource is only available to members of this website.', 'rrze-ac'),
            'user_isnt_logged_in_link_txt' => __('Login', 'rrze-ac'),
            'use_default_login_texts' => 1,
            'user_isnt_sso_logged_in_title' => __("Log in with your SSO Login", 'rrze-ac'),
            'user_isnt_sso_logged_in_msg' => __('Access to this resource is only possible for registered users.', 'rrze-ac'),
            'user_isnt_sso_logged_in_link_txt' => __('Login through Single Sign-On', 'rrze-ac'),
            'use_default_sso_texts' => 1,
            'access_denied_default_title' => __('Access Denied', 'rrze-ac'),
            'access_denied_default_msg' => __('You do not have sufficient permissions to access this resource. If you believe you should have access to this resource, please get in touch with the contact person of the website.', 'rrze-ac'),
            'access_denied_password_title' => __('Login via Access Password', 'rrze-ac'),
            'access_denied_password_msg' => __('If you have a password to access this resource, please enter it in the following field.', 'rrze-ac'),
            'access_denied_password_link_txt' => __('Send password', 'rrze-ac'),
            'use_default_password_texts' => 1
        ];
    }

    private static function defaultPermission()
    {
        return [
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
    }
}
