<?php

require flm_admin_file('common-js.php');
echo '<script src="' . flm_e(
    rtrim(\Widget\Options::alloc()->pluginUrl, '/') . '/FriendLinks/assets/admin.js?v='
    . filemtime(dirname(__DIR__) . '/assets/admin.js')
) . '"></script>';
require flm_admin_file('footer.php');
