<?php

namespace TypechoPlugin\FriendLinks\Infrastructure;

final class WebhookNotificationChannel implements NotificationChannelInterface
{
    /** @var SafeHttpClient */
    private $http;

    /** @var WebhookSigner */
    private $signer;

    public function __construct(?SafeHttpClient $http = null, ?WebhookSigner $signer = null)
    {
        $this->http = $http ?: new SafeHttpClient();
        $this->signer = $signer ?: new WebhookSigner();
    }

    public function send(array $notification, array $settings, ?float $deadline = null): void
    {
        $body = (string) ($notification['payload_json'] ?? '');
        if (!is_array(json_decode($body, true))) {
            throw new \RuntimeException('Webhook 通知负载无效。');
        }

        $timestamp = (string) time();
        $headers = ['X-FriendLinks-Timestamp: ' . $timestamp];
        $secret = (string) ($settings['webhook_secret'] ?? '');
        if ('' !== $secret) {
            $headers[] = 'X-FriendLinks-Signature: sha256='
                . $this->signer->sign($secret, $timestamp, $body);
        }

        $response = $this->http->postJson(
            (string) ($settings['webhook_url'] ?? ''),
            $body,
            $headers,
            (int) ($settings['connect_timeout'] ?? 3),
            $this->requestTimeout($settings, $deadline)
        );
        $status = (int) ($response['status'] ?? 0);
        if (empty($response['ok']) || $status < 200 || $status > 299) {
            throw new \RuntimeException('通用 Webhook 返回异常：HTTP ' . $status . '。');
        }
    }

    private function requestTimeout(array $settings, ?float $deadline): int
    {
        $configured = max(2, (int) ($settings['request_timeout'] ?? 10));
        if (null === $deadline) {
            return $configured;
        }
        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining < 2) {
            throw new \RuntimeException('通知投递时间预算不足。');
        }
        return min($configured, $remaining);
    }
}
