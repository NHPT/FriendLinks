<?php

namespace TypechoPlugin\FriendLinks\Domain;

final class UrlNormalizer
{
    public function normalize(string $url): string
    {
        $url = trim($url);
        if ('' === $url || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
            throw new \InvalidArgumentException('URL 为空、过长，或包含空白、反斜线及控制字符。');
        }

        $parts = parse_url($url);
        if (false === $parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('URL 必须包含协议和主机名。');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('仅允许 http 和 https URL。');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('URL 不能包含用户名或密码。');
        }

        $host = trim((string) $parts['host'], '[]');
        if ('' === $host || strlen($host) > 253 || false !== strpos($host, '%')) {
            throw new \InvalidArgumentException('主机名无效或过长。');
        }
        $host = rtrim(strtolower($host), '.');
        $host = $this->toAsciiWhenAvailable($host);

        if ($this->looksLikeNonCanonicalIp($host)) {
            throw new \InvalidArgumentException('不允许非标准 IP 地址表示。');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (null !== $port && !in_array($port, [80, 443], true)) {
            throw new \InvalidArgumentException('第一版仅允许 80 和 443 端口。');
        }

        $path = isset($parts['path']) && '' !== $parts['path'] ? $parts['path'] : '/';
        if (strlen($path) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new \InvalidArgumentException('URL 路径无效或过长。');
        }

        $displayHost = false !== strpos($host, ':') ? '[' . $host . ']' : $host;
        $normalized = $scheme . '://' . $displayHost;
        if (null !== $port && !(('http' === $scheme && 80 === $port) || ('https' === $scheme && 443 === $port))) {
            $normalized .= ':' . $port;
        }
        $normalized .= '/' === $path[0] ? $path : '/' . $path;
        if (isset($parts['query']) && '' !== $parts['query']) {
            $normalized .= '?' . $parts['query'];
        }

        return $normalized;
    }

    public function hash(string $normalizedUrl): string
    {
        return hash('sha256', $normalizedUrl);
    }

    public function canDetect(string $normalizedUrl): bool
    {
        $host = (string) parse_url($normalizedUrl, PHP_URL_HOST);
        return !preg_match('/[^\x00-\x7F]/', $host) || function_exists('idn_to_ascii');
    }

    private function toAsciiWhenAvailable(string $host): string
    {
        if (!preg_match('/[^\x00-\x7F]/', $host)) {
            return $host;
        }
        if (!function_exists('idn_to_ascii')) {
            return $host;
        }

        $flags = defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0;
        $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 1;
        $ascii = idn_to_ascii($host, $flags, $variant);
        if (false === $ascii || '' === $ascii) {
            throw new \InvalidArgumentException('国际化域名无法转换为 Punycode。');
        }

        return strtolower($ascii);
    }

    private function looksLikeNonCanonicalIp(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        return 1 === preg_match('/^(?:0x[0-9a-f]+|[0-9a-f:.]+)$/i', $host)
            && (bool) preg_match('/[0-9]/', $host);
    }
}
