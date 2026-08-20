<?php

namespace TypechoPlugin\FriendLinks\Application;

use Typecho\Db;
use TypechoPlugin\FriendLinks\Domain\NotificationTemplate;
use TypechoPlugin\FriendLinks\Domain\UrlNormalizer;
use TypechoPlugin\FriendLinks\Infrastructure\Database;
use TypechoPlugin\FriendLinks\Presentation\TemplateCatalog;
use Widget\Options;
use Widget\Plugins\Edit;

final class Settings
{
    public static function defaults(): array
    {
        return [
            'page_cid' => 0,
            'frontend_template' => 'cards',
            'http_interval' => 21600,
            'tls_interval' => 86400,
            'domain_interval' => 86400,
            'connect_timeout' => 3,
            'request_timeout' => 10,
            'max_redirects' => 5,
            'failure_threshold' => 3,
            'history_days' => 90,
            'restricted_is_healthy' => 0,
            'show_expiration_warning' => 1,
            'rel_noreferrer' => 1,
            'rel_nofollow' => 0,
            'worker_secret' => bin2hex(random_bytes(32)),
            'debug_until' => 0,
            'notifications_enabled' => 0,
            'notify_on_down' => 1,
            'notify_on_recovery' => 1,
            'notify_on_warning' => 0,
            'notification_cooldown' => 3600,
            'webhook_enabled' => 0,
            'webhook_url' => '',
            'webhook_secret' => '',
            'dingtalk_enabled' => 0,
            'dingtalk_webhook_url' => '',
            'dingtalk_secret' => '',
            'email_enabled' => 0,
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_encryption' => 'starttls',
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_from_address' => '',
            'smtp_from_name' => 'FriendLinks',
            'email_recipients' => '',
            'notification_subject_template' => NotificationTemplate::DEFAULT_SUBJECT,
            'notification_message_template' => NotificationTemplate::DEFAULT_MESSAGE,
        ];
    }

    public static function all(): array
    {
        $defaults = self::defaults();
        try {
            $database = new Database();
            $db = $database->native();
            $row = $database->fetchRowWrite($db->select('value')->from('table.options')
                ->where('name = ?', 'plugin:FriendLinks')
                ->where('user = ?', 0)
                ->limit(1));
            $stored = $row ? unserialize((string) $row['value'], ['allowed_classes' => false]) : [];
            if (is_array($stored)) {
                foreach ($defaults as $key => $value) {
                    if (array_key_exists($key, $stored)) {
                        $defaults[$key] = $stored[$key];
                    }
                }
            }
        } catch (\Throwable $ignored) {
            try {
                $stored = Options::alloc()->plugin('FriendLinks');
                foreach ($defaults as $key => $value) {
                    if (isset($stored->{$key})) {
                        $defaults[$key] = $stored->{$key};
                    }
                }
            } catch (\Throwable $ignoredAgain) {
            }
        }

        return $defaults;
    }

    public static function get(string $key, $fallback = null)
    {
        $settings = self::all();
        return array_key_exists($key, $settings) ? $settings[$key] : $fallback;
    }

    public static function sanitize(array $input): array
    {
        $current = self::all();
        $integers = [
            'page_cid' => [0, PHP_INT_MAX],
            'http_interval' => [300, 604800],
            'tls_interval' => [3600, 2592000],
            'domain_interval' => [3600, 2592000],
            'connect_timeout' => [1, 30],
            'request_timeout' => [2, 60],
            'max_redirects' => [0, 10],
            'failure_threshold' => [1, 10],
            'history_days' => [30, 365],
        ];

        foreach ($integers as $key => $range) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = filter_var($input[$key], FILTER_VALIDATE_INT);
            if (false === $value || $value < $range[0] || $value > $range[1]) {
                throw new \InvalidArgumentException('设置项 ' . $key . ' 超出允许范围。');
            }
            $current[$key] = (int) $value;
        }

        foreach (['restricted_is_healthy', 'show_expiration_warning', 'rel_noreferrer', 'rel_nofollow'] as $key) {
            $current[$key] = empty($input[$key]) ? 0 : 1;
        }

        $template = (string) ($input['frontend_template'] ?? $current['frontend_template']);
        if (!(new TemplateCatalog())->exists($template)) {
            throw new \InvalidArgumentException('所选展示模板不存在或配置无效。');
        }
        $current['frontend_template'] = $template;

        self::assertPage((int) $current['page_cid']);
        return $current;
    }

    public static function sanitizeNotifications(array $input): array
    {
        $current = self::all();
        foreach ([
            'notifications_enabled',
            'notify_on_down',
            'notify_on_recovery',
            'notify_on_warning',
            'webhook_enabled',
            'dingtalk_enabled',
            'email_enabled',
        ] as $key) {
            $current[$key] = empty($input[$key]) ? 0 : 1;
        }

        $cooldown = filter_var($input['notification_cooldown'] ?? 3600, FILTER_VALIDATE_INT);
        if (false === $cooldown || $cooldown < 300 || $cooldown > 604800) {
            throw new \InvalidArgumentException('通知冷却时间必须在 300 到 604800 秒之间。');
        }
        $current['notification_cooldown'] = (int) $cooldown;

        $current['webhook_url'] = self::secretValue(
            $input,
            'webhook_url',
            'clear_webhook_url',
            (string) $current['webhook_url'],
            2048
        );
        $current['webhook_secret'] = self::secretValue(
            $input,
            'webhook_secret',
            'clear_webhook_secret',
            (string) $current['webhook_secret'],
            512
        );
        $current['dingtalk_webhook_url'] = self::secretValue(
            $input,
            'dingtalk_webhook_url',
            'clear_dingtalk_webhook_url',
            (string) $current['dingtalk_webhook_url'],
            2048
        );
        $current['dingtalk_secret'] = self::secretValue(
            $input,
            'dingtalk_secret',
            'clear_dingtalk_secret',
            (string) $current['dingtalk_secret'],
            512
        );
        $current['smtp_password'] = self::secretValue(
            $input,
            'smtp_password',
            'clear_smtp_password',
            (string) $current['smtp_password'],
            512
        );

        $current['smtp_host'] = self::oneLine($input['smtp_host'] ?? '', 255, 'SMTP 主机');
        $current['smtp_username'] = self::oneLine($input['smtp_username'] ?? '', 255, 'SMTP 用户名');
        $current['smtp_from_name'] = self::oneLine($input['smtp_from_name'] ?? '', 120, '发件人名称');
        $current['smtp_from_address'] = self::email($input['smtp_from_address'] ?? '', false, '发件地址');
        $current['email_recipients'] = self::recipients($input['email_recipients'] ?? '');

        $smtpPort = filter_var($input['smtp_port'] ?? 587, FILTER_VALIDATE_INT);
        if (false === $smtpPort || $smtpPort < 1 || $smtpPort > 65535) {
            throw new \InvalidArgumentException('SMTP 端口必须在 1 到 65535 之间。');
        }
        $current['smtp_port'] = (int) $smtpPort;

        $encryption = (string) ($input['smtp_encryption'] ?? 'starttls');
        if (!in_array($encryption, ['none', 'starttls', 'smtps'], true)) {
            throw new \InvalidArgumentException('SMTP 加密方式无效。');
        }
        $current['smtp_encryption'] = $encryption;
        $current['notification_subject_template'] = NotificationTemplate::validate(
            (string) ($input['notification_subject_template'] ?? ''),
            240,
            '通知标题模板'
        );
        $current['notification_message_template'] = NotificationTemplate::validate(
            (string) ($input['notification_message_template'] ?? ''),
            12000,
            '通知正文模板'
        );

        if (!empty($current['webhook_enabled'])) {
            $current['webhook_url'] = self::httpsUrl((string) $current['webhook_url'], '通用 Webhook 地址');
        }
        if (!empty($current['dingtalk_enabled'])) {
            $url = self::httpsUrl((string) $current['dingtalk_webhook_url'], '钉钉机器人地址');
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (
                'oapi.dingtalk.com' !== $host
                || '/robot/send' !== $path
                || !isset($query['access_token'])
                || !is_string($query['access_token'])
                || '' === trim($query['access_token'])
            ) {
                throw new \InvalidArgumentException('钉钉机器人地址必须使用 oapi.dingtalk.com/robot/send。');
            }
            $current['dingtalk_webhook_url'] = $url;
        }
        if (!empty($current['email_enabled'])) {
            if (!self::validHost((string) $current['smtp_host'])) {
                throw new \InvalidArgumentException('SMTP 主机格式无效。');
            }
            if ('' === $current['smtp_from_address'] || '' === $current['email_recipients']) {
                throw new \InvalidArgumentException('启用邮件通知时必须填写发件地址和收件地址。');
            }
        }
        if (
            !empty($current['notifications_enabled'])
            && empty($current['webhook_enabled'])
            && empty($current['dingtalk_enabled'])
            && empty($current['email_enabled'])
        ) {
            throw new \InvalidArgumentException('启用通知后至少需要启用一个通知渠道。');
        }

        return $current;
    }

    public static function save(array $settings): void
    {
        Edit::configPlugin('FriendLinks', $settings);
    }

    public static function rotateWorkerSecret(): string
    {
        $settings = self::all();
        $settings['worker_secret'] = bin2hex(random_bytes(32));
        self::save($settings);
        return $settings['worker_secret'];
    }

    public static function sensitiveKeys(): array
    {
        return [
            'worker_secret',
            'webhook_url',
            'webhook_secret',
            'dingtalk_webhook_url',
            'dingtalk_secret',
            'smtp_password',
        ];
    }

    public static function assertPage(int $cid): void
    {
        if (0 === $cid) {
            return;
        }

        $db = Db::get();
        $page = $db->fetchRow($db->select('cid', 'type', 'status', 'template', 'password')
            ->from('table.contents')
            ->where('cid = ?', $cid)
            ->limit(1));

        if (!$page || 'page' !== $page['type'] || 'publish' !== $page['status']) {
            throw new \InvalidArgumentException('承载页必须是已发布的独立页面。');
        }
        if (!empty($page['password'])) {
            throw new \InvalidArgumentException('承载页不能设置访问密码。');
        }
        if (!empty($page['template'])) {
            throw new \InvalidArgumentException('承载页必须使用普通页面模板，不能使用自定义模板。');
        }
    }

    private static function secretValue(
        array $input,
        string $key,
        string $clearKey,
        string $current,
        int $maxBytes
    ): string {
        if (!empty($input[$clearKey])) {
            return '';
        }
        $value = trim((string) ($input[$key] ?? ''));
        if ('' === $value) {
            return $current;
        }
        if (strlen($value) > $maxBytes || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException($key . ' 格式无效。');
        }
        return $value;
    }

    private static function oneLine($value, int $maxBytes, string $label): string
    {
        $value = trim((string) $value);
        if (strlen($value) > $maxBytes || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException($label . '格式无效。');
        }
        return $value;
    }

    private static function email($value, bool $required, string $label): string
    {
        $value = self::oneLine($value, 320, $label);
        if ('' === $value && !$required) {
            return '';
        }
        if (false === filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException($label . '格式无效。');
        }
        return $value;
    }

    private static function recipients($value): string
    {
        $parts = preg_split('/[\s,;]+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY);
        $parts = array_values(array_unique($parts ?: []));
        if (count($parts) > 20) {
            throw new \InvalidArgumentException('收件地址最多支持 20 个。');
        }
        foreach ($parts as $address) {
            self::email($address, true, '收件地址');
        }
        return implode(',', $parts);
    }

    private static function httpsUrl(string $url, string $label): string
    {
        if ('' === trim($url)) {
            throw new \InvalidArgumentException($label . '不能为空。');
        }
        try {
            $url = (new UrlNormalizer())->normalize($url);
        } catch (\InvalidArgumentException $error) {
            throw new \InvalidArgumentException($label . '格式无效。');
        }
        if ('https' !== parse_url($url, PHP_URL_SCHEME)) {
            throw new \InvalidArgumentException($label . '必须使用 HTTPS。');
        }
        return $url;
    }

    private static function validHost(string $host): bool
    {
        $host = trim($host, '[]');
        return false !== filter_var($host, FILTER_VALIDATE_IP)
            || false !== filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }
}
