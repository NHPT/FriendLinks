<?php

use Typecho\Common;
use TypechoPlugin\FriendLinks\Application\ImportService;
use TypechoPlugin\FriendLinks\Application\LinkService;
use TypechoPlugin\FriendLinks\Application\NotificationDispatcher;
use TypechoPlugin\FriendLinks\Application\Settings;
use TypechoPlugin\FriendLinks\Application\Worker;
use TypechoPlugin\FriendLinks\Infrastructure\CronUnavailableException;
use TypechoPlugin\FriendLinks\Infrastructure\MigrationManager;
use TypechoPlugin\FriendLinks\Infrastructure\Repositories;
use TypechoPlugin\FriendLinks\Infrastructure\SystemCronManager;
use TypechoPlugin\FriendLinks\Infrastructure\WorkerSigner;
use TypechoPlugin\FriendLinks\Plugin as FriendLinksPlugin;
use Widget\ActionInterface;
use Widget\Base\Options as OptionsWidget;
use Widget\Notice;

require_once __DIR__ . '/vendor/autoload.php';

class FriendLinks_Action extends OptionsWidget implements ActionInterface
{
    public function action()
    {
        $this->user->pass('administrator');
        $this->security->protect();

        try {
            switch ((string) $this->request->get('do', '')) {
                case 'save-link':
                    $this->saveLink();
                    break;
                case 'archive-links':
                    $this->archiveLinks();
                    break;
                case 'delete-link':
                    $this->deleteLink();
                    break;
                case 'schedule':
                    $this->schedule();
                    break;
                case 'run-check':
                    $this->runCheck();
                    return;
                case 'save-category':
                    $this->saveCategory();
                    break;
                case 'delete-category':
                    $this->deleteCategory();
                    break;
                case 'save-settings':
                    $this->saveSettings();
                    break;
                case 'save-notifications':
                    $this->saveNotifications();
                    break;
                case 'test-notification':
                    $this->testNotification();
                    break;
                case 'dispatch-notifications':
                    $this->dispatchNotifications();
                    break;
                case 'retry-notification':
                    $this->retryNotification();
                    break;
                case 'import':
                    $this->import();
                    break;
                case 'export':
                    $this->export();
                    return;
                case 'rotate-secret':
                    $this->rotateWorkerSecret();
                    break;
                case 'uninstall':
                    $this->uninstall();
                    return;
                default:
                    throw new InvalidArgumentException('未知操作。');
            }
        } catch (Throwable $error) {
            Notice::alloc()->set(htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8'), 'error');
        }

        $this->response->goBack();
    }

    public function worker()
    {
        if (empty(Settings::get('http_worker_enabled', 0))) {
            $this->json(['error' => 'worker_disabled'], 403);
            return;
        }
        $body = (string) file_get_contents('php://input');
        if (strlen($body) > 65536) {
            $this->json(['error' => 'payload_too_large'], 413);
            return;
        }
        $path = (string) parse_url($this->request->getRequestUri(), PHP_URL_PATH);
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $valid = 'POST' === $method
            && $this->request->isSecure()
            && (new WorkerSigner())->verify(
                (string) Settings::get('worker_secret', ''),
                $method,
                $path,
                (string) $this->request->getHeader('X-FLM-Timestamp', ''),
                (string) $this->request->getHeader('X-FLM-Nonce', ''),
                (string) $this->request->getHeader('X-FLM-Signature', ''),
                $body
            );

        if (!$valid) {
            $this->json(['error' => 'unauthorized'], 401);
            return;
        }

        $result = (new Worker())->run('http', 5, 20);
        $this->json($result, $result['failed'] > 0 && 0 === $result['completed'] ? 503 : 200);
    }

    private function saveLink(): void
    {
        $id = max(0, (int) $this->request->get('id', 0));
        $repositories = new Repositories();
        $saved = (new LinkService($repositories))->save([
            'name' => $this->request->get('name', ''),
            'url' => $this->request->get('url', ''),
            'description' => $this->request->get('description', ''),
            'logo_url' => $this->request->get('logo_url', ''),
            'category_id' => $this->request->get('category_id', 0),
            'sort_order' => $this->request->get('sort_order', 0),
            'visibility' => $this->request->get('visibility', 'published'),
            'check_enabled' => $this->request->get('check_enabled', 0),
        ], $id);
        $link = $repositories->link($saved, true);
        $message = $id > 0 ? '友链已更新。' : '友链已创建。';
        if ($link && !empty($link['check_enabled']) && 'published' === $link['visibility']) {
            $repositories->schedule([$saved], true);
            $message = ($id > 0 ? '友链已更新' : '友链已创建') . '，已加入检测队列。';
        }
        Notice::alloc()->set($message, 'success');
        Notice::alloc()->highlight('friend-link-' . $saved);
        $redirect = 'extending.php?panel=FriendLinks/panel/links.php';
        if ($link && !empty($link['check_enabled']) && 'published' === $link['visibility']) {
            $redirect .= '&auto_check=' . $saved;
        }
        $this->response->redirect(Common::url(
            $redirect,
            $this->options->adminUrl
        ));
    }

    private function archiveLinks(): void
    {
        $ids = $this->request->getArray('id');
        $count = (new Repositories())->archiveLinks($ids);
        Notice::alloc()->set('已归档 ' . $count . ' 条友链。', 'success');
    }

    private function deleteLink(): void
    {
        $id = (int) $this->request->get('link_id', 0);
        if (!(new Repositories())->deleteLink($id)) {
            throw new InvalidArgumentException('友链不存在。');
        }
        Notice::alloc()->set('友链及其检测记录已删除。', 'success');
    }

    private function schedule(): void
    {
        $ids = $this->request->getArray('id');
        if (!$ids && $this->request->get('id')) {
            $ids = [(int) $this->request->get('id')];
        }
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids)), static function ($id) {
            return $id > 0;
        }));
        if (!$ids) {
            throw new InvalidArgumentException('请先选择需要检测的友链。');
        }
        $repositories = new Repositories();
        $count = $repositories->schedule($ids, '1' === (string) $this->request->get('full', '0'));
        if ($count < 1) {
            throw new InvalidArgumentException('没有可检测的友链。');
        }
        $result = (new Worker($repositories))->run('admin', min(20, count($ids)), 30, $ids);
        Notice::alloc()->set(
            '已检测 ' . $result['completed'] . ' 条'
                . ($result['failed'] > 0 ? '，失败 ' . $result['failed'] . ' 条。' : '。'),
            $result['failed'] > 0 || $result['completed'] < $count ? 'notice' : 'success'
        );
    }

    private function runCheck(): void
    {
        try {
            $id = (int) $this->request->get('id', 0);
            $repositories = new Repositories();
            $link = $id > 0 ? $repositories->link($id, true) : null;
            if (!$link || empty($link['check_enabled']) || 'published' !== $link['visibility']) {
                throw new InvalidArgumentException('该友链未启用自动检测。');
            }
            $repositories->schedule([$id], true);
            $result = (new Worker($repositories))->run('admin', 1, 30, [$id]);
            $this->json([
                'ok' => $result['completed'] > 0,
                'completed' => $result['completed'],
                'failed' => $result['failed'],
            ], $result['completed'] > 0 ? 200 : 503);
        } catch (Throwable $error) {
            $this->json([
                'ok' => false,
                'error' => htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8'),
            ], 422);
        }
    }

    private function saveCategory(): void
    {
        $id = max(0, (int) $this->request->get('id', 0));
        (new LinkService())->saveCategory([
            'name' => $this->request->get('name', ''),
            'slug' => $this->request->get('slug', ''),
            'sort_order' => $this->request->get('sort_order', 0),
            'enabled' => $this->request->get('enabled', 0),
        ], $id);
        Notice::alloc()->set($id > 0 ? '分类已更新。' : '分类已创建。', 'success');
    }

    private function deleteCategory(): void
    {
        $id = (int) $this->request->get('id', 0);
        $repositories = new Repositories();
        if ($id < 1 || !$repositories->deleteCategory($id)) {
            throw new InvalidArgumentException('分类不存在。');
        }
        Notice::alloc()->set('分类已删除，原友链已转为未分类。', 'success');
    }

    private function saveSettings(): void
    {
        $cron = new SystemCronManager();
        $cronStatus = $cron->status();
        $input = [
            'page_cid' => $this->request->get('page_cid', 0),
            'frontend_template' => $this->request->get('frontend_template', 'cards'),
            'http_interval' => $this->request->get('http_interval', 21600),
            'tls_interval' => $this->request->get('tls_interval', 86400),
            'domain_interval' => $this->request->get('domain_interval', 86400),
            'connect_timeout' => $this->request->get('connect_timeout', 3),
            'request_timeout' => $this->request->get('request_timeout', 10),
            'max_redirects' => $this->request->get('max_redirects', 5),
            'failure_threshold' => $this->request->get('failure_threshold', 3),
            'history_days' => $this->request->get('history_days', 90),
            'restricted_is_healthy' => $this->request->get('restricted_is_healthy', 0),
            'show_expiration_warning' => $this->request->get('show_expiration_warning', 0),
            'rel_noreferrer' => $this->request->get('rel_noreferrer', 0),
            'rel_nofollow' => $this->request->get('rel_nofollow', 0),
            'http_worker_enabled' => $this->request->get('http_worker_enabled', 0),
        ];
        if (!empty($cronStatus['available'])) {
            $input += [
                'cron_interval_value' => $this->request->get('cron_interval_value', 5),
                'cron_interval_unit' => $this->request->get('cron_interval_unit', 'minutes'),
                'cli_worker_limit' => $this->request->get('cli_worker_limit', 50),
                'cli_worker_max_seconds' => $this->request->get('cli_worker_max_seconds', 240),
            ];
        }

        $settings = Settings::sanitize($input);
        Settings::save($settings);
        if (!empty($cronStatus['available']) && empty($cronStatus['installed'])) {
            try {
                $cron->install();
            } catch (CronUnavailableException $error) {
                throw new RuntimeException('自动 Cron 修复失败：' . $error->getMessage());
            }
        }
        if (!empty($cronStatus['available'])) {
            $unitLabels = array_map(static function (array $unit): string {
                return $unit['label'];
            }, Settings::cronIntervalUnits());
            $unit = (string) $settings['cron_interval_unit'];
            Notice::alloc()->set(
                '设置已保存。CLI Worker 实际执行周期：每 '
                    . (int) $settings['cron_interval_value'] . ' '
                    . ($unitLabels[$unit] ?? $unit) . '。',
                'success'
            );
            return;
        }
        Notice::alloc()->set('设置已保存。', 'success');
    }

    private function saveNotifications(): void
    {
        $settings = Settings::sanitizeNotifications([
            'notifications_enabled' => $this->request->get('notifications_enabled', 0),
            'notify_on_down' => $this->request->get('notify_on_down', 0),
            'notify_on_recovery' => $this->request->get('notify_on_recovery', 0),
            'notify_on_warning' => $this->request->get('notify_on_warning', 0),
            'notification_cooldown' => $this->request->get('notification_cooldown', 3600),
            'webhook_enabled' => $this->request->get('webhook_enabled', 0),
            'webhook_url' => $this->request->get('webhook_url', ''),
            'webhook_secret' => $this->request->get('webhook_secret', ''),
            'clear_webhook_url' => $this->request->get('clear_webhook_url', 0),
            'clear_webhook_secret' => $this->request->get('clear_webhook_secret', 0),
            'dingtalk_enabled' => $this->request->get('dingtalk_enabled', 0),
            'dingtalk_webhook_url' => $this->request->get('dingtalk_webhook_url', ''),
            'dingtalk_secret' => $this->request->get('dingtalk_secret', ''),
            'clear_dingtalk_webhook_url' => $this->request->get('clear_dingtalk_webhook_url', 0),
            'clear_dingtalk_secret' => $this->request->get('clear_dingtalk_secret', 0),
            'email_enabled' => $this->request->get('email_enabled', 0),
            'smtp_host' => $this->request->get('smtp_host', ''),
            'smtp_port' => $this->request->get('smtp_port', 587),
            'smtp_encryption' => $this->request->get('smtp_encryption', 'starttls'),
            'smtp_username' => $this->request->get('smtp_username', ''),
            'smtp_password' => $this->request->get('smtp_password', ''),
            'clear_smtp_password' => $this->request->get('clear_smtp_password', 0),
            'smtp_from_address' => $this->request->get('smtp_from_address', ''),
            'smtp_from_name' => $this->request->get('smtp_from_name', ''),
            'email_recipients' => $this->request->get('email_recipients', ''),
            'notification_subject_template' => $this->request->get('notification_subject_template', ''),
            'notification_message_template' => $this->request->get('notification_message_template', ''),
        ]);
        Settings::save($settings);
        Notice::alloc()->set('通知设置已保存。', 'success');
    }

    private function testNotification(): void
    {
        $channel = (string) $this->request->get('channel', '');
        if (!in_array($channel, ['webhook', 'dingtalk', 'email'], true)) {
            throw new InvalidArgumentException('通知渠道无效。');
        }
        (new NotificationDispatcher())->sendTest($channel, Settings::all());
        Notice::alloc()->set('测试通知已发送。', 'success');
    }

    private function dispatchNotifications(): void
    {
        $result = (new NotificationDispatcher())->dispatch(20, microtime(true) + 20);
        Notice::alloc()->set(
            '通知处理完成：成功 ' . $result['sent'] . ' 条，失败 ' . $result['failed'] . ' 条。',
            $result['failed'] > 0 ? 'notice' : 'success'
        );
    }

    private function retryNotification(): void
    {
        $id = (int) $this->request->get('id', 0);
        if ($id < 1 || !(new Repositories())->retryNotification($id)) {
            throw new InvalidArgumentException('只能重试失败的通知。');
        }
        Notice::alloc()->set('通知已重新进入待发送队列。', 'success');
    }

    private function rotateWorkerSecret(): void
    {
        $secret = trim((string) $this->request->get('worker_secret_new', ''));
        $confirmation = trim((string) $this->request->get('worker_secret_confirmation', ''));
        if ('' === $secret || !hash_equals($secret, $confirmation)) {
            throw new InvalidArgumentException('两次输入的 HTTP Worker 密钥不一致。');
        }
        Settings::rotateWorkerSecret($secret);
        Notice::alloc()->set('HTTP Worker 密钥已轮换。', 'success');
    }

    private function import(): void
    {
        $json = (string) $this->request->get('rows_json', '');
        if (strlen($json) > 1024 * 1024) {
            throw new InvalidArgumentException('导入数据过大。');
        }
        $rows = json_decode($json, true);
        if (!is_array($rows)) {
            throw new InvalidArgumentException('导入确认数据无效。');
        }
        $result = (new ImportService())->import($rows);
        Notice::alloc()->set(
            '已导入 ' . $result['created'] . ' 条，跳过 ' . $result['skipped'] . ' 条。',
            $result['created'] > 0 ? 'success' : 'notice'
        );
    }

    private function export(): void
    {
        $format = 'csv' === $this->request->get('format') ? 'csv' : 'json';
        $content = (new ImportService())->export($format);
        header('Content-Type: ' . ('csv' === $format ? 'text/csv' : 'application/json') . '; charset=UTF-8');
        header('Content-Disposition: attachment; filename="friend-links.' . $format . '"');
        header('X-Content-Type-Options: nosniff');
        echo $content;
    }

    private function uninstall(): void
    {
        if ('DELETE' !== (string) $this->request->get('confirmation', '')) {
            throw new InvalidArgumentException('请输入 DELETE 确认卸载。');
        }
        FriendLinksPlugin::uninstall();
        (new MigrationManager())->uninstall();
        Notice::alloc()->set('FriendLinks 已停用，业务表已删除。', 'success');
        $this->response->redirect(Common::url('plugins.php', $this->options->adminUrl));
    }

    private function json(array $payload, int $status): void
    {
        $this->response
            ->setStatus($status)
            ->setContentType('application/json')
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff');
        $this->response->throwContent(
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'application/json'
        );
    }
}
