<?php

use Typecho\Router;
use TypechoPlugin\FriendLinks\Application\Settings;
use TypechoPlugin\FriendLinks\Infrastructure\Repositories;
use TypechoPlugin\FriendLinks\Presentation\Renderer;
use TypechoPlugin\FriendLinks\Presentation\TemplateCatalog;

require __DIR__ . '/_bootstrap.php';

$settings = Settings::all();
$pages = (new Repositories())->publishedPages();
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
$hasValidBoundPage = false;
try {
    Settings::assertPage((int) $settings['page_cid']);
    $hasValidBoundPage = (int) $settings['page_cid'] > 0;
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

        <form class="flm-form" method="post" action="<?php echo flm_e(flm_action_url('save-settings')); ?>">
          <h3>展示页面</h3>
          <label for="flm-page">普通独立页面</label>
          <select id="flm-page" name="page_cid">
            <option value="0">暂不绑定</option>
            <?php foreach ($pages as $page): ?>
              <option value="<?php echo (int) $page['cid']; ?>"<?php echo (int) $settings['page_cid'] === (int) $page['cid'] ? ' selected' : ''; ?><?php echo (!empty($page['template']) || !empty($page['password'])) ? ' disabled' : ''; ?>>
                <?php echo flm_e($page['title'] . (!empty($page['template']) ? '（自定义模板，不可绑定）' : '') . (!empty($page['password']) ? '（有密码，不可绑定）' : '')); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="flm-help">插件按 CID 注入列表。承载页必须已发布、无密码且使用普通页面模板。</p>

          <h3>展示模板</h3>
          <label for="flm-template">前台布局</label>
          <select id="flm-template" name="frontend_template">
            <?php foreach ($templates as $template): ?>
              <option value="<?php echo flm_e($template['id']); ?>"<?php echo $settings['frontend_template'] === $template['id'] ? ' selected' : ''; ?>>
                <?php echo flm_e($template['title']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="flm-template-notes">
            <?php foreach ($templates as $template): ?>
              <p><strong><?php echo flm_e($template['title']); ?>：</strong><?php echo flm_e($template['description']); ?></p>
            <?php endforeach; ?>
          </div>
          <p class="flm-help">模板只改变插件根节点内的布局。新增模板使用 JSON 清单和隔离 CSS，不执行 PHP 或 JavaScript。</p>

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

          <h3>检测周期</h3>
          <label for="flm-http-interval">HTTP 与 DNS 周期（秒）</label>
          <input id="flm-http-interval" type="number" name="http_interval" min="300" max="604800" value="<?php echo (int) $settings['http_interval']; ?>">
          <label for="flm-tls-interval">证书元数据周期（秒）</label>
          <input id="flm-tls-interval" type="number" name="tls_interval" min="3600" max="2592000" value="<?php echo (int) $settings['tls_interval']; ?>">
          <label for="flm-domain-interval">域名注册周期（秒）</label>
          <input id="flm-domain-interval" type="number" name="domain_interval" min="3600" max="2592000" value="<?php echo (int) $settings['domain_interval']; ?>">

          <h3>网络限制</h3>
          <label for="flm-connect-timeout">连接超时（秒）</label>
          <input id="flm-connect-timeout" type="number" name="connect_timeout" min="1" max="30" value="<?php echo (int) $settings['connect_timeout']; ?>">
          <label for="flm-request-timeout">整条请求超时（秒）</label>
          <input id="flm-request-timeout" type="number" name="request_timeout" min="2" max="60" value="<?php echo (int) $settings['request_timeout']; ?>">
          <label for="flm-max-redirects">最大重定向次数</label>
          <input id="flm-max-redirects" type="number" name="max_redirects" min="0" max="10" value="<?php echo (int) $settings['max_redirects']; ?>">
          <label for="flm-failure-threshold">连续失败阈值</label>
          <input id="flm-failure-threshold" type="number" name="failure_threshold" min="1" max="10" value="<?php echo (int) $settings['failure_threshold']; ?>">
          <label for="flm-history-days">历史保留天数</label>
          <input id="flm-history-days" type="number" name="history_days" min="30" max="365" value="<?php echo (int) $settings['history_days']; ?>">

          <h3>公开信息</h3>
          <p><label class="flm-check"><input type="checkbox" name="restricted_is_healthy" value="1"<?php echo $settings['restricted_is_healthy'] ? ' checked' : ''; ?>> 将 401/403 显示为正常</label></p>
          <p><label class="flm-check"><input type="checkbox" name="show_expiration_warning" value="1"<?php echo $settings['show_expiration_warning'] ? ' checked' : ''; ?>> 公开显示证书或域名即将到期</label></p>
          <p><label class="flm-check"><input type="checkbox" name="rel_noreferrer" value="1"<?php echo $settings['rel_noreferrer'] ? ' checked' : ''; ?>> 外链使用 noreferrer</label></p>
          <p><label class="flm-check"><input type="checkbox" name="rel_nofollow" value="1"<?php echo $settings['rel_nofollow'] ? ' checked' : ''; ?>> 外链使用 nofollow</label></p>
          <button class="btn primary" type="submit">保存设置</button>
        </form>

        <h3>页面工具</h3>
        <div class="flm-inline">
          <?php if (!$hasValidBoundPage): ?>
            <form method="post" action="<?php echo flm_e(flm_action_url('create-page')); ?>"><button class="btn" type="submit">创建并绑定“友情链接”页面</button></form>
          <?php else: ?>
            <span class="flm-muted">当前已绑定有效承载页，无需重复创建。</span>
          <?php endif; ?>
          <?php foreach ($pages as $page): ?>
            <?php if (!empty($page['template'])): ?>
              <form method="post" action="<?php echo flm_e(flm_action_url('clear-page-template', ['cid' => (int) $page['cid']])); ?>" onsubmit="return confirm('确认清除此页面的自定义模板？页面 URL 和正文会保留。')">
                <button class="btn" type="submit">清除“<?php echo flm_e($page['title']); ?>”自定义模板</button>
              </form>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>

        <h3>签名 HTTP Worker</h3>
        <p>入口：<code><?php echo flm_e($workerUrl); ?></code></p>
        <p>密钥：<code>已生成并保存，不在页面源码中回显。</code></p>
        <p class="flm-help">仅接受 HTTPS POST。签名内容依次为 method、path、timestamp、nonce 和请求体 SHA-256，每行一项，使用 HMAC-SHA256。</p>
        <form method="post" action="<?php echo flm_e(flm_action_url('rotate-secret')); ?>" onsubmit="return confirm('旧密钥会立即失效。继续？')"><button class="btn" type="submit">轮换密钥</button></form>

      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo flm_e($assetBase . 'assets/frontend.js?v=' . \TypechoPlugin\FriendLinks\Presentation\AssetVersion::forFile(dirname(__DIR__) . '/assets/frontend.js')); ?>"></script>
<?php require __DIR__ . '/_footer.php'; ?>
