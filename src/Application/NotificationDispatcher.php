<?php

namespace TypechoPlugin\FriendLinks\Application;

use TypechoPlugin\FriendLinks\Domain\NotificationTemplate;
use TypechoPlugin\FriendLinks\Infrastructure\DingTalkNotificationChannel;
use TypechoPlugin\FriendLinks\Infrastructure\EmailNotificationChannel;
use TypechoPlugin\FriendLinks\Infrastructure\NotificationChannelInterface;
use TypechoPlugin\FriendLinks\Infrastructure\Repositories;
use TypechoPlugin\FriendLinks\Infrastructure\WebhookNotificationChannel;

final class NotificationDispatcher
{
    /** @var Repositories */
    private $repositories;

    /** @var NotificationChannelInterface[] */
    private $channels;

    public function __construct(?Repositories $repositories = null, array $channels = [])
    {
        $this->repositories = $repositories ?: new Repositories();
        $this->channels = $channels ?: [
            'webhook' => new WebhookNotificationChannel(),
            'dingtalk' => new DingTalkNotificationChannel(),
            'email' => new EmailNotificationChannel(),
        ];
    }

    public function dispatch(int $limit = 20, ?float $deadline = null): array
    {
        $settings = Settings::all();
        $counts = ['claimed' => 0, 'sent' => 0, 'failed' => 0];
        $errors = [];
        $this->repositories->expireExhaustedNotifications(time());
        if (empty($settings['notifications_enabled'])) {
            return $counts + ['errors' => []];
        }

        foreach ($this->repositories->dueNotifications(time(), $limit) as $candidate) {
            $channelName = (string) ($candidate['channel'] ?? '');
            if (!$this->hasTimeBudget($channelName, $settings, $deadline)) {
                continue;
            }
            $id = (int) $candidate['id'];
            $token = bin2hex(random_bytes(16));
            $now = time();
            if (!$this->repositories->claimNotification($id, $token, $now, $now + 1800)) {
                continue;
            }
            $counts['claimed']++;
            $notification = $this->repositories->claimedNotification($id, $token);
            if (!$notification) {
                continue;
            }

            $attempts = (int) $notification['attempts'];
            try {
                $channel = (string) $notification['channel'];
                if (empty($settings[$channel . '_enabled'])) {
                    throw new \RuntimeException('通知渠道已停用。');
                }
                $this->channel($channel)->send($notification, $settings, $deadline);
                $this->repositories->markNotificationSent($id, $token, time());
                $counts['sent']++;
            } catch (\Throwable $error) {
                $counts['failed']++;
                $message = $this->summarize($error->getMessage());
                $errors[] = $message;
                $terminal = $attempts >= 5 || '通知渠道已停用。' === $message;
                $availableAt = $terminal
                    ? time() + 86400
                    : time() + min(21600, 300 * (2 ** max(0, $attempts - 1)));
                $this->repositories->markNotificationFailed(
                    $id,
                    $token,
                    $availableAt,
                    $message,
                    $terminal
                );
            }
        }

        return $counts + ['errors' => array_values(array_unique($errors))];
    }

    public function sendTest(string $channel, array $settings): void
    {
        if (empty($settings[$channel . '_enabled'])) {
            throw new \InvalidArgumentException('请先启用并保存该通知渠道。');
        }

        $now = time();
        $context = [
            'event_name' => '测试通知',
            'link_name' => 'FriendLinks 测试站点',
            'link_url' => 'https://example.com/',
            'previous_state' => '状态未知',
            'current_state' => '正常',
            'status_summary' => '正常 · 通知渠道配置有效',
            'reason' => '通知渠道配置有效',
            'reason_code' => 'test',
            'http_code' => '200',
            'response_time_ms' => '128',
            'checked_at' => date('Y-m-d H:i:s', $now),
            'cert_expires_at' => date('Y-m-d H:i:s', $now + 90 * 86400),
            'domain_expires_at' => date('Y-m-d H:i:s', $now + 365 * 86400),
        ];
        $subject = NotificationTemplate::render(
            (string) $settings['notification_subject_template'],
            $context,
            true
        );
        $message = NotificationTemplate::render(
            (string) $settings['notification_message_template'],
            $context
        );
        $payload = json_encode([
            'event_id' => hash('sha256', 'test|' . $channel . '|' . $now),
            'event' => 'test',
            'occurred_at' => $now,
            'link' => ['id' => 0, 'name' => $context['link_name'], 'url' => $context['link_url']],
            'status' => [
                'previous' => 'unknown',
                'current' => 'healthy',
                'reason_code' => 'test',
                'http_code' => 200,
                'response_time_ms' => 128,
                'checked_at' => $now,
            ],
            'subject' => $subject,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (false === $payload) {
            throw new \RuntimeException('测试通知序列化失败。');
        }

        $this->channel($channel)->send([
            'channel' => $channel,
            'subject' => $subject,
            'message' => $message,
            'payload_json' => $payload,
        ], $settings);
    }

    private function channel(string $channel): NotificationChannelInterface
    {
        if (!isset($this->channels[$channel]) || !$this->channels[$channel] instanceof NotificationChannelInterface) {
            throw new \RuntimeException('未知通知渠道。');
        }
        return $this->channels[$channel];
    }

    private function hasTimeBudget(string $channel, array $settings, ?float $deadline): bool
    {
        if (null === $deadline) {
            return true;
        }
        $remaining = (int) floor($deadline - microtime(true));
        if ('email' !== $channel) {
            return $remaining >= 2;
        }
        $recipients = preg_split(
            '/\s*,\s*/',
            trim((string) ($settings['email_recipients'] ?? '')),
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        return $remaining > count($recipients ?: []) + 16;
    }

    private function summarize(string $message): string
    {
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message);
        return substr(trim((string) $message), 0, 500);
    }
}
