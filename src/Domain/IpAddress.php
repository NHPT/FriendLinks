<?php

namespace TypechoPlugin\FriendLinks\Domain;

final class IpAddress
{
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
        '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
        '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    private const BLOCKED_V6 = [
        '::/128', '::1/128', '100::/64', '2001:db8::/32',
        'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    public static function isPublic(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $packed = inet_pton($ip);
        if (false === $packed) {
            return false;
        }

        if (16 === strlen($packed) && 0 === substr_compare($packed, str_repeat("\0", 10) . "\xff\xff", 0, 12)) {
            $ip = inet_ntop(substr($packed, 12));
            $packed = inet_pton($ip);
        }

        $ranges = 4 === strlen($packed) ? self::BLOCKED_V4 : self::BLOCKED_V6;
        foreach ($ranges as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }

        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        return false !== filter_var($ip, FILTER_VALIDATE_IP, $flags);
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr, 2);
        $address = inet_pton($ip);
        $base = inet_pton($network);
        if (false === $address || false === $base || strlen($address) !== strlen($base)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && substr($address, 0, $bytes) !== substr($base, 0, $bytes)) {
            return false;
        }
        if (0 === $remainder) {
            return true;
        }

        $mask = (0xff << (8 - $remainder)) & 0xff;
        return (ord($address[$bytes]) & $mask) === (ord($base[$bytes]) & $mask);
    }
}
