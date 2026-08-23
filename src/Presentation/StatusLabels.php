<?php

namespace TypechoPlugin\FriendLinks\Presentation;

final class StatusLabels
{
    private const STATES = [
        'pending' => '等待检测',
        'healthy' => '正常',
        'warning' => '需要关注',
        'degraded' => '不稳定',
        'down' => '不可用',
        'unknown' => '状态未知',
        'disabled' => '未检测',
    ];

    private const REASONS = [
        'http_unreachable' => '无法连接',
        'http_not_found' => '页面不存在',
        'http_server_error' => '服务异常',
        'http_restricted' => '访问受限',
        'http_rate_limited' => '请求受限',
        'http_redirect_error' => '跳转异常',
        'http_redirect_loop' => '跳转循环',
        'http_redirect_limit' => '跳转次数过多',
        'http_redirect_missing_location' => '跳转地址缺失',
        'http_redirect_invalid' => '跳转地址无效',
        'http_client_error' => '请求被拒绝',
        'http_timeout' => '连接超时',
        'dns_failed' => '解析失败',
        'dns_blocked_target' => '目标被安全策略拒绝',
        'dns_rebinding_detected' => '检测到 DNS Rebinding',
        'idn_unsupported' => '运行环境不支持国际化域名检测',
        'tls_expired' => '证书已过期',
        'tls_not_yet_valid' => '证书尚未生效',
        'tls_expiring' => '证书即将过期',
        'tls_hostname_mismatch' => '证书域名不匹配',
        'tls_untrusted' => '证书不受信任',
        'tls_handshake_failed' => '安全连接失败',
        'tls_unknown_detail' => '证书详情未知',
        'domain_expiration_passed' => '域名到期日期已过',
        'domain_expiring' => '域名即将到期',
        'domain_unknown' => '域名状态未知',
        'domain_unsupported' => '暂不支持此域名后缀',
        'domain_not_applicable' => '不适用域名检测',
        'data_stale' => '检测数据已过期',
        'worker_error' => '检测尚无结论',
    ];

    private const RUN_STATES = [
        'running' => '运行中',
        'completed' => '已完成',
        'partial' => '部分完成',
        'failed' => '失败',
    ];

    public static function state(?string $state): string
    {
        $state = (string) $state;
        return self::STATES[$state] ?? '状态未知';
    }

    public static function reason(?string $reason): string
    {
        $reason = (string) $reason;
        return self::REASONS[$reason] ?? ('' === $reason ? '' : '其他异常');
    }

    public static function summary(?string $state, ?string $reason): string
    {
        $label = self::state($state);
        $reasonLabel = self::reason($reason);
        return '' === $reasonLabel ? $label : $label . ' · ' . $reasonLabel;
    }

    public static function shortState(?string $state): string
    {
        $labels = [
            'pending' => '待检测',
            'healthy' => '正常',
            'warning' => '预警',
            'degraded' => '异常',
            'down' => '不可用',
            'unknown' => '未知',
            'disabled' => '未检测',
        ];
        $state = (string) $state;
        return $labels[$state] ?? '未知';
    }

    public static function runState(?string $state): string
    {
        $state = (string) $state;
        return self::RUN_STATES[$state] ?? '未知';
    }

    public static function states(): array
    {
        return self::STATES;
    }
}
