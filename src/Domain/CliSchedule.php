<?php

namespace TypechoPlugin\FriendLinks\Domain;

final class CliSchedule
{
    public static function decision(
        ?array $latestRun,
        int $now,
        int $intervalSeconds,
        int $maxSeconds
    ): array {
        if (!$latestRun) {
            return ['due' => true, 'reason' => null, 'next_run_at' => $now];
        }

        $startedAt = (int) ($latestRun['started_at'] ?? 0);
        $heartbeatAt = (int) ($latestRun['heartbeat_at'] ?? 0);
        if ($startedAt > $now + 300) {
            return [
                'due' => false,
                'reason' => 'clock_skew',
                'next_run_at' => $startedAt,
            ];
        }

        $nextRunAt = $startedAt + max(60, $intervalSeconds);
        $running = 'running' === ($latestRun['status'] ?? null)
            && $heartbeatAt >= $now - max(300, $maxSeconds + 60);
        if ($running) {
            return [
                'due' => false,
                'reason' => 'worker_running',
                'next_run_at' => max($now, $nextRunAt),
            ];
        }
        if ($nextRunAt > $now) {
            return [
                'due' => false,
                'reason' => 'schedule_not_due',
                'next_run_at' => $nextRunAt,
            ];
        }
        return ['due' => true, 'reason' => null, 'next_run_at' => $now];
    }
}
