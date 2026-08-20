<?php

include __TYPECHO_ROOT_DIR__ . '/admin/common-js.php';
echo '<script src="' . flm_e(
    rtrim(\Widget\Options::alloc()->pluginUrl, '/') . '/FriendLinks/assets/admin.js?v='
    . filemtime(dirname(__DIR__) . '/assets/admin.js')
) . '"></script>';
include __TYPECHO_ROOT_DIR__ . '/admin/footer.php';
