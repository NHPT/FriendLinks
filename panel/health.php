<?php

use TypechoPlugin\FriendLinks\Application\Settings;
use TypechoPlugin\FriendLinks\Infrastructure\MigrationManager;
use TypechoPlugin\FriendLinks\Infrastructure\Repositories;
use TypechoPlugin\FriendLinks\Presentation\StatusLabels;

require __DIR__ . '/_bootstrap.php';

$repositories = new Repositories();
$counts = $repositories->statusCounts();
$backlog = $repositories->backlog(time());
$runs = $repositories->latestRuns(20);
$latest = $runs[0] ?? null;
$pageError = null;
try {
    Settings::assertPage((int) Settings::get('page_cid', 0));
    if ((int) Settings::get('page_cid', 0) < 1) {
        $pageError = '尚未绑定承载页。';
    }
} catch (\Throwable $error) {
    $pageError = $error->getMessage();
}
$cron = 'php ' . escapeshellarg(dirname(__DIR__) . '/bin/console.php') . ' check --due --limit=50';
?>
<div class="main flm-admin">
  <div class="body container">
    <div class="typecho-page-title"><h2>检测健康总览</h2></div>
    <div class="row typecho-page-main" role="main">
      <div class="col-mb-12">
        <?php flm_tabs('health'); ?>
        <?php if ($pageError): ?><p class="flm-warning"><?php echo flm_e($pageError); ?></p><?php endif; ?>
        <?php if (!extension_loaded('curl')): ?><p class="flm-warning">缺少 PHP cURL 扩展，自动检测已不可用；管理和展示不受影响。</p><?php endif; ?>
        <div class="flm-grid">
          <?php foreach (['healthy' => '正常', 'warning' => '预警', 'degraded' => '不稳定', 'down' => '不可用', 'unknown' => '未知', 'pending' => '待检测'] as $state => $label): ?>
            <div class="flm-stat"><strong class="flm-state flm-state-<?php echo $state; ?>"><?php echo (int) ($counts[$state] ?? 0); ?></strong><span><?php echo $label; ?></span></div>
          <?php endforeach; ?>
          <div class="flm-stat"><strong><?php echo $backlog['due']; ?></strong><span>到期任务</span></div>
          <div class="flm-stat"><strong><?php echo $backlog['leased']; ?></strong><span>租约中</span></div>
        </div>

        <h3>系统 Cron</h3>
        <code class="flm-code"><?php echo flm_e($cron); ?></code>
        <p class="flm-help">建议每 5 分钟调用一次。命令使用绝对插件路径，不依赖 Cron 工作目录。</p>

        <h3>运行状态</h3>
        <p>Schema 版本：<?php echo (new MigrationManager())->version(); ?>；
          最近心跳：<?php echo $latest ? flm_e(date('Y-m-d H:i:s', (int) $latest['heartbeat_at'])) : '从未运行'; ?>；
          最近结果：<?php echo $latest ? flm_e($latest['status']) : '无'; ?></p>
        <div class="typecho-table-wrap">
          <table class="typecho-list-table">
            <thead><tr><th>开始时间</th><th>模式</th><th>状态</th><th>领取</th><th>完成</th><th>失败</th><th>摘要</th></tr></thead>
            <tbody>
            <?php foreach ($runs as $run): ?>
              <tr>
                <td><?php echo flm_e(date('Y-m-d H:i:s', (int) $run['started_at'])); ?></td>
                <td><?php echo flm_e($run['mode']); ?></td>
                <td><?php echo flm_e(StatusLabels::runState($run['status'])); ?></td>
                <td><?php echo (int) $run['claimed_count']; ?></td>
                <td><?php echo (int) $run['completed_count']; ?></td>
                <td><?php echo (int) $run['failed_count']; ?></td>
                <td><?php echo flm_e($run['error_summary']); ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$runs): ?><tr><td colspan="7">暂无 Worker 运行记录</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
