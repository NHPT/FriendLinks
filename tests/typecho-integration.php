<?php

$root = getenv('TYPECHO_TEST_ROOT');
if (!$root || !is_file($root . '/config.inc.php')) {
    fwrite(STDERR, "TYPECHO_TEST_ROOT must point to an installed Typecho tree.\n");
    exit(2);
}

require $root . '/config.inc.php';
if ('cli' === PHP_SAPI && !defined('__TYPECHO_ROOT_URL__')) {
    define('__TYPECHO_ROOT_URL__', 'http://localhost/');
}
\Widget\Init::alloc();
require dirname(__DIR__) . '/Plugin.php';
require dirname(__DIR__) . '/Action.php';

set_exception_handler(static function (\Throwable $error): void {
    fwrite(STDERR, $error . PHP_EOL);
    exit(1);
});

use Typecho\Db;
use Typecho\Plugin as TypechoPluginRegistry;
use Typecho\Widget\Helper\Form;
use TypechoPlugin\FriendLinks\Application\ImportService;
use TypechoPlugin\FriendLinks\Application\LinkService;
use TypechoPlugin\FriendLinks\Application\NotificationDispatcher;
use TypechoPlugin\FriendLinks\Application\Settings;
use TypechoPlugin\FriendLinks\Application\Worker;
use TypechoPlugin\FriendLinks\Domain\NotificationTemplate;
use TypechoPlugin\FriendLinks\Infrastructure\EmailNotificationChannel;
use TypechoPlugin\FriendLinks\Infrastructure\MigrationManager;
use TypechoPlugin\FriendLinks\Infrastructure\NotificationChannelInterface;
use TypechoPlugin\FriendLinks\Infrastructure\Repositories;
use TypechoPlugin\FriendLinks\Infrastructure\SystemCronManager;
use TypechoPlugin\FriendLinks\Plugin;
use TypechoPlugin\FriendLinks\Presentation\ContentInjector;
use TypechoPlugin\FriendLinks\Presentation\Renderer;
use TypechoPlugin\FriendLinks\Presentation\TemplateCatalog;
use Utils\Helper;
use Widget\Base\Options as OptionsWidget;

$assertions = 0;
$check = static function ($condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $message);
    }
};
$runProcess = static function (array $command, array $environment = []): array {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = getenv();
    $env = is_array($env) ? array_merge($env, $environment) : $environment;
    $process = proc_open($command, $descriptors, $pipes, null, $env, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start test process.');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
};

$fakeCronState = sys_get_temp_dir() . '/friendlinks-crontab-' . bin2hex(random_bytes(6));
@unlink($fakeCronState);
putenv('FRIENDLINKS_CRONTAB_BINARY=' . __DIR__ . '/fake-crontab.sh');
putenv('FRIENDLINKS_PHP_CLI=' . PHP_BINARY);
putenv('FRIENDLINKS_FAKE_CRONTAB_STATE=' . $fakeCronState);
putenv('FRIENDLINKS_FAKE_CRONTAB_REQUIRE_C_LOCALE=1');

$foreignMenuIndex = Helper::addMenu('友情链接');
Helper::addPanel(
    $foreignMenuIndex,
    'OtherLinks/panel/links.php',
    'Other links',
    'Other plugin links',
    'administrator'
);
$legacyMenuIndex = Helper::addMenu('友情链接 · FriendLinks');
Helper::addPanel(
    $legacyMenuIndex,
    'FriendLinks/panel/links.php',
    'Legacy links',
    'Legacy FriendLinks panel',
    'administrator'
);
$db->query($db->insert('table.options')->rows([
    'name' => 'friendlinks_menu_index',
    'user' => 0,
    'value' => (string) $legacyMenuIndex,
]));
Plugin::activate();
TypechoPluginRegistry::activate('FriendLinks');
$pluginRegistry = TypechoPluginRegistry::export();
$contentExHooks = $pluginRegistry['handles']['Widget_Abstract_Contents:contentEx']
    ?? $pluginRegistry['handles']['Widget_Base_Contents:contentEx']
    ?? [];
$check(
    isset($contentExHooks[99989]) && isset($pluginRegistry['handles']['Widget_Archive:footer']),
    'frontend renderer registers high-priority content hook and footer fallback'
);
$defaultWorkerSecret = Settings::defaults()['worker_secret'];
$check('' === $defaultWorkerSecret, 'default settings do not generate a transient HTTP Worker secret');
$check(
    0 === Settings::defaults()['http_worker_enabled'],
    'signed HTTP Worker is disabled by default'
);
$check(
    5 === Settings::defaults()['cron_interval_value']
        && 'minutes' === Settings::defaults()['cron_interval_unit']
        && 50 === Settings::defaults()['cli_worker_limit']
        && 240 === Settings::defaults()['cli_worker_max_seconds'],
    'CLI Worker uses bounded scheduling defaults'
);
Settings::initialize(Settings::defaults());
$initializedWorkerSecret = (string) Settings::get('worker_secret');
$check(
    1 === preg_match('/^[a-f0-9]{64}$/', $initializedWorkerSecret),
    'initial configuration generates and persists a random HTTP Worker secret'
);
$workerSecretRow = Db::get()->fetchRow(Db::get()->select('value')->from('table.options')
    ->where('name = ?', 'friendlinks_worker_secret')->where('user = ?', 0)->limit(1));
$check(
    $workerSecretRow && $initializedWorkerSecret === $workerSecretRow['value'],
    'HTTP Worker secret is stored in its dedicated database option'
);
$pluginSettingsRow = Db::get()->fetchRow(Db::get()->select('value')->from('table.options')
    ->where('name = ?', 'plugin:FriendLinks')->where('user = ?', 0)->limit(1));
$check(
    false === strpos((string) ($pluginSettingsRow['value'] ?? ''), $initializedWorkerSecret),
    'HTTP Worker secret is not duplicated in serialized plugin settings'
);
Plugin::activate();
$check(
    $initializedWorkerSecret === Settings::get('worker_secret'),
    'repeated activation preserves the HTTP Worker secret'
);
$cronContents = (string) file_get_contents($fakeCronState);
$ownCronId = preg_match('/^# BEGIN FriendLinks ([a-f0-9]{32})$/m', $cronContents, $cronMatch)
    ? $cronMatch[1]
    : '';
$check(
    '' !== $ownCronId
        && 1 === substr_count($cronContents, '# BEGIN FriendLinks ')
        && 1 === substr_count($cronContents, '# END FriendLinks ')
        && false !== strpos($cronContents, 'bin/console.php')
        && false !== strpos($cronContents, '* * * * *'),
    'activation installs exactly one managed FriendLinks Cron block'
);
$cronSeedRow = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'friendlinks_cron_id')->where('user = ?', 0)->limit(1));
$cronPhpRow = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'friendlinks_cron_php')->where('user = ?', 0)->limit(1));
$check(
    $cronSeedRow
        && 1 === preg_match('/^[a-f0-9]{32}$/', (string) $cronSeedRow['value'])
        && $ownCronId !== $cronSeedRow['value'],
    'Cron marker is derived from the persisted random seed and plugin path'
);
$check(
    $cronPhpRow && PHP_BINARY === $cronPhpRow['value'],
    'activation persists the verified PHP CLI path'
);
$cronManager = new SystemCronManager();
$check(
    !empty($cronManager->inspect()['installed'])
        && !empty($cronManager->status()['available'])
        && !empty($cronManager->status()['installed']),
    'Cron inspection confirms the automatically installed task'
);
$check(
    false !== strpos($cronContents, '* * * * *')
        && false !== strpos($cronContents, 'check --scheduled --due'),
    'automatic Cron wakes every minute and delegates exact scheduling to the CLI'
);
$consolePath = dirname(__DIR__) . '/bin/console.php';
$consoleHelp = $runProcess([PHP_BINARY, $consolePath, 'help']);
$check(
    0 === $consoleHelp['code']
        && false !== strpos($consoleHelp['stdout'], 'Usage: php bin/console.php')
        && '' === trim($consoleHelp['stderr']),
    'standalone CLI help does not require Typecho HTTP request initialization'
);
$consoleSelfTest = $runProcess([PHP_BINARY, $consolePath, 'self-test']);
$check(
    0 === $consoleSelfTest['code']
        && 'FriendLinks CLI ready' === trim($consoleSelfTest['stdout'])
        && false === strpos($consoleSelfTest['stderr'], 'Cookie.php')
        && false === strpos($consoleSelfTest['stdout'], '<!DOCTYPE html>'),
    'standalone CLI self-test initializes Typecho without Cookie host warnings or 500 output'
);
$restrictedRunner = static function (array $command): array {
    if ('-l' === ($command[1] ?? null)) {
        return ['code' => 1, 'stdout' => '', 'stderr' => 'no crontab for www'];
    }
    if ('-r' === ($command[1] ?? null)) {
        return ['code' => 0, 'stdout' => 'cli', 'stderr' => ''];
    }
    if ('self-test' === ($command[2] ?? null)) {
        return ['code' => 0, 'stdout' => "FriendLinks CLI ready\n", 'stderr' => ''];
    }
    return ['code' => 127, 'stdout' => '', 'stderr' => 'unexpected command'];
};
$restrictedCronManager = new SystemCronManager(
    null,
    $restrictedRunner,
    dirname(__DIR__),
    '/open-basedir-hidden/crontab',
    '/open-basedir-hidden/php',
    'Linux'
);
$findCrontabBinary = new ReflectionMethod(SystemCronManager::class, 'findCrontabBinary');
$findCrontabBinary->setAccessible(true);
$findPhpCli = new ReflectionMethod(SystemCronManager::class, 'findPhpCli');
$findPhpCli->setAccessible(true);
$check(
    '/open-basedir-hidden/crontab' === $findCrontabBinary->invoke($restrictedCronManager)
        && '/open-basedir-hidden/php' === $findPhpCli->invoke($restrictedCronManager),
    'Cron and PHP CLI discovery use execution probes when open_basedir hides binary metadata'
);
$invalidCronPathRejected = false;
try {
    $invalidCronManager = new SystemCronManager(
        null,
        $restrictedRunner,
        dirname(__DIR__),
        "relative/\x01crontab",
        '/open-basedir-hidden/php',
        'Linux'
    );
    $findCrontabBinary->invoke($invalidCronManager);
} catch (RuntimeException $error) {
    $invalidCronPathRejected = false !== strpos($error->getMessage(), '路径无效');
}
$check($invalidCronPathRejected, 'Cron discovery rejects unsafe command paths before execution');
$otherCronId = substr(hash('sha256', 'friendlinks-other-instance'), 0, 32);
$otherCronBlock = "# BEGIN FriendLinks {$otherCronId}\n"
    . "# Managed automatically by FriendLinks. Do not edit this block.\n"
    . "*/5 * * * * /bin/true\n"
    . "# END FriendLinks {$otherCronId}\n";
file_put_contents(
    $fakeCronState,
    "# unrelated task\n0 0 * * * /bin/true\n\n"
        . $otherCronBlock . "\n" . $cronContents . "\n" . $cronContents
);
Plugin::activate();
$cronContents = (string) file_get_contents($fakeCronState);
$check(
    1 === substr_count($cronContents, '# BEGIN FriendLinks ' . $ownCronId)
        && false !== strpos($cronContents, '# BEGIN FriendLinks ' . $otherCronId)
        && false !== strpos($cronContents, "# unrelated task\n0 0 * * * /bin/true"),
    'repeated activation de-duplicates this instance while preserving other Cron tasks'
);
file_put_contents($fakeCronState, $cronContents . "\n# task added after FriendLinks\n15 2 * * * /bin/true\n");
$check(
    !empty((new SystemCronManager())->inspect()['installed']),
    'Cron inspection accepts an exact managed block followed by unrelated tasks'
);
$cronContents = (string) file_get_contents($fakeCronState);
$cronOwnerRow = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'friendlinks_cron_owner')->where('user = ?', 0)->limit(1));
$db->query($db->update('table.options')->rows([
    'value' => (string) ((int) $cronOwnerRow['value'] + 1),
])->where('name = ?', 'friendlinks_cron_owner')->where('user = ?', 0));
$wrongOwnerRejected = false;
try {
    (new SystemCronManager())->remove();
} catch (RuntimeException $error) {
    $wrongOwnerRejected = false !== strpos($error->getMessage(), '不是 FriendLinks Cron 的安装用户');
}
$db->query($db->update('table.options')->rows([
    'value' => (string) $cronOwnerRow['value'],
])->where('name = ?', 'friendlinks_cron_owner')->where('user = ?', 0));
$check(
    $wrongOwnerRejected
        && $cronContents === file_get_contents($fakeCronState),
    'Cron removal refuses a different PHP system user without changing crontab'
);
$unsupportedCronRejected = false;
try {
    (new SystemCronManager(null, null, null, null, null, 'Darwin'))->install();
} catch (RuntimeException $error) {
    $unsupportedCronRejected = false !== strpos($error->getMessage(), '仅支持 Linux');
}
$check($unsupportedCronRejected, 'automatic Cron rejects unsupported operating systems');
$unsupportedCronStatus = (new SystemCronManager(
    null,
    null,
    null,
    null,
    null,
    'Darwin'
))->status();
$check(
    empty($unsupportedCronStatus['available'])
        && false !== strpos($unsupportedCronStatus['message'], '仅支持 Linux'),
    'Cron status reports unsupported environments without throwing'
);
$validCronContents = $cronContents;
$malformedCronContents = preg_replace(
    '/^# END FriendLinks [a-f0-9]{32}\n?/m',
    '',
    $validCronContents
);
file_put_contents($fakeCronState, $malformedCronContents);
$malformedCronRejected = false;
try {
    (new SystemCronManager())->install();
} catch (RuntimeException $error) {
    $malformedCronRejected = false !== strpos($error->getMessage(), '缺少结束标记');
}
$check(
    $malformedCronRejected
        && $malformedCronContents === file_get_contents($fakeCronState),
    'automatic Cron refuses malformed markers without overwriting the crontab'
);
file_put_contents($fakeCronState, $validCronContents);
$cronContents = $validCronContents;
$memoryCron = "# preserved cron\n";
$corruptNextWrite = true;
$runner = static function (array $command, string $input) use (&$memoryCron, &$corruptNextWrite): array {
    $argument = $command[1] ?? '';
    if ('-l' === $argument) {
        return ['code' => 0, 'stdout' => $memoryCron, 'stderr' => ''];
    }
    if ('-' === $argument) {
        $memoryCron = $corruptNextWrite
            ? str_replace('check --scheduled --due', 'check --corrupted --due', $input)
                . "# task added during verification\n30 3 * * * /bin/true\n"
            : $input;
        $corruptNextWrite = false;
        return ['code' => 0, 'stdout' => '', 'stderr' => ''];
    }
    if ('-r' === $argument) {
        return ['code' => 0, 'stdout' => 'cli', 'stderr' => ''];
    }
    if ('self-test' === ($command[2] ?? null)) {
        return ['code' => 0, 'stdout' => "FriendLinks CLI ready\n", 'stderr' => ''];
    }
    return ['code' => 0, 'stdout' => "Usage\n", 'stderr' => ''];
};
$rollbackCronRejected = false;
try {
    (new SystemCronManager(
        null,
        $runner,
        dirname(__DIR__),
        '/bin/sh',
        '/bin/sh',
        'Linux'
    ))->install();
} catch (RuntimeException $error) {
    $rollbackCronRejected = false !== strpos($error->getMessage(), '状态与预期不一致');
}
$check(
    $rollbackCronRejected
        && false !== strpos($memoryCron, '# preserved cron')
        && false !== strpos($memoryCron, '# task added during verification')
        && false === strpos($memoryCron, 'check --corrupted'),
    'automatic Cron rolls back only its block while preserving concurrent external tasks'
);
$cloneRoot = sys_get_temp_dir() . '/friendlinks-clone-' . bin2hex(random_bytes(6));
mkdir($cloneRoot . '/bin', 0777, true);
file_put_contents($cloneRoot . '/bin/console.php', "<?php\n");
$cloneCron = '';
$cloneRunner = static function (array $command, string $input) use (&$cloneCron): array {
    $argument = $command[1] ?? '';
    if ('-l' === $argument) {
        return ['code' => 0, 'stdout' => $cloneCron, 'stderr' => ''];
    }
    if ('-' === $argument) {
        $cloneCron = $input;
        return ['code' => 0, 'stdout' => '', 'stderr' => ''];
    }
    if ('-r' === $argument) {
        return ['code' => 0, 'stdout' => 'cli', 'stderr' => ''];
    }
    if ('self-test' === ($command[2] ?? null)) {
        return ['code' => 0, 'stdout' => "FriendLinks CLI ready\n", 'stderr' => ''];
    }
    return ['code' => 0, 'stdout' => "Usage\n", 'stderr' => ''];
};
$cloneInstall = (new SystemCronManager(
    null,
    $cloneRunner,
    $cloneRoot,
    '/bin/sh',
    '/bin/sh',
    'Linux'
))->install();
$check(
    $cloneInstall['cron_id'] !== $ownCronId
        && false !== strpos($cloneCron, '# BEGIN FriendLinks ' . $cloneInstall['cron_id']),
    'a cloned database derives a distinct Cron marker from the new plugin path'
);
@unlink($cloneRoot . '/bin/console.php');
@rmdir($cloneRoot . '/bin');
@rmdir($cloneRoot);

$db->query($db->delete('table.options')
    ->where('name = ?', 'friendlinks_cron_owner')->where('user = ?', 0));
$failedInstallRunner = static function (array $command): array {
    $argument = $command[1] ?? '';
    if ('-l' === $argument) {
        return ['code' => 1, 'stdout' => '', 'stderr' => 'no crontab for test'];
    }
    if ('-' === $argument) {
        return ['code' => 2, 'stdout' => '', 'stderr' => 'simulated write failure'];
    }
    if ('-r' === $argument) {
        return ['code' => 0, 'stdout' => 'cli', 'stderr' => ''];
    }
    if ('self-test' === ($command[2] ?? null)) {
        return ['code' => 0, 'stdout' => "FriendLinks CLI ready\n", 'stderr' => ''];
    }
    return ['code' => 0, 'stdout' => "Usage\n", 'stderr' => ''];
};
$failedInstallReleasedOwner = false;
try {
    (new SystemCronManager(
        null,
        $failedInstallRunner,
        dirname(__DIR__),
        '/bin/sh',
        '/bin/sh',
        'Linux'
    ))->install();
} catch (RuntimeException $error) {
    $ownerAfterFailure = $db->fetchRow($db->select('value')->from('table.options')
        ->where('name = ?', 'friendlinks_cron_owner')->where('user = ?', 0)->limit(1));
    $failedInstallReleasedOwner = !$ownerAfterFailure;
}
$check(
    $failedInstallReleasedOwner,
    'a failed first Cron installation releases the newly claimed system user'
);
(new SystemCronManager())->install();

$pluginInfo = TypechoPluginRegistry::parseInfo(dirname(__DIR__) . '/Plugin.php');
$check(
    '独立的 Typecho 友情链接管理、展示、健康检测与通知插件。' === $pluginInfo['description'],
    'plugin description is Chinese'
);
$check(
    'https://github.com/NHPT/FriendLinks' === $pluginInfo['homepage'],
    'plugin homepage points to the FriendLinks repository'
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
$check(
    false !== strpos($configHtml, 'flm-config-link'),
    'plugin config page renders the vertically centered settings link'
);
$check(
    false !== strpos($configHtml, 'flm-config-delete-confirmation')
        && false !== strpos($configHtml, 'typecho-option-submit{display:none}'),
    'plugin config page replaces the save control with explicit uninstall confirmation'
);
$check(
    is_subclass_of(\FriendLinks_Action::class, OptionsWidget::class),
    'plugin action initializes Typecho user, security, options, and database components'
);
$linkEditPanel = (string) file_get_contents(dirname(__DIR__) . '/panel/link-edit.php');
$linksPanel = (string) file_get_contents(dirname(__DIR__) . '/panel/links.php');
$categoriesPanel = (string) file_get_contents(dirname(__DIR__) . '/panel/categories.php');
$healthPanel = (string) file_get_contents(dirname(__DIR__) . '/panel/health.php');
$settingsPanel = (string) file_get_contents(dirname(__DIR__) . '/panel/settings.php');
$notificationsPanel = (string) file_get_contents(dirname(__DIR__) . '/panel/notifications.php');
$frontendStyles = (string) file_get_contents(dirname(__DIR__) . '/assets/frontend.css');
$adminScript = (string) file_get_contents(dirname(__DIR__) . '/assets/admin.js');
$adminStyles = (string) file_get_contents(dirname(__DIR__) . '/assets/admin.css');
$actionSource = (string) file_get_contents(dirname(__DIR__) . '/Action.php');
$pluginSource = (string) file_get_contents(dirname(__DIR__) . '/Plugin.php');
$consoleSource = (string) file_get_contents(dirname(__DIR__) . '/bin/console.php');
$settingsSource = (string) file_get_contents(dirname(__DIR__) . '/src/Application/Settings.php');
$repositoriesSource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Infrastructure/Repositories.php'
);
$cronSource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Infrastructure/SystemCronManager.php'
);
$saveLinkSource = preg_match(
    '/private function saveLink\\(\\): void(.*?)private function archiveLinks\\(\\): void/s',
    $actionSource,
    $saveLinkMatch
) ? $saveLinkMatch[1] : '';
$saveSettingsSource = preg_match(
    '/private function saveSettings\\(\\): void(.*?)private function saveNotifications\\(\\): void/s',
    $actionSource,
    $saveSettingsMatch
) ? $saveSettingsMatch[1] : '';
$testNotificationSource = preg_match(
    '/private function testNotification\\(\\): void(.*?)private function notificationInputFromRequest\\(\\): array/s',
    $actionSource,
    $testNotificationMatch
) ? $testNotificationMatch[1] : '';
$check(
    false === strpos($linkEditPanel, '最近状态')
        && false === strpos($linkEditPanel, "json_decode(\$link['details_json']")
        && false === strpos($linkEditPanel, 'StatusLabels::'),
    'link editor does not render detection status or diagnostic details'
);
$check(
    false !== strpos($saveLinkSource, '$repositories->schedule([$saved], true)')
        && false === strpos($saveLinkSource, '->run(')
        && false !== strpos($saveLinkSource, '&auto_check='),
    'saving a link queues detection and redirects to automatic background checking'
);
$check(
    false !== strpos($linksPanel, 'data-flm-auto-check')
        && false !== strpos($actionSource, "case 'run-check'")
        && false !== strpos($adminScript, 'initializeAutomaticCheck'),
    'link list automatically starts queued detection without a manual click'
);
$check(
    false !== strpos($linksPanel, 'data-flm-confirm-title="删除友链"')
        && false === strpos($linksPanel, 'confirm('),
    'link deletion uses the in-page confirmation dialog'
);
$check(
    false !== strpos($categoriesPanel, 'data-flm-confirm-title="删除分类"')
        && false === strpos($categoriesPanel, 'confirm('),
    'category deletion uses the in-page confirmation dialog'
);
$check(
    false === strpos($settingsPanel, "\$settings['worker_secret']"),
    'settings page does not render the HTTP Worker secret'
);
$check(
    false !== strpos($settingsPanel, 'data-flm-settings-tab="display"')
        && false !== strpos($settingsPanel, 'data-flm-settings-tab="detection"')
        && false !== strpos($settingsPanel, 'data-flm-settings-tab="cli-worker"')
        && false !== strpos($settingsPanel, 'data-flm-settings-tab="worker"')
        && false !== strpos($settingsPanel, '<div class="col-mb-12">')
        && false === strpos($settingsPanel, 'col-tb-offset'),
    'settings page uses the same full content width as other FriendLinks pages'
);
$check(
    false === strpos($settingsPanel, 'create-page')
        && false === strpos($settingsPanel, 'clear-page-template')
        && false === strpos($actionSource, "case 'create-page'")
        && false === strpos($actionSource, "case 'clear-page-template'"),
    'plugin does not create or modify Typecho pages'
);
$check(
    false !== strpos($settingsPanel, 'name="worker_secret_new"')
        && false !== strpos($settingsPanel, 'data-flm-confirm-title="轮换 HTTP Worker 密钥"')
        && false !== strpos($adminScript, 'initializeConfirmDialog'),
    'worker secret rotation uses user input and the shared in-page dialog'
);
$check(
    false !== strpos($settingsPanel, 'name="http_worker_enabled"')
        && false !== strpos($actionSource, "'worker_disabled'"),
    'signed HTTP Worker requires explicit administrator enablement'
);
$check(
    false === strpos($healthPanel, 'SystemCronManager')
        && false === strpos($healthPanel, '自动定时任务')
        && false === strpos($healthPanel, '手动配置')
        && false === strpos($healthPanel, 'flm-code'),
    'health page contains no Cron management section'
);
$check(
    false !== strpos($settingsPanel, 'name="cron_interval_value"')
        && false !== strpos($settingsPanel, 'name="cron_interval_unit"')
        && false !== strpos($settingsPanel, 'name="cli_worker_limit"')
        && false !== strpos($settingsPanel, 'name="cli_worker_max_seconds"')
        && false !== strpos($settingsPanel, 'data-min=')
        && false !== strpos($settingsPanel, 'data-max=')
        && false !== strpos($settingsSource, "'seconds'")
        && false !== strpos($settingsSource, "'weeks'")
        && false !== strpos($settingsSource, "'months'")
        && false !== strpos($settingsPanel, "\$cronStatus['available']")
        && false !== strpos($settingsPanel, "latestRunByMode('cli')")
        && false !== strpos($cronSource, 'check --scheduled --due')
        && false !== strpos($consoleSource, 'Settings::cronIntervalSeconds')
        && false !== strpos($consoleSource, 'claimCliSchedule')
        && false !== strpos($repositoriesSource, "latestRunByMode('cli', true)")
        && false !== strpos($consoleSource, "\$settings['cli_worker_limit']")
        && false !== strpos($consoleSource, "\$settings['cli_worker_max_seconds']"),
    'CLI Worker exposes bounded scheduling controls and reports automatic task status'
);
$check(
    false !== strpos($settingsPanel, 'class="flm-error" role="alert"')
        && false !== strpos($settingsPanel, '手工部署 CLI Cron')
        && false !== strpos($settingsPanel, 'data-flm-cron-unavailable=')
        && false !== strpos($settingsPanel, 'data-flm-settings-save')
        && false !== strpos($adminScript, "cronUnavailable && id === 'cli-worker'")
        && false !== strpos($adminStyles, '.flm-admin .flm-error')
        && false !== strpos($adminStyles, 'color: var(--flm-admin-bad)')
        && substr_count($settingsPanel, "\$cronDisabled ? ' disabled' : ''") >= 4,
    'unavailable automatic Cron shows a critical fallback notice and disables CLI-only controls'
);
$check(
    false !== strpos($frontendStyles, '.flm-root .flm-list')
        && false !== strpos($frontendStyles, 'margin: 0 !important')
        && false !== strpos($frontendStyles, 'padding: 0 !important')
        && false !== strpos($frontendStyles, '.flm-root .flm-item')
        && false !== strpos($frontendStyles, 'html.theme-dark .flm-root')
        && false !== strpos($frontendStyles, '--flm-bg: var(--theme-surface')
        && false !== strpos($frontendStyles, '--flm-accent: var(--theme-accent')
        && false !== strpos($frontendStyles, '--flm-accent-soft: var(--theme-accent-soft'),
    'frontend renderer clears theme list spacing and follows theme color variables'
);
$check(
    false === strpos($notificationsPanel, '测试已保存配置')
        && false !== strpos($notificationsPanel, '发送测试消息')
        && false !== strpos($notificationsPanel, 'data-flm-notification-test="webhook"')
        && false !== strpos($notificationsPanel, 'data-flm-notification-test="dingtalk"')
        && false !== strpos($notificationsPanel, 'data-flm-notification-test="email"')
        && false !== strpos($notificationsPanel, 'data-flm-configured=')
        && false !== strpos($notificationsPanel, 'flm-notification-action')
        && false !== strpos($adminStyles, '.flm-admin .flm-notification-action')
        && false !== strpos($adminScript, 'initializeNotificationTestButtons')
        && false !== strpos($adminScript, "configuredValue('webhook_url'")
        && false !== strpos($adminScript, "configuredValue('dingtalk_webhook_url'")
        && false !== strpos($adminScript, "configuredValue('smtp_password'")
        && false !== strpos($testNotificationSource, 'notificationInputFromRequest')
        && false !== strpos($testNotificationSource, "\$input[\$channel . '_enabled'] = 1"),
    'notification test buttons use current form values with clear labels and visible styling'
);
$cronStatusPosition = strpos($saveSettingsSource, '$cronStatus = $cron->status();');
$cronGuardPosition = strpos($saveSettingsSource, "if (!empty(\$cronStatus['available']))");
$cronInputPosition = strpos($saveSettingsSource, "'cron_interval_value' =>");
$check(
    false !== $cronStatusPosition
        && false !== $cronGuardPosition
        && false !== $cronInputPosition
        && $cronStatusPosition < $cronGuardPosition
        && $cronGuardPosition < $cronInputPosition,
    'server ignores submitted CLI scheduling values while automatic Cron is unavailable'
);
$check(
    false !== strpos($actionSource, 'FriendLinksPlugin::uninstall()')
        && false === strpos($actionSource, "Helper::removePlugin('FriendLinks')"),
    'explicit uninstall uses the plugin-owned lifecycle path without swallowed deactivation errors'
);
$check(
    false === strpos($pluginSource, 'panelTable')
        && false === strpos($pluginSource, 'debugLifecycleState')
        && false === strpos($pluginSource, '.dbg'),
    'plugin lifecycle uses only public menu APIs and contains no debug telemetry'
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
$allMenusHaveChildren = true;
foreach ($panelTable['parent'] ?? [] as $key => $name) {
    if (!isset($panelTable['child'][10 + $key]) || !is_array($panelTable['child'][10 + $key])) {
        $allMenusHaveChildren = false;
        break;
    }
}
$check(
    $allMenusHaveChildren,
    'test fixture keeps every extension menu compatible with Typecho Widget Menu iteration'
);
$friendMenus = array_filter($panelTable['parent'], static function ($name) {
    return '友情链接 ' === $name;
});
$check(
    1 === count($friendMenus)
        && '友情链接' === trim((string) reset($friendMenus))
        && !in_array('友情链接 · FriendLinks', $panelTable['parent'] ?? [], true),
    'repeated activation keeps one visually concise FriendLinks admin menu'
);
$foreignMenus = array_filter($panelTable['parent'], static function ($name) {
    return '友情链接' === $name;
});
$check(
    !empty($foreignMenus)
        && isset($panelTable['parent'][$foreignMenuIndex - 10])
        && '友情链接' === $panelTable['parent'][$foreignMenuIndex - 10],
    'FriendLinks registration preserves another plugin menu with a generic label'
);
$menuIndexRow = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'friendlinks_menu_index')->where('user = ?', 0)->limit(1));
$check(
    $menuIndexRow && isset($panelTable['child'][(int) $menuIndexRow['value']]),
    'activation persists the FriendLinks menu index'
);
$menuNameRow = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'friendlinks_menu_name')->where('user = ?', 0)->limit(1));
$check(
    $menuNameRow && '友情链接 ' === $menuNameRow['value'],
    'activation persists the exact internal menu removal key'
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
$cliSettings = Settings::sanitize([
    'cron_interval_value' => 3,
    'cron_interval_unit' => 'hours',
    'cli_worker_limit' => 75,
    'cli_worker_max_seconds' => 180,
]);
$check(
    10800 === Settings::cronIntervalSeconds($cliSettings)
        && 75 === $cliSettings['cli_worker_limit']
        && 180 === $cliSettings['cli_worker_max_seconds'],
    'CLI Worker settings accept bounded scheduling and processing values'
);
$settingsBeforeAvailablePost = Settings::all();
$originalPost = $_POST;
$_POST = $settingsBeforeAvailablePost;
$_POST['cron_interval_value'] = 1;
$_POST['cron_interval_unit'] = 'days';
$_POST['cli_worker_limit'] = 77;
$_POST['cli_worker_max_seconds'] = 180;
\Typecho\Cookie::setPrefix('http://localhost/');
$saveSettings = new ReflectionMethod(\FriendLinks_Action::class, 'saveSettings');
$saveSettings->setAccessible(true);
$saveSettings->invoke(\FriendLinks_Action::alloc());
$_POST = $originalPost;
$settingsAfterAvailablePost = Settings::all();
$check(
    1 === (int) $settingsAfterAvailablePost['cron_interval_value']
        && 'days' === $settingsAfterAvailablePost['cron_interval_unit']
        && 86400 === Settings::cronIntervalSeconds($settingsAfterAvailablePost)
        && 77 === (int) $settingsAfterAvailablePost['cli_worker_limit']
        && 180 === (int) $settingsAfterAvailablePost['cli_worker_max_seconds'],
    'available automatic Cron saves CLI scheduling values through the settings action'
);
$coexistingWorkers = Settings::sanitize([
    'cron_interval_value' => 2,
    'cron_interval_unit' => 'days',
    'cli_worker_limit' => 25,
    'cli_worker_max_seconds' => 120,
    'http_worker_enabled' => 1,
]);
$check(
    172800 === Settings::cronIntervalSeconds($coexistingWorkers)
        && 1 === $coexistingWorkers['http_worker_enabled'],
    'CLI and HTTP Worker settings can be enabled together'
);
$extendedCliSettings = Settings::sanitize([
    'cron_interval_value' => 2,
    'cron_interval_unit' => 'weeks',
]);
$check(
    120 === Settings::cronIntervalSeconds([
        'cron_interval_value' => 120,
        'cron_interval_unit' => 'seconds',
    ])
        && 1209600 === Settings::cronIntervalSeconds($extendedCliSettings)
        && 2592000 === Settings::cronIntervalSeconds([
            'cron_interval_value' => 1,
            'cron_interval_unit' => 'months',
        ]),
    'CLI Worker settings support seconds, weeks, and 30-day month intervals'
);
$invalidCliSettingsRejected = false;
try {
    Settings::sanitize([
        'cron_interval_value' => 59,
        'cron_interval_unit' => 'seconds',
    ]);
} catch (InvalidArgumentException $error) {
    $invalidCliSettingsRejected = true;
}
$check($invalidCliSettingsRejected, 'CLI Worker settings reject sub-minute scheduling intervals');
$rotatedWorkerSecret = str_repeat('a', 64);
Settings::rotateWorkerSecret($rotatedWorkerSecret);
$check(
    $rotatedWorkerSecret === Settings::get('worker_secret'),
    'administrator-provided HTTP Worker secret is persisted'
);
$invalidWorkerSecretRejected = false;
try {
    Settings::rotateWorkerSecret('too-short');
} catch (InvalidArgumentException $error) {
    $invalidWorkerSecretRejected = true;
}
$check($invalidWorkerSecretRejected, 'HTTP Worker secret must be 64 hexadecimal characters');

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
$plainAuthRejected = false;
try {
    Settings::sanitizeNotifications(array_merge($emailSettings, [
        'smtp_encryption' => 'none',
    ]));
} catch (InvalidArgumentException $error) {
    $plainAuthRejected = false !== strpos($error->getMessage(), '无加密 SMTP');
}
$check($plainAuthRejected, 'notification settings reject SMTP authentication without transport encryption');
$remotePlaintextRejected = false;
try {
    Settings::sanitizeNotifications(array_merge($emailSettings, [
        'smtp_encryption' => 'none',
        'smtp_username' => '',
        'smtp_password' => '',
    ]));
} catch (InvalidArgumentException $error) {
    $remotePlaintextRejected = false !== strpos($error->getMessage(), '回环地址');
}
$check($remotePlaintextRejected, 'notification settings restrict plaintext SMTP to loopback relays');
$localRelaySettings = Settings::sanitizeNotifications(array_merge($emailSettings, [
    'smtp_host' => '127.0.0.1',
    'smtp_port' => 25,
    'smtp_encryption' => 'none',
    'smtp_username' => '',
    'smtp_password' => '',
]));
$check(
    'none' === $localRelaySettings['smtp_encryption'],
    'notification settings allow an unauthenticated loopback SMTP relay'
);
$senderPlaintextRejected = false;
try {
    (new EmailNotificationChannel())->send([
        'subject' => 'Security regression',
        'message' => 'No notification may be sent over remote plaintext SMTP.',
    ], array_merge($emailSettings, [
        'smtp_encryption' => 'none',
        'smtp_username' => '',
        'smtp_password' => '',
    ]));
} catch (Throwable $error) {
    $senderPlaintextRejected = false !== strpos($error->getMessage(), '回环地址');
}
$check($senderPlaintextRejected, 'SMTP sender independently rejects remote plaintext delivery');

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
$backlogBeforeDisabled = $repositories->backlog(time());
$disabledBacklogLinkId = $service->save([
    'name' => 'Disabled backlog link',
    'url' => 'https://disabled-backlog.example.com/',
    'description' => '',
    'logo_url' => '',
    'category_id' => 0,
    'sort_order' => 0,
    'visibility' => 'published',
    'check_enabled' => 0,
]);
$backlogAfterDisabled = $repositories->backlog(time());
$check(
    $backlogBeforeDisabled['due'] === $backlogAfterDisabled['due']
        && 0 === $repositories->schedule([$disabledBacklogLinkId], true),
    'disabled links are excluded from backlog and manual scheduling'
);
$searchLinkId = $service->save([
    'name' => '100% reliable_name',
    'url' => 'https://search-literal.example.com/',
    'description' => '',
    'logo_url' => '',
    'category_id' => 0,
    'sort_order' => 0,
    'visibility' => 'draft',
    'check_enabled' => 0,
]);
$percentMatches = array_map('intval', array_column($repositories->links(['keywords' => '%']), 'id'));
$underscoreMatches = array_map('intval', array_column($repositories->links(['keywords' => '_']), 'id'));
$check(
    in_array($searchLinkId, $percentMatches, true) && in_array($searchLinkId, $underscoreMatches, true),
    'keyword search treats percent and underscore as literal characters'
);

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
$archiveReflection = new ReflectionClass(\Widget\Archive::class);
$archive = $archiveReflection->newInstanceWithoutConstructor();
foreach ([
    'archiveType' => 'page',
    'archiveSingle' => true,
    'makeSinglePageAsFrontPage' => false,
    'archiveSlug' => 'friendlinks',
] as $property => $value) {
    $archiveProperty = $archiveReflection->getProperty($property);
    $archiveProperty->setAccessible(true);
    $archiveProperty->setValue($archive, $value);
}
$archive->cid = $pageId;
ob_start();
ContentInjector::footer($archive);
$footerFallback = (string) ob_get_clean();
$check(
    false !== strpos($footerFallback, 'flm-footer-fallback-template')
        && false !== strpos($footerFallback, 'document.querySelector(".post-content")')
        && false !== strpos($footerFallback, '&lt;Example&gt;')
        && false === strpos($footerFallback, '<Example>'),
    'frontend footer fallback can inject rendered links when theme bypasses content hooks'
);
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
$leaseNow = time();
$leaseUntil = $leaseNow + 300;
$check($repositories->claim($linkId, $token, $leaseNow, $leaseUntil), 'due task was claimed atomically');
$claimed = $repositories->claimedLink($linkId, $token);
$check($claimed && (int) $claimed['id'] === $linkId, 'claimed task can be loaded by token');
$check(
    $repositories->renewLease($linkId, $token, $leaseNow, $leaseUntil),
    'active detection lease renewal accepts an unchanged lease deadline'
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
$check(
    1 === $repositories->schedule([$linkId], true)
        && 1 === $repositories->schedule([$linkId], true),
    'scheduling counts eligible links even when due timestamps are already zero'
);
$supersededToken = str_repeat('1', 32);
$supersededNow = time();
$check(
    $repositories->claim($linkId, $supersededToken, $supersededNow, $supersededNow + 300),
    'scheduled task can be claimed before a newer manual request'
);
$repositories->schedule([$linkId], true);
$check(
    null === $repositories->claimedLink($linkId, $supersededToken),
    'a newer manual schedule invalidates the in-flight lease'
);
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
    str_repeat('错', 200)
);
$failedNotification = null;
foreach ($repositories->notifications(20) as $notification) {
    if ((int) $notification['id'] === $notificationId) {
        $failedNotification = $notification;
        break;
    }
}
$check(
    $failedNotification
        && strlen((string) $failedNotification['last_error']) <= 500
        && 1 === preg_match('//u', (string) $failedNotification['last_error']),
    'notification errors are truncated at a valid UTF-8 boundary'
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
$bomPreview = $importService->preview(
    'csv',
    "\xEF\xBB\xBFname,url,visibility,check_enabled\n"
        . "BOM import,https://bom-import.example.com/,draft,false\n"
);
$check(
    1 === count($bomPreview)
        && empty($bomPreview[0]['errors'])
        && 0 === $bomPreview[0]['check_enabled'],
    'CSV import accepts a UTF-8 BOM and preserves explicit false values'
);
$invalidImportPreview = $importService->preview('json', (string) json_encode([[
    'name' => 'Invalid visibility',
    'url' => 'https://invalid-visibility.example.com/',
    'visibility' => 'private',
    'check_enabled' => 'false',
]]));
$check(
    !empty($invalidImportPreview[0]['errors'])
        && false !== strpos(implode(' ', $invalidImportPreview[0]['errors']), '可见性'),
    'import preview reports an invalid visibility instead of publishing the row'
);
$invalidImportResult = $importService->import($invalidImportPreview);
$check(
    0 === $invalidImportResult['created'] && 1 === $invalidImportResult['skipped'],
    'final import skips a row with invalid visibility'
);
$falseBooleanPreview = $importService->preview('json', (string) json_encode([[
    'name' => 'Disabled imported check',
    'url' => 'https://disabled-import.example.com/',
    'visibility' => 'draft',
    'check_enabled' => 'false',
]]));
$check(
    empty($falseBooleanPreview[0]['errors']) && 0 === $falseBooleanPreview[0]['check_enabled'],
    'import parses the string false as disabled detection'
);
$falseBooleanResult = $importService->import($falseBooleanPreview);
$importedDisabled = array_values(array_filter(
    $repositories->exportLinks(),
    static function ($link) {
        return 'https://disabled-import.example.com/' === $link['url'];
    }
));
$check(
    1 === $falseBooleanResult['created']
        && 1 === count($importedDisabled)
        && 'draft' === $importedDisabled[0]['visibility']
        && 0 === (int) $importedDisabled[0]['check_enabled'],
    'final import preserves explicit draft visibility and disabled detection'
);
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

$deleteLinkId = $service->save([
    'name' => 'Delete integration link',
    'url' => 'https://delete.example.com/',
    'description' => '',
    'logo_url' => '',
    'category_id' => 0,
    'sort_order' => 0,
    'visibility' => 'published',
    'check_enabled' => 0,
]);
$db->query($db->insert('table.flm_check_history')->rows([
    'link_id' => $deleteLinkId,
    'run_id' => str_repeat('9', 32),
    'overall_state' => 'disabled',
    'reason_code' => null,
    'http_code' => null,
    'response_time_ms' => null,
    'started_at' => time(),
    'finished_at' => time(),
    'details_json' => '{}',
]));
$repositories->enqueueNotifications([[
    'event_key' => hash('sha256', 'delete-link-notification'),
    'link_id' => $deleteLinkId,
    'event_type' => 'warning',
    'channel' => 'email',
    'subject' => 'Delete link notification',
    'message' => 'Delete link notification',
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
$check($repositories->deleteLink($deleteLinkId), 'link can be permanently deleted');
$check(null === $repositories->link($deleteLinkId), 'deleted link is no longer available');
$check([] === $repositories->history(10, $deleteLinkId), 'deleting a link removes its history');
$deleteNotifications = array_filter($repositories->notifications(100), static function ($notification) use ($deleteLinkId) {
    return (int) $notification['link_id'] === $deleteLinkId;
});
$check(!$deleteNotifications, 'deleting a link removes its notification outbox rows');

$workerFailureSettings = Settings::all();
$workerFailureSettings['notifications_enabled'] = 0;
Settings::save($workerFailureSettings);
$blockedLinkId = $service->save([
    'name' => 'Blocked target',
    'url' => 'https://127.0.0.1/',
    'description' => '',
    'logo_url' => '',
    'category_id' => 0,
    'sort_order' => 0,
    'visibility' => 'published',
    'check_enabled' => 1,
]);
$blockedStartedAt = time();
$db->query($db->update('table.flm_current_status')->rows([
    'domain_next_check_at' => $blockedStartedAt + 86400,
])->where('link_id = ?', $blockedLinkId));
$blockedResult = (new Worker($repositories))->run('admin', 1, 5, [$blockedLinkId]);
$blockedStatus = $db->fetchRow($db->select()->from('table.flm_current_status')
    ->where('link_id = ?', $blockedLinkId)->limit(1));
$check(
    1 === $blockedResult['completed']
        && 0 === $blockedResult['failed']
        && (int) $blockedStatus['next_check_at'] > $blockedStartedAt
        && (int) $blockedStatus['http_next_check_at'] > $blockedStartedAt
        && (int) $blockedStatus['tls_next_check_at'] > $blockedStartedAt
        && null === $blockedStatus['http_checked_at']
        && null === $blockedStatus['tls_checked_at'],
    'DNS policy failures back off due HTTP and TLS work without marking either probe as completed'
);
$workerFailureDatabase = new \TypechoPlugin\FriendLinks\Infrastructure\Database();
$quote = 'mysql' === $workerFailureDatabase->driver() ? '`' : '"';
$statusTable = $workerFailureDatabase->prefix() . 'flm_current_status';
$hiddenStatusTable = $workerFailureDatabase->prefix() . 'flm_current_status_failure_test';
$workerFailureDatabase->rawWrite(
    'ALTER TABLE ' . $quote . $statusTable . $quote
    . ' RENAME TO ' . $quote . $hiddenStatusTable . $quote
);
try {
    $workerFailureResult = (new Worker())->run('cli', 1, 2);
} finally {
    $workerFailureDatabase->rawWrite(
        'ALTER TABLE ' . $quote . $hiddenStatusTable . $quote
        . ' RENAME TO ' . $quote . $statusTable . $quote
    );
}
$failedRun = $repositories->latestRuns(1)[0] ?? null;
$latestCliRun = $repositories->latestRunByMode('cli');
$check(
    0 === $workerFailureResult['completed']
        && $workerFailureResult['failed'] >= 1
        && $failedRun
        && $latestCliRun
        && $latestCliRun['run_id'] === $failedRun['run_id']
        && 'failed' === $failedRun['status']
        && (int) $failedRun['failed_count'] >= 1,
    'worker top-level database failures propagate to result counters and run status'
);
$scheduleClaimNow = time() + 7200;
$firstScheduleClaim = $repositories->claimCliSchedule($scheduleClaimNow, 300, 240);
$secondScheduleClaim = $repositories->claimCliSchedule($scheduleClaimNow, 300, 240);
$check(
    !empty($firstScheduleClaim['due'])
        && 1 === preg_match('/^[a-f0-9]{32}$/', (string) $firstScheduleClaim['run_id'])
        && empty($secondScheduleClaim['due'])
        && 'worker_running' === $secondScheduleClaim['reason'],
    'scheduled CLI claim atomically creates one running record before Worker startup'
);
$scheduledWorkerResult = (new Worker($repositories))->run(
    'cli',
    1,
    1,
    [PHP_INT_MAX],
    $firstScheduleClaim['run_id']
);
$scheduledRun = $repositories->latestRunByMode('cli', true);
$check(
    $scheduledRun
        && $firstScheduleClaim['run_id'] === $scheduledWorkerResult['run_id']
        && $firstScheduleClaim['run_id'] === $scheduledRun['run_id']
        && 'completed' === $scheduledRun['status'],
    'scheduled Worker completes the pre-created run without inserting a duplicate'
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

putenv('FRIENDLINKS_FAKE_CRONTAB_FAIL_WRITE=1');
$failedDeactivationRejected = false;
$failedDeactivationMessage = '';
try {
    Plugin::deactivate();
} catch (\Typecho\Plugin\Exception $error) {
    $failedDeactivationMessage = $error->getMessage();
    $failedDeactivationRejected = false !== strpos($error->getMessage(), '无法写入当前用户 crontab');
}
putenv('FRIENDLINKS_FAKE_CRONTAB_FAIL_WRITE');
$panelRow = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'panelTable')->where('user = ?', 0)->limit(1));
$panelTable = unserialize((string) $panelRow['value'], ['allowed_classes' => false]);
$check(
    $failedDeactivationRejected
        && in_array('友情链接 ', $panelTable['parent'] ?? [], true)
        && false !== strpos(
            (string) file_get_contents($fakeCronState),
            '# BEGIN FriendLinks ' . $ownCronId
        ),
    'Cron removal failure rejects deactivation before removing admin registration'
);

Plugin::deactivate();
$cronContents = (string) file_get_contents($fakeCronState);
$check(
    false === strpos($cronContents, '# BEGIN FriendLinks ' . $ownCronId)
        && false !== strpos($cronContents, '# BEGIN FriendLinks ' . $otherCronId)
        && false !== strpos($cronContents, "# unrelated task\n0 0 * * * /bin/true"),
    'deactivation removes only this instance Cron block'
);
$cronMetadataAfterDeactivate = $db->fetchRow($db->select('value')->from('table.options')
    ->where(
        'name = ? OR name = ? OR name = ?',
        'friendlinks_cron_id',
        'friendlinks_cron_owner',
        'friendlinks_cron_php'
    )
    ->where('user = ?', 0)->limit(1));
$check(!$cronMetadataAfterDeactivate, 'deactivation removes automatic Cron metadata');
$panelRow = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'panelTable')->where('user = ?', 0)->limit(1));
$panelTable = unserialize((string) $panelRow['value'], ['allowed_classes' => false]);
$check(
    !in_array('友情链接 ', $panelTable['parent'] ?? [], true),
    'deactivation removes the registered FriendLinks menu'
);
$check(
    in_array('友情链接', $panelTable['parent'] ?? [], true),
    'deactivation does not remove another plugin menu with a generic label'
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
$menuNameRow = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'friendlinks_menu_name')->where('user = ?', 0)->limit(1));
$check(!$menuNameRow, 'deactivation removes the stored FriendLinks menu key');

$db->query($db->delete('table.options')->where('name = ?', 'plugin:FriendLinks'));
Plugin::activate();
Plugin::configHandle(Settings::defaults(), true);
$reactivatedCronContents = (string) file_get_contents($fakeCronState);
$reactivatedCronId = '';
preg_match_all(
    '/^# BEGIN FriendLinks ([a-f0-9]{32})$/m',
    $reactivatedCronContents,
    $reactivatedCronMatch
);
foreach ($reactivatedCronMatch[1] ?? [] as $candidateCronId) {
    if ($candidateCronId !== $otherCronId) {
        $reactivatedCronId = $candidateCronId;
        break;
    }
}
$check(
    !empty((new SystemCronManager())->inspect()['installed']),
    'reactivation automatically restores the managed Cron task'
);
$check('minimal' === Settings::get('frontend_template'), 'settings survive deactivate and reactivate');
$check(
    $rotatedWorkerSecret === Settings::get('worker_secret'),
    'HTTP Worker secret survives deactivate and reactivate without external changes'
);

Plugin::deactivate();
$check(
    '' !== $reactivatedCronId
        && false === strpos(
        (string) file_get_contents($fakeCronState),
        '# BEGIN FriendLinks ' . $reactivatedCronId
        ),
    'final deactivation removes the restored Cron task'
);
putenv('FRIENDLINKS_FAKE_CRONTAB_FAIL_WRITE=1');
$manualActivationMessage = Plugin::activate();
putenv('FRIENDLINKS_FAKE_CRONTAB_FAIL_WRITE');
$panelRow = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'panelTable')->where('user = ?', 0)->limit(1));
$panelTable = unserialize((string) $panelRow['value'], ['allowed_classes' => false]);
$manualCronMetadata = $db->fetchRow($db->select('value')->from('table.options')
    ->where(
        'name = ? OR name = ? OR name = ?',
        'friendlinks_cron_id',
        'friendlinks_cron_owner',
        'friendlinks_cron_php'
    )
    ->where('user = ?', 0)->limit(1));
$manualCronError = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'friendlinks_cron_error')->where('user = ?', 0)->limit(1));
$manualCronStatus = (new SystemCronManager())->status();
$check(
    false !== strpos($manualActivationMessage, '请按 README 手工配置')
        && in_array('友情链接 ', $panelTable['parent'] ?? [], true)
        && !$manualCronMetadata
        && $manualCronError
        && empty($manualCronStatus['available'])
        && false !== strpos(
            (string) file_get_contents($fakeCronState),
            '# BEGIN FriendLinks ' . $otherCronId
        ),
    'an unavailable automatic Cron falls back to manual scheduling without stale metadata'
);
$settingsBeforeUnavailablePost = Settings::all();
$originalPost = $_POST;
$_POST = $settingsBeforeUnavailablePost;
$_POST['cron_interval_value'] = 2;
$_POST['cron_interval_unit'] = 'days';
$_POST['cli_worker_limit'] = 1;
$_POST['cli_worker_max_seconds'] = 30;
\Typecho\Cookie::setPrefix('http://localhost/');
$saveSettings = new ReflectionMethod(\FriendLinks_Action::class, 'saveSettings');
$saveSettings->setAccessible(true);
$saveSettings->invoke(\FriendLinks_Action::alloc());
$_POST = $originalPost;
$settingsAfterUnavailablePost = Settings::all();
$check(
    $settingsBeforeUnavailablePost['cron_interval_value']
        === $settingsAfterUnavailablePost['cron_interval_value']
        && $settingsBeforeUnavailablePost['cron_interval_unit']
        === $settingsAfterUnavailablePost['cron_interval_unit']
        && $settingsBeforeUnavailablePost['cli_worker_limit']
        === $settingsAfterUnavailablePost['cli_worker_limit']
        && $settingsBeforeUnavailablePost['cli_worker_max_seconds']
        === $settingsAfterUnavailablePost['cli_worker_max_seconds'],
    'server ignores forged CLI scheduling values while automatic Cron is unavailable'
);
Plugin::uninstall();
$manualCronError = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'friendlinks_cron_error')->where('user = ?', 0)->limit(1));
$check(
    !$manualCronError && !TypechoPluginRegistry::exists('FriendLinks'),
    'explicit uninstall clears fallback state and persists the disabled plugin registry'
);
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
$workerSecretRow = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'friendlinks_worker_secret')->where('user = ?', 0)->limit(1));
$check(!$workerSecretRow, 'uninstall removes the dedicated HTTP Worker secret');
$cronIdRow = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'friendlinks_cron_id')->where('user = ?', 0)->limit(1));
$check(!$cronIdRow, 'uninstall removes the dedicated Cron instance identifier');
$cronOwnerRow = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'friendlinks_cron_owner')->where('user = ?', 0)->limit(1));
$check(!$cronOwnerRow, 'uninstall removes the Cron system user identifier');
$cronPhpRow = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'friendlinks_cron_php')->where('user = ?', 0)->limit(1));
$check(!$cronPhpRow, 'uninstall removes the Cron PHP CLI path');
$pluginConfigRow = $db->fetchRow($db->select('value')->from('table.options')
    ->where('name = ?', 'plugin:FriendLinks')->where('user = ?', 0)->limit(1));
$check(!$pluginConfigRow, 'uninstall removes the serialized plugin configuration');
Helper::removePanel($foreignMenuIndex, 'OtherLinks/panel/links.php');
Helper::removeMenu('友情链接');
@unlink($fakeCronState);
putenv('FRIENDLINKS_CRONTAB_BINARY');
putenv('FRIENDLINKS_PHP_CLI');
putenv('FRIENDLINKS_FAKE_CRONTAB_STATE');
putenv('FRIENDLINKS_FAKE_CRONTAB_REQUIRE_C_LOCALE');

fwrite(STDOUT, "OK: {$assertions} Typecho integration assertions\n");
