<?php

require flm_admin_file('common-js.php');
?>
<div class="flm-admin flm-dialog-host">
  <dialog class="flm-dialog flm-confirm-dialog" data-flm-confirm-dialog aria-labelledby="flm-confirm-dialog-title">
    <div class="flm-dialog-head">
      <h3 id="flm-confirm-dialog-title" data-flm-confirm-title>确认操作</h3>
      <button class="flm-dialog-close" type="button" data-flm-confirm-cancel aria-label="关闭">&times;</button>
    </div>
    <div class="flm-dialog-body">
      <p class="flm-confirm-message" data-flm-confirm-message></p>
      <div class="flm-dialog-actions">
        <button class="btn" type="button" data-flm-confirm-cancel>取消</button>
        <button class="btn btn-warn" type="button" data-flm-confirm-accept>确认</button>
      </div>
    </div>
  </dialog>
</div>
<?php
echo '<script src="' . flm_e(
    rtrim(\Widget\Options::alloc()->pluginUrl, '/') . '/FriendLinks/assets/admin.js?v='
    . \TypechoPlugin\FriendLinks\Presentation\AssetVersion::forFile(dirname(__DIR__) . '/assets/admin.js')
) . '"></script>';
require flm_admin_file('footer.php');
