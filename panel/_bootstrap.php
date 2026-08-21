<?php

if (!defined('__TYPECHO_ADMIN__')) {
    exit;
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
$user->pass('administrator');

function flm_admin_file(string $file): string
{
    $adminDir = defined('__TYPECHO_ADMIN_DIR__') ? __TYPECHO_ADMIN_DIR__ : '/admin/';
    return rtrim(__TYPECHO_ROOT_DIR__, '/\\') . '/' . trim($adminDir, '/\\') . '/' . ltrim($file, '/\\');
}

require flm_admin_file('header.php');
require flm_admin_file('menu.php');

function flm_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function flm_panel_url(string $panel, array $query = []): string
{
    $url = 'extending.php?panel=FriendLinks/panel/' . $panel . '.php';
    if ($query) {
        $url .= '&' . http_build_query($query);
    }
    return \Typecho\Common::url($url, \Widget\Options::alloc()->adminUrl);
}

function flm_action_url(string $operation, array $query = []): string
{
    $query = array_merge(['do' => $operation], $query);
    return \Widget\Security::alloc()->getIndex('/action/friendlinks?' . http_build_query($query));
}

function flm_tabs(string $current): void
{
    $tabs = [
        'links' => '友链',
        'categories' => '分类',
        'health' => '健康',
        'history' => '历史',
        'import' => '导入导出',
        'notifications' => '通知',
        'settings' => '设置',
    ];
    echo '<ul class="typecho-option-tabs fix-tabs clearfix">';
    foreach ($tabs as $key => $label) {
        echo '<li' . ($key === $current ? ' class="current"' : '') . '><a href="'
            . flm_e(flm_panel_url($key)) . '">' . flm_e($label) . '</a></li>';
    }
    echo '</ul>';
}
?>
<link rel="stylesheet" href="<?php echo flm_e(
    rtrim($options->pluginUrl, '/') . '/FriendLinks/assets/admin.css?v='
    . filemtime(dirname(__DIR__) . '/assets/admin.css')
); ?>">
