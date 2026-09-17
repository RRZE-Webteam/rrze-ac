<?php

namespace RRZE\AccessControl;

defined('ABSPATH') || exit;

class Options
{
    /*
     * Get options.
     * @return array
     */
    public static function getOptions()
    {
        $defaults = Config::get('default_options');
        $defaultPermission = Config::get('default_permission');
        $options = (array) get_option(self::getOptionName());

        $options = wp_parse_args($options, $defaults);
        $options = array_intersect_key($options, $defaults);

        $options['permissions'] = wp_parse_args($options['permissions'], $defaults['permissions']);
        foreach ($options['permissions'] as $key => $permission) {
            if ($key === 'all') {
                unset($options['permissions'][$key]);
                continue;
            }

            // Keep existing Siteimprove permissions working until they are saved again.
            if (!empty($permission['siteimprove']) && empty($permission['crawlers'])) {
                $permission['crawlers'] = ['siteimprove'];
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
        return Config::get('option_name');
    }

    public static function getEnabledOptionName()
    {
        return Config::get('enabled_option_name');
    }
}
