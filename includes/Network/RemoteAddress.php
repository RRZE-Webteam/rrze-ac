<?php

namespace RRZE\AccessControl\Network;

defined('ABSPATH') || exit;

/**
 * Resolve a visitor IP address from the direct peer and trusted proxy chain.
 */
class RemoteAddress {
    /**
     * @var array
     */
    protected $trustedProxies;

    /**
     * @param array|null $trustedProxies Explicit IPs/CIDRs, or null to use the shared filter.
     */
    public function __construct(?array $trustedProxies = null) {
        /**
         * Infrastructure-owned proxies. Never populate this from a visitor
         * access allowlist.
         *
         * @param string[] $trustedProxies Trusted proxy IP addresses or CIDRs.
         */
        $trustedProxies = $trustedProxies ?? apply_filters('rrze_trusted_proxies', []);
        $this->trustedProxies = is_array($trustedProxies) ? $trustedProxies : [];
    }

    /**
     * Return the nearest untrusted hop, or an empty string if it cannot be resolved.
     */
    public function getIpAddress() {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!$this->isValidIp($remoteAddr)) {
            return '';
        }

        if (!$this->ipInTrustedProxies($remoteAddr)) {
            return $remoteAddr;
        }

        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!is_string($forwarded) || trim($forwarded) === '') {
            return '';
        }

        foreach (array_reverse(explode(',', $forwarded)) as $hop) {
            $hop = trim($hop);
            if (!$this->isValidIp($hop)) {
                return '';
            }
            if (!$this->ipInTrustedProxies($hop)) {
                return $hop;
            }
        }

        return '';
    }

    protected function isValidIp($ip) {
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    protected function ipInTrustedProxies($ip) {
        foreach ($this->trustedProxies as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Single IPv4/IPv6 addresses represent /32 and /128 respectively.
     */
    protected function ipInRange($ip, $range) {
        if (!$this->isValidIp($ip) || !is_string($range)) {
            return false;
        }

        $parts = explode('/', trim($range));
        $subnet = $parts[0];
        if (count($parts) > 2 || !$this->isValidIp($subnet)) {
            return false;
        }

        $maxBits = str_contains($subnet, ':') ? 128 : 32;
        $bits = $parts[1] ?? (string) $maxBits;
        if (!ctype_digit($bits) || (int) $bits > $maxBits) {
            return false;
        }

        return IP::fromStringIP($ip)->isInRange($subnet . '/' . (int) $bits);
    }
}
