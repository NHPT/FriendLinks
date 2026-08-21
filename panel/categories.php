<?php

use TypechoPlugin\FriendLinks\Infrastructure\Repositories;

require __DIR__ . '/_bootstrap.php';

$repositories = new Repositories();
$categories = $repositories->categories();
$editId = max(0, (int) $request->get('id', 0));
$editing = $editId ? $repositories->category($editId) : null;
if ($editId && !$editing) {
    throw new \Typecho\Widget\Exception('分类不存在', 404);
}
$editing = $editing ?: ['name' => '', 'slug' => '', 'sort_order' => 0, 'enabled' => 1];
?>
<div class="main flm-admin">
  <div class="body container">
    <div class="typecho-page-title"><h2>友链分类</h2></div>
    <div class="row typecho-page-main" role="main">
      <div class="col-mb-12">
        <?php flm_tabs('categories'); ?>
        <div class="flm-toolbar">
          <?php if ($editId): ?>
            <a class="btn primary flm-add-link" href="<?php echo flm_e(flm_panel_url('categories')); ?>">新增分类</a>
          <?php else: ?>
            <button class="btn primary flm-add-link" type="button" data-flm-category-toggle aria-expanded="false">新增分类</button>
          <?php endif; ?>
          <span class="flm-muted"><?php echo count($categories); ?> 个分类</span>
        </div>
        <form class="flm-form flm-category-form" method="post" action="<?php echo flm_e(flm_action_url('save-category')); ?>" data-flm-category-editor<?php echo $editId ? '' : ' hidden'; ?>>
          <h4><?php echo $editId ? '编辑分类' : '新增分类'; ?></h4>
          <input type="hidden" name="id" value="<?php echo $editId; ?>">
          <div class="flm-category-fields">
            <div class="flm-field">
              <label for="flm-category-name">名称</label>
              <input id="flm-category-name" type="text" name="name" required maxlength="120" value="<?php echo flm_e($editing['name']); ?>">
            </div>
            <div class="flm-field">
              <label for="flm-category-slug">稳定标识</label>
              <input id="flm-category-slug" type="text" name="slug" maxlength="120" pattern="[A-Za-z0-9][A-Za-z0-9_-]*" value="<?php echo flm_e($editing['slug']); ?>">
            </div>
            <div class="flm-field flm-field-order">
              <label for="flm-category-sort">排序</label>
              <input id="flm-category-sort" type="number" name="sort_order" value="<?php echo (int) $editing['sort_order']; ?>">
            </div>
            <label class="flm-check flm-category-enabled"><input type="checkbox" name="enabled" value="1"<?php echo $editing['enabled'] ? ' checked' : ''; ?>> 前台显示</label>
          </div>
          <div class="flm-form-actions">
            <div class="flm-inline">
              <button class="btn primary flm-category-submit" type="submit"><?php echo $editId ? '更新分类' : '新增分类'; ?></button>
              <a class="btn" href="<?php echo flm_e(flm_panel_url('categories')); ?>">取消</a>
            </div>
          </div>
        </form>

        <div class="typecho-table-wrap">
          <table class="typecho-list-table">
            <thead><tr><th>名称</th><th class="kit-hidden-mb">标识</th><th class="kit-hidden-mb">排序</th><th><span class="kit-hidden-mb">前台</span>显示</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($categories as $category): ?>
              <tr>
                <td><strong><?php echo flm_e($category['name']); ?></strong></td>
                <td class="kit-hidden-mb"><code><?php echo flm_e($category['slug']); ?></code></td>
                <td class="kit-hidden-mb"><?php echo (int) $category['sort_order']; ?></td>
                <td><?php echo $category['enabled'] ? '是' : '否'; ?></td>
                <td>
                  <div class="flm-row-actions">
                    <a class="btn btn-s" href="<?php echo flm_e(flm_panel_url('categories', ['id' => (int) $category['id']])); ?>">编辑</a>
                    <form method="post" action="<?php echo flm_e(flm_action_url('delete-category', ['id' => (int) $category['id']])); ?>" onsubmit="return confirm('删除分类后，原友链将转为未分类。继续？')">
                      <button class="btn btn-s btn-warn" type="submit">删除</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$categories): ?><tr><td colspan="5">暂无分类</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
