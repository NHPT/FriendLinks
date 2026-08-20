<?php

namespace TypechoPlugin\FriendLinks;

use Typecho\Common;
use Typecho\Db;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Layout;
use TypechoPlugin\FriendLinks\Application\Settings;
use TypechoPlugin\FriendLinks\Infrastructure\Database;
use TypechoPlugin\FriendLinks\Infrastructure\MigrationManager;
use TypechoPlugin\FriendLinks\Presentation\ContentInjector;
use Utils\Helper;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Theme-independent friend links management, display, and health checks.
 *
 * @package FriendLinks
 * @author NHPT
 * @version 0.2.0
 * @since 1.2.0
 * @link https://github.com/NHPT
 */
final class Plugin implements PluginInterface
{
    private const MENU_NAME = '友情链接';
    private const SETTINGS_BACKUP_OPTION = 'friendlinks_settings_backup';
    private const ACTION_NAME = 'friendlinks';
    private const ROUTE_NAME = 'friendlinks-worker';

    private const PANELS = [
        'FriendLinks/panel/links.php',
        'FriendLinks/panel/link-edit.php',
        'FriendLinks/panel/categories.php',
        'FriendLinks/panel/health.php',
        'FriendLinks/panel/history.php',
        'FriendLinks/panel/import.php',
        'FriendLinks/panel/notifications.php',
        'FriendLinks/panel/settings.php',
    ];

    public static function activate()
    {
        if (version_compare(PHP_VERSION, '7.4.0', '<')) {
            throw new \Typecho\Plugin\Exception('FriendLinks 要求 PHP 7.4 或更高版本。');
        }
        if (version_compare(Common::VERSION, '1.2.0', '<')) {
            throw new \Typecho\Plugin\Exception('FriendLinks 要求 Typecho 1.2.0 或更高版本。');
        }

        (new MigrationManager())->migrate();
        self::removeAdminRegistration();

        $menuIndex = Helper::addMenu(self::MENU_NAME);
        Helper::addPanel(
            $menuIndex,
            self::PANELS[0],
            '友链',
            '友链管理',
            'administrator',
            false,
            'extending.php?panel=FriendLinks/panel/link-edit.php'
        );
        Helper::addPanel($menuIndex, self::PANELS[1], '编辑友链', '编辑友链', 'administrator', true);
        Helper::addPanel($menuIndex, self::PANELS[2], '分类', '友链分类', 'administrator');
        Helper::addPanel($menuIndex, self::PANELS[3], '健康', '检测健康总览', 'administrator');
        Helper::addPanel($menuIndex, self::PANELS[4], '历史', '检测历史', 'administrator');
        Helper::addPanel($menuIndex, self::PANELS[5], '导入导出', '导入导出', 'administrator');
        Helper::addPanel($menuIndex, self::PANELS[6], '通知', '通知设置与投递记录', 'administrator');
        Helper::addPanel($menuIndex, self::PANELS[7], '设置', 'FriendLinks 设置', 'administrator');

        Helper::addAction(self::ACTION_NAME, 'FriendLinks_Action');
        Helper::addRoute(self::ROUTE_NAME, '/friendlinks/worker', 'FriendLinks_Action', 'worker');

        // Keep serialized callbacks on Plugin so Typecho loads this file and its PSR-4 loader first.
        \Widget\Base\Contents::pluginHandle()->contentEx = [__CLASS__, 'injectLinks'];
        \Widget\Archive::pluginHandle()->header = [__CLASS__, 'frontendHeader'];

        return 'FriendLinks 已启用。请先选择或创建普通独立页面，并配置系统 Cron。';
    }

    public static function deactivate()
    {
        self::backupSettings();
        self::removeAdminRegistration();
        Helper::removeAction(self::ACTION_NAME);
        Helper::removeRoute(self::ROUTE_NAME);

        return 'FriendLinks 已停用，业务数据仍保留。';
    }

    public static function config(Form $form)
    {
        $layout = new Layout();
        $layout->html(
            '<p>FriendLinks 是包含管理、检测和历史记录的独立工具，请通过专用菜单操作。</p>'
            . '<p><a class="btn primary" href="' . htmlspecialchars(Helper::url(self::PANELS[7]), ENT_QUOTES, 'UTF-8')
            . '">打开 FriendLinks 设置</a></p>'
            . '<p>停用插件不会删除业务数据；删除数据必须在专用设置页中二次确认。</p>'
        );
        $form->addItem($layout);

        $sensitive = Settings::sensitiveKeys();
        foreach (Settings::defaults() as $name => $value) {
            if (in_array($name, $sensitive, true)) {
                continue;
            }
            $input = new Form\Element\Text($name, null, (string) $value, $name);
            $input->setAttribute('class', 'hidden');
            $form->addInput($input);
        }
    }

    public static function personalConfig(Form $form)
    {
    }

    public static function configHandle(array $settings, bool $isInit): void
    {
        if ($isInit) {
            $backup = self::settingsBackup();
            if (null !== $backup) {
                Settings::save($backup);
                Db::get()->query(Db::get()->delete('table.options')
                    ->where('name = ?', self::SETTINGS_BACKUP_OPTION));
                return;
            }
        }

        Settings::save($isInit ? array_replace(Settings::defaults(), $settings) : Settings::all());
    }

    public static function injectLinks($content, $widget, $lastResult = null)
    {
        return ContentInjector::injectLinks($content, $widget, $lastResult);
    }

    public static function frontendHeader($header, $widget, $lastResult = null)
    {
        return ContentInjector::header($header, $widget, $lastResult);
    }

    private static function backupSettings(): void
    {
        $db = Db::get();
        $value = serialize(Settings::all());
        $updated = $db->query($db->update('table.options')->rows(['value' => $value])
            ->where('name = ?', self::SETTINGS_BACKUP_OPTION)->where('user = ?', 0));
        if (0 === $updated) {
            try {
                $db->query($db->insert('table.options')->rows([
                    'name' => self::SETTINGS_BACKUP_OPTION,
                    'user' => 0,
                    'value' => $value,
                ]));
            } catch (\Throwable $error) {
                $db->query($db->update('table.options')->rows(['value' => $value])
                    ->where('name = ?', self::SETTINGS_BACKUP_OPTION)->where('user = ?', 0));
            }
        }
    }

    private static function settingsBackup(): ?array
    {
        $database = new Database();
        $db = $database->native();
        $row = $database->fetchRowWrite($db->select('value')->from('table.options')
            ->where('name = ?', self::SETTINGS_BACKUP_OPTION)->where('user = ?', 0)->limit(1));
        if (!$row) {
            return null;
        }

        $settings = unserialize((string) $row['value'], ['allowed_classes' => false]);
        return is_array($settings) ? $settings : null;
    }

    private static function removeAdminRegistration(): void
    {
        $database = new Database();
        $db = $database->native();
        $row = $database->fetchRowWrite($db->select('value')->from('table.options')
            ->where('name = ?', 'panelTable')->where('user = ?', 0)->limit(1));
        if (!$row) {
            return;
        }

        $panelTable = unserialize((string) $row['value'], ['allowed_classes' => false]);
        if (!is_array($panelTable)) {
            return;
        }
        $parents = is_array($panelTable['parent'] ?? null) ? $panelTable['parent'] : [];
        $children = is_array($panelTable['child'] ?? null) ? $panelTable['child'] : [];
        $files = is_array($panelTable['file'] ?? null) ? $panelTable['file'] : [];

        foreach ($children as $menuIndex => $items) {
            if (!is_array($items)) {
                continue;
            }
            $ownsMenu = false;
            foreach ($items as $item) {
                $url = is_array($item) ? (string) ($item[2] ?? '') : '';
                if (false !== strpos(urldecode($url), 'FriendLinks/panel/')) {
                    $ownsMenu = true;
                    break;
                }
            }
            if ($ownsMenu) {
                unset($children[$menuIndex]);
                $parentIndex = (int) $menuIndex - 10;
                unset($parents[$parentIndex]);
            }
        }

        $encodedPanels = array_map(static function ($panel) {
            return urlencode(trim($panel, '/'));
        }, self::PANELS);
        $files = array_values(array_filter($files, static function ($file) use ($encodedPanels) {
            return !in_array((string) $file, $encodedPanels, true);
        }));

        $panelTable['parent'] = $parents;
        $panelTable['child'] = $children;
        $panelTable['file'] = $files;
        $value = serialize($panelTable);
        Helper::options()->panelTable = $value;
        $db->query($db->update('table.options')->rows(['value' => $value])
            ->where('name = ?', 'panelTable')->where('user = ?', 0));
        $db->query($db->delete('table.options')->where('name = ?', 'friendlinks_menu_index'));
    }
}
