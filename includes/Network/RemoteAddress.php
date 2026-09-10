<?php

namespace RRZE\AccessControl\Network;

defined('ABSPATH') || exit;

/**
 * Resolve client addresses using an explicit infrastructure proxy allowlist.
 *
 * Kept equivalent in Settings, Private Site and AC for independent plugin use.
 */
class RemoteAddress
{
    protected $trustedProxies;

    /**
     * @param array|null $trustedProxies Explicit IPs/CIDRs, or null to use the filter.
     */
    public function __construct(?array $trustedProxies = null)
    {
        /**
         * Infrastructure-owned proxies, shared by Settings, Private Site and AC.
         * Never populate this from a visitor access allowlist.
         *
         * @param string[] $trustedProxies Trusted proxy IP addresses or CIDRs.
         */
        $trustedProxies = $trustedProxies ?? apply_filters('rrze_trusted_proxies', []);
        $this->trustedProxies = is_array($trustedProxies) ? $trustedProxies : [];
    }

    /**
     * Return the nearest untrusted hop, or an empty string if it cannot be resolved.
     */
    public function getIpAddress()
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!$this->isValidIp($remoteAddr)) {
            return '';
        }

        if (!$this->ipInTrustedProxies($remoteAddr)) {
            // A direct client cannot assert its own identity through headers.
            return $remoteAddr;
        }

        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!is_string($forwarded) || trim($forwarded) === '') {
            // A trusted proxy is a transport, not an authorized visitor.
            return '';
        }

        // Trusted proxies must append the address of their immediate peer or
        // overwrite an incoming header. Stop before any client-controlled prefix.
        foreach (array_reverse(explode(',', $forwarded)) as $hop) {
            $hop = trim($hop);
            if (!$this->isValidIp($hop)) {
                return '';
            }
            if (!$this->ipInTrustedProxies($hop)) {
                return $hop;
            }
        }

        // All hops are infrastructure; no visitor address was established.
        return '';
    }

    protected function isValidIp($ip)
    {
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    protected function ipInTrustedProxies($ip)
    {
        foreach ($this->trustedProxies as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate IPs and CIDRs before using the existing bit-accurate range matcher.
     * Single IPv4/IPv6 addresses represent /32 and /128 respectively.
     */
    protected function ipInRange($ip, $range)
    {
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
