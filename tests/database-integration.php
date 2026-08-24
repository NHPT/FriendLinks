<?php

$typechoRoot = getenv('TYPECHO_SOURCE_ROOT');
$adapter = getenv('FLM_DB_ADAPTER');
if (!$typechoRoot || !is_file($typechoRoot . '/var/Typecho/Common.php')) {
    fwrite(STDERR, "TYPECHO_SOURCE_ROOT must point to Typecho source.\n");
    exit(2);
}
if (!in_array($adapter, ['Pdo_Mysql', 'Pdo_Pgsql'], true)) {
    fwrite(STDERR, "FLM_DB_ADAPTER must be Pdo_Mysql or Pdo_Pgsql.\n");
    exit(2);
}

define('__TYPECHO_ROOT_DIR__', $typechoRoot);
define('__TYPECHO_PLUGIN_DIR__', '/usr/plugins');
define('__TYPECHO_THEME_DIR__', '/usr/themes');
define('__TYPECHO_ADMIN_DIR__', '/admin/');
require $typechoRoot . '/var/Typecho/Common.php';
\Typecho\Common::init();
require dirname(__DIR__) . '/vendor/autoload.php';

use Typecho\Db;
use TypechoPlugin\FriendLinks\Infrastructure\Database;
use TypechoPlugin\FriendLinks\Infrastructure\MigrationManager;
use TypechoPlugin\FriendLinks\Infrastructure\Repositories;

$config = [
    'host' => getenv('FLM_DB_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('FLM_DB_PORT') ?: ('Pdo_Mysql' === $adapter ? 3306 : 5432)),
    'user' => getenv('FLM_DB_USER') ?: 'friendlinks',
    'password' => getenv('FLM_DB_PASSWORD') ?: 'friendlinks',
    'database' => getenv('FLM_DB_NAME') ?: 'friendlinks',
    'charset' => 'utf8',
];
$db = new Db($adapter, 'flmtest_');
$db->addServer($config, Db::READ | Db::WRITE);
Db::set($db);
$database = new Database($db);

if ('mysql' === $database->driver()) {
    $database->rawWrite(
        'CREATE TABLE IF NOT EXISTS `flmtest_options` ('
        . '`name` VARCHAR(64) NOT NULL, `user` INT NOT NULL DEFAULT 0, `value` LONGTEXT, '
        . 'PRIMARY KEY (`name`, `user`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
} else {
    $database->rawWrite(
        'CREATE TABLE IF NOT EXISTS "flmtest_options" ('
        . '"name" VARCHAR(64) NOT NULL, "user" INTEGER NOT NULL DEFAULT 0, "value" TEXT, '
        . 'PRIMARY KEY ("name", "user"))'
    );
}

$migration = new MigrationManager($database);
$migration->uninstall();
$migration->migrate();
$repositories = new Repositories($database);
$now = time();
$categoryId = $repositories->saveCategory([
    'name' => 'Database category',
    'slug' => 'database-category',
    'sort_order' => 0,
    'enabled' => 1,
    'created_at' => $now,
    'updated_at' => $now,
]);
$linkId = $repositories->createLink([
    'category_id' => $categoryId,
    'name' => 'Database 100%_integration',
    'url' => 'https://example.com/',
    'normalized_url' => 'https://example.com/',
    'url_hash' => hash('sha256', 'https://example.com/'),
    'description' => '',
    'logo_url' => null,
    'sort_order' => 0,
    'visibility' => 'published',
    'check_enabled' => 1,
    'created_at' => $now,
    'updated_at' => $now,
]);

$assertions = 0;
$check = static function ($condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $message);
    }
};

$check(
    1 === $repositories->schedule([$linkId], true)
        && 1 === $repositories->schedule([$linkId], true),
    'idempotent scheduling counts the eligible row'
);
$check(
    1 === count($repositories->links(['keywords' => '%']))
        && 1 === count($repositories->links(['keywords' => '_'])),
    'keyword search treats SQL wildcard characters literally'
);
$token = str_repeat('a', 32);
$leaseUntil = $now + 300;
$check($repositories->claim($linkId, $token, $now, $leaseUntil), 'task lease can be claimed');
$check(
    $repositories->renewLease($linkId, $token, $now, $leaseUntil),
    'unchanged lease deadline remains owned'
);
$repositories->schedule([$linkId], true);
$check(
    null === $repositories->claimedLink($linkId, $token),
    'new scheduling invalidates an older in-flight lease'
);
$lockedCategory = $repositories->transaction(function () use ($repositories, $categoryId) {
    return $repositories->categoryForUpdate($categoryId);
});
$check(
    $lockedCategory && (int) $lockedCategory['id'] === $categoryId,
    'category row can be locked inside a write transaction'
);

$dueBefore = $repositories->backlog($now)['due'];
$disabledId = $repositories->createLink([
    'category_id' => null,
    'name' => 'Disabled database integration',
    'url' => 'https://disabled.example.com/',
    'normalized_url' => 'https://disabled.example.com/',
    'url_hash' => hash('sha256', 'https://disabled.example.com/'),
    'description' => '',
    'logo_url' => null,
    'sort_order' => 0,
    'visibility' => 'published',
    'check_enabled' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);
$check(
    $dueBefore === $repositories->backlog($now)['due']
        && 0 === $repositories->schedule([$disabledId], true),
    'disabled links stay out of backlog and scheduling'
);
$check(
    1 === $repositories->archiveLinks([$linkId])
        && 'archived' === $repositories->link($linkId, true)['visibility'],
    'archive updates link and status in one operation'
);
$repositories->enqueueNotifications([[
    'event_key' => hash('sha256', 'database-integration-notification'),
    'link_id' => $disabledId,
    'event_type' => 'warning',
    'channel' => 'email',
    'subject' => 'Database integration',
    'message' => 'Database integration',
    'payload_json' => '{}',
    'status' => 'pending',
    'attempts' => 0,
    'available_at' => $now,
    'lease_token' => null,
    'lease_until' => null,
    'last_error' => null,
    'created_at' => $now,
    'sent_at' => null,
    '_cooldown' => 0,
]]);
$notification = $repositories->notifications(1)[0];
$notificationToken = str_repeat('b', 32);
$check(
    $repositories->claimNotification((int) $notification['id'], $notificationToken, $now, $now + 120),
    'notification lease can be claimed'
);
$repositories->markNotificationFailed(
    (int) $notification['id'],
    $notificationToken,
    $now + 300,
    str_repeat('错', 200)
);
$notification = $repositories->notifications(1)[0];
$check(
    strlen((string) $notification['last_error']) <= 500
        && 1 === preg_match('//u', (string) $notification['last_error']),
    'notification error remains valid UTF-8 after truncation'
);

$db->query($db->insert('table.options')->rows([
    'name' => 'friendlinks_cron_id',
    'user' => 0,
    'value' => str_repeat('c', 32),
]));
$db->query($db->insert('table.options')->rows([
    'name' => 'friendlinks_cron_owner',
    'user' => 0,
    'value' => '1000',
]));
$db->query($db->insert('table.options')->rows([
    'name' => 'friendlinks_cron_php',
    'user' => 0,
    'value' => '/usr/bin/php',
]));
$migration->uninstall();
$cronOption = $database->fetchRowWrite($db->select('value')->from('table.options')
    ->where(
        'name = ? OR name = ? OR name = ?',
        'friendlinks_cron_id',
        'friendlinks_cron_owner',
        'friendlinks_cron_php'
    )
    ->where('user = ?', 0)->limit(1));
$check(!$cronOption, 'uninstall removes Cron instance, system user and PHP CLI identifiers');
fwrite(STDOUT, "OK: {$assertions} {$database->driver()} database assertions\n");
