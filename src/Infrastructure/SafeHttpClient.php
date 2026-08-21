<?php

namespace TypechoPlugin\FriendLinks\Infrastructure;

use TypechoPlugin\FriendLinks\Domain\PreparedTarget;
use TypechoPlugin\FriendLinks\Domain\TargetException;
use TypechoPlugin\FriendLinks\Domain\TargetPolicy;
use TypechoPlugin\FriendLinks\Domain\UrlNormalizer;

final class SafeHttpClient
{
    /** @var TargetPolicy */
    private $policy;

    /** @var UrlNormalizer */
    private $normalizer;

    public function __construct(?TargetPolicy $policy = null, ?UrlNormalizer $normalizer = null)
    {
        $this->policy = $policy ?: new TargetPolicy();
        $this->normalizer = $normalizer ?: new UrlNormalizer();
    }

    public function get(
        string $url,
        int $connectTimeout = 3,
        int $timeout = 10,
        int $maxRedirects = 5,
        int $maxBytes = 65536,
        ?PreparedTarget $initialTarget = null
    ): array {
        if (!extension_loaded('curl')) {
            return $this->failure('curl_missing', 'PHP cURL 扩展未安装。');
        }

        $started = microtime(true);
        $deadline = $started + max(2, $timeout);
        $visited = [];
        $chain = [];
        $totalBytes = 0;
        $current = $url;
        $target = $initialTarget;
        $finalTls = ['state' => 'not_applicable'];

        for ($redirects = 0; $redirects <= $maxRedirects; $redirects++) {
            try {
                $target = $target ?: $this->policy->prepare($current, $deadline);
            } catch (TargetException $error) {
                return $this->failure($error->reasonCode(), $error->getMessage(), $started, $chain);
            }

            if (isset($visited[$target->url])) {
                return $this->failure('http_redirect_loop', '重定向链存在循环。', $started, $chain);
            }
            $visited[$target->url] = true;

            $remainingMs = (int) floor(($deadline - microtime(true)) * 1000);
            if ($remainingMs < 1) {
                return $this->failure('http_timeout', '整条重定向链已超时。', $started, $chain);
            }

            $hop = $this->requestHop(
                $target,
                min($connectTimeout * 1000, $remainingMs),
                $remainingMs,
                max(0, $maxBytes - $totalBytes)
            );
            $totalBytes += $hop['body_bytes'];
            $chain[] = [
                'url' => $target->url,
                'status' => $hop['status'],
                'primary_ip' => $hop['primary_ip'],
            ];

            if (!$hop['ok']) {
                $hop['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
                $hop['chain'] = $chain;
                return $hop;
            }

            if ('https' === $target->scheme) {
                $finalTls = $hop['tls'];
            }

            $status = (int) $hop['status'];
            if ($status >= 300 && $status <= 399) {
                if ($redirects >= $maxRedirects) {
                    return $this->failure('http_redirect_limit', '重定向次数超过上限。', $started, $chain);
                }
                $location = $hop['headers']['location'] ?? '';
                if ('' === trim((string) $location)) {
                    return $this->failure('http_redirect_missing_location', '重定向响应缺少 Location。', $started, $chain);
                }
                try {
                    $current = $this->resolveUrl($target->url, (string) $location);
                    $current = $this->normalizer->normalize($current);
                } catch (\Throwable $error) {
                    return $this->failure('http_redirect_invalid', '重定向目标无效。', $started, $chain);
                }
                $target = null;
                continue;
            }

            return [
                'ok' => true,
                'reason_code' => null,
                'error' => null,
                'status' => $status,
                'headers' => $hop['headers'],
                'body' => $hop['body'],
                'body_truncated' => $hop['body_truncated'],
                'final_url' => $target->url,
                'primary_ip' => $hop['primary_ip'],
                'addresses' => $target->addresses,
                'tls' => $finalTls,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'chain' => $chain,
            ];
        }

        return $this->failure('http_redirect_limit', '重定向次数超过上限。', $started, $chain);
    }

    public function postJson(
        string $url,
        string $json,
        array $headers = [],
        int $connectTimeout = 3,
        int $timeout = 10,
        int $maxBytes = 65536,
        ?PreparedTarget $target = null
    ): array {
        if (!extension_loaded('curl')) {
            return $this->failure('curl_missing', 'PHP cURL 扩展未安装。');
        }
        if (strlen($json) > 65536) {
            return $this->failure('payload_too_large', 'Webhook 请求体超过 64 KiB。');
        }

        $started = microtime(true);
        $deadline = $started + max(2, $timeout);
        try {
            $target = $target ?: $this->policy->prepare($url, $deadline);
        } catch (TargetException $error) {
            return $this->failure($error->reasonCode(), $error->getMessage(), $started);
        }
        $remainingMs = (int) floor(($deadline - microtime(true)) * 1000);
        if ($remainingMs < 1) {
            return $this->failure('http_timeout', 'Webhook 请求已超时。', $started);
        }

        $hop = $this->requestHop(
            $target,
            min(max(1, $connectTimeout) * 1000, $remainingMs),
            $remainingMs,
            max(0, $maxBytes),
            'POST',
            $json,
            array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers)
        );
        $chain = [[
            'url' => $target->url,
            'status' => $hop['status'],
            'primary_ip' => $hop['primary_ip'],
        ]];
        if (!$hop['ok']) {
            $hop['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
            $hop['chain'] = $chain;
            return $hop;
        }

        return [
            'ok' => true,
            'reason_code' => null,
            'error' => null,
            'status' => (int) $hop['status'],
            'headers' => $hop['headers'],
            'body' => $hop['body'],
            'body_truncated' => $hop['body_truncated'],
            'final_url' => $target->url,
            'primary_ip' => $hop['primary_ip'],
            'addresses' => $target->addresses,
            'tls' => 'https' === $target->scheme ? $hop['tls'] : ['state' => 'not_applicable'],
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'chain' => $chain,
        ];
    }

    private function requestHop(
        PreparedTarget $target,
        int $connectTimeoutMs,
        int $timeoutMs,
        int $maxBytes,
        string $method = 'GET',
        string $requestBody = '',
        array $requestHeaders = []
    ): array
    {
        $headers = [];
        $body = '';
        $truncated = false;
        $handle = curl_init();
        $resolveAddresses = array_map(static function ($ip) {
            return false !== strpos($ip, ':') ? '[' . $ip . ']' : $ip;
        }, $target->addresses);

        $options = [
            CURLOPT_URL => $target->url,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, $connectTimeoutMs),
            CURLOPT_TIMEOUT_MS => max(1, $timeoutMs),
            CURLOPT_USERAGENT => 'FriendLinks/0.2.1 (+Typecho health check)',
            CURLOPT_HTTPHEADER => $requestHeaders ?: ['Accept: text/html,application/json;q=0.9,*/*;q=0.1'],
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$headers) {
                $length = strlen($line);
                $position = strpos($line, ':');
                if (false !== $position) {
                    $name = strtolower(trim(substr($line, 0, $position)));
                    $value = trim(substr($line, $position + 1));
                    if ('' !== $name) {
                        $headers[$name] = $value;
                    }
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (&$body, &$truncated, $maxBytes) {
                $remaining = $maxBytes - strlen($body);
                if ($remaining <= 0) {
                    $truncated = true;
                    return 0;
                }
                if (strlen($chunk) > $remaining) {
                    $body .= substr($chunk, 0, $remaining);
                    $truncated = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if (!filter_var($target->host, FILTER_VALIDATE_IP)) {
            $options[CURLOPT_RESOLVE] = [
                $target->host . ':' . $target->port . ':' . implode(',', $resolveAddresses),
            ];
        }
        if ('POST' === $method) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $requestBody;
        } else {
            $options[CURLOPT_HTTPGET] = true;
        }
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_CERTINFO')) {
            $options[CURLOPT_CERTINFO] = true;
        }
        curl_setopt_array($handle, $options);

        $executed = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $primaryIp = (string) curl_getinfo($handle, CURLINFO_PRIMARY_IP);
        $certInfo = defined('CURLINFO_CERTINFO') ? curl_getinfo($handle, CURLINFO_CERTINFO) : [];
        $verifyResult = defined('CURLINFO_SSL_VERIFYRESULT')
            ? (int) curl_getinfo($handle, CURLINFO_SSL_VERIFYRESULT)
            : 0;
        curl_close($handle);

        $acceptedTruncation = $truncated && defined('CURLE_WRITE_ERROR') && CURLE_WRITE_ERROR === $errno && $status > 0;
        if (false === $executed && !$acceptedTruncation) {
            [$reason, $tls] = $this->mapCurlError($errno, $error, $verifyResult, $target->scheme);
            return [
                'ok' => false,
                'reason_code' => $reason,
                'error' => $this->sanitizeError($error),
                'status' => $status,
                'headers' => [],
                'body' => '',
                'body_bytes' => strlen($body),
                'body_truncated' => $truncated,
                'primary_ip' => $primaryIp,
                'tls' => $tls,
            ];
        }

        if (!$this->addressWasApproved($primaryIp, $target->addresses)) {
            return [
                'ok' => false,
                'reason_code' => 'dns_rebinding_detected',
                'error' => '实际连接地址不在已验证 DNS 结果中。',
                'status' => $status,
                'headers' => [],
                'body' => '',
                'body_bytes' => strlen($body),
                'body_truncated' => $truncated,
                'primary_ip' => '',
                'tls' => ['state' => 'unknown'],
            ];
        }

        return [
            'ok' => true,
            'reason_code' => null,
            'error' => null,
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
            'body_bytes' => strlen($body),
            'body_truncated' => $truncated,
            'primary_ip' => $primaryIp,
            'tls' => 'https' === $target->scheme ? $this->parseCertificate($certInfo) : ['state' => 'not_applicable'],
        ];
    }

    private function parseCertificate($certInfo): array
    {
        if (!is_array($certInfo) || empty($certInfo[0]) || !is_array($certInfo[0])) {
            return ['state' => 'unknown_detail'];
        }
        $leaf = $certInfo[0];
        $notBefore = $this->certificateTime($leaf, ['Start date', 'Not Before']);
        $notAfter = $this->certificateTime($leaf, ['Expire date', 'Not After']);
        $state = 'healthy';
        if ($notBefore && $notBefore > time()) {
            $state = 'not_yet_valid';
        } elseif ($notAfter && $notAfter <= time()) {
            $state = 'expired';
        } elseif ($notAfter && $notAfter <= time() + 30 * 86400) {
            $state = 'expiring';
        }

        return [
            'state' => $state,
            'not_before' => $notBefore,
            'not_after' => $notAfter,
            'issuer' => isset($leaf['Issuer']) ? substr((string) $leaf['Issuer'], 0, 300) : null,
        ];
    }

    private function certificateTime(array $certificate, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (!empty($certificate[$key])) {
                $time = strtotime((string) $certificate[$key]);
                return false === $time ? null : $time;
            }
        }
        return null;
    }

    private function mapCurlError(int $errno, string $error, int $verifyResult, string $scheme): array
    {
        if ('https' === $scheme) {
            $lower = strtolower($error);
            if (
                false !== strpos($lower, 'certificate has expired')
                || false !== strpos($lower, 'certificate expired')
            ) {
                return ['tls_expired', ['state' => 'expired', 'verify_result' => $verifyResult]];
            }
            if (
                false !== strpos($lower, 'certificate is not yet valid')
                || false !== strpos($lower, 'not yet valid')
            ) {
                return ['tls_not_yet_valid', ['state' => 'not_yet_valid', 'verify_result' => $verifyResult]];
            }
            if (
                false !== strpos($lower, 'subject alternative name')
                || false !== strpos($lower, 'no alternative certificate')
                || false !== strpos($lower, 'certificate subject name')
            ) {
                return ['tls_hostname_mismatch', ['state' => 'hostname_mismatch', 'verify_result' => $verifyResult]];
            }
            if (
                (defined('CURLE_PEER_FAILED_VERIFICATION') && CURLE_PEER_FAILED_VERIFICATION === $errno)
                || (defined('CURLE_SSL_CACERT') && CURLE_SSL_CACERT === $errno)
            ) {
                return ['tls_untrusted', ['state' => 'untrusted', 'verify_result' => $verifyResult]];
            }
            if (defined('CURLE_SSL_CONNECT_ERROR') && CURLE_SSL_CONNECT_ERROR === $errno) {
                return ['tls_handshake_failed', ['state' => 'handshake_failed', 'verify_result' => $verifyResult]];
            }
        }
        if (defined('CURLE_OPERATION_TIMEDOUT') && CURLE_OPERATION_TIMEDOUT === $errno) {
            return ['http_timeout', ['state' => 'unknown']];
        }
        return ['http_unreachable', ['state' => 'unknown']];
    }

    private function addressWasApproved(string $actual, array $approved): bool
    {
        $actualPacked = @inet_pton($actual);
        if (false === $actualPacked) {
            return false;
        }
        foreach ($approved as $ip) {
            if ($actualPacked === @inet_pton($ip)) {
                return true;
            }
        }
        return false;
    }

    private function resolveUrl(string $base, string $location): string
    {
        $location = trim($location);
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = $parts['scheme'] . '://' . (false !== strpos($parts['host'], ':') ? '[' . trim($parts['host'], '[]') . ']' : $parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        if (0 === strpos($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        if (0 === strpos($location, '/')) {
            return $origin . $location;
        }
        if (0 === strpos($location, '?')) {
            return $origin . ($parts['path'] ?? '/') . $location;
        }
        if (0 === strpos($location, '#')) {
            return $base . $location;
        }

        $directory = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');
        $path = $directory . $location;
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return $origin . '/' . implode('/', $segments);
    }

    private function failure(string $reason, string $message, ?float $started = null, array $chain = []): array
    {
        return [
            'ok' => false,
            'reason_code' => $reason,
            'error' => $this->sanitizeError($message),
            'status' => 0,
            'headers' => [],
            'body' => '',
            'body_truncated' => false,
            'final_url' => null,
            'primary_ip' => null,
            'addresses' => [],
            'tls' => ['state' => 0 === strpos($reason, 'tls_') ? substr($reason, 4) : 'unknown'],
            'duration_ms' => null === $started ? 0 : (int) round((microtime(true) - $started) * 1000),
            'chain' => $chain,
        ];
    }

    private function sanitizeError(string $message): string
    {
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message);
        return substr(trim((string) $message), 0, 300);
    }
}
