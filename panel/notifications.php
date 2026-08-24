<?php

use TypechoPlugin\FriendLinks\Application\Settings;
use TypechoPlugin\FriendLinks\Domain\NotificationTemplate;
use TypechoPlugin\FriendLinks\Infrastructure\Repositories;

require __DIR__ . '/_bootstrap.php';

$settings = Settings::all();
$repositories = new Repositories();
$notifications = $repositories->notifications(50);
$counts = $repositories->notificationCounts();
$channelLabels = [
    'webhook' => '通用 Webhook',
    'dingtalk' => '钉钉机器人',
    'email' => 'SMTP 邮件',
];
$eventLabels = [
    'down' => '站点不可用',
    'recovery' => '站点恢复',
    'warning' => '状态预警',
    'test' => '测试通知',
];
$statusLabels = [
    'pending' => '待发送',
    'sending' => '发送中',
    'sent' => '已发送',
    'failed' => '发送失败',
];
$placeholders = array_map(static function ($name) {
    return '{{' . $name . '}}';
}, NotificationTemplate::placeholders());
?>
<div class="main flm-admin">
  <div class="body container">
    <div class="typecho-page-title"><h2>FriendLinks 通知</h2></div>
    <div class="row typecho-page-main" role="main">
      <div class="col-mb-12">
        <?php flm_tabs('notifications'); ?>

        <form class="flm-form flm-notification-form" method="post" action="<?php echo flm_e(flm_action_url('save-notifications')); ?>" data-flm-notification-settings>
          <ul class="typecho-option-tabs fix-tabs flm-notification-tabs" role="tablist" aria-label="通知设置">
            <?php foreach (['policy' => '通知策略', 'webhook' => 'Webhook', 'dingtalk' => '钉钉机器人', 'email' => 'SMTP 邮件', 'template' => '消息模板'] as $tab => $label): ?>
              <li<?php echo 'policy' === $tab ? ' class="current"' : ''; ?>>
                <button type="button" role="tab" data-flm-notification-tab="<?php echo flm_e($tab); ?>" aria-selected="<?php echo 'policy' === $tab ? 'true' : 'false'; ?>"><?php echo flm_e($label); ?></button>
              </li>
            <?php endforeach; ?>
          </ul>

          <section class="flm-notification-panel" data-flm-notification-panel="policy">
            <div class="flm-section-head">
              <div>
                <h3>通知策略</h3>
                <p>通知事件先写入 Outbox，再由 Worker 异步投递；渠道故障不会影响健康检测结果。</p>
              </div>
            </div>
            <p><label class="flm-check"><input type="checkbox" name="notifications_enabled" value="1"<?php echo $settings['notifications_enabled'] ? ' checked' : ''; ?>> 启用通知</label></p>
            <div class="flm-trigger-options">
              <p><label class="flm-check"><input type="checkbox" name="notify_on_down" value="1"<?php echo $settings['notify_on_down'] ? ' checked' : ''; ?>> 站点确认不可用时通知</label></p>
              <p><label class="flm-check"><input type="checkbox" name="notify_on_recovery" value="1"<?php echo $settings['notify_on_recovery'] ? ' checked' : ''; ?>> 异常恢复正常时通知</label></p>
              <p><label class="flm-check"><input type="checkbox" name="notify_on_warning" value="1"<?php echo $settings['notify_on_warning'] ? ' checked' : ''; ?>> 预警或不稳定时通知</label></p>
            </div>
            <label for="flm-notification-cooldown">同类通知冷却时间（秒）</label>
            <input id="flm-notification-cooldown" type="number" name="notification_cooldown" min="300" max="604800" value="<?php echo (int) $settings['notification_cooldown']; ?>">
            <p class="flm-help">同一友链、同一事件和同一渠道在冷却时间内只创建一条通知，默认 3600 秒。</p>
            <button class="btn flm-notification-action flm-notification-queue" type="submit" formaction="<?php echo flm_e(flm_action_url('dispatch-notifications')); ?>" formnovalidate<?php echo empty($settings['notifications_enabled']) ? ' disabled' : ''; ?>>立即处理队列</button>
          </section>

          <section class="flm-notification-panel" data-flm-notification-panel="webhook" hidden>
            <div class="flm-section-head"><div><h3>通用 Webhook</h3><p>向 HTTPS 接口投递签名 JSON。</p></div></div>
            <p><label class="flm-check"><input type="checkbox" name="webhook_enabled" value="1"<?php echo $settings['webhook_enabled'] ? ' checked' : ''; ?>> 启用通用 Webhook</label></p>
            <label for="flm-webhook-url">HTTPS 地址</label>
            <input id="flm-webhook-url" type="password" name="webhook_url" value="" autocomplete="new-password" data-flm-configured="<?php echo $settings['webhook_url'] ? '1' : '0'; ?>" placeholder="<?php echo $settings['webhook_url'] ? '已配置，留空保持不变' : 'https://hooks.example.com/friendlinks'; ?>">
            <p class="flm-help">请求体为 JSON；禁止私网、回环和非 HTTPS 目标，不跟随重定向。</p>
            <label for="flm-webhook-secret">HMAC-SHA256 签名密钥（可选）</label>
            <input id="flm-webhook-secret" type="password" name="webhook_secret" value="" autocomplete="new-password" placeholder="<?php echo $settings['webhook_secret'] ? '已配置，留空保持不变' : '留空则不发送签名头'; ?>">
            <div class="flm-secret-actions">
              <label class="flm-check"><input type="checkbox" name="clear_webhook_url" value="1"> 清除已保存的地址</label>
              <label class="flm-check"><input type="checkbox" name="clear_webhook_secret" value="1"> 清除已保存的密钥</label>
            </div>
            <button class="btn flm-notification-action flm-notification-test" type="submit" formaction="<?php echo flm_e(flm_action_url('test-notification', ['channel' => 'webhook'])); ?>" formnovalidate data-flm-notification-test="webhook">发送测试消息</button>
          </section>

          <section class="flm-notification-panel" data-flm-notification-panel="dingtalk" hidden>
            <div class="flm-section-head"><div><h3>钉钉机器人</h3><p>支持钉钉自定义机器人的加签安全模式。</p></div></div>
            <p><label class="flm-check"><input type="checkbox" name="dingtalk_enabled" value="1"<?php echo $settings['dingtalk_enabled'] ? ' checked' : ''; ?>> 启用钉钉机器人</label></p>
            <label for="flm-dingtalk-url">机器人 Webhook 地址</label>
            <input id="flm-dingtalk-url" type="password" name="dingtalk_webhook_url" value="" autocomplete="new-password" data-flm-configured="<?php echo $settings['dingtalk_webhook_url'] ? '1' : '0'; ?>" placeholder="<?php echo $settings['dingtalk_webhook_url'] ? '已配置，留空保持不变' : 'https://oapi.dingtalk.com/robot/send?access_token=...'; ?>">
            <label for="flm-dingtalk-secret">加签密钥（可选）</label>
            <input id="flm-dingtalk-secret" type="password" name="dingtalk_secret" value="" autocomplete="new-password" placeholder="<?php echo $settings['dingtalk_secret'] ? '已配置，留空保持不变' : 'SEC...'; ?>">
            <div class="flm-secret-actions">
              <label class="flm-check"><input type="checkbox" name="clear_dingtalk_webhook_url" value="1"> 清除已保存的地址</label>
              <label class="flm-check"><input type="checkbox" name="clear_dingtalk_secret" value="1"> 清除已保存的密钥</label>
            </div>
            <button class="btn flm-notification-action flm-notification-test" type="submit" formaction="<?php echo flm_e(flm_action_url('test-notification', ['channel' => 'dingtalk'])); ?>" formnovalidate data-flm-notification-test="dingtalk">发送测试消息</button>
          </section>

          <section class="flm-notification-panel" data-flm-notification-panel="email" hidden>
            <div class="flm-section-head"><div><h3>SMTP 邮件</h3><p>支持 STARTTLS、SMTPS，以及无认证的本地明文中继。</p></div></div>
            <p><label class="flm-check"><input type="checkbox" name="email_enabled" value="1"<?php echo $settings['email_enabled'] ? ' checked' : ''; ?>> 启用 SMTP 邮件</label></p>
            <div class="flm-field-grid flm-field-grid-three">
              <div class="flm-field">
                <label for="flm-smtp-host">SMTP 主机</label>
                <input id="flm-smtp-host" type="text" name="smtp_host" value="<?php echo flm_e($settings['smtp_host']); ?>" placeholder="smtp.example.com">
              </div>
              <div class="flm-field">
                <label for="flm-smtp-port">端口</label>
                <input id="flm-smtp-port" type="number" name="smtp_port" min="1" max="65535" value="<?php echo (int) $settings['smtp_port']; ?>">
              </div>
              <div class="flm-field">
                <label for="flm-smtp-encryption">加密方式</label>
                <select id="flm-smtp-encryption" name="smtp_encryption">
                  <option value="starttls"<?php echo 'starttls' === $settings['smtp_encryption'] ? ' selected' : ''; ?>>STARTTLS</option>
                  <option value="smtps"<?php echo 'smtps' === $settings['smtp_encryption'] ? ' selected' : ''; ?>>SMTPS</option>
                  <option value="none"<?php echo 'none' === $settings['smtp_encryption'] ? ' selected' : ''; ?>>无（仅无认证本地中继）</option>
                </select>
              </div>
            </div>
            <label for="flm-smtp-username">SMTP 用户名</label>
            <input id="flm-smtp-username" type="text" name="smtp_username" value="<?php echo flm_e($settings['smtp_username']); ?>" autocomplete="username">
            <label for="flm-smtp-password">SMTP 密码</label>
            <input id="flm-smtp-password" type="password" name="smtp_password" value="" autocomplete="new-password" data-flm-configured="<?php echo $settings['smtp_password'] ? '1' : '0'; ?>" placeholder="<?php echo $settings['smtp_password'] ? '已配置，留空保持不变' : 'SMTP 密码或应用专用密码'; ?>">
            <p><label class="flm-check"><input type="checkbox" name="clear_smtp_password" value="1"> 清除已保存的 SMTP 密码</label></p>
            <div class="flm-field-grid">
              <div class="flm-field">
                <label for="flm-smtp-from-address">发件地址</label>
                <input id="flm-smtp-from-address" type="text" name="smtp_from_address" value="<?php echo flm_e($settings['smtp_from_address']); ?>" placeholder="monitor@example.com">
              </div>
              <div class="flm-field">
                <label for="flm-smtp-from-name">发件人名称</label>
                <input id="flm-smtp-from-name" type="text" name="smtp_from_name" value="<?php echo flm_e($settings['smtp_from_name']); ?>">
              </div>
            </div>
            <label for="flm-email-recipients">收件地址</label>
            <input id="flm-email-recipients" type="text" name="email_recipients" value="<?php echo flm_e($settings['email_recipients']); ?>" placeholder="admin@example.com, ops@example.com">
            <p class="flm-help">最多 20 个地址，使用逗号、分号或空格分隔。填写用户名或密码时必须使用 STARTTLS 或 SMTPS。</p>
            <button class="btn flm-notification-action flm-notification-test" type="submit" formaction="<?php echo flm_e(flm_action_url('test-notification', ['channel' => 'email'])); ?>" formnovalidate data-flm-notification-test="email">发送测试消息</button>
          </section>

          <section class="flm-notification-panel" data-flm-notification-panel="template" hidden>
            <div class="flm-section-head"><div><h3>消息模板</h3><p>三个通知渠道共享同一套纯文本模板。</p></div></div>
            <label for="flm-notification-subject">标题模板</label>
            <input id="flm-notification-subject" type="text" name="notification_subject_template" maxlength="240" value="<?php echo flm_e($settings['notification_subject_template']); ?>">
            <label for="flm-notification-message">正文模板</label>
            <textarea id="flm-notification-message" name="notification_message_template" rows="9"><?php echo flm_e($settings['notification_message_template']); ?></textarea>
            <p class="flm-help">支持变量：<code><?php echo flm_e(implode('  ', $placeholders)); ?></code></p>
            <p class="flm-help">模板仅执行变量替换，不执行 HTML、PHP 或表达式。邮件和钉钉均发送纯文本。</p>
          </section>

          <div class="flm-form-actions">
            <button class="btn primary" type="submit">保存通知设置</button>
          </div>
        </form>

        <div class="flm-section-head flm-section-head-list">
          <div>
            <h3>最近投递</h3>
            <p>待发送 <?php echo (int) ($counts['pending'] ?? 0); ?>，失败 <?php echo (int) ($counts['failed'] ?? 0); ?>，已发送 <?php echo (int) ($counts['sent'] ?? 0); ?>。</p>
          </div>
        </div>
        <div class="typecho-table-wrap">
          <table class="typecho-list-table">
            <thead><tr><th class="kit-hidden-mb">时间</th><th>友链/事件</th><th>渠道</th><th>状态</th><th class="kit-hidden-mb">尝试</th><th class="kit-hidden-mb">结果</th><th>操作</th></tr></thead>
            <tbody>
            <?php if (!$notifications): ?>
              <tr><td colspan="7">暂无通知记录。</td></tr>
            <?php else: ?>
              <?php foreach ($notifications as $notification): ?>
                <?php
                $status = (string) $notification['status'];
                $isFailed = 'failed' === $status;
                ?>
                <tr>
                  <td class="kit-hidden-mb"><?php echo flm_e(date('Y-m-d H:i', (int) $notification['created_at'])); ?></td>
                  <td><strong><?php echo flm_e($notification['link_name'] ?: '测试通知'); ?></strong><br><small><?php echo flm_e($eventLabels[$notification['event_type']] ?? $notification['event_type']); ?></small></td>
                  <td><?php echo flm_e($channelLabels[$notification['channel']] ?? $notification['channel']); ?></td>
                  <td class="<?php echo $isFailed ? 'flm-state-down' : ''; ?>"><?php echo flm_e($statusLabels[$status] ?? $status); ?></td>
                  <td class="kit-hidden-mb"><?php echo (int) $notification['attempts']; ?>/5</td>
                  <td class="kit-hidden-mb"><small><?php echo flm_e($notification['last_error'] ?: ($notification['sent_at'] ? date('Y-m-d H:i', (int) $notification['sent_at']) : '—')); ?></small></td>
                  <td>
                    <?php if ($isFailed): ?>
                      <form method="post" action="<?php echo flm_e(flm_action_url('retry-notification', ['id' => (int) $notification['id']])); ?>">
                        <button class="btn btn-s" type="submit">重试</button>
                      </form>
                    <?php else: ?>—<?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
