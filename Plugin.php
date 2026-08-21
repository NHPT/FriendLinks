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
 * 独立的 Typecho 友情链接管理、展示、健康检测与通知插件。
 *
 * @package FriendLinks
 * @author NHPT
 * @version 0.2.3
 * @since 1.2.0
 * @link https://github.com/NHPT/FriendLinks
 */
final class Plugin implements PluginInterface
{
    private const MENU_NAME = '友情链接';
    private const MENU_INDEX_OPTION = 'friendlinks_menu_index';
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
        self::saveMenuIndex($menuIndex);
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
        $uninstallUrl = \Widget\Security::alloc()->getIndex('/action/friendlinks?do=uninstall');
        $layout = new Layout();
        $layout->html(
            '<style>'
            . '.typecho-page-main form[action*="plugins-edit"] .typecho-option-submit{display:none}'
            . '.flm-config-link,.flm-config-uninstall{align-items:center;box-sizing:border-box;display:inline-flex;'
            . 'justify-content:center;line-height:1}'
            . '.flm-config-danger{border-top:1px solid #d9d9d6;margin-top:28px;padding-top:16px}'
            . '.flm-config-danger input{box-sizing:border-box;display:block;margin:6px 0 10px;max-width:360px;width:100%}'
            . '</style>'
            . '<p>FriendLinks 是包含管理、检测和历史记录的独立工具，请通过专用菜单操作。</p>'
            . '<p><a class="btn primary flm-config-link" href="'
            . htmlspecialchars(Helper::url(self::PANELS[7]), ENT_QUOTES, 'UTF-8')
            . '">打开 FriendLinks 设置</a></p>'
            . '<p>停用插件不会删除业务数据。</p>'
            . '<div class="flm-config-danger"><h3>卸载并删除数据</h3>'
            . '<p>此操作会永久删除友链、分类、检测历史、运行记录和通知记录。</p>'
            . '<label for="flm-config-delete-confirmation">输入 DELETE 确认</label>'
            . '<input id="flm-config-delete-confirmation" type="text" name="confirmation" '
            . 'form="flm-uninstall-form" autocomplete="off">'
            . '<button id="flm-config-uninstall" class="btn btn-warn flm-config-uninstall" '
            . 'type="submit" form="flm-uninstall-form" disabled>停用插件并删除数据</button></div>'
            . '<script>(function(){function init(){'
            . 'var input=document.getElementById("flm-config-delete-confirmation");'
            . 'var button=document.getElementById("flm-config-uninstall");'
            . 'if(!input||!button||document.getElementById("flm-uninstall-form"))return;'
            . 'var target=document.createElement("form");target.id="flm-uninstall-form";'
            . 'target.method="post";target.action='
            . json_encode($uninstallUrl, JSON_UNESCAPED_SLASHES)
            . ';target.style.display="none";document.body.appendChild(target);'
            . 'input.addEventListener("input",function(){button.disabled=input.value!=="DELETE";});'
            . 'target.addEventListener("submit",function(event){'
            . 'if(input.value!=="DELETE"||!confirm("此操作会永久删除所有 FriendLinks 数据。继续？"))'
            . '{event.preventDefault();}});}'
            . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);}else{init();}'
            . '}());</script>'
        );
        $form->addItem($layout);

        foreach (Settings::defaults() as $name => $value) {
            // Typecho expects one form item for every persisted option when it preloads this page.
            // Fake items satisfy that contract without rendering configuration or secrets into HTML.
            $form->addInput(new Form\Element\Fake($name, $value));
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
        $menuIndexRow = $database->fetchRowWrite($db->select('value')->from('table.options')
            ->where('name = ?', self::MENU_INDEX_OPTION)->where('user = ?', 0)->limit(1));
        $ownedMenuIndexes = [];
        if ($menuIndexRow && preg_match('/^\d+$/D', (string) $menuIndexRow['value'])) {
            $ownedMenuIndexes[(int) $menuIndexRow['value']] = true;
        }

        $row = $database->fetchRowWrite($db->select('value')->from('table.options')
            ->where('name = ?', 'panelTable')->where('user = ?', 0)->limit(1));
        if ($row) {
            $panelTable = unserialize((string) $row['value'], ['allowed_classes' => false]);
            if (is_array($panelTable)) {
                $parents = is_array($panelTable['parent'] ?? null) ? $panelTable['parent'] : [];
                $children = is_array($panelTable['child'] ?? null) ? $panelTable['child'] : [];
                $files = is_array($panelTable['file'] ?? null) ? $panelTable['file'] : [];
                foreach ($parents as $parentIndex => $name) {
                    if (self::MENU_NAME === (string) $name) {
                        $ownedMenuIndexes[(int) $parentIndex + 10] = true;
                    }
                }

                $detectedMenuIndexes = [];
                $removedPanelFiles = [];
                foreach ($children as $menuIndex => $items) {
                    if (!is_array($items)) {
                        continue;
                    }
                    if (isset($ownedMenuIndexes[(int) $menuIndex])) {
                        foreach ($items as $item) {
                            $file = self::panelFileFromReference(
                                is_array($item) ? (string) ($item[2] ?? '') : ''
                            );
                            if ('' !== $file) {
                                $removedPanelFiles[$file] = true;
                            }
                        }
                        unset($children[$menuIndex]);
                        continue;
                    }

                    $remaining = [];
                    foreach ($items as $item) {
                        $url = is_array($item) ? (string) ($item[2] ?? '') : '';
                        if (self::isOwnPanelReference($url)) {
                            $detectedMenuIndexes[(int) $menuIndex] = true;
                            $removedPanelFiles[self::panelFileFromReference($url)] = true;
                            continue;
                        }
                        $remaining[] = $item;
                    }
                    if ($remaining) {
                        $children[$menuIndex] = $remaining;
                    } else {
                        unset($children[$menuIndex]);
                    }
                }

                foreach ($parents as $parentIndex => $name) {
                    $menuIndex = (int) $parentIndex + 10;
                    if (
                        isset($ownedMenuIndexes[$menuIndex])
                        || (isset($detectedMenuIndexes[$menuIndex]) && empty($children[$menuIndex]))
                    ) {
                        unset($parents[$parentIndex]);
                    }
                }

                $panelTable['parent'] = $parents;
                $panelTable['child'] = $children;
                $panelTable['file'] = array_values(array_filter(
                    $files,
                    static function ($file) use ($removedPanelFiles) {
                        $normalized = self::panelFileFromReference((string) $file);
                        return !isset($removedPanelFiles[$normalized])
                            && !self::isOwnPanelReference((string) $file);
                    }
                ));
                $value = serialize($panelTable);
                Helper::options()->panelTable = $value;
                $db->query($db->update('table.options')->rows(['value' => $value])
                    ->where('name = ?', 'panelTable')->where('user = ?', 0));
            }
        }

        $db->query($db->delete('table.options')->where('name = ?', self::MENU_INDEX_OPTION));
    }

    private static function saveMenuIndex(int $menuIndex): void
    {
        $db = Db::get();
        $db->query($db->delete('table.options')
            ->where('name = ?', self::MENU_INDEX_OPTION)->where('user = ?', 0));
        $db->query($db->insert('table.options')->rows([
            'name' => self::MENU_INDEX_OPTION,
            'user' => 0,
            'value' => (string) $menuIndex,
        ]));
    }

    private static function isOwnPanelReference(string $reference): bool
    {
        return 0 === strpos(self::panelFileFromReference($reference), 'FriendLinks/panel/');
    }

    private static function panelFileFromReference(string $reference): string
    {
        $reference = html_entity_decode($reference, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $query = parse_url($reference, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $parameters);
            if (isset($parameters['panel']) && is_string($parameters['panel'])) {
                $reference = $parameters['panel'];
            }
        }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $decoded = rawurldecode($reference);
            if ($decoded === $reference) {
                break;
            }
            $reference = $decoded;
        }
        return trim($reference, '/');
    }
}
