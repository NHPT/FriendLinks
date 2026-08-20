<?php

use TypechoPlugin\FriendLinks\Application\ImportService;

require __DIR__ . '/_bootstrap.php';

$preview = [];
$previewError = null;
$format = (string) $request->get('format', 'csv');
$payload = (string) $request->get('payload', '');

if ($request->isPost()) {
    $security->protect();
    try {
        $preview = (new ImportService())->preview($format, $payload);
    } catch (\Throwable $error) {
        $previewError = $error->getMessage();
    }
}
?>
<div class="main flm-admin">
  <div class="body container">
    <div class="typecho-page-title"><h2>导入导出</h2></div>
    <div class="row typecho-page-main" role="main">
      <div class="col-mb-12">
        <?php flm_tabs('import'); ?>
        <h3>导入预览</h3>
        <form class="flm-form" method="post">
          <input type="hidden" name="_" value="<?php echo flm_e($security->getToken($request->getRequestUrl())); ?>">
          <label for="flm-import-format">格式</label>
          <select id="flm-import-format" name="format">
            <option value="csv"<?php echo 'csv' === $format ? ' selected' : ''; ?>>CSV</option>
            <option value="json"<?php echo 'json' === $format ? ' selected' : ''; ?>>JSON</option>
          </select>
          <label for="flm-import-payload">导入内容</label>
          <textarea id="flm-import-payload" name="payload" placeholder="粘贴 CSV 或 JSON"><?php echo flm_e($payload); ?></textarea>
          <p class="flm-help">预览不会写入数据。CSV 表头至少包含 name 和 url。</p>
          <button class="btn" type="submit">生成预览</button>
        </form>

        <?php if ($previewError): ?><p class="flm-warning"><?php echo flm_e($previewError); ?></p><?php endif; ?>
        <?php if ($preview): ?>
          <h3>确认结果</h3>
          <div class="typecho-table-wrap">
            <table class="typecho-list-table">
              <thead><tr><th>行</th><th>名称</th><th>URL</th><th>分类</th><th>检查结果</th></tr></thead>
              <tbody>
              <?php foreach ($preview as $row): ?>
                <tr><td><?php echo (int) $row['line']; ?></td><td><?php echo flm_e($row['name']); ?></td><td><?php echo flm_e($row['url']); ?></td><td><?php echo flm_e($row['category']); ?></td><td class="<?php echo $row['errors'] ? 'flm-preview-errors' : ''; ?>"><?php echo $row['errors'] ? flm_e(implode('；', $row['errors'])) : '可导入'; ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <form method="post" action="<?php echo flm_e(flm_action_url('import')); ?>">
            <textarea name="rows_json" hidden><?php echo flm_e(json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></textarea>
            <button class="btn primary" type="submit" onclick="return confirm('确认写入预览中的有效友链？')">确认导入</button>
          </form>
        <?php endif; ?>

        <h3>导出</h3>
        <div class="flm-inline">
          <form method="post" action="<?php echo flm_e(flm_action_url('export', ['format' => 'json'])); ?>"><button class="btn" type="submit">导出 JSON</button></form>
          <form method="post" action="<?php echo flm_e(flm_action_url('export', ['format' => 'csv'])); ?>"><button class="btn" type="submit">导出 CSV</button></form>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
