<?php

namespace RRZE\AccessControl\Crawler;

defined('ABSPATH') || exit;

use RRZE\AccessControl\Network\IPUtils;

class Siteimprove
{
    public static function getIpAddresses(): array
    {
        $ipRange = [];
        $ipAddresses = apply_filters('rrze_ac_siteimprove_crawler_ip_addresses', '');
        if (empty($ipAddresses) || !is_array($ipAddresses)) {
            $ipAddresses = [];
        }
        $ipAddresses = array_filter(array_map('trim', $ipAddresses));
        $ipAddresses = array_unique(array_values($ipAddresses));
        if (!empty($ipAddresses)) {
            $ipRange = self::getIpRange($ipAddresses);
        }
        return $ipRange;
    }

    protected static function getIpRange(array $ipAddresses): array
    {
        $ipRange = [];
        if (!empty($ipAddresses)) {
            foreach ($ipAddresses as $value) {
                $value = trim($value);
                if (empty($value)) {
                    continue;
                }
                $sanitized_value = IPUtils::sanitizeIpRange($value);
                if (!is_null($sanitized_value)) {
                    $ipRange[] = $sanitized_value;
                }
            }
        }
        return $ipRange;
    }
}
