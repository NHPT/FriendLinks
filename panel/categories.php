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
        <div class="flm-section-head">
          <div>
            <h3><?php echo $editId ? '编辑分类' : '新增分类'; ?></h3>
            <p>分类用于组织前台友链，不会随检测状态自动变更。</p>
          </div>
          <?php if ($editId): ?><a class="btn btn-s" href="<?php echo flm_e(flm_panel_url('categories')); ?>">取消编辑</a><?php endif; ?>
        </div>
        <form class="flm-form flm-category-form" method="post" action="<?php echo flm_e(flm_action_url('save-category')); ?>">
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
            <button class="btn primary flm-category-submit" type="submit"><?php echo $editId ? '更新分类' : '新增分类'; ?></button>
          </div>
        </form>

        <div class="flm-section-head flm-section-head-list">
          <div>
            <h3>分类列表</h3>
            <p>删除分类后，原友链会转为未分类。</p>
          </div>
          <span class="flm-muted"><?php echo count($categories); ?> 个分类</span>
        </div>
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
                    <a href="<?php echo flm_e(flm_panel_url('categories', ['id' => (int) $category['id']])); ?>">编辑</a>
                    <form method="post" action="<?php echo flm_e(flm_action_url('delete-category', ['id' => (int) $category['id']])); ?>" onsubmit="return confirm('删除分类后，原友链将转为未分类。继续？')">
                      <button class="btn-link" type="submit">删除</button>
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
