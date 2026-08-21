<?php

use TypechoPlugin\FriendLinks\Infrastructure\Repositories;
use TypechoPlugin\FriendLinks\Presentation\StatusLabels;

require __DIR__ . '/_bootstrap.php';

$repositories = new Repositories();
$id = max(0, (int) $request->get('id', 0));
$link = $id > 0 ? $repositories->link($id) : null;
if ($id > 0 && !$link) {
    throw new \Typecho\Widget\Exception('友链不存在', 404);
}
$link = $link ?: [
    'name' => '',
    'url' => '',
    'description' => '',
    'logo_url' => '',
    'category_id' => null,
    'sort_order' => 0,
    'visibility' => 'published',
    'check_enabled' => 1,
    'overall_state' => 'pending',
    'reason_code' => null,
    'checked_at' => null,
    'details_json' => null,
];
?>
<div class="main flm-admin">
  <div class="body container">
    <div class="typecho-page-title"><h2><?php echo $id ? '编辑友链' : '新增友链'; ?></h2></div>
    <div class="row typecho-page-main" role="main">
      <div class="col-mb-12">
        <?php flm_tabs('links'); ?>
        <form class="flm-form" method="post" action="<?php echo flm_e(flm_action_url('save-link')); ?>">
          <input type="hidden" name="id" value="<?php echo $id; ?>">
          <label for="flm-name">名称</label>
          <input id="flm-name" type="text" name="name" maxlength="150" required value="<?php echo flm_e($link['name']); ?>">

          <label for="flm-url">URL</label>
          <input id="flm-url" type="url" name="url" maxlength="2048" required placeholder="https://example.com/" value="<?php echo flm_e($link['url']); ?>">
          <p class="flm-help">仅允许 HTTP/HTTPS 和 80/443 端口；规范化 URL 不能重复。</p>

          <label for="flm-description">描述</label>
          <textarea id="flm-description" name="description" maxlength="500"><?php echo flm_e($link['description']); ?></textarea>

          <label for="flm-logo">Logo URL</label>
          <input id="flm-logo" type="url" name="logo_url" maxlength="2048" value="<?php echo flm_e($link['logo_url']); ?>">
          <p class="flm-help">留空时前台使用名称首字符，不自动抓取 favicon。</p>

          <label for="flm-category">分类</label>
          <select id="flm-category" name="category_id">
            <option value="0">未分类</option>
            <?php foreach ($repositories->categories() as $category): ?>
              <option value="<?php echo (int) $category['id']; ?>"<?php echo (int) $link['category_id'] === (int) $category['id'] ? ' selected' : ''; ?>><?php echo flm_e($category['name']); ?></option>
            <?php endforeach; ?>
          </select>

          <label for="flm-visibility">可见性</label>
          <select id="flm-visibility" name="visibility">
            <?php foreach (['published' => '公开', 'draft' => '草稿', 'archived' => '已归档'] as $value => $label): ?>
              <option value="<?php echo $value; ?>"<?php echo $link['visibility'] === $value ? ' selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
          </select>

          <label for="flm-sort">排序</label>
          <input id="flm-sort" type="number" name="sort_order" min="-2147483648" max="2147483647" value="<?php echo (int) $link['sort_order']; ?>">

          <p><label class="flm-check"><input type="checkbox" name="check_enabled" value="1"<?php echo $link['check_enabled'] ? ' checked' : ''; ?>> 启用自动检测</label></p>

          <div class="flm-form-actions">
            <div class="flm-inline">
              <button class="btn primary" type="submit">保存</button>
              <a class="btn" href="<?php echo flm_e(flm_panel_url('links')); ?>">返回</a>
            </div>
          </div>
        </form>

        <?php if ($id): ?>
          <h3>最近状态</h3>
          <p><span class="flm-state flm-state-<?php echo flm_e($link['overall_state']); ?>"><?php echo flm_e(StatusLabels::state($link['overall_state'])); ?></span>
            <?php echo $link['checked_at'] ? ' · ' . flm_e(date('Y-m-d H:i:s', (int) $link['checked_at'])) : ''; ?></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
