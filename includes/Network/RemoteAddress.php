<?php

namespace RRZE\AccessControl\Network;

defined('ABSPATH') || exit;

class RemoteAddress
{
    /**
     * List of trusted proxy IPs or CIDR ranges.
     * Only these proxies are allowed to set X-Forwarded-For.
     * 
     * @var array
     */
    protected $trustedProxies = [];

    /**
     * Constructor to set trusted proxies.
     * 
     * @param array $trustedProxies List of trusted proxy IPs or CIDR ranges.
     * @return void
     */
    public function __construct(array $trustedProxies = [])
    {
        $this->trustedProxies = $trustedProxies;
    }

    /**
     * Get the real client IP address.
     * - If the request comes from a trusted proxy, check X-Forwarded-For.
     * - Otherwise, fall back to REMOTE_ADDR.
     * 
     * @return string The real client IP address.
     */
    public function getIpAddress()
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

        // Only trust X-Forwarded-For if the remote address is a trusted proxy
        if ($this->ipInTrustedProxies($remoteAddr)) {
            $ipFromProxy = $this->getIpAddressFromProxy();
            if ($ipFromProxy) {
                return $ipFromProxy;
            }
        }

        // Default: use the direct remote address
        return $remoteAddr;
    }

    /**
     * Extract the original client IP from X-Forwarded-For header.
     * By convention, the left-most IP is the original client.
     * 
     * @return string|false The original client IP or false if not set.
     */
    protected function getIpAddressFromProxy()
    {
        if (empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return false;
        }

        $ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));

        // Return the first IP in the list (original client IP)
        return $ips[0];
    }

    /**
     * Check if an IP address belongs to any trusted proxy range.
     * 
     * @param string $ip The IP address to check.
     * @return boolean True if the IP is in a trusted proxy range, false otherwise.
     */
    protected function ipInTrustedProxies($ip)
    {
        foreach ($this->trustedProxies as $cidr) {
            if ($this->ipInRange($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if an IP is inside a CIDR range (IPv4 or IPv6).
     * 
     * @param string $ip The IP address to check.
     * @param string $cidr The CIDR range.
     * @return boolean True if the IP is in the CIDR range, false otherwise.
     */
    protected function ipInRange($ip, $cidr)
    {
        if (strpos($cidr, ':') !== false) {
            // IPv6 handling
            list($subnet, $mask) = explode('/', $cidr);
            $mask = intval($mask);
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            return substr($ipBin, 0, $mask / 8) === substr($subnetBin, 0, $mask / 8);
        } else {
            // IPv4 handling
            list($subnet, $mask) = explode('/', $cidr);
            $mask = 32 - intval($mask);
            return (ip2long($ip) >> $mask) === (ip2long($subnet) >> $mask);
        }
    }
}
