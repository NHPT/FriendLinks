<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use TypechoPlugin\FriendLinks\Application\NotificationPlanner;
use TypechoPlugin\FriendLinks\Domain\IpAddress;
use TypechoPlugin\FriendLinks\Domain\NotificationTemplate;
use TypechoPlugin\FriendLinks\Domain\PublicSuffixList;
use TypechoPlugin\FriendLinks\Domain\StatusAggregator;
use TypechoPlugin\FriendLinks\Domain\UrlNormalizer;
use TypechoPlugin\FriendLinks\Infrastructure\DingTalkSigner;
use TypechoPlugin\FriendLinks\Infrastructure\SafeHttpClient;
use TypechoPlugin\FriendLinks\Infrastructure\WebhookSigner;
use TypechoPlugin\FriendLinks\Infrastructure\WorkerSigner;
use TypechoPlugin\FriendLinks\Presentation\TemplateCatalog;
use TypechoPlugin\FriendLinks\Presentation\StatusLabels;

$tests = 0;

function check($condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $message);
    }
}

function rejects(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        check(true, $message);
        return;
    }
    check(false, $message);
}

$urls = new UrlNormalizer();
check(
    'https://example.com/a?x=1' === $urls->normalize('HTTPS://Example.COM:443/a?x=1#fragment'),
    'URL normalization removes default port and fragment'
);
check(
    hash('sha256', 'https://example.com/') === $urls->hash('https://example.com/'),
    'URL hash is stable'
);
rejects(static function () use ($urls) {
    $urls->normalize('https://user:secret@example.com/');
}, 'URL userinfo is rejected');
rejects(static function () use ($urls) {
    $urls->normalize('http://0177.0.0.1/');
}, 'ambiguous IPv4 is rejected');
rejects(static function () use ($urls) {
    $urls->normalize('file:///etc/passwd');
}, 'non-HTTP schemes are rejected');
rejects(static function () use ($urls) {
    $urls->normalize('https://example.com:8443/');
}, 'non-default ports are rejected');
rejects(static function () use ($urls) {
    $urls->normalize('https://example.com/path with spaces');
}, 'unencoded whitespace is rejected');

check(!IpAddress::isPublic('127.0.0.1'), 'IPv4 loopback is blocked');
check(!IpAddress::isPublic('169.254.169.254'), 'cloud metadata IPv4 is blocked');
check(!IpAddress::isPublic('10.0.0.1'), 'IPv4 private range is blocked');
check(!IpAddress::isPublic('::1'), 'IPv6 loopback is blocked');
check(!IpAddress::isPublic('::ffff:127.0.0.1'), 'IPv4-mapped IPv6 loopback is blocked');
check(IpAddress::isPublic('1.1.1.1'), 'public IPv4 is allowed');
check(IpAddress::isPublic('2606:4700:4700::1111'), 'public IPv6 is allowed');

$psl = PublicSuffixList::bundled();
check('example.co.uk' === $psl->registrableDomain('www.example.co.uk'), 'multi-label ICANN suffix works');
check('blogspot.com' === $psl->registrableDomain('site.blogspot.com'), 'PRIVATE suffixes are ignored');
check(null === $psl->registrableDomain('co.uk'), 'public suffix itself has no registrable domain');
$customPsl = new PublicSuffixList("com\n*.kawasaki.jp\n!city.kawasaki.jp\n");
check(
    'city.kawasaki.jp' === $customPsl->registrableDomain('www.city.kawasaki.jp'),
    'PSL exception rule works'
);

$aggregator = new StatusAggregator();
$settings = ['failure_threshold' => 3, 'restricted_is_healthy' => 0];
$healthyComponents = [
    'dns' => ['state' => 'healthy'],
    'http' => ['state' => 'healthy'],
    'tls' => ['state' => 'healthy'],
    'domain' => ['state' => 'healthy'],
];
$result = $aggregator->aggregate([], $healthyComponents, $settings, time());
check('healthy' === $result['overall_state'] && 0 === $result['availability_consecutive_failures'], 'healthy result resets failures');

$failed = $healthyComponents;
$failed['http'] = ['state' => 'server_error'];
$result = $aggregator->aggregate(['availability_consecutive_failures' => 0], $failed, $settings, time());
check('degraded' === $result['overall_state'] && 1 === $result['availability_consecutive_failures'], 'first failure degrades');
$result = $aggregator->aggregate(['availability_consecutive_failures' => 2], $failed, $settings, time());
check('down' === $result['overall_state'] && 3 === $result['availability_consecutive_failures'], 'third failure marks down');

$tlsFailure = $healthyComponents;
$tlsFailure['tls'] = ['state' => 'hostname_mismatch'];
$result = $aggregator->aggregate(['availability_consecutive_failures' => 0], $tlsFailure, $settings, time());
check('down' === $result['overall_state'] && 'tls_hostname_mismatch' === $result['reason_code'], 'confirmed TLS failure is immediately down');

$domainWarning = $healthyComponents;
$domainWarning['domain'] = ['state' => 'past_expiration'];
$result = $aggregator->aggregate([], $domainWarning, $settings, time());
check('warning' === $result['overall_state'], 'past RDAP expiration remains a warning while main path is healthy');

$restricted = $healthyComponents;
$restricted['http'] = ['state' => 'restricted'];
$result = $aggregator->aggregate([], $restricted, $settings, time());
check('degraded' === $result['overall_state'] && 0 === $result['availability_consecutive_failures'], '401/403 does not increase failures');

$signer = new WorkerSigner();
$signature = $signer->sign('secret', 'POST', '/friendlinks/worker', 1700000000, 'nonce_1234567890', '{}');
check(
    hash_equals(
        hash_hmac('sha256', "POST\n/friendlinks/worker\n1700000000\nnonce_1234567890\n" . hash('sha256', '{}'), 'secret'),
        $signature
    ),
    'worker signature canonicalization is stable'
);
$webhookSignature = (new WebhookSigner())->sign('secret', '1700000000', '{"event":"down"}');
check(
    hash_equals(hash_hmac('sha256', "1700000000\n{\"event\":\"down\"}", 'secret'), $webhookSignature),
    'generic webhook HMAC signature is stable'
);
$dingTalkSignature = (new DingTalkSigner())->sign('SECtest', '1700000000000');
check(
    hash_equals(
        base64_encode(hash_hmac('sha256', "1700000000000\nSECtest", 'SECtest', true)),
        $dingTalkSignature
    ),
    'DingTalk robot signature is stable'
);
$blockedWebhook = (new SafeHttpClient())->postJson('https://127.0.0.1/hook', '{}');
check(
    empty($blockedWebhook['ok']) && 'dns_blocked_target' === $blockedWebhook['reason_code'],
    'notification HTTP client rejects loopback webhook targets'
);

$templates = (new TemplateCatalog())->all();
check(5 === count($templates), 'five bundled presentation templates are available');
foreach (['cards', 'compact', 'logo-grid', 'directory', 'minimal'] as $template) {
    check(isset($templates[$template]), 'bundled template is available: ' . $template);
}
check(!(new TemplateCatalog())->exists('../invalid'), 'invalid template identifier is rejected');
check('不可用 · 无法连接' === StatusLabels::summary('down', 'http_unreachable'), 'status summary is localized');
check('已完成' === StatusLabels::runState('completed'), 'worker run state is localized');
check('异常' === StatusLabels::shortState('degraded'), 'compact card status is localized');

$notificationTemplate = NotificationTemplate::validate(
    '[{{ event_name }}] {{link_name}}',
    240,
    '通知标题模板'
);
check(
    '[站点不可用] Example' === NotificationTemplate::render($notificationTemplate, [
        'event_name' => '站点不可用',
        'link_name' => 'Example',
    ], true),
    'notification template renders allowlisted placeholders'
);
rejects(static function () {
    NotificationTemplate::validate('{{unknown_value}}', 240, '通知标题模板');
}, 'notification template rejects unknown placeholders');

$notificationSettings = [
    'notifications_enabled' => 1,
    'notify_on_down' => 1,
    'notify_on_recovery' => 1,
    'notify_on_warning' => 1,
    'notification_cooldown' => 3600,
    'webhook_enabled' => 1,
    'dingtalk_enabled' => 1,
    'email_enabled' => 1,
    'notification_subject_template' => NotificationTemplate::DEFAULT_SUBJECT,
    'notification_message_template' => NotificationTemplate::DEFAULT_MESSAGE,
];
$planner = new NotificationPlanner();
$notificationRows = $planner->plan([
    'id' => 42,
    'name' => 'Example',
    'url' => 'https://example.com/',
    'overall_state' => 'degraded',
    'reason_code' => 'http_server_error',
], [
    'overall_state' => 'down',
    'reason_code' => 'http_unreachable',
    'http_code' => null,
    'response_time_ms' => 1200,
    'checked_at' => 1700000000,
], $notificationSettings, str_repeat('a', 32), 1700000000);
check(3 === count($notificationRows), 'down transition queues every enabled notification channel');
$plannedChannels = array_values(array_unique(array_column($notificationRows, 'channel')));
sort($plannedChannels);
check(
    ['dingtalk', 'email', 'webhook'] === $plannedChannels,
    'notification planner emits the expected channels'
);
check(
    [] === $planner->plan([
        'id' => 42,
        'overall_state' => 'down',
        'reason_code' => 'http_unreachable',
    ], [
        'overall_state' => 'down',
        'reason_code' => 'http_unreachable',
    ], $notificationSettings, str_repeat('b', 32), 1700000100),
    'unchanged status does not create duplicate notification events'
);

fwrite(STDOUT, "OK: {$tests} assertions\n");
