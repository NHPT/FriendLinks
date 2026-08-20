<?php

namespace TypechoPlugin\FriendLinks\Domain;

final class StatusAggregator
{
    public function aggregate(array $previous, array $components, array $settings, int $now): array
    {
        $dns = $components['dns'] ?? [];
        $http = $components['http'] ?? [];
        $tls = $components['tls'] ?? [];
        $domain = $components['domain'] ?? [];
        $oldFailures = (int) ($previous['availability_consecutive_failures'] ?? 0);
        $threshold = max(1, (int) ($settings['failure_threshold'] ?? 3));

        $immediateTls = [
            'expired' => 'tls_expired',
            'not_yet_valid' => 'tls_not_yet_valid',
            'hostname_mismatch' => 'tls_hostname_mismatch',
            'untrusted' => 'tls_untrusted',
        ];
        $tlsState = (string) ($tls['state'] ?? '');
        if (isset($immediateTls[$tlsState])) {
            return $this->result('down', $immediateTls[$tlsState], $oldFailures + 1, false, $now);
        }

        $failureReason = $this->availabilityFailureReason($dns, $http, $tls);
        $mainSuccess = 'healthy' === ($dns['state'] ?? null)
            && in_array($http['state'] ?? null, ['healthy', 'restricted'], true)
            && !in_array($tlsState, ['handshake_failed'], true);

        if (null !== $failureReason) {
            $failures = $oldFailures + 1;
            return $this->result(
                $failures >= $threshold ? 'down' : 'degraded',
                $failureReason,
                $failures,
                false,
                $now
            );
        }

        $failures = $mainSuccess ? 0 : $oldFailures;
        if ('restricted' === ($http['state'] ?? null) && empty($settings['restricted_is_healthy'])) {
            return $this->result('degraded', 'http_restricted', $failures, true, $now);
        }

        if ('expiring' === $tlsState) {
            return $this->result('warning', 'tls_expiring', $failures, $mainSuccess, $now);
        }

        $domainReasons = [
            'past_expiration' => 'domain_expiration_passed',
            'expiring' => 'domain_expiring',
        ];
        $domainState = (string) ($domain['state'] ?? '');
        if (isset($domainReasons[$domainState])) {
            return $this->result('warning', $domainReasons[$domainState], $failures, $mainSuccess, $now);
        }

        if ($mainSuccess) {
            return $this->result('healthy', null, 0, true, $now);
        }

        $hasConclusion = array_filter([$dns['state'] ?? null, $http['state'] ?? null, $tlsState, $domainState]);
        if (!$hasConclusion) {
            return $this->result('unknown', 'worker_error', $failures, false, $now);
        }

        return $this->result('unknown', $this->unknownReason($dns, $http, $tls, $domain), $failures, false, $now);
    }

    private function availabilityFailureReason(array $dns, array $http, array $tls): ?string
    {
        if ('blocked' === ($dns['state'] ?? null)) {
            return 'dns_blocked_target';
        }
        if ('failed' === ($dns['state'] ?? null)) {
            return 'dns_failed';
        }

        if ('handshake_failed' === ($tls['state'] ?? null)) {
            return 'tls_handshake_failed';
        }

        $httpReasons = [
            'not_found' => 'http_not_found',
            'server_error' => 'http_server_error',
            'rate_limited' => 'http_rate_limited',
            'network_error' => 'http_unreachable',
            'redirect_error' => 'http_redirect_error',
            'client_error' => 'http_client_error',
        ];
        $state = (string) ($http['state'] ?? '');
        return $httpReasons[$state] ?? null;
    }

    private function unknownReason(array $dns, array $http, array $tls, array $domain): string
    {
        if ('unknown' === ($domain['state'] ?? null)) {
            return 'domain_unknown';
        }
        if ('unsupported' === ($domain['state'] ?? null)) {
            return 'domain_unsupported';
        }
        if ('not_applicable' === ($domain['state'] ?? null)) {
            return 'domain_not_applicable';
        }
        if ('unknown_detail' === ($tls['state'] ?? null)) {
            return 'tls_unknown_detail';
        }
        return 'worker_error';
    }

    private function result(string $state, ?string $reason, int $failures, bool $mainSuccess, int $now): array
    {
        return [
            'overall_state' => $state,
            'reason_code' => $reason,
            'availability_consecutive_failures' => max(0, $failures),
            'main_success' => $mainSuccess,
            'evaluated_at' => $now,
        ];
    }
}
