<?php

flm_require_admin_file('common-js.php');
echo '<script src="' . flm_e(
    rtrim(\Widget\Options::alloc()->pluginUrl, '/') . '/FriendLinks/assets/admin.js?v='
    . \TypechoPlugin\FriendLinks\Presentation\AssetVersion::forFile(dirname(__DIR__) . '/assets/admin.js')
) . '"></script>';
flm_require_admin_file('footer.php');
