<?php

namespace TypechoPlugin\FriendLinks\Infrastructure;

use Typecho\Db;

final class Repositories
{
    /** @var Database */
    private $database;

    /** @var Db */
    private $db;

    public function __construct(?Database $database = null)
    {
        $this->database = $database ?: new Database();
        $this->db = $this->database->native();
    }

    public function transaction(callable $callback)
    {
        return $this->database->transaction($callback);
    }

    public function categories(bool $publicOnly = false): array
    {
        $query = $this->db->select()->from('table.flm_categories')
            ->order('sort_order', Db::SORT_ASC)
            ->order('name', Db::SORT_ASC);
        if ($publicOnly) {
            $query->where('enabled = ?', 1);
        }
        return $this->database->inTransaction()
            ? $this->database->fetchAllWrite($query)
            : $this->db->fetchAll($query);
    }

    public function category(int $id): ?array
    {
        $query = $this->db->select()->from('table.flm_categories')
            ->where('id = ?', $id)->limit(1);
        return $this->database->inTransaction()
            ? $this->database->fetchRowWrite($query)
            : $this->db->fetchRow($query);
    }

    public function saveCategory(array $row, int $id = 0): int
    {
        if ($id > 0) {
            $this->db->query($this->db->update('table.flm_categories')->rows($row)->where('id = ?', $id));
            return $id;
        }
        return (int) $this->db->query($this->db->insert('table.flm_categories')->rows($row));
    }

    public function deleteCategory(int $id): void
    {
        $this->database->transaction(function () use ($id) {
            $this->db->query($this->db->update('table.flm_links')
                ->rows(['category_id' => null, 'updated_at' => time()])
                ->where('category_id = ?', $id));
            $this->db->query($this->db->delete('table.flm_categories')->where('id = ?', $id));
        });
    }

    public function links(array $filters = [], int $limit = 200): array
    {
        $query = $this->db->select(
            'table.flm_links.*',
            'table.flm_categories.name AS category_name',
            'table.flm_current_status.overall_state',
            'table.flm_current_status.reason_code',
            'table.flm_current_status.checked_at',
            'table.flm_current_status.next_check_at'
        )->from('table.flm_links')
            ->join('table.flm_categories', 'table.flm_links.category_id = table.flm_categories.id', Db::LEFT_JOIN)
            ->join('table.flm_current_status', 'table.flm_links.id = table.flm_current_status.link_id', Db::LEFT_JOIN)
            ->order('table.flm_links.sort_order', Db::SORT_ASC)
            ->order('table.flm_links.id', Db::SORT_ASC)
            ->limit(max(1, min(500, $limit)));

        if (!empty($filters['visibility'])) {
            $query->where('table.flm_links.visibility = ?', $filters['visibility']);
        }
        if (!empty($filters['category_id'])) {
            $query->where('table.flm_links.category_id = ?', (int) $filters['category_id']);
        }
        if (!empty($filters['state'])) {
            $query->where('table.flm_current_status.overall_state = ?', $filters['state']);
        }
        if (!empty($filters['keywords'])) {
            $keyword = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['keywords']) . '%';
            $query->where(
                'table.flm_links.name LIKE ? OR table.flm_links.url LIKE ? OR table.flm_links.description LIKE ?',
                $keyword,
                $keyword,
                $keyword
            );
        }

        return $this->db->fetchAll($query);
    }

    public function exportLinks(): array
    {
        return $this->db->fetchAll($this->db->select(
            'table.flm_links.*',
            'table.flm_categories.name AS category_name'
        )->from('table.flm_links')
            ->join('table.flm_categories', 'table.flm_links.category_id = table.flm_categories.id', Db::LEFT_JOIN)
            ->order('table.flm_links.sort_order', Db::SORT_ASC)
            ->order('table.flm_links.id', Db::SORT_ASC));
    }

    public function frontendLinks(): array
    {
        return $this->db->fetchAll($this->db->select(
            'table.flm_links.id',
            'table.flm_links.name',
            'table.flm_links.url',
            'table.flm_links.description',
            'table.flm_links.logo_url',
            'table.flm_links.sort_order',
            'table.flm_categories.name AS category_name',
            'table.flm_categories.slug AS category_slug',
            'table.flm_current_status.overall_state',
            'table.flm_current_status.reason_code',
            'table.flm_current_status.checked_at'
        )->from('table.flm_links')
            ->join('table.flm_categories', 'table.flm_links.category_id = table.flm_categories.id', Db::LEFT_JOIN)
            ->join('table.flm_current_status', 'table.flm_links.id = table.flm_current_status.link_id', Db::LEFT_JOIN)
            ->where('table.flm_links.visibility = ?', 'published')
            ->where('table.flm_categories.id IS NULL OR table.flm_categories.enabled = ?', 1)
            ->order('table.flm_categories.sort_order', Db::SORT_ASC)
            ->order('table.flm_links.sort_order', Db::SORT_ASC)
            ->order('table.flm_links.id', Db::SORT_ASC));
    }

    public function link(int $id): ?array
    {
        return $this->db->fetchRow($this->db->select(
            'table.flm_links.*',
            'table.flm_current_status.overall_state',
            'table.flm_current_status.reason_code',
            'table.flm_current_status.checked_at',
            'table.flm_current_status.details_json'
        )->from('table.flm_links')
            ->join('table.flm_current_status', 'table.flm_links.id = table.flm_current_status.link_id', Db::LEFT_JOIN)
            ->where('table.flm_links.id = ?', $id)
            ->limit(1));
    }

    public function findByHash(string $hash, int $excludeId = 0): ?array
    {
        $query = $this->db->select('id')->from('table.flm_links')->where('url_hash = ?', $hash);
        if ($excludeId > 0) {
            $query->where('id <> ?', $excludeId);
        }
        $query->limit(1);
        return $this->database->inTransaction()
            ? $this->database->fetchRowWrite($query)
            : $this->db->fetchRow($query);
    }

    public function createLink(array $row): int
    {
        return $this->database->transaction(function () use ($row) {
            $id = (int) $this->db->query($this->db->insert('table.flm_links')->rows($row));
            $state = !empty($row['check_enabled']) && 'published' === $row['visibility']
                ? 'pending'
                : 'disabled';
            $this->db->query($this->db->insert('table.flm_current_status')->rows([
                'link_id' => $id,
                'overall_state' => $state,
                'availability_consecutive_failures' => 0,
                'dns_next_check_at' => 0,
                'http_next_check_at' => 0,
                'tls_next_check_at' => 0,
                'domain_next_check_at' => 0,
                'next_check_at' => 0,
                'state_changed_at' => time(),
            ]));
            return $id;
        });
    }

    public function updateLink(int $id, array $row, bool $resetStatus = false): void
    {
        $this->database->transaction(function () use ($id, $row, $resetStatus) {
            $this->db->query($this->db->update('table.flm_links')->rows($row)->where('id = ?', $id));

            if (empty($row['check_enabled']) || 'published' !== $row['visibility']) {
                $this->db->query($this->db->update('table.flm_current_status')->rows([
                    'overall_state' => 'disabled',
                    'reason_code' => null,
                    'lease_token' => null,
                    'lease_until' => null,
                    'state_changed_at' => time(),
                ])->where('link_id = ?', $id));
            } elseif ($resetStatus) {
                $this->db->query($this->db->update('table.flm_current_status')->rows([
                    'overall_state' => 'pending',
                    'reason_code' => null,
                    'http_state' => null,
                    'http_code' => null,
                    'response_time_ms' => null,
                    'final_url' => null,
                    'dns_state' => null,
                    'tls_state' => null,
                    'cert_not_after' => null,
                    'domain_state' => null,
                    'domain_expires_at' => null,
                    'availability_consecutive_failures' => 0,
                    'checked_at' => null,
                    'dns_checked_at' => null,
                    'http_checked_at' => null,
                    'tls_checked_at' => null,
                    'domain_checked_at' => null,
                    'dns_next_check_at' => 0,
                    'http_next_check_at' => 0,
                    'tls_next_check_at' => 0,
                    'domain_next_check_at' => 0,
                    'last_success_at' => null,
                    'last_failure_at' => null,
                    'state_changed_at' => time(),
                    'next_check_at' => 0,
                    'lease_token' => null,
                    'lease_until' => null,
                    'details_json' => null,
                ])->where('link_id = ?', $id));
            } else {
                $this->db->query($this->db->update('table.flm_current_status')
                    ->rows(['next_check_at' => 0])
                    ->where('link_id = ?', $id));
            }
        });
    }

    public function archiveLinks(array $ids): int
    {
        $count = 0;
        foreach (array_slice(array_unique(array_map('intval', $ids)), 0, 100) as $id) {
            if ($id < 1) {
                continue;
            }
            $count += (int) $this->db->query($this->db->update('table.flm_links')->rows([
                'visibility' => 'archived',
                'check_enabled' => 0,
                'updated_at' => time(),
            ])->where('id = ?', $id));
            $this->db->query($this->db->update('table.flm_current_status')->rows([
                'overall_state' => 'disabled',
                'lease_token' => null,
                'lease_until' => null,
                'state_changed_at' => time(),
            ])->where('link_id = ?', $id));
        }
        return $count;
    }

    public function deleteLink(int $id): bool
    {
        if ($id < 1) {
            return false;
        }

        return $this->database->transaction(function () use ($id) {
            if (!$this->database->fetchRowWrite(
                $this->db->select('id')->from('table.flm_links')->where('id = ?', $id)->limit(1)
            )) {
                return false;
            }

            $this->db->query($this->db->delete('table.flm_notification_outbox')->where('link_id = ?', $id));
            $this->db->query($this->db->delete('table.flm_check_history')->where('link_id = ?', $id));
            $this->db->query($this->db->delete('table.flm_current_status')->where('link_id = ?', $id));
            $this->db->query($this->db->delete('table.flm_links')->where('id = ?', $id));
            return true;
        });
    }

    public function schedule(array $ids, bool $full = false): int
    {
        $count = 0;
        foreach (array_slice(array_unique(array_map('intval', $ids)), 0, 100) as $id) {
            if ($id < 1) {
                continue;
            }
            $rows = [
                'dns_next_check_at' => 0,
                'http_next_check_at' => 0,
                'tls_next_check_at' => 0,
                'next_check_at' => 0,
            ];
            if ($full) {
                $rows['domain_next_check_at'] = 0;
            }
            $count += (int) $this->db->query($this->db->update('table.flm_current_status')
                ->rows($rows)->where('link_id = ?', $id));
        }
        return $count;
    }

    public function dueCandidates(int $now, int $limit): array
    {
        return $this->db->fetchAll($this->db->select('table.flm_current_status.link_id')
            ->from('table.flm_current_status')
            ->join('table.flm_links', 'table.flm_current_status.link_id = table.flm_links.id', Db::INNER_JOIN)
            ->where('table.flm_links.visibility = ?', 'published')
            ->where('table.flm_links.check_enabled = ?', 1)
            ->where('table.flm_current_status.next_check_at <= ?', $now)
            ->where('table.flm_current_status.lease_until IS NULL OR table.flm_current_status.lease_until < ?', $now)
            ->order('table.flm_current_status.next_check_at', Db::SORT_ASC)
            ->limit(max(1, min(500, $limit))));
    }

    public function claim(int $linkId, string $token, int $now, int $leaseUntil): bool
    {
        $affected = $this->db->query($this->db->update('table.flm_current_status')->rows([
            'lease_token' => $token,
            'lease_until' => $leaseUntil,
        ])->where('link_id = ?', $linkId)
            ->where('next_check_at <= ?', $now)
            ->where('lease_until IS NULL OR lease_until < ?', $now));

        return 1 === $affected;
    }

    public function claimedLink(int $linkId, string $token): ?array
    {
        return $this->database->fetchRowWrite($this->db->select(
            'table.flm_links.*',
            'table.flm_current_status.overall_state',
            'table.flm_current_status.reason_code',
            'table.flm_current_status.availability_consecutive_failures',
            'table.flm_current_status.checked_at',
            'table.flm_current_status.dns_checked_at',
            'table.flm_current_status.http_checked_at',
            'table.flm_current_status.tls_checked_at',
            'table.flm_current_status.domain_checked_at',
            'table.flm_current_status.dns_next_check_at',
            'table.flm_current_status.http_next_check_at',
            'table.flm_current_status.tls_next_check_at',
            'table.flm_current_status.domain_next_check_at',
            'table.flm_current_status.last_success_at',
            'table.flm_current_status.last_failure_at',
            'table.flm_current_status.state_changed_at',
            'table.flm_current_status.lease_until',
            'table.flm_current_status.details_json'
        )->from('table.flm_links')
            ->join('table.flm_current_status', 'table.flm_links.id = table.flm_current_status.link_id', Db::INNER_JOIN)
            ->where('table.flm_links.id = ?', $linkId)
            ->where('table.flm_current_status.lease_token = ?', $token)
            ->limit(1));
    }

    public function persistResult(
        int $linkId,
        string $token,
        int $now,
        array $statusRows,
        array $history,
        array $notifications = []
    ): void {
        $this->database->transaction(function () use (
            $linkId,
            $token,
            $now,
            $statusRows,
            $history,
            $notifications
        ) {
            $statusRows['lease_token'] = null;
            $statusRows['lease_until'] = null;
            $affected = $this->db->query($this->db->update('table.flm_current_status')
                ->rows($statusRows)
                ->where('link_id = ?', $linkId)
                ->where('lease_token = ?', $token)
                ->where('lease_until >= ?', $now));
            if (1 !== $affected) {
                throw new \RuntimeException('检测租约已过期，结果已丢弃。');
            }
            $this->db->query($this->db->insert('table.flm_check_history')->rows($history));
            $this->insertNotifications($notifications);
        });
    }

    public function releaseLease(int $linkId, string $token, int $nextCheckAt): void
    {
        $this->db->query($this->db->update('table.flm_current_status')->rows([
            'lease_token' => null,
            'lease_until' => null,
            'next_check_at' => $nextCheckAt,
        ])->where('link_id = ?', $linkId)->where('lease_token = ?', $token));
    }

    public function renewLease(int $linkId, string $token, int $now, int $leaseUntil): bool
    {
        return 1 === $this->db->query($this->db->update('table.flm_current_status')->rows([
            'lease_until' => $leaseUntil,
        ])->where('link_id = ?', $linkId)
            ->where('lease_token = ?', $token)
            ->where('lease_until >= ?', $now));
    }

    public function createRun(string $runId, string $mode, int $now): void
    {
        $this->db->query($this->db->insert('table.flm_runs')->rows([
            'run_id' => $runId,
            'mode' => $mode,
            'status' => 'running',
            'started_at' => $now,
            'heartbeat_at' => $now,
            'claimed_count' => 0,
            'completed_count' => 0,
            'failed_count' => 0,
        ]));
    }

    public function updateRun(string $runId, array $rows): void
    {
        $this->db->query($this->db->update('table.flm_runs')->rows($rows)->where('run_id = ?', $runId));
    }

    public function enqueueNotifications(array $rows): void
    {
        $this->database->transaction(function () use ($rows) {
            $this->insertNotifications($rows);
        });
    }

    public function expireExhaustedNotifications(int $now): int
    {
        return (int) $this->db->query($this->db->update('table.flm_notification_outbox')->rows([
            'status' => 'failed',
            'lease_token' => null,
            'lease_until' => null,
            'available_at' => $now,
            'last_error' => '投递进程在最后一次尝试中断，已停止自动重试。',
        ])->where('status = ?', 'sending')
            ->where('attempts >= ?', 5)
            ->where('lease_until < ?', $now));
    }

    public function dueNotifications(int $now, int $limit = 20): array
    {
        return $this->db->fetchAll($this->db->select('id', 'channel')
            ->from('table.flm_notification_outbox')
            ->where('status = ? OR status = ? OR status = ?', 'pending', 'failed', 'sending')
            ->where('attempts < ?', 5)
            ->where('available_at <= ?', $now)
            ->where('lease_until IS NULL OR lease_until < ?', $now)
            ->order('available_at', Db::SORT_ASC)
            ->order('id', Db::SORT_ASC)
            ->limit(max(1, min(100, $limit))));
    }

    public function claimNotification(int $id, string $token, int $now, int $leaseUntil): bool
    {
        $query = $this->db->update('table.flm_notification_outbox')->rows([
            'status' => 'sending',
            'lease_token' => $token,
            'lease_until' => $leaseUntil,
        ])->expression('attempts', 'attempts + 1', false)
            ->where('id = ?', $id)
            ->where('status = ? OR status = ? OR status = ?', 'pending', 'failed', 'sending')
            ->where('attempts < ?', 5)
            ->where('available_at <= ?', $now)
            ->where('lease_until IS NULL OR lease_until < ?', $now);
        $affected = $this->db->query($query);

        return 1 === $affected;
    }

    public function claimedNotification(int $id, string $token): ?array
    {
        return $this->database->fetchRowWrite($this->db->select()
            ->from('table.flm_notification_outbox')
            ->where('id = ?', $id)
            ->where('status = ?', 'sending')
            ->where('lease_token = ?', $token)
            ->limit(1));
    }

    public function markNotificationSent(int $id, string $token, int $sentAt): void
    {
        $affected = $this->db->query($this->db->update('table.flm_notification_outbox')->rows([
            'status' => 'sent',
            'lease_token' => null,
            'lease_until' => null,
            'last_error' => null,
            'sent_at' => $sentAt,
        ])->where('id = ?', $id)->where('lease_token = ?', $token));
        if (1 !== $affected) {
            throw new \RuntimeException('通知投递租约已过期，成功结果未写入。');
        }
    }

    public function markNotificationFailed(
        int $id,
        string $token,
        int $availableAt,
        string $error,
        bool $terminal = false
    ): void {
        $query = $this->db->update('table.flm_notification_outbox')->rows([
            'status' => 'failed',
            'available_at' => $availableAt,
            'lease_token' => null,
            'lease_until' => null,
            'last_error' => substr($error, 0, 500),
        ]);
        if ($terminal) {
            $query->expression('attempts', '5', false);
        }
        $this->db->query($query->where('id = ?', $id)->where('lease_token = ?', $token));
    }

    public function notifications(int $limit = 50): array
    {
        return $this->db->fetchAll($this->db->select(
            'table.flm_notification_outbox.*',
            'table.flm_links.name AS link_name'
        )->from('table.flm_notification_outbox')
            ->join(
                'table.flm_links',
                'table.flm_notification_outbox.link_id = table.flm_links.id',
                Db::LEFT_JOIN
            )
            ->order('table.flm_notification_outbox.created_at', Db::SORT_DESC)
            ->order('table.flm_notification_outbox.id', Db::SORT_DESC)
            ->limit(max(1, min(200, $limit))));
    }

    public function notificationCounts(): array
    {
        $rows = $this->db->fetchAll($this->db->select('status', 'COUNT(*) AS total')
            ->from('table.flm_notification_outbox')
            ->group('status'));
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }
        return $counts;
    }

    public function retryNotification(int $id): bool
    {
        return 1 === $this->db->query($this->db->update('table.flm_notification_outbox')->rows([
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => time(),
            'lease_token' => null,
            'lease_until' => null,
            'last_error' => null,
            'sent_at' => null,
        ])->where('id = ?', $id)->where('status = ?', 'failed'));
    }

    public function statusCounts(): array
    {
        $rows = $this->db->fetchAll($this->db->select('overall_state', 'COUNT(*) AS total')
            ->from('table.flm_current_status')->group('overall_state'));
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['overall_state']] = (int) $row['total'];
        }
        return $counts;
    }

    public function backlog(int $now): array
    {
        $due = $this->db->fetchRow($this->db->select('COUNT(*) AS total')
            ->from('table.flm_current_status')->where('next_check_at <= ?', $now));
        $leased = $this->db->fetchRow($this->db->select('COUNT(*) AS total')
            ->from('table.flm_current_status')->where('lease_until >= ?', $now));
        return ['due' => (int) ($due['total'] ?? 0), 'leased' => (int) ($leased['total'] ?? 0)];
    }

    public function latestRuns(int $limit = 20): array
    {
        return $this->db->fetchAll($this->db->select()->from('table.flm_runs')
            ->order('started_at', Db::SORT_DESC)->limit(max(1, min(100, $limit))));
    }

    public function history(int $limit = 100, int $linkId = 0): array
    {
        $query = $this->db->select(
            'table.flm_check_history.*',
            'table.flm_links.name AS link_name'
        )->from('table.flm_check_history')
            ->join('table.flm_links', 'table.flm_check_history.link_id = table.flm_links.id', Db::LEFT_JOIN)
            ->order('table.flm_check_history.started_at', Db::SORT_DESC)
            ->limit(max(1, min(500, $limit)));
        if ($linkId > 0) {
            $query->where('table.flm_check_history.link_id = ?', $linkId);
        }
        return $this->db->fetchAll($query);
    }

    public function cacheGet(string $namespace, string $key, int $now): ?string
    {
        $hash = hash('sha256', $namespace . ':' . $key);
        $row = $this->db->fetchRow($this->db->select('payload')->from('table.flm_cache')
            ->where('cache_key = ?', $hash)
            ->where('namespace = ?', $namespace)
            ->where('expires_at > ?', $now)
            ->limit(1));
        return $row ? (string) $row['payload'] : null;
    }

    public function cachePut(string $namespace, string $key, string $payload, int $expiresAt): void
    {
        $hash = hash('sha256', $namespace . ':' . $key);
        $rows = [
            'namespace' => $namespace,
            'payload' => $payload,
            'expires_at' => $expiresAt,
            'updated_at' => time(),
        ];
        $updated = $this->db->query($this->db->update('table.flm_cache')
            ->rows($rows)->where('cache_key = ?', $hash));
        if (0 === $updated) {
            try {
                $rows['cache_key'] = $hash;
                $this->db->query($this->db->insert('table.flm_cache')->rows($rows));
            } catch (\Throwable $error) {
                unset($rows['cache_key']);
                $this->db->query($this->db->update('table.flm_cache')
                    ->rows($rows)->where('cache_key = ?', $hash));
            }
        }
    }

    public function consumeNonce(string $nonce, int $expiresAt): bool
    {
        $hash = hash('sha256', 'nonce:' . $nonce);
        try {
            $this->db->query($this->db->insert('table.flm_cache')->rows([
                'cache_key' => $hash,
                'namespace' => 'nonce',
                'payload' => '',
                'expires_at' => $expiresAt,
                'updated_at' => time(),
            ]));
            return true;
        } catch (\Throwable $error) {
            return false;
        }
    }

    public function cleanup(int $historyBefore, int $now, int $batch = 500): array
    {
        $historyRows = $this->db->fetchAll($this->db->select('id')->from('table.flm_check_history')
            ->where('started_at < ?', $historyBefore)->limit($batch));
        $historyDeleted = 0;
        foreach ($historyRows as $row) {
            $historyDeleted += (int) $this->db->query(
                $this->db->delete('table.flm_check_history')->where('id = ?', (int) $row['id'])
            );
        }

        $cacheRows = $this->db->fetchAll($this->db->select('cache_key')->from('table.flm_cache')
            ->where('expires_at < ?', $now)->limit($batch));
        $cacheDeleted = 0;
        foreach ($cacheRows as $row) {
            $cacheDeleted += (int) $this->db->query(
                $this->db->delete('table.flm_cache')->where('cache_key = ?', $row['cache_key'])
            );
        }

        $notificationRows = $this->db->fetchAll($this->db->select('id')
            ->from('table.flm_notification_outbox')
            ->where('created_at < ?', $historyBefore)
            ->where('status = ? OR attempts >= ?', 'sent', 5)
            ->limit($batch));
        $notificationsDeleted = 0;
        foreach ($notificationRows as $row) {
            $notificationsDeleted += (int) $this->db->query(
                $this->db->delete('table.flm_notification_outbox')->where('id = ?', (int) $row['id'])
            );
        }
        return [
            'history' => $historyDeleted,
            'cache' => $cacheDeleted,
            'notifications' => $notificationsDeleted,
        ];
    }

    public function publishedPages(): array
    {
        return $this->db->fetchAll($this->db->select('cid', 'title', 'slug', 'template', 'password')
            ->from('table.contents')
            ->where('type = ?', 'page')
            ->where('status = ?', 'publish')
            ->order('order', Db::SORT_ASC));
    }

    private function insertNotifications(array $notifications): void
    {
        foreach ($notifications as $notification) {
            $cooldown = max(0, (int) ($notification['_cooldown'] ?? 0));
            unset($notification['_cooldown']);
            if ($cooldown > 0) {
                $existing = $this->database->fetchRowWrite($this->db->select('id')
                    ->from('table.flm_notification_outbox')
                    ->where('link_id = ?', (int) $notification['link_id'])
                    ->where('event_type = ?', (string) $notification['event_type'])
                    ->where('channel = ?', (string) $notification['channel'])
                    ->where('created_at >= ?', (int) $notification['created_at'] - $cooldown)
                    ->limit(1));
                if ($existing) {
                    continue;
                }
            }
            $duplicate = $this->database->fetchRowWrite($this->db->select('id')
                ->from('table.flm_notification_outbox')
                ->where('event_key = ?', (string) $notification['event_key'])
                ->where('channel = ?', (string) $notification['channel'])
                ->limit(1));
            if (!$duplicate) {
                $this->db->query($this->db->insert('table.flm_notification_outbox')->rows($notification));
            }
        }
    }
}
