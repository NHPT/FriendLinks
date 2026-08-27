<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use TypechoPlugin\FriendLinks\Application\NotificationPlanner;
use TypechoPlugin\FriendLinks\Domain\CliSchedule;
use TypechoPlugin\FriendLinks\Domain\IpAddress;
use TypechoPlugin\FriendLinks\Domain\NotificationTemplate;
use TypechoPlugin\FriendLinks\Domain\PublicSuffixList;
use TypechoPlugin\FriendLinks\Domain\StatusAggregator;
use TypechoPlugin\FriendLinks\Domain\Text;
use TypechoPlugin\FriendLinks\Domain\UrlNormalizer;
use TypechoPlugin\FriendLinks\Infrastructure\DingTalkSigner;
use TypechoPlugin\FriendLinks\Infrastructure\SafeHttpClient;
use TypechoPlugin\FriendLinks\Infrastructure\WebhookSigner;
use TypechoPlugin\FriendLinks\Infrastructure\WorkerSigner;
use TypechoPlugin\FriendLinks\Presentation\AssetVersion;
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

$scheduleNow = 1700000000;
check(
    CliSchedule::decision(null, $scheduleNow, 300, 240)['due'],
    'CLI schedule runs when no previous CLI execution exists'
);
$notDue = CliSchedule::decision([
    'status' => 'completed',
    'started_at' => $scheduleNow - 60,
    'heartbeat_at' => $scheduleNow - 30,
], $scheduleNow, 300, 240);
check(
    !$notDue['due']
        && 'schedule_not_due' === $notDue['reason']
        && $scheduleNow + 240 === $notDue['next_run_at'],
    'CLI schedule skips until the configured interval elapses'
);
$running = CliSchedule::decision([
    'status' => 'running',
    'started_at' => $scheduleNow - 600,
    'heartbeat_at' => $scheduleNow - 10,
], $scheduleNow, 300, 240);
check(
    !$running['due'] && 'worker_running' === $running['reason'],
    'CLI schedule does not overlap a live CLI worker'
);
check(
    CliSchedule::decision([
        'status' => 'running',
        'started_at' => $scheduleNow - 3600,
        'heartbeat_at' => $scheduleNow - 3600,
    ], $scheduleNow, 300, 240)['due'],
    'CLI schedule recovers after a stale running record'
);
$clockSkew = CliSchedule::decision([
    'status' => 'completed',
    'started_at' => $scheduleNow + 3600,
    'heartbeat_at' => $scheduleNow + 3600,
], $scheduleNow, 300, 240);
check(
    !$clockSkew['due'] && 'clock_skew' === $clockSkew['reason'],
    'CLI schedule does not run every minute after a backward clock adjustment'
);

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
$blockedAndExpired = $tlsFailure;
$blockedAndExpired['dns'] = ['state' => 'blocked', 'reason_code' => 'dns_blocked_target'];
$result = $aggregator->aggregate(
    ['availability_consecutive_failures' => 0],
    $blockedAndExpired,
    $settings,
    time()
);
check(
    'down' === $result['overall_state'] && 'dns_blocked_target' === $result['reason_code'],
    'DNS and SSRF failures take reason priority over simultaneous TLS failures'
);

$domainWarning = $healthyComponents;
$domainWarning['domain'] = ['state' => 'past_expiration'];
$result = $aggregator->aggregate([], $domainWarning, $settings, time());
check('warning' === $result['overall_state'], 'past RDAP expiration remains a warning while main path is healthy');

$restricted = $healthyComponents;
$restricted['http'] = ['state' => 'restricted'];
$result = $aggregator->aggregate([], $restricted, $settings, time());
check('degraded' === $result['overall_state'] && 0 === $result['availability_consecutive_failures'], '401/403 does not increase failures');
$timeout = $healthyComponents;
$timeout['http'] = ['state' => 'network_error', 'reason_code' => 'http_timeout'];
$result = $aggregator->aggregate([], $timeout, $settings, time());
check('http_timeout' === $result['reason_code'], 'aggregator preserves the precise HTTP network failure reason');
$redirectBlocked = $healthyComponents;
$redirectBlocked['http'] = ['state' => 'network_error', 'reason_code' => 'dns_blocked_target'];
$result = $aggregator->aggregate([], $redirectBlocked, $settings, time());
check(
    'dns_blocked_target' === $result['reason_code'],
    'aggregator preserves SSRF policy failures discovered during redirects'
);

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
foreach (['cards', 'compact', 'logo-grid', 'directory', 'minimal'] as $template) {
    check(isset($templates[$template]), 'bundled template is available: ' . $template);
    check(
        null !== (new TemplateCatalog())->stylesheetPath($templates[$template]),
        'bundled template owns its stylesheet: ' . $template
    );
}
$customTemplateRoot = sys_get_temp_dir() . '/friendlinks-template-' . bin2hex(random_bytes(6));
mkdir($customTemplateRoot . '/contributor-grid', 0777, true);
file_put_contents($customTemplateRoot . '/contributor-grid/manifest.json', json_encode([
    'schema' => 1,
    'title' => 'Contributor grid',
    'description' => 'Fixture',
    'layout' => 'contributor-grid',
]));
file_put_contents(
    $customTemplateRoot . '/contributor-grid/style.css',
    '.flm-root.flm-template-contributor-grid{display:block}'
);
$customCatalog = new TemplateCatalog($customTemplateRoot);
check($customCatalog->exists('contributor-grid'), 'contributor templates are discovered without a core allowlist');
unlink($customTemplateRoot . '/contributor-grid/style.css');
unlink($customTemplateRoot . '/contributor-grid/manifest.json');
rmdir($customTemplateRoot . '/contributor-grid');
rmdir($customTemplateRoot);
check(!(new TemplateCatalog())->exists('../invalid'), 'invalid template identifier is rejected');
check(
    (string) filemtime(__FILE__) === AssetVersion::forFile(__FILE__),
    'asset version uses the file modification time'
);
check(
    'missing' === AssetVersion::forFile(__DIR__ . '/missing-asset.css'),
    'missing asset version does not emit a filesystem warning'
);
check('友' === Text::firstCharacter('友链'), 'UTF-8 first character works without byte slicing');
check(
    '友链' === Text::truncateUtf8("友\xFF链", 20),
    'UTF-8 truncation removes invalid bytes without optional extensions'
);
check(
    !is_file(dirname(__DIR__) . '/vendor/phpmailer/phpmailer/get_oauth_token.php'),
    'unused PHPMailer OAuth helper is not shipped'
);
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
$longNotificationRows = $planner->plan([
    'id' => 43,
    'name' => str_repeat('站', 500),
    'url' => 'https://example.com/',
    'overall_state' => 'healthy',
    'reason_code' => null,
], [
    'overall_state' => 'down',
    'reason_code' => 'http_timeout',
    'http_code' => null,
    'response_time_ms' => null,
    'checked_at' => 1700000200,
], array_merge($notificationSettings, [
    'notification_subject_template' => '{{link_name}}{{link_name}}',
    'notification_message_template' => str_repeat('{{link_name}}', 20),
]), str_repeat('c', 32), 1700000200);
check(
    strlen($longNotificationRows[0]['subject']) <= NotificationTemplate::SUBJECT_MAX_BYTES
        && 1 === preg_match('//u', $longNotificationRows[0]['subject'])
        && strlen($longNotificationRows[0]['message']) <= NotificationTemplate::MESSAGE_MAX_BYTES
        && strlen($longNotificationRows[0]['payload_json']) <= NotificationTemplate::PAYLOAD_MAX_BYTES,
    'rendered notification values stay within database and webhook byte limits'
);

fwrite(STDOUT, "OK: {$tests} assertions\n");
