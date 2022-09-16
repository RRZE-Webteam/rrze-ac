<?php

namespace RRZE\AccessControl;

defined('ABSPATH') || exit;

class Options
{
    protected $option_name = 'rrze_ac';
    protected $version_option_name = 'rrze_ac_version';
    protected $enabled_option_name = 'rrze_ac_enabled';

    public function __construct()
    {
    }

    /*
     * Standard Einstellungen werden definiert
     * @return array
     */
    private function default_options()
    {
        $options = array(
            'permissions' => array(
                'logged-in' =>  array(
                    'permission_key' => 'logged-in',
                    'description'    => __("Logged-in user", 'rrze-ac'),
                    'select'         => __("Logged-in user", 'rrze-ac'),
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
                ),
                'all' =>  array(
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
                )
            ),
            'default_permission' => 'logged-in'
        );

        return $options;
    }

    /*
     * Standard Berechtigung wird definiert.
     * @return array
     */
    private function default_permission()
    {
        $permission = array(
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
        );

        return $permission;
    }

    /*
     * Gibt die Einstellungen zurück.
     * @return object
     */
    public function get_options()
    {
        $defaults = $this->default_options();
        $default_permission = $this->default_permission();
        $options = (array) get_option($this->option_name);

        $options = wp_parse_args($options, $defaults);
        $options['permissions'] = wp_parse_args($options['permissions'], $defaults['permissions']);
        foreach ($options['permissions'] as $key => $permission) {
            $options['permissions'][$key] = $this->combine_atts($default_permission, $permission);
        }

        return $options;
    }

    private function combine_atts($default_atts, $atts)
    {
        $atts = (array)$atts;
        $combine_atts = array();
        foreach ($default_atts as $key => $default) {
            if (array_key_exists($key, $atts)) {
                $combine_atts[$key] = $atts[$key];
            } else {
                $combine_atts[$key] = $default;
            }
        }

        return $combine_atts;
    }

    public function get_option_name()
    {
        return $this->option_name;
    }

    public function get_enabled_option_name()
    {
        return $this->enabled_option_name;
    }
}
