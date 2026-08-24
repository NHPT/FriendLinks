<?php

namespace TypechoPlugin\FriendLinks;

use Typecho\Common;
use Typecho\Db;
use Typecho\Plugin as TypechoPluginRegistry;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Layout;
use TypechoPlugin\FriendLinks\Application\Settings;
use TypechoPlugin\FriendLinks\Infrastructure\CronUnavailableException;
use TypechoPlugin\FriendLinks\Infrastructure\Database;
use TypechoPlugin\FriendLinks\Infrastructure\MigrationManager;
use TypechoPlugin\FriendLinks\Infrastructure\SystemCronManager;
use TypechoPlugin\FriendLinks\Presentation\ContentInjector;
use Utils\Helper;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * 独立的 Typecho 友情链接管理、展示、健康检测与通知插件。
 *
 * @package FriendLinks
 * @author NHPT
 * @version 1.0.0
 * @since 1.2.0
 * @link https://github.com/NHPT/FriendLinks
 */
final class Plugin implements PluginInterface
{
    // Typecho uses the visible label as the removal key. The trailing space is
    // collapsed by HTML while keeping this plugin distinct from legacy menus.
    private const MENU_NAME = '友情链接 ';
    private const LEGACY_MENU_NAME = '友情链接 · FriendLinks';
    private const MENU_INDEX_OPTION = 'friendlinks_menu_index';
    private const MENU_NAME_OPTION = 'friendlinks_menu_name';
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

        $menuIndex = null;
        $cron = new SystemCronManager();
        $cronWarning = null;
        try {
            $menuIndex = self::registerAdminRegistration();
            self::registerEndpoints();

            // Keep serialized callbacks on Plugin so Typecho loads this file and its PSR-4 loader first.
            \Widget\Base\Contents::pluginHandle()->contentEx_99999 = [__CLASS__, 'injectLinks'];
            \Widget\Archive::pluginHandle()->header = [__CLASS__, 'frontendHeader'];
            \Widget\Archive::pluginHandle()->footer = [__CLASS__, 'frontendFooter'];

            try {
                $cron->install();
            } catch (CronUnavailableException $error) {
                $cronWarning = $error->getMessage();
            }
        } catch (\Throwable $error) {
            try {
                $cron->remove();
            } catch (\Throwable $ignored) {
            }
            try {
                Helper::removeAction(self::ACTION_NAME);
            } catch (\Throwable $ignored) {
            }
            try {
                Helper::removeRoute(self::ROUTE_NAME);
            } catch (\Throwable $ignored) {
            }
            try {
                self::removeAdminRegistration($menuIndex);
            } catch (\Throwable $ignored) {
            }
            throw new \Typecho\Plugin\Exception('FriendLinks 启用失败：' . $error->getMessage());
        }

        return null === $cronWarning
            ? 'FriendLinks 已启用，系统 Cron 已自动安装。'
            : 'FriendLinks 已启用，但无法自动安装 Cron：' . $cronWarning
                . ' 请按 README 手工配置定时任务。';
    }

    public static function deactivate()
    {
        $cron = new SystemCronManager();
        $cronRemoved = false;
        try {
            self::backupSettings();
            $cron->removeAndClear();
            $cronRemoved = true;
            self::removeAdminRegistration();
            Helper::removeAction(self::ACTION_NAME);
            Helper::removeRoute(self::ROUTE_NAME);
        } catch (\Throwable $error) {
            $rollbackError = null;
            if ($cronRemoved) {
                try {
                    self::restoreAfterFailedDeactivation($cron);
                } catch (\Throwable $rollback) {
                    $rollbackError = $rollback->getMessage();
                }
            }
            $message = 'FriendLinks 停用清理失败：' . $error->getMessage();
            if (null !== $rollbackError) {
                $message .= '；Cron 回滚失败：' . $rollbackError;
            }
            throw new \Typecho\Plugin\Exception($message);
        }

        return 'FriendLinks 已停用，系统 Cron 已删除，业务数据仍保留。';
    }

    public static function uninstall(): void
    {
        $registry = TypechoPluginRegistry::export();
        self::deactivate();

        try {
            TypechoPluginRegistry::deactivate('FriendLinks');
            self::persistPluginRegistry(TypechoPluginRegistry::export());
        } catch (\Throwable $error) {
            $rollbackErrors = [];
            TypechoPluginRegistry::init($registry);
            try {
                self::persistPluginRegistry($registry);
            } catch (\Throwable $rollback) {
                $rollbackErrors[] = $rollback->getMessage();
            }
            try {
                self::restoreAfterFailedDeactivation(new SystemCronManager());
            } catch (\Throwable $rollback) {
                $rollbackErrors[] = $rollback->getMessage();
            }

            $message = 'FriendLinks 注销失败：' . $error->getMessage();
            if ($rollbackErrors) {
                $message .= '；回滚失败：' . implode('；', $rollbackErrors);
            }
            throw new \RuntimeException($message, 0, $error);
        }
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
            . '.flm-config-dialog{background:#fff;border:1px solid #d9d9d6;border-radius:4px;'
            . 'box-shadow:0 12px 36px rgba(0,0,0,.2);color:#444;max-width:440px;padding:0;width:calc(100% - 32px)}'
            . '.flm-config-dialog::backdrop{background:rgba(0,0,0,.42)}'
            . '.flm-config-dialog h3{border-bottom:1px solid #d9d9d6;font-size:16px;margin:0;padding:16px 18px}'
            . '.flm-config-dialog p{line-height:1.7;margin:0;padding:16px 18px}'
            . '.flm-config-dialog-actions{border-top:1px solid #d9d9d6;display:flex;gap:8px;'
            . 'justify-content:flex-end;padding:12px 18px}'
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
            . 'type="button" disabled>停用插件并删除数据</button></div>'
            . '<dialog id="flm-config-dialog" class="flm-config-dialog" aria-labelledby="flm-config-dialog-title">'
            . '<h3 id="flm-config-dialog-title">卸载并删除数据</h3>'
            . '<p>此操作会永久删除全部 FriendLinks 数据并停用插件，无法撤销。</p>'
            . '<div class="flm-config-dialog-actions">'
            . '<button class="btn" id="flm-config-dialog-cancel" type="button">取消</button>'
            . '<button class="btn btn-warn" id="flm-config-dialog-accept" type="button">确认删除</button>'
            . '</div></dialog>'
            . '<script>(function(){function init(){'
            . 'var input=document.getElementById("flm-config-delete-confirmation");'
            . 'var button=document.getElementById("flm-config-uninstall");'
            . 'var dialog=document.getElementById("flm-config-dialog");'
            . 'var cancel=document.getElementById("flm-config-dialog-cancel");'
            . 'var accept=document.getElementById("flm-config-dialog-accept");'
            . 'if(!input||!button||document.getElementById("flm-uninstall-form"))return;'
            . 'var target=document.createElement("form");target.id="flm-uninstall-form";'
            . 'target.method="post";target.action='
            . json_encode($uninstallUrl, JSON_UNESCAPED_SLASHES)
            . ';target.style.display="none";document.body.appendChild(target);'
            . 'input.addEventListener("input",function(){button.disabled=input.value!=="DELETE";});'
            . 'button.addEventListener("click",function(){if(input.value!=="DELETE")return;'
            . 'if(dialog.showModal){dialog.showModal();}else{dialog.setAttribute("open","");}});'
            . 'cancel.addEventListener("click",function(){if(dialog.close){dialog.close();}'
            . 'else{dialog.removeAttribute("open");}button.focus();});'
            . 'accept.addEventListener("click",function(){target.submit();});'
            . 'dialog.addEventListener("cancel",function(event){event.preventDefault();cancel.click();});}'
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
                Settings::initialize($backup);
                Db::get()->query(Db::get()->delete('table.options')
                    ->where('name = ?', self::SETTINGS_BACKUP_OPTION));
                return;
            }
        }

        if ($isInit) {
            Settings::initialize($settings);
            return;
        }
        Settings::save(Settings::all());
    }

    public static function injectLinks($content, $widget, $lastResult = null)
    {
        return ContentInjector::injectLinks($content, $widget, $lastResult);
    }

    public static function frontendHeader($header, $widget, $lastResult = null)
    {
        return ContentInjector::header($header, $widget, $lastResult);
    }

    public static function frontendFooter($widget, $lastResult = null)
    {
        return ContentInjector::footer($widget, $lastResult);
    }

    private static function backupSettings(): void
    {
        $db = Db::get();
        $settings = Settings::all();
        $settings['worker_secret'] = '';
        $value = serialize($settings);
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

    private static function removeAdminRegistration(
        ?int $menuIndex = null,
        ?string $menuName = null
    ): void
    {
        $database = new Database();
        $db = $database->native();
        if (null === $menuIndex) {
            $row = $database->fetchRowWrite($db->select('value')->from('table.options')
                ->where('name = ?', self::MENU_INDEX_OPTION)->where('user = ?', 0)->limit(1));
            $menuIndex = $row && preg_match('/^\d+$/D', (string) $row['value'])
                ? (int) $row['value']
                : null;
        }
        if (null !== $menuIndex && null === $menuName) {
            $row = $database->fetchRowWrite($db->select('value')->from('table.options')
                ->where('name = ?', self::MENU_NAME_OPTION)->where('user = ?', 0)->limit(1));
            $storedName = $row ? (string) $row['value'] : self::LEGACY_MENU_NAME;
            if (in_array($storedName, [self::MENU_NAME, self::LEGACY_MENU_NAME], true)) {
                $menuName = $storedName;
            }
        }

        if (null !== $menuIndex && null === $menuName) {
            throw new \RuntimeException('FriendLinks 菜单清理标识无效，已拒绝继续。');
        }
        if (null !== $menuIndex) {
            foreach (self::PANELS as $panel) {
                Helper::removePanel($menuIndex, $panel);
            }
            Helper::removeMenu($menuName);
        }
        $db->query($db->delete('table.options')
            ->where('name = ?', self::MENU_INDEX_OPTION)->where('user = ?', 0));
        $db->query($db->delete('table.options')
            ->where('name = ?', self::MENU_NAME_OPTION)->where('user = ?', 0));
    }

    private static function registerAdminRegistration(): int
    {
        $menuIndex = Helper::addMenu(self::MENU_NAME);
        try {
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
            return $menuIndex;
        } catch (\Throwable $error) {
            try {
                self::removeAdminRegistration($menuIndex, self::MENU_NAME);
            } catch (\Throwable $ignored) {
            }
            throw $error;
        }
    }

    private static function registerEndpoints(): void
    {
        Helper::addAction(self::ACTION_NAME, 'FriendLinks_Action');
        Helper::addRoute(self::ROUTE_NAME, '/friendlinks/worker', 'FriendLinks_Action', 'worker');
    }

    private static function restoreAfterFailedDeactivation(SystemCronManager $cron): void
    {
        self::removeAdminRegistration();
        self::registerAdminRegistration();
        self::registerEndpoints();
        $cron->install();
    }

    private static function saveMenuIndex(int $menuIndex): void
    {
        $db = Db::get();
        $db->query($db->delete('table.options')
            ->where('name = ?', self::MENU_INDEX_OPTION)->where('user = ?', 0));
        $db->query($db->delete('table.options')
            ->where('name = ?', self::MENU_NAME_OPTION)->where('user = ?', 0));
        $db->query($db->insert('table.options')->rows([
            'name' => self::MENU_INDEX_OPTION,
            'user' => 0,
            'value' => (string) $menuIndex,
        ]));
        $db->query($db->insert('table.options')->rows([
            'name' => self::MENU_NAME_OPTION,
            'user' => 0,
            'value' => self::MENU_NAME,
        ]));
    }

    private static function persistPluginRegistry(array $registry): void
    {
        $database = new Database();
        $db = $database->native();
        $value = serialize($registry);
        $db->query($db->update('table.options')->rows(['value' => $value])
            ->where('name = ?', 'plugins')
            ->where('user = ?', 0));
        $row = $database->fetchRowWrite($db->select('value')->from('table.options')
            ->where('name = ?', 'plugins')
            ->where('user = ?', 0)
            ->limit(1));
        if (!$row || !hash_equals($value, (string) $row['value'])) {
            throw new \RuntimeException('无法持久化 Typecho 插件注册状态。');
        }
    }
}
