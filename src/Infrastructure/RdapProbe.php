<?php

namespace TypechoPlugin\FriendLinks\Infrastructure;

use TypechoPlugin\FriendLinks\Domain\PublicSuffixList;

final class RdapProbe
{
    private const BOOTSTRAP_URL = 'https://data.iana.org/rdap/dns.json';
    private const PSL_URL = 'https://publicsuffix.org/list/public_suffix_list.dat';

    /** @var Repositories */
    private $repositories;

    /** @var SafeHttpClient */
    private $http;

    /** @var PublicSuffixList */
    private $suffixes;

    /** @var bool */
    private $suffixRefreshAttempted = false;

    public function __construct(
        ?Repositories $repositories = null,
        ?SafeHttpClient $http = null,
        ?PublicSuffixList $suffixes = null
    ) {
        $this->repositories = $repositories ?: new Repositories();
        $this->http = $http ?: new SafeHttpClient();
        $this->suffixes = $suffixes ?: $this->loadSuffixes();
    }

    public function probe(string $url, array $settings, ?float $deadline = null): array
    {
        $this->refreshSuffixes($settings, $deadline);
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ['state' => 'not_applicable', 'expires_at' => null, 'domain' => null];
        }

        $domain = $this->suffixes->registrableDomain($host);
        if (null === $domain) {
            return ['state' => 'unsupported', 'expires_at' => null, 'domain' => null];
        }

        $now = time();
        $cached = $this->repositories->cacheGet('rdap_domain', $domain, $now);
        if (null !== $cached) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $bootstrap = $this->bootstrap($settings, $now, $deadline);
        if (null === $bootstrap) {
            return $this->cacheResult($domain, [
                'state' => 'unknown', 'expires_at' => null, 'domain' => $domain,
            ], $now + 3600);
        }

        $endpoint = $this->endpointFor($bootstrap, $domain);
        if (null === $endpoint) {
            return $this->cacheResult($domain, [
                'state' => 'unsupported', 'expires_at' => null, 'domain' => $domain,
            ], $now + 86400);
        }

        $response = $this->http->get(
            rtrim($endpoint, '/') . '/domain/' . rawurlencode($domain),
            (int) $settings['connect_timeout'],
            $this->requestTimeout($settings, $deadline),
            3,
            262144
        );
        if (!$response['ok']) {
            return $this->cacheResult($domain, [
                'state' => 'unknown', 'expires_at' => null, 'domain' => $domain,
            ], $now + 3600);
        }
        if (404 === (int) $response['status']) {
            return $this->cacheResult($domain, [
                'state' => 'not_found', 'expires_at' => null, 'domain' => $domain,
            ], $now + 86400);
        }
        if (429 === (int) $response['status'] || (int) $response['status'] >= 500) {
            return $this->cacheResult($domain, [
                'state' => 'unknown', 'expires_at' => null, 'domain' => $domain,
            ], $now + 3600);
        }
        if ((int) $response['status'] < 200 || (int) $response['status'] >= 300) {
            return $this->cacheResult($domain, [
                'state' => 'unknown', 'expires_at' => null, 'domain' => $domain,
            ], $now + 3600);
        }

        $document = json_decode((string) $response['body'], true);
        $expiresAt = $this->expirationEvent(is_array($document) ? $document : []);
        $state = 'unknown';
        if (null !== $expiresAt) {
            $state = $expiresAt <= $now ? 'past_expiration'
                : ($expiresAt <= $now + 30 * 86400 ? 'expiring' : 'healthy');
        }

        return $this->cacheResult($domain, [
            'state' => $state,
            'expires_at' => $expiresAt,
            'domain' => $domain,
        ], $now + 86400);
    }

    private function bootstrap(array $settings, int $now, ?float $deadline): ?array
    {
        $cached = $this->repositories->cacheGet('rdap_bootstrap', 'dns', $now);
        if (null !== $cached) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $response = $this->http->get(
            self::BOOTSTRAP_URL,
            (int) $settings['connect_timeout'],
            $this->requestTimeout($settings, $deadline),
            2,
            1048576
        );
        if (!$response['ok'] || (int) $response['status'] < 200 || (int) $response['status'] >= 300) {
            return null;
        }
        $decoded = json_decode((string) $response['body'], true);
        if (!is_array($decoded) || empty($decoded['services']) || !is_array($decoded['services'])) {
            return null;
        }
        $this->repositories->cachePut(
            'rdap_bootstrap',
            'dns',
            (string) json_encode($decoded, JSON_UNESCAPED_SLASHES),
            $now + 7 * 86400
        );
        return $decoded;
    }

    private function loadSuffixes(): PublicSuffixList
    {
        $cached = $this->repositories->cacheGet('psl', 'icann', time());
        if (null !== $cached && $this->validSuffixList($cached)) {
            return new PublicSuffixList($cached);
        }
        return PublicSuffixList::bundled();
    }

    private function refreshSuffixes(array $settings, ?float $deadline): void
    {
        if ($this->suffixRefreshAttempted) {
            return;
        }
        $this->suffixRefreshAttempted = true;
        $now = time();
        $cached = $this->repositories->cacheGet('psl', 'icann', $now);
        if (null !== $cached) {
            if ($this->validSuffixList($cached)) {
                $this->suffixes = new PublicSuffixList($cached);
            }
            return;
        }

        $response = $this->http->get(
            self::PSL_URL,
            (int) $settings['connect_timeout'],
            $this->requestTimeout($settings, $deadline),
            2,
            1572864
        );
        $data = $response['ok'] && (int) $response['status'] >= 200 && (int) $response['status'] < 300
            ? (string) $response['body']
            : '';
        if (!$this->validSuffixList($data)) {
            return;
        }

        $this->repositories->cachePut('psl', 'icann', $data, $now + 7 * 86400);
        $this->suffixes = new PublicSuffixList($data);
    }

    private function requestTimeout(array $settings, ?float $deadline): int
    {
        $configured = max(2, (int) ($settings['request_timeout'] ?? 10));
        if (null === $deadline) {
            return $configured;
        }
        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining < 2) {
            throw new \RuntimeException('Worker 本次运行时间预算不足以继续 RDAP 请求。');
        }
        return min($configured, $remaining);
    }

    private function validSuffixList(string $data): bool
    {
        return strlen($data) >= 100000
            && false !== strpos($data, '// ===BEGIN ICANN DOMAINS===')
            && false !== strpos($data, '// ===END ICANN DOMAINS===');
    }

    private function endpointFor(array $bootstrap, string $domain): ?string
    {
        $labels = explode('.', $domain);
        $tld = strtolower((string) end($labels));
        foreach ($bootstrap['services'] as $service) {
            if (!is_array($service) || count($service) < 2 || !is_array($service[0]) || !is_array($service[1])) {
                continue;
            }
            $suffixes = array_map('strtolower', $service[0]);
            if (in_array($tld, $suffixes, true)) {
                foreach ($service[1] as $endpoint) {
                    if (0 === strpos((string) $endpoint, 'https://')) {
                        return (string) $endpoint;
                    }
                }
            }
        }
        return null;
    }

    private function expirationEvent(array $document): ?int
    {
        if (empty($document['events']) || !is_array($document['events'])) {
            return null;
        }
        foreach ($document['events'] as $event) {
            if (!is_array($event) || 'expiration' !== strtolower((string) ($event['eventAction'] ?? ''))) {
                continue;
            }
            $timestamp = strtotime((string) ($event['eventDate'] ?? ''));
            if (false !== $timestamp) {
                return $timestamp;
            }
        }
        return null;
    }

    private function cacheResult(string $domain, array $result, int $expiresAt): array
    {
        $this->repositories->cachePut(
            'rdap_domain',
            $domain,
            (string) json_encode($result, JSON_UNESCAPED_SLASHES),
            $expiresAt
        );
        return $result;
    }
}
