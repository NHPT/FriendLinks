<?php

namespace TypechoPlugin\FriendLinks\Application;

use TypechoPlugin\FriendLinks\Domain\StatusAggregator;
use TypechoPlugin\FriendLinks\Domain\TargetException;
use TypechoPlugin\FriendLinks\Domain\TargetPolicy;
use TypechoPlugin\FriendLinks\Infrastructure\RdapProbe;
use TypechoPlugin\FriendLinks\Infrastructure\Repositories;
use TypechoPlugin\FriendLinks\Infrastructure\SafeHttpClient;

final class Worker
{
    /** @var Repositories */
    private $repositories;

    /** @var TargetPolicy */
    private $policy;

    /** @var SafeHttpClient */
    private $http;

    /** @var RdapProbe */
    private $rdap;

    /** @var StatusAggregator */
    private $aggregator;

    /** @var NotificationPlanner */
    private $notificationPlanner;

    /** @var NotificationDispatcher */
    private $notificationDispatcher;

    public function __construct(
        ?Repositories $repositories = null,
        ?TargetPolicy $policy = null,
        ?SafeHttpClient $http = null,
        ?RdapProbe $rdap = null,
        ?StatusAggregator $aggregator = null,
        ?NotificationPlanner $notificationPlanner = null,
        ?NotificationDispatcher $notificationDispatcher = null
    ) {
        $this->repositories = $repositories ?: new Repositories();
        $this->policy = $policy ?: new TargetPolicy();
        $this->http = $http ?: new SafeHttpClient($this->policy);
        $this->rdap = $rdap ?: new RdapProbe($this->repositories, $this->http);
        $this->aggregator = $aggregator ?: new StatusAggregator();
        $this->notificationPlanner = $notificationPlanner ?: new NotificationPlanner();
        $this->notificationDispatcher = $notificationDispatcher ?: new NotificationDispatcher($this->repositories);
    }

    public function run(string $mode = 'cli', int $limit = 50, int $maxSeconds = 240, array $linkIds = []): array
    {
        $mode = in_array($mode, ['http', 'admin'], true) ? $mode : 'cli';
        $limitCap = 'http' === $mode ? 5 : ('admin' === $mode ? 20 : 500);
        $secondsCap = 'http' === $mode ? 20 : ('admin' === $mode ? 30 : 3600);
        $limit = max(1, min($limitCap, $limit));
        $maxSeconds = max(1, min($secondsCap, $maxSeconds));
        $linkIds = array_values(array_filter(array_unique(array_map('intval', $linkIds)), static function ($id) {
            return $id > 0;
        }));
        if ($linkIds) {
            $linkIds = array_slice($linkIds, 0, $limit);
        }
        $startedAt = time();
        $deadline = microtime(true) + $maxSeconds;
        $runId = bin2hex(random_bytes(16));
        $counts = ['claimed' => 0, 'completed' => 0, 'failed' => 0];
        $errors = [];
        $notificationCounts = ['claimed' => 0, 'sent' => 0, 'failed' => 0, 'errors' => []];
        $this->repositories->createRun($runId, $mode, $startedAt);

        try {
            if (!extension_loaded('curl')) {
                throw new \RuntimeException('PHP cURL 扩展未安装，自动检测不可用。');
            }

            $candidates = $linkIds
                ? array_map(static function ($linkId) {
                    return ['link_id' => $linkId];
                }, $linkIds)
                : $this->repositories->dueCandidates($startedAt, $limit);
            foreach ($candidates as $candidate) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                $linkId = (int) $candidate['link_id'];
                $token = bin2hex(random_bytes(16));
                $now = time();
                if (!$this->repositories->claim($linkId, $token, $now, $now + 300)) {
                    continue;
                }
                $counts['claimed']++;

                try {
                    $link = $this->repositories->claimedLink($linkId, $token);
                    if (!$link || 'published' !== $link['visibility'] || empty($link['check_enabled'])) {
                        $this->repositories->releaseLease($linkId, $token, $now + 3600);
                        continue;
                    }
                    $this->checkLink($link, $token, $runId, Settings::all(), $now, $deadline);
                    $counts['completed']++;
                } catch (\Throwable $error) {
                    $counts['failed']++;
                    $errors[] = $this->summarize($error->getMessage());
                    $failures = isset($link) ? (int) $link['availability_consecutive_failures'] : 0;
                    $backoff = min(21600, 300 * (2 ** min(6, $failures)));
                    $this->repositories->releaseLease($linkId, $token, time() + $backoff);
                }

                $this->repositories->updateRun($runId, [
                    'heartbeat_at' => time(),
                    'claimed_count' => $counts['claimed'],
                    'completed_count' => $counts['completed'],
                    'failed_count' => $counts['failed'],
                ]);
            }

            $partial = microtime(true) >= $deadline
                || (!$linkIds && count($candidates) >= $limit)
                || ($linkIds && ($counts['completed'] + $counts['failed']) < count($candidates));
            $status = $counts['failed'] > 0 ? ($counts['completed'] > 0 ? 'partial' : 'failed')
                : ($partial ? 'partial' : 'completed');
            $this->repositories->cleanup(
                time() - ((int) Settings::get('history_days', 90) * 86400),
                time(),
                200
            );
            $this->repositories->updateRun($runId, [
                'status' => $status,
                'heartbeat_at' => time(),
                'finished_at' => time(),
                'claimed_count' => $counts['claimed'],
                'completed_count' => $counts['completed'],
                'failed_count' => $counts['failed'],
                'error_summary' => $errors ? implode('; ', array_slice(array_unique($errors), 0, 3)) : null,
            ]);
        } catch (\Throwable $error) {
            $errors[] = $this->summarize($error->getMessage());
            $this->repositories->updateRun($runId, [
                'status' => 'failed',
                'heartbeat_at' => time(),
                'finished_at' => time(),
                'claimed_count' => $counts['claimed'],
                'completed_count' => $counts['completed'],
                'failed_count' => max(1, $counts['failed']),
                'error_summary' => implode('; ', array_slice(array_unique($errors), 0, 3)),
            ]);
        }

        if (microtime(true) < $deadline) {
            try {
                $notificationCounts = $this->notificationDispatcher->dispatch(
                    'http' === $mode ? 3 : 20,
                    $deadline
                );
            } catch (\Throwable $error) {
                $notificationCounts['failed'] = 1;
                $notificationCounts['errors'] = [$this->summarize($error->getMessage())];
            }
        }

        return [
            'run_id' => $runId,
            'mode' => $mode,
            'claimed' => $counts['claimed'],
            'completed' => $counts['completed'],
            'failed' => $counts['failed'],
            'errors' => $errors,
            'notifications' => $notificationCounts,
        ];
    }

    private function checkLink(
        array $link,
        string $token,
        string $runId,
        array $settings,
        int $now,
        float $deadline
    ): void
    {
        $started = microtime(true);
        $details = json_decode((string) ($link['details_json'] ?? ''), true);
        if (!is_array($details)) {
            $details = [];
        }
        $components = [
            'dns' => is_array($details['dns'] ?? null) ? $details['dns'] : [],
            'http' => is_array($details['http'] ?? null) ? $details['http'] : [],
            'tls' => is_array($details['tls'] ?? null) ? $details['tls'] : [],
            'domain' => is_array($details['domain'] ?? null) ? $details['domain'] : [],
        ];

        $tlsDue = (int) $link['tls_next_check_at'] <= $now;
        $httpDue = (int) $link['http_next_check_at'] <= $now;
        $dnsDue = $httpDue || $tlsDue || (int) $link['dns_next_check_at'] <= $now;
        $domainDue = (int) $link['domain_next_check_at'] <= $now;
        $prepared = null;

        if ($dnsDue) {
            $this->assertDeadline($deadline);
            $this->renewLeaseOrFail((int) $link['id'], $token);
            try {
                $prepared = $this->policy->prepare(
                    (string) $link['normalized_url'],
                    min($deadline, microtime(true) + max(2, (int) $settings['request_timeout']))
                );
                $components['dns'] = [
                    'state' => 'healthy',
                    'addresses' => $prepared->addresses,
                ];
            } catch (TargetException $error) {
                $components['dns'] = [
                    'state' => 'dns_blocked_target' === $error->reasonCode() ? 'blocked' : 'failed',
                    'reason_code' => $error->reasonCode(),
                    'error' => $this->summarize($error->getMessage()),
                ];
            }
            $this->renewLeaseOrFail((int) $link['id'], $token);
        }

        $mustRequest = ($httpDue || $tlsDue) && null !== $prepared;
        if ($mustRequest) {
            $requestTimeout = $this->remainingTimeout($deadline, (int) $settings['request_timeout']);
            $this->renewLeaseOrFail((int) $link['id'], $token);
            $response = $this->http->get(
                (string) $link['normalized_url'],
                min((int) $settings['connect_timeout'], $requestTimeout),
                $requestTimeout,
                (int) $settings['max_redirects'],
                65536,
                $prepared
            );
            if ($httpDue) {
                $components['http'] = $this->httpComponent($response);
            }
            $components['tls'] = $response['tls'];
            if (!$response['ok'] && 0 === strpos((string) $response['reason_code'], 'tls_')) {
                $components['tls']['reason_code'] = $response['reason_code'];
            }
            $this->renewLeaseOrFail((int) $link['id'], $token);
        } elseif ($httpDue) {
            $components['http'] = [
                'state' => 'network_error',
                'code' => null,
                'reason_code' => $components['dns']['reason_code'] ?? 'dns_failed',
                'duration_ms' => 0,
                'final_url' => null,
            ];
            $components['tls'] = 'https' === parse_url((string) $link['normalized_url'], PHP_URL_SCHEME)
                ? ['state' => 'unknown']
                : ['state' => 'not_applicable'];
        }

        if ($domainDue) {
            $this->assertDeadline($deadline);
            $this->renewLeaseOrFail((int) $link['id'], $token);
            $components['domain'] = $this->rdap->probe(
                (string) $link['normalized_url'],
                $settings,
                $deadline
            );
            $this->renewLeaseOrFail((int) $link['id'], $token);
        }

        $aggregate = $this->aggregator->aggregate($link, $components, $settings, $now);
        $dnsNext = $dnsDue ? $now + $this->jitter((int) $settings['http_interval']) : (int) $link['dns_next_check_at'];
        $httpNext = $httpDue ? $now + $this->jitter((int) $settings['http_interval']) : (int) $link['http_next_check_at'];
        $tlsNext = ($httpDue || $tlsDue)
            ? $now + $this->jitter((int) $settings['tls_interval'])
            : (int) $link['tls_next_check_at'];
        $domainNext = $domainDue
            ? $now + $this->jitter((int) $settings['domain_interval'])
            : (int) $link['domain_next_check_at'];
        $http = $components['http'];
        $tls = $components['tls'];
        $domain = $components['domain'];

        $statusRows = [
            'overall_state' => $aggregate['overall_state'],
            'reason_code' => $aggregate['reason_code'],
            'http_state' => $http['state'] ?? null,
            'http_code' => $http['code'] ?? null,
            'response_time_ms' => $http['duration_ms'] ?? null,
            'final_url' => $http['final_url'] ?? null,
            'dns_state' => $components['dns']['state'] ?? null,
            'tls_state' => $tls['state'] ?? null,
            'cert_not_after' => $tls['not_after'] ?? null,
            'domain_state' => $domain['state'] ?? null,
            'domain_expires_at' => $domain['expires_at'] ?? null,
            'availability_consecutive_failures' => $aggregate['availability_consecutive_failures'],
            'checked_at' => $now,
            'dns_checked_at' => $dnsDue ? $now : $link['dns_checked_at'],
            'http_checked_at' => $httpDue ? $now : $link['http_checked_at'],
            'tls_checked_at' => ($httpDue || $tlsDue) ? $now : $link['tls_checked_at'],
            'domain_checked_at' => $domainDue ? $now : $link['domain_checked_at'],
            'dns_next_check_at' => $dnsNext,
            'http_next_check_at' => $httpNext,
            'tls_next_check_at' => $tlsNext,
            'domain_next_check_at' => $domainNext,
            'last_success_at' => $aggregate['main_success'] ? $now : $link['last_success_at'],
            'last_failure_at' => $aggregate['main_success'] ? $link['last_failure_at'] : $now,
            'state_changed_at' => $link['overall_state'] !== $aggregate['overall_state']
                ? $now
                : $link['state_changed_at'],
            'next_check_at' => min($dnsNext, $httpNext, $tlsNext, $domainNext),
            'details_json' => (string) json_encode($components, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $history = [
            'link_id' => (int) $link['id'],
            'run_id' => $runId,
            'overall_state' => $aggregate['overall_state'],
            'reason_code' => $aggregate['reason_code'],
            'http_code' => $http['code'] ?? null,
            'response_time_ms' => $http['duration_ms'] ?? null,
            'started_at' => $now,
            'finished_at' => time(),
            'details_json' => $statusRows['details_json'],
        ];
        $notifications = $this->notificationPlanner->plan($link, $statusRows, $settings, $runId, $now);
        $this->renewLeaseOrFail((int) $link['id'], $token);
        $this->repositories->persistResult(
            (int) $link['id'],
            $token,
            time(),
            $statusRows,
            $history,
            $notifications
        );
    }

    private function renewLeaseOrFail(int $linkId, string $token): void
    {
        $now = time();
        if (!$this->repositories->renewLease($linkId, $token, $now, $now + 300)) {
            throw new \RuntimeException('检测租约已过期，当前任务已停止。');
        }
    }

    private function assertDeadline(float $deadline): void
    {
        if (microtime(true) >= $deadline) {
            throw new \RuntimeException('Worker 本次运行时间预算已耗尽。');
        }
    }

    private function remainingTimeout(float $deadline, int $configured): int
    {
        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining < 2) {
            throw new \RuntimeException('Worker 本次运行时间预算不足以继续网络请求。');
        }
        return max(2, min($configured, $remaining));
    }

    private function httpComponent(array $response): array
    {
        if (!$response['ok']) {
            $reason = (string) $response['reason_code'];
            $state = 0 === strpos($reason, 'http_redirect_') ? 'redirect_error' : 'network_error';
            return [
                'state' => $state,
                'code' => $response['status'] ?: null,
                'reason_code' => $reason,
                'duration_ms' => (int) $response['duration_ms'],
                'final_url' => $response['final_url'],
                'error' => $this->summarize((string) $response['error']),
                'chain' => $response['chain'],
            ];
        }

        $code = (int) $response['status'];
        if ($code >= 200 && $code <= 299) {
            $state = 'healthy';
        } elseif (in_array($code, [401, 403], true)) {
            $state = 'restricted';
        } elseif (in_array($code, [404, 410], true)) {
            $state = 'not_found';
        } elseif (in_array($code, [408, 425, 429], true)) {
            $state = 'rate_limited';
        } elseif ($code >= 500 && $code <= 599) {
            $state = 'server_error';
        } elseif ($code >= 400 && $code <= 499) {
            $state = 'client_error';
        } else {
            $state = 'network_error';
        }

        return [
            'state' => $state,
            'code' => $code,
            'duration_ms' => (int) $response['duration_ms'],
            'final_url' => $response['final_url'],
            'body_truncated' => (bool) $response['body_truncated'],
            'chain' => $response['chain'],
        ];
    }

    private function jitter(int $seconds): int
    {
        $variance = max(1, (int) floor($seconds * 0.1));
        return max(60, $seconds + random_int(-$variance, $variance));
    }

    private function summarize(string $message): string
    {
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message);
        return substr(trim((string) $message), 0, 300);
    }
}
