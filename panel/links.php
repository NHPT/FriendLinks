<?php

use TypechoPlugin\FriendLinks\Infrastructure\Repositories;
use TypechoPlugin\FriendLinks\Presentation\StatusLabels;

require __DIR__ . '/_bootstrap.php';

$repositories = new Repositories();
$filters = [
    'visibility' => in_array($request->get('visibility'), ['published', 'draft', 'archived'], true)
        ? $request->get('visibility')
        : '',
    'category_id' => (int) $request->get('category_id', 0),
    'state' => array_key_exists((string) $request->get('state', ''), StatusLabels::states())
        ? (string) $request->get('state')
        : '',
    'keywords' => trim((string) $request->get('keywords', '')),
];
$links = $repositories->links($filters);
$categories = $repositories->categories();
$autoCheckId = max(0, (int) $request->get('auto_check', 0));
?>
<div class="main flm-admin">
  <div class="body container">
    <div class="typecho-page-title"><h2>友情链接</h2></div>
    <div class="row typecho-page-main" role="main">
      <div class="col-mb-12">
        <?php flm_tabs('links'); ?>
        <?php if ($autoCheckId): ?>
          <span
            hidden
            data-flm-auto-check
            data-flm-auto-check-id="<?php echo $autoCheckId; ?>"
            data-flm-auto-check-url="<?php echo flm_e(flm_action_url('run-check', ['id' => $autoCheckId])); ?>"
          ></span>
        <?php endif; ?>
        <div class="flm-toolbar">
          <a class="btn primary flm-add-link" href="<?php echo flm_e(flm_panel_url('link-edit')); ?>">新增友链</a>
          <form class="flm-inline" method="get">
            <input type="hidden" name="panel" value="FriendLinks/panel/links.php">
            <input class="text-s" type="text" name="keywords" value="<?php echo flm_e($filters['keywords']); ?>" placeholder="名称、URL 或描述">
            <select name="category_id">
              <option value="0">全部分类</option>
              <?php foreach ($categories as $category): ?>
                <option value="<?php echo (int) $category['id']; ?>"<?php echo $filters['category_id'] === (int) $category['id'] ? ' selected' : ''; ?>>
                  <?php echo flm_e($category['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <select name="visibility">
              <option value="">全部可见性</option>
              <?php foreach (['published' => '公开', 'draft' => '草稿', 'archived' => '已归档'] as $value => $label): ?>
                <option value="<?php echo $value; ?>"<?php echo $filters['visibility'] === $value ? ' selected' : ''; ?>><?php echo $label; ?></option>
              <?php endforeach; ?>
            </select>
            <select name="state">
              <option value="">全部状态</option>
              <?php foreach (StatusLabels::states() as $value => $label): ?>
                <option value="<?php echo flm_e($value); ?>"<?php echo $filters['state'] === $value ? ' selected' : ''; ?>><?php echo flm_e($label); ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-s" type="submit">筛选</button>
          </form>
        </div>

        <form class="flm-operate-form" method="post" action="<?php echo flm_e(flm_action_url('archive-links')); ?>">
          <div class="flm-form-actions">
            <div class="flm-inline">
              <button class="btn btn-s" type="submit" formaction="<?php echo flm_e(flm_action_url('schedule')); ?>">立即检测</button>
              <button class="btn btn-s" type="submit" formaction="<?php echo flm_e(flm_action_url('schedule', ['full' => 1])); ?>">完整复检</button>
              <button
                class="btn btn-s btn-warn"
                type="submit"
                data-flm-confirm
                data-flm-confirm-title="归档友链"
                data-flm-confirm-message="确认归档选中的友链？归档后将不再公开展示。"
                data-flm-confirm-label="确认归档"
              >归档</button>
            </div>
            <span class="flm-muted"><?php echo count($links); ?> 条结果</span>
          </div>
          <div class="typecho-table-wrap">
            <table class="typecho-list-table">
              <thead><tr><th width="4%"><input type="checkbox" class="typecho-table-select-all" aria-label="全选友链"></th><th>名称</th><th class="kit-hidden-mb">分类</th><th>状态</th><th class="kit-hidden-mb">最近检测</th><th class="kit-hidden-mb">排序</th><th>操作</th></tr></thead>
              <tbody>
              <?php if (!$links): ?>
                <tr><td colspan="7"><h6 class="typecho-list-table-title">暂无友链</h6></td></tr>
              <?php endif; ?>
              <?php foreach ($links as $link): ?>
                <tr id="friend-link-<?php echo (int) $link['id']; ?>">
                  <td><input type="checkbox" name="id[]" value="<?php echo (int) $link['id']; ?>" aria-label="选择 <?php echo flm_e($link['name']); ?>"></td>
                  <td><strong><?php echo flm_e($link['name']); ?></strong><br><small><?php echo flm_e($link['url']); ?></small></td>
                  <td class="kit-hidden-mb"><?php echo flm_e($link['category_name'] ?: '未分类'); ?></td>
                  <td><span id="flm-link-state-<?php echo (int) $link['id']; ?>" class="flm-state flm-state-<?php echo flm_e($link['overall_state'] ?: 'pending'); ?>" aria-live="polite"><?php echo flm_e(StatusLabels::state($link['overall_state'] ?: 'pending')); ?></span></td>
                  <td class="kit-hidden-mb"><?php echo $link['checked_at'] ? flm_e(date('Y-m-d H:i', (int) $link['checked_at'])) : '尚未检测'; ?></td>
                  <td class="kit-hidden-mb"><?php echo (int) $link['sort_order']; ?></td>
                  <td>
                    <div class="flm-row-actions">
                      <a class="btn btn-s" href="<?php echo flm_e(flm_panel_url('link-edit', ['id' => (int) $link['id']])); ?>">编辑</a>
                      <button
                        class="btn btn-s btn-warn"
                        type="submit"
                        formaction="<?php echo flm_e(flm_action_url('delete-link', ['link_id' => (int) $link['id']])); ?>"
                        data-flm-confirm
                        data-flm-confirm-title="删除友链"
                        data-flm-confirm-message="此操作会永久删除该友链及其检测记录，且无法撤销。"
                        data-flm-confirm-label="永久删除"
                      >删除</button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
