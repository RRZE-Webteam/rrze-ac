<?php

// https://github.com/piwik/component-network

/**
 * IP address.
 *
 * This class is immutable, i.e. once created it can't be changed. Methods that modify it
 * will always return a new instance.
 */
abstract class IP {

    /**
     * Binary representation of the IP.
     *
     * @var string
     */
    protected $ip;

    /**
     * @see fromBinaryIP
     * @see fromStringIP
     *
     * @param string $ip Binary representation of the IP.
     */
    protected function __construct($ip) {
        $this->ip = $ip;
    }

    /**
     * Factory method to create an IP instance from an IP in binary format.
     *
     * @see fromStringIP
     *
     * @param string $ip IP address in a binary format.
     * @return IP
     */
    public static function fromBinaryIP($ip) {
        if ($ip === NULL || $ip === '') {
            return new IPv4("\x00\x00\x00\x00");
        }

        if (self::isIPv4($ip)) {
            return new IPv4($ip);
        }

        return new IPv6($ip);
    }

    /**
     * Factory method to create an IP instance from an IP represented as string.
     *
     * @see fromBinaryIP
     *
     * @param string $ip IP address in a string format (X.X.X.X).
     * @return IP
     */
    public static function fromStringIP($ip) {
        return self::fromBinaryIP(IPUtils::stringToBinaryIP($ip));
    }

    /**
     * Returns the IP address in a binary format.
     *
     * @return string
     */
    public function toBinary() {
        return $this->ip;
    }

    /**
     * Returns the IP address in a string format (X.X.X.X).
     *
     * @return string
     */
    public function toString() {
        return IPUtils::binaryToStringIP($this->ip);
    }

    /**
     * @return string
     */
    public function __toString() {
        return $this->toString();
    }

    /**
     * Tries to return the hostname associated to the IP.
     *
     * @return string|NULL The hostname or NULL if the hostname can't be resolved.
     */
    public function getHostname() {
        $stringIp = $this->toString();

        $host = strtolower(@gethostbyaddr($stringIp));

        if ($host === '' || $host === $stringIp) {
            return NULL;
        }

        return $host;
    }

    /**
     * Determines if the IP address is in a specified IP address range.
     *
     * An IPv4-mapped address should be range checked with an IPv4-mapped address range.
     *
     * @param array|string $ipRange IP address range (string or array containing min and max IP addresses)
     * @return bool
     */
    public function isInRange($ipRange) {
        $ipLen = strlen($this->ip);
        if (empty($this->ip) || empty($ipRange) || ($ipLen != 4 && $ipLen != 16)) {
            return FALSE;
        }

        if (is_array($ipRange)) {
            // already split into low/high IP addresses
            $ipRange[0] = IPUtils::stringToBinaryIP($ipRange[0]);
            $ipRange[1] = IPUtils::stringToBinaryIP($ipRange[1]);
        } else {
            // expect CIDR format but handle some variations
            $ipRange = IPUtils::getIPRangeBounds($ipRange);
        }
        if ($ipRange === NULL) {
            return FALSE;
        }

        $low = $ipRange[0];
        $high = $ipRange[1];
        if (strlen($low) != $ipLen) {
            return FALSE;
        }

        // binary-safe string comparison
        if ($this->ip >= $low && $this->ip <= $high) {
            return TRUE;
        }

        return FALSE;
    }

    /**
     * Determines if the IP address is in a specified IP address range.
     *
     * An IPv4-mapped address should be range checked with IPv4-mapped address ranges.
     *
     * @param array $ipRanges List of IP address ranges (strings or arrays containing min and max IP addresses).
     * @return bool True if in any of the specified IP address ranges; FALSE otherwise.
     */
    public function isInRanges(array $ipRanges) {
        $ipLen = strlen($this->ip);
        if (empty($this->ip) || empty($ipRanges) || ($ipLen != 4 && $ipLen != 16)) {
            return FALSE;
        }

        foreach ($ipRanges as $ipRange) {
            if ($this->isInRange($ipRange)) {
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * Returns the IP address as an IPv4 string when possible.
     *
     * Some IPv6 can be transformed to IPv4 addresses, for example
     * IPv4-mapped IPv6 addresses: `::ffff:192.168.0.1` will return `192.168.0.1`.
     *
     * @return string|NULL IPv4 string address e.g. `'192.0.2.128'` or NULL if this is not an IPv4 address.
     */
    public abstract function toIPv4String();

    /**
     * Anonymize X bytes of the IP address by setting them to a NULL byte.
     *
     * This method returns a new IP instance, it does not modify the current object.
     *
     * @param int $byteCount Number of bytes to set to "\0".
     *
     * @return IP Returns a new modified instance.
     */
    public abstract function anonymize($byteCount);

    /**
     * Returns TRUE if this is an IPv4, IPv4-compat, or IPv4-mapped address, FALSE otherwise.
     *
     * @param string $binaryIp
     * @return bool
     */
    private static function isIPv4($binaryIp) {
        // in case mbstring overloads strlen function
        $strlen = function_exists('mb_orig_strlen') ? 'mb_orig_strlen' : 'strlen';

        return $strlen($binaryIp) == 4;
    }

}

/**
 * IP v4 address.
 *
 * This class is immutable, i.e. once created it can't be changed. Methods that modify it
 * will always return a new instance.
 */
class IPv4 extends IP {

    /**
     * {@inheritdoc}
     */
    public function toIPv4String() {
        return $this->toString();
    }

    /**
     * {@inheritdoc}
     */
    public function anonymize($byteCount) {
        $newBinaryIp = $this->ip;

        $i = strlen($newBinaryIp);
        if ($byteCount > $i) {
            $byteCount = $i;
        }

        while ($byteCount-- > 0) {
            $newBinaryIp[--$i] = chr(0);
        }

        return self::fromBinaryIP($newBinaryIp);
    }

}

/**
 * IP v6 address.
 *
 * This class is immutable, i.e. once created it can't be changed. Methods that modify it
 * will always return a new instance.
 */
class IPv6 extends IP {

    const MAPPED_IPv4_START = '::ffff:';

    /**
     * {@inheritdoc}
     */
    public function anonymize($byteCount) {
        $newBinaryIp = $this->ip;

        if ($this->isMappedIPv4()) {
            $i = strlen($newBinaryIp);
            if ($byteCount > $i) {
                $byteCount = $i;
            }

            while ($byteCount-- > 0) {
                $newBinaryIp[--$i] = chr(0);
            }

            return self::fromBinaryIP($newBinaryIp);
        }

        $masks = array(
            'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff',
            'ffff:ffff:ffff:ffff::',
            'ffff:ffff:ffff:0000::',
            'ffff:ff00:0000:0000::'
        );

        $newBinaryIp = $newBinaryIp & pack('a16', inet_pton($masks[$byteCount]));

        return self::fromBinaryIP($newBinaryIp);
    }

    /**
     * {@inheritdoc}
     */
    public function toIPv4String() {
        $str = $this->toString();

        if ($this->isMappedIPv4()) {
            return substr($str, strlen(self::MAPPED_IPv4_START));
        }

        return NULL;
    }

    /**
     * Returns TRUE if this is a IPv4 mapped address, FALSE otherwise.
     *
     * @return bool
     */
    public function isMappedIPv4() {
        return substr_compare($this->ip, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff", 0, 12) === 0 || substr_compare($this->ip, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00", 0, 12) === 0;
    }

}

/**
 * IP address utilities (for both IPv4 and IPv6).
 *
 * As a matter of naming convention, we use `$ip` for the binary format (network address format)
 * and `$ipString` for the string/presentation format (i.e., human-readable form).
 */
class IPUtils {

    /**
     * Removes the port and the last portion of a CIDR IP address.
     *
     * @param string $ipString The IP address to sanitize.
     * @return string
     */
    public static function sanitizeIp($ipString) {
        $ipString = trim($ipString);

        // CIDR notation, A.B.C.D/E
        $posSlash = strrpos($ipString, '/');
        if ($posSlash !== false) {
            $ipString = substr($ipString, 0, $posSlash);
        }

        $posColon = strrpos($ipString, ':');
        $posDot = strrpos($ipString, '.');
        if ($posColon !== false) {
            // IPv6 address with port, [A:B:C:D:E:F:G:H]:EEEE
            $posRBrac = strrpos($ipString, ']');
            if ($posRBrac !== false && $ipString[0] == '[') {
                $ipString = substr($ipString, 1, $posRBrac - 1);
            }

            if ($posDot !== false) {
                // IPv4 address with port, A.B.C.D:EEEE
                if ($posColon > $posDot) {
                    $ipString = substr($ipString, 0, $posColon);
                }
                // else: Dotted quad IPv6 address, A:B:C:D:E:F:G.H.I.J
            } else if (strpos($ipString, ':') === $posColon) {
                $ipString = substr($ipString, 0, $posColon);
            }
            // else: IPv6 address, A:B:C:D:E:F:G:H
        }
        // else: IPv4 address, A.B.C.D

        return $ipString;
    }

    /**
     * Sanitize human-readable (user-supplied) IP address range.
     *
     * Accepts the following formats for $ipRange:
     * - single IPv4 address, e.g., 127.0.0.1
     * - single IPv6 address, e.g., ::1/128
     * - IPv4 block using CIDR notation, e.g., 192.168.0.0/22 represents the IPv4 addresses from 192.168.0.0 to 192.168.3.255
     * - IPv6 block using CIDR notation, e.g., 2001:DB8::/48 represents the IPv6 addresses from 2001:DB8:0:0:0:0:0:0 to 2001:DB8:0:FFFF:FFFF:FFFF:FFFF:FFFF
     * - wildcards, e.g., 192.168.0.* or 2001:DB8:*:*:*:*:*:*
     *
     * @param string $ipRangeString IP address range
     * @return string|null  IP address range in CIDR notation OR null on failure
     */
    public static function sanitizeIpRange($ipRangeString) {
        $ipRangeString = trim($ipRangeString);
        if (empty($ipRangeString)) {
            return null;
        }

        // IP address with wildcards '*'
        if (strpos($ipRangeString, '*') !== false) {
            // Disallow prefixed wildcards and anything other than wildcards
            // and separators (including IPv6 zero groups) after first wildcard
            if (preg_match('/[^.:]\*|\*.*([^.:*]|::)/', $ipRangeString)) {
                return null;
            }

            $numWildcards = substr_count($ipRangeString, '*');
            $ipRangeString = str_replace('*', '0', $ipRangeString);

            // CIDR
        } elseif (($pos = strpos($ipRangeString, '/')) !== false) {
            $bits = substr($ipRangeString, $pos + 1);
            $ipRangeString = substr($ipRangeString, 0, $pos);

            if (!is_numeric($bits)) {
                return null;
            }
        }

        // single IP
        if (($ip = @inet_pton($ipRangeString)) === false)
            return null;

        $maxbits = strlen($ip) * 8;
        if (!isset($bits)) {
            $bits = $maxbits;

            if (isset($numWildcards)) {
                $bits -= ($maxbits === 32 ? 8 : 16) * $numWildcards;
            }
        }

        if ($bits < 0 || $bits > $maxbits) {
            return null;
        }

        return "$ipRangeString/$bits";
    }

    /**
     * Converts an IP address in string/presentation format to binary/network address format.
     *
     * @param string $ipString IP address, either IPv4 or IPv6, e.g. `'127.0.0.1'`.
     * @return string Binary-safe string, e.g. `"\x7F\x00\x00\x01"`.
     */
    public static function stringToBinaryIP($ipString) {
        // use @inet_pton() because it throws an exception and E_WARNING on invalid input
        $ip = @inet_pton($ipString);
        return $ip === false ? "\x00\x00\x00\x00" : $ip;
    }

    /**
     * Convert binary/network address format to string/presentation format.
     *
     * @param string $ip IP address in binary/network address format, e.g. `"\x7F\x00\x00\x01"`.
     * @return string IP address in string format, e.g. `'127.0.0.1'`.
     */
    public static function binaryToStringIP($ip) {
        // use @inet_ntop() because it throws an exception and E_WARNING on invalid input
        $ipStr = @inet_ntop($ip);
        return $ipStr === false ? '0.0.0.0' : $ipStr;
    }

    /**
     * Get low and high IP addresses for a specified IP range.
     *
     * @param string $ipRange An IP address range in string format, e.g. `'192.168.1.1/24'`.
     * @return array|null Array `array($lowIp, $highIp)` in binary format, or null on failure.
     */
    public static function getIPRangeBounds($ipRange) {
        if (strpos($ipRange, '/') === false) {
            $ipRange = self::sanitizeIpRange($ipRange);

            if ($ipRange === null) {
                return null;
            }
        }
        $pos = strpos($ipRange, '/');

        $bits = substr($ipRange, $pos + 1);
        $range = substr($ipRange, 0, $pos);
        $high = $low = @inet_pton($range);
        if ($low === false) {
            return null;
        }

        $lowLen = strlen($low);
        $i = $lowLen - 1;
        $bits = $lowLen * 8 - $bits;

        for ($n = (int) ($bits / 8); $n > 0; $n--, $i--) {
            $low[$i] = chr(0);
            $high[$i] = chr(255);
        }

        $n = $bits % 8;
        if ($n) {
            $low[$i] = chr(ord($low[$i]) & ~((1 << $n) - 1));
            $high[$i] = chr(ord($high[$i]) | ((1 << $n) - 1));
        }

        return array($low, $high);
    }

}
