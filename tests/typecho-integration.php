<?php

$root = getenv('TYPECHO_TEST_ROOT');
if (!$root || !is_file($root . '/config.inc.php')) {
    fwrite(STDERR, "TYPECHO_TEST_ROOT must point to an installed Typecho tree.\n");
    exit(2);
}

require $root . '/config.inc.php';
\Widget\Init::alloc();
require dirname(__DIR__) . '/Plugin.php';

use Typecho\Db;
use Typecho\Plugin as TypechoPluginRegistry;
use Typecho\Widget\Helper\Form;
use TypechoPlugin\FriendLinks\Application\ImportService;
use TypechoPlugin\FriendLinks\Application\LinkService;
use TypechoPlugin\FriendLinks\Application\NotificationDispatcher;
use TypechoPlugin\FriendLinks\Application\Settings;
use TypechoPlugin\FriendLinks\Domain\NotificationTemplate;
use TypechoPlugin\FriendLinks\Infrastructure\MigrationManager;
use TypechoPlugin\FriendLinks\Infrastructure\NotificationChannelInterface;
use TypechoPlugin\FriendLinks\Infrastructure\Repositories;
use TypechoPlugin\FriendLinks\Plugin;
use TypechoPlugin\FriendLinks\Presentation\Renderer;
use TypechoPlugin\FriendLinks\Presentation\TemplateCatalog;

$assertions = 0;
$check = static function ($condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $message);
    }
};

Plugin::activate();
TypechoPluginRegistry::activate('FriendLinks');
Settings::save(Settings::defaults());
Plugin::activate();

$pluginInfo = TypechoPluginRegistry::parseInfo(dirname(__DIR__) . '/Plugin.php');
$check(
    '独立的 Typecho 友情链接管理、展示、健康检测与通知插件。' === $pluginInfo['description'],
    'plugin description is Chinese'
);
$configForm = new Form();
Plugin::config($configForm);
$configInputs = $configForm->getInputs();
$storedSettings = Settings::all();
foreach ($storedSettings as $name => $value) {
    $check(isset($configInputs[$name]), 'plugin config form covers stored option: ' . $name);
    $check(
        $configInputs[$name] instanceof Form\Element\Fake,
        'plugin config option is non-rendering: ' . $name
    );
    $configInputs[$name]->value($value);
}
ob_start();
$configForm->render();
$configHtml = (string) ob_get_clean();
$check(
    false === strpos($configHtml, (string) $storedSettings['worker_secret']),
    'plugin config page does not render stored secrets'
);

$migration = new MigrationManager();
$check(2 === $migration->version(), 'schema version was stored');
$db = Db::get();
foreach (['categories', 'links', 'current_status', 'check_history', 'runs', 'cache', 'notification_outbox'] as $table) {
    $db->fetchRow($db->select('1')->from('table.flm_' . $table)->limit(1));
    $check(true, 'table exists: ' . $table);
}
$panelRow = Db::get()->fetchRow(Db::get()->select('value')->from('table.options')
    ->where('name = ?', 'panelTable')->where('user = ?', 0)->limit(1));
$panelTable = unserialize((string) $panelRow['value'], ['allowed_classes' => false]);
$friendMenus = array_filter($panelTable['parent'], static function ($name) {
    return '友情链接' === $name;
});
$check(1 === count($friendMenus), 'repeated activation keeps one FriendLinks admin menu');
$menuIndexRow = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'friendlinks_menu_index')->where('user = ?', 0)->limit(1));
$check(
    $menuIndexRow && isset($panelTable['child'][(int) $menuIndexRow['value']]),
    'activation persists the FriendLinks menu index'
);
foreach ($panelTable['child'] as $items) {
    foreach ($items as $item) {
        if (false !== strpos(urldecode((string) ($item[2] ?? '')), 'FriendLinks/panel/')) {
            $check('administrator' === ($item[3] ?? null), 'FriendLinks panel is administrator-only');
        }
    }
}

$now = time();
$pageSlug = 'integration-friends-' . bin2hex(random_bytes(4));
$pageId = (int) $db->query($db->insert('table.contents')->rows([
    'title' => '友情链接',
    'slug' => $pageSlug,
    'created' => $now,
    'modified' => $now,
    'text' => '<p>Intro</p>',
    'order' => 0,
    'authorId' => 1,
    'template' => null,
    'type' => 'page',
    'status' => 'publish',
    'password' => null,
    'commentsNum' => 0,
    'allowComment' => 0,
    'allowPing' => 0,
    'allowFeed' => 1,
    'parent' => 0,
]));
$settings = Settings::all();
$settings['page_cid'] = $pageId;
Settings::save($settings);
Settings::assertPage($pageId);
$check((int) Settings::get('page_cid') === $pageId, 'page binding persisted');

$notificationSettings = Settings::sanitizeNotifications([
    'notifications_enabled' => 1,
    'notify_on_down' => 1,
    'notify_on_recovery' => 1,
    'notify_on_warning' => 0,
    'notification_cooldown' => 3600,
    'webhook_enabled' => 1,
    'webhook_url' => 'https://example.com/friendlinks',
    'webhook_secret' => 'integration-secret',
    'dingtalk_enabled' => 0,
    'email_enabled' => 0,
    'smtp_host' => '',
    'smtp_port' => 587,
    'smtp_encryption' => 'starttls',
    'smtp_username' => '',
    'smtp_from_address' => '',
    'smtp_from_name' => 'FriendLinks',
    'email_recipients' => '',
    'notification_subject_template' => NotificationTemplate::DEFAULT_SUBJECT,
    'notification_message_template' => NotificationTemplate::DEFAULT_MESSAGE,
]);
Settings::save($notificationSettings);
$check(
    'https://example.com/friendlinks' === Settings::get('webhook_url'),
    'notification settings persist validated HTTPS webhook'
);
$preservedNotificationSettings = Settings::sanitizeNotifications(array_merge($notificationSettings, [
    'webhook_url' => '',
    'webhook_secret' => '',
]));
$check(
    'integration-secret' === $preservedNotificationSettings['webhook_secret'],
    'blank secret fields preserve stored notification credentials'
);
$invalidWebhookRejected = false;
try {
    Settings::sanitizeNotifications(array_merge($notificationSettings, [
        'webhook_url' => 'http://127.0.0.1/hook',
    ]));
} catch (InvalidArgumentException $error) {
    $invalidWebhookRejected = true;
}
$check($invalidWebhookRejected, 'notification settings reject non-HTTPS webhook targets');
$dingTalkSettings = Settings::sanitizeNotifications(array_merge($notificationSettings, [
    'webhook_enabled' => 0,
    'dingtalk_enabled' => 1,
    'dingtalk_webhook_url' => 'https://oapi.dingtalk.com/robot/send?access_token=test-token',
    'dingtalk_secret' => 'SEC-test',
]));
$check(
    false !== strpos($dingTalkSettings['dingtalk_webhook_url'], 'access_token=test-token'),
    'DingTalk notification settings require the official signed robot endpoint'
);
$invalidDingTalkRejected = false;
try {
    Settings::sanitizeNotifications(array_merge($notificationSettings, [
        'webhook_enabled' => 0,
        'dingtalk_enabled' => 1,
        'dingtalk_webhook_url' => 'https://oapi.dingtalk.com/robot/send',
    ]));
} catch (InvalidArgumentException $error) {
    $invalidDingTalkRejected = true;
}
$check($invalidDingTalkRejected, 'DingTalk notification settings reject a missing access token');
$emailSettings = Settings::sanitizeNotifications(array_merge($notificationSettings, [
    'webhook_enabled' => 0,
    'email_enabled' => 1,
    'smtp_host' => 'smtp.example.com',
    'smtp_port' => 465,
    'smtp_encryption' => 'smtps',
    'smtp_username' => 'monitor@example.com',
    'smtp_password' => 'application-password',
    'smtp_from_address' => 'monitor@example.com',
    'smtp_from_name' => 'FriendLinks',
    'email_recipients' => 'admin@example.com; ops@example.com',
]));
$check(
    'admin@example.com,ops@example.com' === $emailSettings['email_recipients'],
    'SMTP notification settings normalize and validate recipients'
);

$repositories = new Repositories();
$service = new LinkService($repositories);
$categoryId = $service->saveCategory([
    'name' => '技术',
    'slug' => 'technology',
    'sort_order' => 10,
    'enabled' => 1,
]);
$linkId = $service->save([
    'name' => '<Example>',
    'url' => 'https://example.com/',
    'description' => 'Integration & rendering',
    'logo_url' => '',
    'category_id' => $categoryId,
    'sort_order' => 20,
    'visibility' => 'published',
    'check_enabled' => 1,
]);
$check($linkId > 0, 'link was created');
$check(1 === count($repositories->frontendLinks()), 'frontend query returns published link');

$duplicateRejected = false;
try {
    $service->save([
        'name' => 'Duplicate',
        'url' => 'https://EXAMPLE.com:443/',
        'visibility' => 'published',
        'check_enabled' => 1,
    ]);
} catch (InvalidArgumentException $error) {
    $duplicateRejected = true;
}
$check($duplicateRejected, 'normalized duplicate URL was rejected');

$html = (new Renderer())->render($repositories->frontendLinks());
$check(false !== strpos($html, '&lt;Example&gt;'), 'renderer escapes names');
$check(false === strpos($html, '<Example>'), 'renderer contains no raw injected tag');
$check(false !== strpos($html, 'flm-status-summary'), 'renderer groups the compact status label');
$check(false !== strpos($html, 'flm-status-short'), 'renderer exposes compact card status');
$check(false !== strpos($html, 'flm-status-detail'), 'renderer exposes detailed hover status');
$check(false !== strpos((new Renderer())->render([]), '暂无公开友链'), 'empty frontend renders an explicit empty state');
$templates = (new TemplateCatalog())->all();
foreach ($templates as $template) {
    $settings = Settings::all();
    $settings['frontend_template'] = $template['id'];
    Settings::save($settings);
    $html = (new Renderer())->render($repositories->frontendLinks());
    $check(
        false !== strpos($html, 'flm-template-' . $template['id']),
        'renderer applies template: ' . $template['id']
    );
    $check(false === strpos($html, '<Example>'), 'template preserves output escaping: ' . $template['id']);
}
$groupHtml = (new Renderer())->render([
    [
        'name' => 'Reserved slug',
        'url' => 'https://example.com/reserved',
        'description' => '',
        'logo_url' => '',
        'category_name' => 'Reserved',
        'category_slug' => 'uncategorized',
        'overall_state' => 'healthy',
        'reason_code' => null,
        'checked_at' => time(),
    ],
    [
        'name' => 'No category',
        'url' => 'https://example.com/no-category',
        'description' => '',
        'logo_url' => '',
        'category_name' => null,
        'category_slug' => null,
        'overall_state' => 'healthy',
        'reason_code' => null,
        'checked_at' => time(),
    ],
], 'cards');
$check(
    false !== strpos($groupHtml, 'data-flm-group="category-uncategorized"')
        && false !== strpos($groupHtml, 'data-flm-group="uncategorized"'),
    'reserved category slug does not merge with uncategorized links'
);
$disabledHtml = (new Renderer())->render([[
    'name' => 'Disabled',
    'url' => 'https://example.com/disabled',
    'description' => '',
    'logo_url' => '',
    'category_name' => null,
    'category_slug' => null,
    'overall_state' => 'disabled',
    'reason_code' => null,
    'checked_at' => time() - 864000,
]], 'cards');
$check(
    false !== strpos($disabledHtml, 'flm-item-state-disabled'),
    'disabled status is not replaced by stale-data state'
);

$token = str_repeat('a', 32);
$check($repositories->claim($linkId, $token, time(), time() + 300), 'due task was claimed atomically');
$claimed = $repositories->claimedLink($linkId, $token);
$check($claimed && (int) $claimed['id'] === $linkId, 'claimed task can be loaded by token');
$check(
    $repositories->renewLease($linkId, $token, time(), time() + 300),
    'active detection lease can be renewed by its owner'
);
$repositories->persistResult($linkId, $token, time(), [
    'overall_state' => 'healthy',
    'reason_code' => null,
    'http_state' => 'healthy',
    'http_code' => 200,
    'response_time_ms' => 10,
    'final_url' => 'https://example.com/',
    'dns_state' => 'healthy',
    'tls_state' => 'healthy',
    'cert_not_after' => time() + 86400,
    'domain_state' => 'healthy',
    'domain_expires_at' => time() + 86400,
    'availability_consecutive_failures' => 0,
    'checked_at' => time(),
    'dns_checked_at' => time(),
    'http_checked_at' => time(),
    'tls_checked_at' => time(),
    'domain_checked_at' => time(),
    'dns_next_check_at' => time() + 3600,
    'http_next_check_at' => time() + 3600,
    'tls_next_check_at' => time() + 3600,
    'domain_next_check_at' => time() + 3600,
    'last_success_at' => time(),
    'last_failure_at' => null,
    'state_changed_at' => time(),
    'next_check_at' => time() + 3600,
    'details_json' => '{}',
], [
    'link_id' => $linkId,
    'run_id' => str_repeat('b', 32),
    'overall_state' => 'healthy',
    'reason_code' => null,
    'http_code' => 200,
    'response_time_ms' => 10,
    'started_at' => time(),
    'finished_at' => time(),
    'details_json' => '{}',
], [[
    'event_key' => hash('sha256', 'integration-notification'),
    'link_id' => $linkId,
    'event_type' => 'recovery',
    'channel' => 'webhook',
    'subject' => 'Integration notification',
    'message' => 'Integration notification body',
    'payload_json' => '{}',
    'status' => 'pending',
    'attempts' => 0,
    'available_at' => time(),
    'lease_token' => null,
    'lease_until' => null,
    'last_error' => null,
    'created_at' => time(),
    'sent_at' => null,
    '_cooldown' => 3600,
]]);
$check('healthy' === $repositories->link($linkId)['overall_state'], 'leased result was persisted');
$check(1 === count($repositories->history(10, $linkId)), 'history was inserted in result transaction');
$queuedNotifications = $repositories->notifications(10);
$check(1 === count($queuedNotifications), 'notification was queued with the check result');
$notificationId = (int) $queuedNotifications[0]['id'];
$notificationToken = str_repeat('c', 32);
$check(
    $repositories->claimNotification($notificationId, $notificationToken, time(), time() + 120),
    'notification outbox row was claimed atomically'
);
$claimedNotification = $repositories->claimedNotification($notificationId, $notificationToken);
$check(
    $claimedNotification
        && 'sending' === $claimedNotification['status']
        && 1 === (int) $claimedNotification['attempts'],
    'claiming a notification atomically records the delivery attempt'
);
$repositories->markNotificationFailed(
    $notificationId,
    $notificationToken,
    time() + 300,
    'integration failure'
);
$check($repositories->retryNotification($notificationId), 'failed notification can be retried');
$retryToken = str_repeat('d', 32);
$check(
    $repositories->claimNotification($notificationId, $retryToken, time(), time() + 120),
    'retried notification can be claimed'
);
$repositories->markNotificationSent($notificationId, $retryToken, time());
$check(1 === ($repositories->notificationCounts()['sent'] ?? 0), 'notification success is recorded');
$exhaustedEventKey = hash('sha256', 'exhausted-notification');
$repositories->enqueueNotifications([[
    'event_key' => $exhaustedEventKey,
    'link_id' => $linkId,
    'event_type' => 'warning',
    'channel' => 'email',
    'subject' => 'Exhausted notification',
    'message' => 'Exhausted notification body',
    'payload_json' => '{}',
    'status' => 'pending',
    'attempts' => 4,
    'available_at' => time(),
    'lease_token' => null,
    'lease_until' => null,
    'last_error' => null,
    'created_at' => time(),
    'sent_at' => null,
    '_cooldown' => 0,
]]);
$exhaustedNotification = null;
foreach ($repositories->notifications(20) as $notification) {
    if ($exhaustedEventKey === $notification['event_key']) {
        $exhaustedNotification = $notification;
        break;
    }
}
$exhaustedToken = str_repeat('f', 32);
$check(
    $exhaustedNotification
        && $repositories->claimNotification(
            (int) $exhaustedNotification['id'],
            $exhaustedToken,
            time(),
            time() - 1
        ),
    'fifth notification attempt can be claimed'
);
$check(
    1 === $repositories->expireExhaustedNotifications(time())
        && 1 === ($repositories->notificationCounts()['failed'] ?? 0),
    'expired fifth attempt becomes a terminal failed notification'
);
$repositories->enqueueNotifications([[
    'event_key' => hash('sha256', 'dispatcher-notification'),
    'link_id' => $linkId,
    'event_type' => 'down',
    'channel' => 'webhook',
    'subject' => 'Dispatcher notification',
    'message' => 'Dispatcher notification body',
    'payload_json' => '{}',
    'status' => 'pending',
    'attempts' => 0,
    'available_at' => time(),
    'lease_token' => null,
    'lease_until' => null,
    'last_error' => null,
    'created_at' => time(),
    'sent_at' => null,
    '_cooldown' => 0,
]]);
$fakeChannel = new class implements NotificationChannelInterface {
    public $sent = 0;

    public function send(array $notification, array $settings, ?float $deadline = null): void
    {
        $this->sent++;
    }
};
$dispatchResult = (new NotificationDispatcher($repositories, ['webhook' => $fakeChannel]))->dispatch(10);
$check(1 === $dispatchResult['sent'] && 1 === $fakeChannel->sent, 'dispatcher sends a claimed outbox row');
$check(
    $repositories->retryNotification((int) $exhaustedNotification['id']),
    'terminal notification can be manually retried'
);

$importService = new ImportService($repositories, $service);
$orphanResult = $importService->import([[
    'name' => 'Duplicate import',
    'url' => 'https://example.com/',
    'category' => 'Orphan category',
    'check_enabled' => 0,
]]);
$categoryNames = array_column($repositories->categories(), 'name');
$check(
    0 === $orphanResult['created']
        && 1 === $orphanResult['skipped']
        && !in_array('Orphan category', $categoryNames, true),
    'invalid import rows do not leave orphan categories'
);
$tooManyRowsRejected = false;
try {
    $importService->preview('json', (string) json_encode(array_fill(0, 501, [
        'name' => 'Bulk',
        'url' => 'https://example.com/',
    ])));
} catch (InvalidArgumentException $error) {
    $tooManyRowsRejected = true;
}
$check($tooManyRowsRejected, 'imports over 500 rows are rejected instead of truncated');
$service->save([
    'name' => '=1+1',
    'url' => 'https://formula.example.com/',
    'description' => 'Spreadsheet formula test',
    'logo_url' => '',
    'category_id' => $categoryId,
    'sort_order' => 30,
    'visibility' => 'draft',
    'check_enabled' => 0,
]);
$service->save([
    'name' => "'=SUM(1,2)",
    'url' => 'https://formula-text.example.com/',
    'description' => 'Literal spreadsheet text',
    'logo_url' => '',
    'category_id' => $categoryId,
    'sort_order' => 31,
    'visibility' => 'draft',
    'check_enabled' => 0,
]);
$exportedCsv = $importService->export('csv');
$check(
    false !== strpos($exportedCsv, "'=1+1"),
    'CSV export neutralizes spreadsheet formula prefixes'
);
$roundTripNames = array_column($importService->preview('csv', $exportedCsv), 'name');
$check(
    in_array('=1+1', $roundTripNames, true) && in_array("'=SUM(1,2)", $roundTripNames, true),
    'formula-safe CSV encoding preserves exact values when reimported'
);

$repositories->schedule([$linkId], true);
$oldTargetToken = str_repeat('e', 32);
$check(
    $repositories->claim($linkId, $oldTargetToken, time(), time() + 300),
    'old target can be leased before an edit'
);
$service->save([
    'name' => '<Example>',
    'url' => 'https://example.org/new-target',
    'description' => 'Integration & rendering',
    'logo_url' => '',
    'category_id' => $categoryId,
    'sort_order' => 20,
    'visibility' => 'published',
    'check_enabled' => 1,
], $linkId);
$updatedTarget = $repositories->link($linkId);
$check(
    'pending' === $updatedTarget['overall_state']
        && null === $updatedTarget['checked_at']
        && null === $updatedTarget['details_json']
        && null === $repositories->claimedLink($linkId, $oldTargetToken),
    'changing a link target clears stale status, invalidates the old lease, and schedules a fresh check'
);

if (getenv('KEEP_TYPECHO_FIXTURE')) {
    $db->query($db->update('table.options')
        ->rows(['value' => serialize(TypechoPluginRegistry::export())])
        ->where('name = ?', 'plugins')
        ->where('user = ?', 0));
    fwrite(STDOUT, "FIXTURE_SLUG={$pageSlug}\n");
    fwrite(STDOUT, "OK: {$assertions} Typecho integration assertions\n");
    exit(0);
}

$settings = Settings::all();
$settings['frontend_template'] = 'minimal';
Settings::save($settings);

$panelRow = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'panelTable')->where('user = ?', 0)->limit(1));
$panelTable = unserialize((string) $panelRow['value'], ['allowed_classes' => false]);
$panelTable['parent'][] = '友情链接';
$staleParentIndex = array_key_last($panelTable['parent']);
$staleMenuIndex = (int) $staleParentIndex + 10;
$stalePanel = rawurlencode(rawurlencode('FriendLinks/panel/links.php'));
$panelTable['child'][$staleMenuIndex][] = [
    '友链',
    '遗留菜单测试',
    'extending.php?panel=' . $stalePanel,
    'administrator',
    false,
    '',
];
$panelTable['file'][] = $stalePanel;
$panelValue = serialize($panelTable);
\Utils\Helper::options()->panelTable = $panelValue;
$db->query($db->update('table.options')->rows(['value' => $panelValue])
    ->where('name = ?', 'panelTable')->where('user = ?', 0));

Plugin::deactivate();
$panelRow = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'panelTable')->where('user = ?', 0)->limit(1));
$panelTable = unserialize((string) $panelRow['value'], ['allowed_classes' => false]);
$check(
    !in_array('友情链接', $panelTable['parent'] ?? [], true),
    'deactivation removes current and stale FriendLinks menus'
);
$remainingPanelReferences = [];
foreach ($panelTable['child'] ?? [] as $items) {
    foreach ((array) $items as $item) {
        $remainingPanelReferences[] = rawurldecode(rawurldecode((string) ($item[2] ?? '')));
    }
}
foreach ($panelTable['file'] ?? [] as $file) {
    $remainingPanelReferences[] = rawurldecode(rawurldecode((string) $file));
}
$check(
    !array_filter($remainingPanelReferences, static function ($reference) {
        return false !== strpos($reference, 'FriendLinks/panel/');
    }),
    'deactivation removes FriendLinks child panels and access whitelist entries'
);
$menuIndexRow = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'friendlinks_menu_index')->where('user = ?', 0)->limit(1));
$check(!$menuIndexRow, 'deactivation removes the stored FriendLinks menu index');

$db->query($db->delete('table.options')->where('name = ?', 'plugin:FriendLinks'));
Plugin::activate();
Plugin::configHandle(Settings::defaults(), true);
$check('minimal' === Settings::get('frontend_template'), 'settings survive deactivate and reactivate');

Plugin::deactivate();
$migration->uninstall();
foreach (['categories', 'links', 'current_status', 'check_history', 'runs', 'cache', 'notification_outbox'] as $table) {
    $tableExists = true;
    try {
        $db->fetchRow($db->select('1')->from('table.flm_' . $table)->limit(1));
    } catch (Throwable $error) {
        $tableExists = false;
    }
    $check(!$tableExists, 'uninstall dropped table: ' . $table);
}

fwrite(STDOUT, "OK: {$assertions} Typecho integration assertions\n");
