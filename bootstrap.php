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

if ('cli' === PHP_SAPI && !defined('__TYPECHO_ROOT_URL__')) {
    $rootUrl = 'http://localhost/';
    try {
        $db = \Typecho\Db::get();
        $row = $db->fetchRow($db->select('value')->from('table.options')
            ->where('name = ?', 'siteUrl')
            ->where('user = ?', 0)
            ->limit(1));
        $siteUrl = trim((string) ($row['value'] ?? ''));
        $parts = '' === $siteUrl ? false : parse_url($siteUrl);
        if (
            false !== $parts
            && !empty($parts['scheme'])
            && !empty($parts['host'])
        ) {
            $rootUrl = rtrim($siteUrl, '/') . '/';
        }
    } catch (\Throwable $ignored) {
    }
    define('__TYPECHO_ROOT_URL__', $rootUrl);
}

\Widget\Init::alloc();
