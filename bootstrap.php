<?php

require_once __DIR__ . '/vendor/autoload.php';

if (!defined('__TYPECHO_ROOT_DIR__')) {
    $root = dirname(__DIR__, 3);
    $config = $root . '/config.inc.php';
    if (!is_file($config)) {
        throw new RuntimeException('Typecho config.inc.php was not found relative to the plugin directory.');
    }
    require_once $config;
}

\Widget\Init::alloc();
