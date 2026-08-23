<?php

namespace TypechoPlugin\FriendLinks\Domain;

final class NotificationTemplate
{
    public const SUBJECT_MAX_BYTES = 240;
    public const MESSAGE_MAX_BYTES = 12288;
    public const PAYLOAD_MAX_BYTES = 65536;

    public const DEFAULT_SUBJECT = '[FriendLinks] {{link_name}} {{event_name}}';

    public const DEFAULT_MESSAGE = "站点：{{link_name}}\n"
        . "地址：{{link_url}}\n"
        . "事件：{{event_name}}\n"
        . "状态：{{previous_state}} → {{current_state}}\n"
        . "详情：{{status_summary}}\n"
        . "检测时间：{{checked_at}}";

    private const PLACEHOLDERS = [
        'event_name',
        'link_name',
        'link_url',
        'previous_state',
        'current_state',
        'status_summary',
        'reason',
        'reason_code',
        'http_code',
        'response_time_ms',
        'checked_at',
        'cert_expires_at',
        'domain_expires_at',
    ];

    public static function validate(string $template, int $maxBytes, string $field): string
    {
        $template = str_replace(["\r\n", "\r"], "\n", trim($template));
        if ('' === $template) {
            throw new \InvalidArgumentException($field . '不能为空。');
        }
        if (strlen($template) > $maxBytes) {
            throw new \InvalidArgumentException($field . '过长。');
        }

        preg_match_all('/{{([^{}]+)}}/', $template, $matches);
        if (
            substr_count($template, '{{') !== count($matches[0] ?? [])
            || substr_count($template, '}}') !== count($matches[0] ?? [])
        ) {
            throw new \InvalidArgumentException($field . '包含未闭合的模板变量。');
        }
        foreach (array_unique($matches[1] ?? []) as $rawPlaceholder) {
            $placeholder = trim((string) $rawPlaceholder);
            if (
                !preg_match('/^[a-z_]+$/', $placeholder)
                || !in_array($placeholder, self::PLACEHOLDERS, true)
            ) {
                throw new \InvalidArgumentException($field . '包含未知变量：{{' . $placeholder . '}}。');
            }
        }

        return $template;
    }

    public static function render(
        string $template,
        array $context,
        bool $singleLine = false,
        int $maxBytes = 0
    ): string
    {
        $values = [];
        foreach (self::PLACEHOLDERS as $placeholder) {
            $value = (string) ($context[$placeholder] ?? '');
            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $value);
            $values[$placeholder] = trim((string) $value);
        }

        $rendered = preg_replace_callback('/{{([^{}]+)}}/', static function ($matches) use ($values) {
            $placeholder = trim((string) $matches[1]);
            return $values[$placeholder] ?? '';
        }, $template);
        if ($singleLine) {
            $rendered = preg_replace('/\s+/u', ' ', $rendered);
        }
        $rendered = trim((string) $rendered);
        return $maxBytes > 0 ? Text::truncateUtf8($rendered, $maxBytes) : $rendered;
    }

    public static function placeholders(): array
    {
        return self::PLACEHOLDERS;
    }
}
