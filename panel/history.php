<?php

use TypechoPlugin\FriendLinks\Infrastructure\Repositories;
use TypechoPlugin\FriendLinks\Presentation\StatusLabels;

require __DIR__ . '/_bootstrap.php';

$repositories = new Repositories();
$linkId = max(0, (int) $request->get('link_id', 0));
$history = $repositories->history(200, $linkId);
?>
<div class="main flm-admin">
  <div class="body container">
    <div class="typecho-page-title"><h2>检测历史</h2></div>
    <div class="row typecho-page-main" role="main">
      <div class="col-mb-12">
        <?php flm_tabs('history'); ?>
        <form class="flm-toolbar" method="get">
          <input type="hidden" name="panel" value="FriendLinks/panel/history.php">
          <div class="flm-inline">
            <select name="link_id">
              <option value="0">全部友链</option>
              <?php foreach ($repositories->links([], 500) as $link): ?>
                <option value="<?php echo (int) $link['id']; ?>"<?php echo $linkId === (int) $link['id'] ? ' selected' : ''; ?>><?php echo flm_e($link['name']); ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-s" type="submit">筛选</button>
          </div>
          <span class="flm-muted">最多显示最近 200 条</span>
        </form>
        <div class="typecho-table-wrap">
          <table class="typecho-list-table">
            <thead><tr><th>友链</th><th>状态</th><th>原因</th><th>HTTP</th><th>耗时</th><th>开始时间</th><th>诊断</th></tr></thead>
            <tbody>
            <?php foreach ($history as $row): ?>
              <tr>
                <td><a href="<?php echo flm_e(flm_panel_url('link-edit', ['id' => (int) $row['link_id']])); ?>"><?php echo flm_e($row['link_name'] ?: '#' . $row['link_id']); ?></a></td>
                <td><span class="flm-state flm-state-<?php echo flm_e($row['overall_state']); ?>"><?php echo flm_e(StatusLabels::state($row['overall_state'])); ?></span></td>
                <td><?php echo flm_e(StatusLabels::reason($row['reason_code'])); ?></td>
                <td><?php echo null === $row['http_code'] ? '-' : (int) $row['http_code']; ?></td>
                <td><?php echo null === $row['response_time_ms'] ? '-' : (int) $row['response_time_ms'] . ' ms'; ?></td>
                <td><?php echo flm_e(date('Y-m-d H:i:s', (int) $row['started_at'])); ?></td>
                <td><details><summary>查看</summary><pre class="flm-code"><?php echo flm_e(json_encode(json_decode($row['details_json'], true), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre></details></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$history): ?><tr><td colspan="7">暂无检测历史</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
