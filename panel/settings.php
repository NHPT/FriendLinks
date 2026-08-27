<?php

use Typecho\Router;
use TypechoPlugin\FriendLinks\Application\Settings;
use TypechoPlugin\FriendLinks\Infrastructure\Repositories;
use TypechoPlugin\FriendLinks\Infrastructure\SystemCronManager;
use TypechoPlugin\FriendLinks\Presentation\Renderer;
use TypechoPlugin\FriendLinks\Presentation\StatusLabels;
use TypechoPlugin\FriendLinks\Presentation\TemplateCatalog;

require __DIR__ . '/_bootstrap.php';

$settings = Settings::all();
$repositories = new Repositories();
$pages = $repositories->publishedPages();
$latestRun = $repositories->latestRunByMode('cli');
$cronIntervalValue = (int) $settings['cron_interval_value'];
$cronUnits = Settings::cronIntervalUnits();
$cronUnitLabels = array_map(static function (array $unit): string {
    return $unit['label'];
}, $cronUnits);
$cronIntervalUnit = (string) $settings['cron_interval_unit'];
$cronIntervalUnit = isset($cronUnits[$cronIntervalUnit]) ? $cronIntervalUnit : 'minutes';
$cronIntervalLabel = '每 ' . $cronIntervalValue . ' ' . $cronUnitLabels[$cronIntervalUnit];
$cronIntervalRange = $cronUnits[$cronIntervalUnit];
$cronStatus = (new SystemCronManager())->status();
$cronDisabled = empty($cronStatus['available']);
$templateCatalog = new TemplateCatalog();
$templates = $templateCatalog->all();
$templateData = [];
foreach ($templates as $template) {
    $templateData[$template['id']] = [
        'layout' => $template['layout'],
        'title' => $template['title'],
        'description' => $template['description'],
    ];
}
$previewLinks = [
    [
        'name' => 'Open Source',
        'url' => 'https://example.com/',
        'description' => '稳定可用的示例站点',
        'logo_url' => '',
        'category_name' => '推荐',
        'category_slug' => 'featured',
        'overall_state' => 'healthy',
        'reason_code' => null,
        'checked_at' => time() - 300,
    ],
    [
        'name' => 'Certificate Watch',
        'url' => 'https://example.com/',
        'description' => '证书即将到期的预警示例',
        'logo_url' => '',
        'category_name' => '推荐',
        'category_slug' => 'featured',
        'overall_state' => 'warning',
        'reason_code' => 'tls_expiring',
        'checked_at' => time() - 900,
    ],
    [
        'name' => 'Restricted Site',
        'url' => 'https://example.com/',
        'description' => '访问受限或服务不稳定',
        'logo_url' => '',
        'category_name' => '状态示例',
        'category_slug' => 'status',
        'overall_state' => 'degraded',
        'reason_code' => 'http_restricted',
        'checked_at' => time() - 1200,
    ],
    [
        'name' => 'Unavailable Site',
        'url' => 'https://example.com/',
        'description' => '连续检测失败后的不可用状态',
        'logo_url' => '',
        'category_name' => '状态示例',
        'category_slug' => 'status',
        'overall_state' => 'down',
        'reason_code' => 'http_unreachable',
        'checked_at' => time() - 1800,
    ],
];
$assetBase = rtrim((string) $options->pluginUrl, '/') . '/FriendLinks/';
$pageError = null;
try {
    Settings::assertPage((int) $settings['page_cid']);
} catch (\Throwable $error) {
    $pageError = $error->getMessage();
}
$workerUrl = Router::url('friendlinks-worker', [], $options->index);
?>
<link rel="stylesheet" href="<?php echo flm_e($assetBase . 'assets/frontend.css?v=' . \TypechoPlugin\FriendLinks\Presentation\AssetVersion::forFile(dirname(__DIR__) . '/assets/frontend.css')); ?>">
<?php foreach ($templates as $template): ?>
  <?php $templateStylesheet = $templateCatalog->stylesheetPath($template); ?>
  <?php if (null !== $templateStylesheet): ?>
    <link rel="stylesheet" href="<?php echo flm_e($assetBase . 'templates/' . rawurlencode($template['id']) . '/style.css?v=' . \TypechoPlugin\FriendLinks\Presentation\AssetVersion::forFile($templateStylesheet)); ?>">
  <?php endif; ?>
<?php endforeach; ?>
<div class="main flm-admin">
  <div class="body container">
    <div class="typecho-page-title"><h2>FriendLinks 设置</h2></div>
    <div class="row typecho-page-main" role="main">
      <div class="col-mb-12">
        <?php flm_tabs('settings'); ?>
        <?php if ($pageError): ?><p class="flm-warning"><?php echo flm_e($pageError); ?></p><?php endif; ?>

        <form
          class="flm-form flm-settings-form"
          method="post"
          action="<?php echo flm_e(flm_action_url('save-settings')); ?>"
          data-flm-settings
          data-flm-cron-unavailable="<?php echo $cronDisabled ? '1' : '0'; ?>"
        >
          <ul class="typecho-option-tabs flm-settings-tabs clearfix" role="tablist" aria-label="设置分类">
            <li class="current"><button type="button" role="tab" aria-selected="true" data-flm-settings-tab="display">页面与展示</button></li>
            <li><button type="button" role="tab" aria-selected="false" data-flm-settings-tab="detection">检测策略</button></li>
            <li><button type="button" role="tab" aria-selected="false" data-flm-settings-tab="cli-worker">CLI Worker</button></li>
            <li><button type="button" role="tab" aria-selected="false" data-flm-settings-tab="worker">HTTP Worker</button></li>
          </ul>

          <section class="flm-settings-panel" role="tabpanel" data-flm-settings-panel="display">
            <h3>页面与展示</h3>
            <div class="flm-field-grid flm-display-fields">
              <div class="flm-field">
                <label for="flm-page">页面绑定</label>
                <select id="flm-page" name="page_cid">
                  <option value="0">暂不绑定</option>
                  <?php foreach ($pages as $page): ?>
                    <option value="<?php echo (int) $page['cid']; ?>"<?php echo (int) $settings['page_cid'] === (int) $page['cid'] ? ' selected' : ''; ?><?php echo (!empty($page['template']) || !empty($page['password'])) ? ' disabled' : ''; ?>>
                      <?php echo flm_e($page['title'] . (!empty($page['template']) ? '（自定义模板，不可绑定）' : '') . (!empty($page['password']) ? '（有密码，不可绑定）' : '')); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <p class="flm-help">请先在 Typecho 中创建已发布、无密码的普通独立页面。</p>
              </div>
              <div class="flm-field">
                <label for="flm-template">前台布局</label>
                <select id="flm-template" name="frontend_template">
                  <?php foreach ($templates as $template): ?>
                    <option value="<?php echo flm_e($template['id']); ?>"<?php echo $settings['frontend_template'] === $template['id'] ? ' selected' : ''; ?>>
                      <?php echo flm_e($template['title']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <p class="flm-help">模板在隔离组件内控制完整布局，不执行 PHP、自定义 HTML 或 JavaScript。</p>
              </div>
            </div>

            <section class="flm-template-preview" data-flm-templates="<?php echo flm_e(json_encode($templateData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>">
              <div class="flm-preview-toolbar">
                <div>
                  <h4>模板预览</h4>
                  <p class="flm-preview-description"><?php echo flm_e($templates[$settings['frontend_template']]['description'] ?? ''); ?></p>
                </div>
                <div class="flm-preview-modes" role="group" aria-label="预览尺寸">
                  <button class="is-active" type="button" data-flm-preview-size="desktop" aria-pressed="true">桌面</button>
                  <button type="button" data-flm-preview-size="mobile" aria-pressed="false">移动端</button>
                </div>
              </div>
              <div class="flm-preview-stage" data-flm-preview-stage="desktop">
                <div class="flm-preview-canvas">
                  <?php echo (new Renderer())->render($previewLinks, (string) $settings['frontend_template']); ?>
                </div>
              </div>
            </section>
          </section>

          <section class="flm-settings-panel" role="tabpanel" data-flm-settings-panel="detection" hidden>
            <h3>检测周期</h3>
            <div class="flm-field-grid">
              <div class="flm-field">
                <label for="flm-http-interval">HTTP 与 DNS 周期（秒）</label>
                <input id="flm-http-interval" type="number" name="http_interval" min="300" max="604800" value="<?php echo (int) $settings['http_interval']; ?>">
              </div>
              <div class="flm-field">
                <label for="flm-tls-interval">证书元数据周期（秒）</label>
                <input id="flm-tls-interval" type="number" name="tls_interval" min="3600" max="2592000" value="<?php echo (int) $settings['tls_interval']; ?>">
              </div>
              <div class="flm-field">
                <label for="flm-domain-interval">域名注册周期（秒）</label>
                <input id="flm-domain-interval" type="number" name="domain_interval" min="3600" max="2592000" value="<?php echo (int) $settings['domain_interval']; ?>">
              </div>
            </div>

            <h3>网络限制</h3>
            <div class="flm-field-grid">
              <div class="flm-field">
                <label for="flm-connect-timeout">连接超时（秒）</label>
                <input id="flm-connect-timeout" type="number" name="connect_timeout" min="1" max="30" value="<?php echo (int) $settings['connect_timeout']; ?>">
              </div>
              <div class="flm-field">
                <label for="flm-request-timeout">整条请求超时（秒）</label>
                <input id="flm-request-timeout" type="number" name="request_timeout" min="2" max="60" value="<?php echo (int) $settings['request_timeout']; ?>">
              </div>
              <div class="flm-field">
                <label for="flm-max-redirects">最大重定向次数</label>
                <input id="flm-max-redirects" type="number" name="max_redirects" min="0" max="10" value="<?php echo (int) $settings['max_redirects']; ?>">
              </div>
              <div class="flm-field">
                <label for="flm-failure-threshold">连续失败阈值</label>
                <input id="flm-failure-threshold" type="number" name="failure_threshold" min="1" max="10" value="<?php echo (int) $settings['failure_threshold']; ?>">
              </div>
              <div class="flm-field">
                <label for="flm-history-days">历史保留天数</label>
                <input id="flm-history-days" type="number" name="history_days" min="30" max="365" value="<?php echo (int) $settings['history_days']; ?>">
              </div>
            </div>

            <h3>公开信息</h3>
            <div class="flm-settings-checks">
              <label class="flm-check"><input type="checkbox" name="restricted_is_healthy" value="1"<?php echo $settings['restricted_is_healthy'] ? ' checked' : ''; ?>> 将 401/403 显示为正常</label>
              <label class="flm-check"><input type="checkbox" name="show_expiration_warning" value="1"<?php echo $settings['show_expiration_warning'] ? ' checked' : ''; ?>> 公开显示证书或域名即将到期</label>
              <label class="flm-check"><input type="checkbox" name="rel_noreferrer" value="1"<?php echo $settings['rel_noreferrer'] ? ' checked' : ''; ?>> 外链使用 noreferrer</label>
              <label class="flm-check"><input type="checkbox" name="rel_nofollow" value="1"<?php echo $settings['rel_nofollow'] ? ' checked' : ''; ?>> 外链使用 nofollow</label>
            </div>
          </section>

          <section class="flm-settings-panel" role="tabpanel" data-flm-settings-panel="cli-worker" hidden>
            <h3>CLI Worker</h3>
            <?php if (!empty($cronStatus['available']) && !empty($cronStatus['installed'])): ?>
              <p><strong>自动任务已安装</strong> · 调度周期 <?php echo flm_e($cronIntervalLabel); ?></p>
            <?php elseif (!empty($cronStatus['available'])): ?>
              <p class="flm-warning">当前环境支持自动 Cron，但尚未检测到本实例任务；保存设置时会尝试安装。</p>
            <?php else: ?>
              <p class="flm-error" role="alert">
                <strong>自动 Cron 不可用：</strong><?php echo flm_e($cronStatus['message']); ?><br>
                请在主机面板手工部署 CLI Cron，或改用签名 HTTP Worker。下方自动 CLI 调度参数当前不可修改。
              </p>
            <?php endif; ?>
            <p>
              最近运行：
              <?php if ($latestRun): ?>
                <?php echo flm_e(date('Y-m-d H:i:s', (int) $latestRun['heartbeat_at'])); ?>
                · <?php echo flm_e(StatusLabels::runState($latestRun['status'])); ?>
              <?php else: ?>
                从未运行
              <?php endif; ?>
            </p>
            <?php if ($cronDisabled): ?>
              <input type="hidden" name="cron_interval_value" value="<?php echo $cronIntervalValue; ?>">
              <input type="hidden" name="cron_interval_unit" value="<?php echo flm_e($cronIntervalUnit); ?>">
              <input type="hidden" name="cli_worker_limit" value="<?php echo (int) $settings['cli_worker_limit']; ?>">
              <input type="hidden" name="cli_worker_max_seconds" value="<?php echo (int) $settings['cli_worker_max_seconds']; ?>">
            <?php endif; ?>
            <div class="flm-field-grid">
              <div class="flm-field">
                <label for="flm-cron-interval-value">调度周期</label>
                <input id="flm-cron-interval-value" type="number" name="cron_interval_value" min="<?php echo (int) $cronIntervalRange['min']; ?>" max="<?php echo (int) $cronIntervalRange['max']; ?>" value="<?php echo $cronIntervalValue; ?>"<?php echo $cronDisabled ? ' disabled' : ''; ?>>
              </div>
              <div class="flm-field">
                <label for="flm-cron-interval-unit">周期单位</label>
                <select id="flm-cron-interval-unit" name="cron_interval_unit"<?php echo $cronDisabled ? ' disabled' : ''; ?>>
                  <?php foreach ($cronUnits as $unit => $config): ?>
                    <option value="<?php echo $unit; ?>" data-min="<?php echo (int) $config['min']; ?>" data-max="<?php echo (int) $config['max']; ?>"<?php echo $cronIntervalUnit === $unit ? ' selected' : ''; ?>><?php echo flm_e($config['label']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="flm-field">
                <label for="flm-cli-worker-limit">每批处理条数</label>
                <input id="flm-cli-worker-limit" type="number" name="cli_worker_limit" min="1" max="500" value="<?php echo (int) $settings['cli_worker_limit']; ?>"<?php echo $cronDisabled ? ' disabled' : ''; ?>>
              </div>
              <div class="flm-field">
                <label for="flm-cli-worker-max-seconds">单次运行预算（秒）</label>
                <input id="flm-cli-worker-max-seconds" type="number" name="cli_worker_max_seconds" min="30" max="3600" value="<?php echo (int) $settings['cli_worker_max_seconds']; ?>"<?php echo $cronDisabled ? ' disabled' : ''; ?>>
              </div>
            </div>
            <p class="flm-help">秒单位最小 60；月按 30 天折算。系统 Cron 每分钟唤醒，仅在设定周期到期时执行。</p>
            <?php if ($cronDisabled): ?>
              <p class="flm-help">当前环境无法由插件管理系统任务，请按 README 手工配置 CLI Cron，或使用签名 HTTP Worker。环境修复后，停用并重新启用插件可重新探测。</p>
            <?php else: ?>
              <p class="flm-help">PHP CLI 路径、crontab 路径和原始命令由插件自动管理，不接受后台输入。</p>
            <?php endif; ?>
          </section>

          <section class="flm-settings-panel" role="tabpanel" data-flm-settings-panel="worker" hidden>
            <h3>签名 HTTP Worker</h3>
            <label class="flm-check">
              <input type="checkbox" name="http_worker_enabled" value="1"<?php echo $settings['http_worker_enabled'] ? ' checked' : ''; ?>>
              启用签名 HTTP Worker
            </label>
            <p class="flm-help">默认关闭。系统 CLI Cron 已由插件自动管理；仅在需要外部监控平台主动触发时启用。</p>
            <label>请求入口</label>
            <p><code><?php echo flm_e($workerUrl); ?></code></p>
            <label>签名密钥</label>
            <p><code>已单独存储于数据库，不在页面源码中回显。</code></p>
            <p class="flm-help">仅接受 HTTPS POST。签名内容依次为 method、path、timestamp、nonce 和请求体 SHA-256，每行一项，使用 HMAC-SHA256。</p>
            <div class="flm-field-grid">
              <div class="flm-field">
                <label for="flm-worker-secret-new">新密钥</label>
                <input id="flm-worker-secret-new" type="password" name="worker_secret_new" minlength="64" maxlength="64" pattern="[A-Fa-f0-9]{64}" autocomplete="new-password" placeholder="64 位十六进制字符串">
              </div>
              <div class="flm-field">
                <label for="flm-worker-secret-confirmation">确认新密钥</label>
                <input id="flm-worker-secret-confirmation" type="password" name="worker_secret_confirmation" minlength="64" maxlength="64" pattern="[A-Fa-f0-9]{64}" autocomplete="new-password" placeholder="再次输入新密钥">
              </div>
            </div>
            <p class="flm-help">可使用 <code>openssl rand -hex 32</code> 生成。停用并重新启用不会更换密钥；主动轮换后必须同步更新外部调用脚本。CLI Cron 不使用此密钥。</p>
            <button
              class="btn"
              type="submit"
              formaction="<?php echo flm_e(flm_action_url('rotate-secret')); ?>"
              data-flm-confirm
              data-flm-confirm-title="轮换 HTTP Worker 密钥"
              data-flm-confirm-message="旧密钥会立即失效，现有 HTTP Worker 调用方必须同步更新。"
              data-flm-confirm-label="确认轮换"
            >轮换密钥</button>
          </section>

          <div class="flm-settings-actions">
            <button class="btn primary" type="submit" data-flm-settings-save>保存设置</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo flm_e($assetBase . 'assets/frontend.js?v=' . \TypechoPlugin\FriendLinks\Presentation\AssetVersion::forFile(dirname(__DIR__) . '/assets/frontend.js')); ?>"></script>
<?php require __DIR__ . '/_footer.php'; ?>
