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
                ],
                'all' =>  [
                    'permission_key' => 'all',
                    'description'    => __('All', 'rrze-ac'),
                    'select'         => __('All', 'rrze-ac'),
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
                ]
            ],
            'default_permission' => 'logged-in'
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
        $options['permissions'] = wp_parse_args($options['permissions'], $defaults['permissions']);
        foreach ($options['permissions'] as $key => $permission) {
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
