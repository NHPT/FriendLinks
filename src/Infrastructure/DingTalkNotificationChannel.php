<?php

namespace TypechoPlugin\FriendLinks\Infrastructure;

final class DingTalkNotificationChannel implements NotificationChannelInterface
{
    /** @var SafeHttpClient */
    private $http;

    /** @var DingTalkSigner */
    private $signer;

    public function __construct(?SafeHttpClient $http = null, ?DingTalkSigner $signer = null)
    {
        $this->http = $http ?: new SafeHttpClient();
        $this->signer = $signer ?: new DingTalkSigner();
    }

    public function send(array $notification, array $settings, ?float $deadline = null): void
    {
        $url = (string) ($settings['dingtalk_webhook_url'] ?? '');
        $secret = (string) ($settings['dingtalk_secret'] ?? '');
        if ('' !== $secret) {
            $timestamp = (string) ((int) floor(microtime(true) * 1000));
            $signature = $this->signer->sign($secret, $timestamp);
            $url .= (false === strpos($url, '?') ? '?' : '&')
                . 'timestamp=' . rawurlencode($timestamp)
                . '&sign=' . rawurlencode($signature);
        }

        $body = json_encode([
            'msgtype' => 'text',
            'text' => [
                'content' => trim(
                    (string) ($notification['subject'] ?? '')
                    . "\n"
                    . (string) ($notification['message'] ?? '')
                ),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (false === $body) {
            throw new \RuntimeException('钉钉通知负载序列化失败。');
        }

        $response = $this->http->postJson(
            $url,
            $body,
            [],
            (int) ($settings['connect_timeout'] ?? 3),
            $this->requestTimeout($settings, $deadline)
        );
        $status = (int) ($response['status'] ?? 0);
        if (empty($response['ok']) || $status < 200 || $status > 299) {
            throw new \RuntimeException('钉钉机器人返回异常：HTTP ' . $status . '。');
        }

        $result = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($result) || 0 !== (int) ($result['errcode'] ?? -1)) {
            throw new \RuntimeException('钉钉机器人拒绝了通知。');
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
