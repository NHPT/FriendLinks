<?php

namespace TypechoPlugin\FriendLinks\Application;

use TypechoPlugin\FriendLinks\Domain\NotificationTemplate;
use TypechoPlugin\FriendLinks\Presentation\StatusLabels;

final class NotificationPlanner
{
    public function plan(
        array $link,
        array $statusRows,
        array $settings,
        string $runId,
        int $occurredAt
    ): array {
        if (empty($settings['notifications_enabled'])) {
            return [];
        }

        $eventType = $this->eventType($link, $statusRows, $settings);
        if (null === $eventType) {
            return [];
        }

        $channels = $this->enabledChannels($settings);
        if (!$channels) {
            return [];
        }

        $eventKey = hash('sha256', implode('|', [
            $runId,
            (string) ($link['id'] ?? 0),
            $eventType,
            (string) ($statusRows['overall_state'] ?? ''),
            (string) ($statusRows['reason_code'] ?? ''),
        ]));
        $context = $this->context($eventType, $link, $statusRows, $occurredAt);
        $subject = NotificationTemplate::render(
            (string) $settings['notification_subject_template'],
            $context,
            true
        );
        $message = NotificationTemplate::render(
            (string) $settings['notification_message_template'],
            $context
        );
        $payload = [
            'event_id' => $eventKey,
            'event' => $eventType,
            'occurred_at' => $occurredAt,
            'link' => [
                'id' => (int) ($link['id'] ?? 0),
                'name' => (string) ($link['name'] ?? ''),
                'url' => (string) ($link['url'] ?? ''),
            ],
            'status' => [
                'previous' => (string) ($link['overall_state'] ?? 'pending'),
                'current' => (string) ($statusRows['overall_state'] ?? 'unknown'),
                'reason_code' => $statusRows['reason_code'] ?? null,
                'http_code' => $statusRows['http_code'] ?? null,
                'response_time_ms' => $statusRows['response_time_ms'] ?? null,
                'checked_at' => (int) ($statusRows['checked_at'] ?? $occurredAt),
            ],
            'subject' => $subject,
            'message' => $message,
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (false === $payloadJson) {
            throw new \RuntimeException('通知事件序列化失败。');
        }

        $rows = [];
        foreach ($channels as $channel) {
            $rows[] = [
                'event_key' => $eventKey,
                'link_id' => (int) ($link['id'] ?? 0),
                'event_type' => $eventType,
                'channel' => $channel,
                'subject' => $subject,
                'message' => $message,
                'payload_json' => $payloadJson,
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => $occurredAt,
                'lease_token' => null,
                'lease_until' => null,
                'last_error' => null,
                'created_at' => $occurredAt,
                'sent_at' => null,
                '_cooldown' => (int) ($settings['notification_cooldown'] ?? 3600),
            ];
        }
        return $rows;
    }

    private function eventType(array $link, array $statusRows, array $settings): ?string
    {
        $previous = (string) ($link['overall_state'] ?? 'pending');
        $current = (string) ($statusRows['overall_state'] ?? 'unknown');
        $reasonChanged = (string) ($link['reason_code'] ?? '') !== (string) ($statusRows['reason_code'] ?? '');

        if ('down' === $current && 'down' !== $previous && !empty($settings['notify_on_down'])) {
            return 'down';
        }
        if (
            'healthy' === $current
            && in_array($previous, ['warning', 'degraded', 'down'], true)
            && !empty($settings['notify_on_recovery'])
        ) {
            return 'recovery';
        }
        if (
            in_array($current, ['warning', 'degraded'], true)
            && ($current !== $previous || $reasonChanged)
            && !empty($settings['notify_on_warning'])
        ) {
            return 'warning';
        }

        return null;
    }

    private function enabledChannels(array $settings): array
    {
        $channels = [];
        foreach (['webhook', 'dingtalk', 'email'] as $channel) {
            if (!empty($settings[$channel . '_enabled'])) {
                $channels[] = $channel;
            }
        }
        return $channels;
    }

    private function context(string $eventType, array $link, array $statusRows, int $occurredAt): array
    {
        $previous = (string) ($link['overall_state'] ?? 'pending');
        $current = (string) ($statusRows['overall_state'] ?? 'unknown');
        $reasonCode = (string) ($statusRows['reason_code'] ?? '');
        $checkedAt = (int) ($statusRows['checked_at'] ?? $occurredAt);

        return [
            'event_name' => [
                'down' => '站点不可用',
                'recovery' => '站点已恢复',
                'warning' => '站点状态预警',
                'test' => '测试通知',
            ][$eventType] ?? '状态更新',
            'link_name' => (string) ($link['name'] ?? ''),
            'link_url' => (string) ($link['url'] ?? ''),
            'previous_state' => StatusLabels::state($previous),
            'current_state' => StatusLabels::state($current),
            'status_summary' => StatusLabels::summary($current, $reasonCode),
            'reason' => StatusLabels::reason($reasonCode) ?: '无',
            'reason_code' => $reasonCode ?: 'none',
            'http_code' => null === ($statusRows['http_code'] ?? null)
                ? '无'
                : (string) $statusRows['http_code'],
            'response_time_ms' => null === ($statusRows['response_time_ms'] ?? null)
                ? '无'
                : (string) $statusRows['response_time_ms'],
            'checked_at' => $this->formatTime($checkedAt),
            'cert_expires_at' => $this->formatTime((int) ($statusRows['cert_not_after'] ?? 0)),
            'domain_expires_at' => $this->formatTime((int) ($statusRows['domain_expires_at'] ?? 0)),
        ];
    }

    private function formatTime(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '无';
    }
}
