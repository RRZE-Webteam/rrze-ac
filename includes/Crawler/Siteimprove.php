<?php

namespace RRZE\AccessControl\Crawler;

defined('ABSPATH') || exit;

use RRZE\AccessControl\Network\IPUtils;

class Siteimprove
{
    public static function getIpAddresses(): array
    {
        $ipRange = [];
        $ipAddress = (array) explode(PHP_EOL, self::ipAddresses());
        $ipAddress = array_filter(array_map('trim', $ipAddress));
        $ipAddress = array_unique(array_values($ipAddress));
        if (!empty($ipAddress)) {
            $ipRange = self::getIpRange($ipAddress);
        }
        return $ipRange;
    }

    public static function getIpRange(array $ipAddress): array
    {
        $ipRange = [];
        if (!empty($ipAddress)) {
            foreach ($ipAddress as $value) {
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

    protected static function ipAddresses()
    {
        return <<<EOF
        93.160.60.22
        80.62.246.50
        52.48.230.149
        52.57.25.76
        88.150.142.65
        35.157.236.87
        35.157.42.7
        35.156.240.123
        3.10.81.130
        52.55.30.145
        52.20.183.91
        52.60.34.56
        185.229.144.22
        185.229.144.10
        173.45.86.10
        173.45.86.74
        52.7.141.1
        52.4.143.42
        52.64.209.168
        52.47.166.84
        52.199.41.160
        52.62.119.24
        52.62.147.245
        54.233.93.223
        52.29.157.54
        52.29.165.98
        35.154.71.109
        52.78.201.227
        52.79.109.89
        52.49.22.75
        52.17.221.188
        52.192.200.212
        52.77.4.220
        52.76.24.207
        52.71.233.116
        52.70.218.116
        52.89.228.115
        52.33.171.213
        52.8.142.166
        52.8.3.121
        52.29.157.54
        52.29.165.98
        80.62.246.46
        93.160.60.51
        149.72.248.150
        168.245.125.127
        18.223.89.15
        3.209.121.100
        54.193.30.204
        3.123.96.88
        3.10.81.130
        52.63.157.211
        3.113.133.88
        18.230.72.87
        13.48.47.239
        35.182.100.126
        18.162.92.10
        35.204.24.17
        EOF;
    }
}
