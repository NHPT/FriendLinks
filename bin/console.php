#!/usr/bin/env php
<?php

if ('cli' !== PHP_SAPI) {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';

use TypechoPlugin\FriendLinks\Application\Worker;

$command = $argv[1] ?? 'help';
if (!in_array($command, ['check', 'help'], true)) {
    fwrite(STDERR, "Unknown command.\n");
    exit(2);
}
if ('help' === $command) {
    fwrite(STDOUT, "Usage: php bin/console.php check --due [--limit=50] [--max-seconds=240]\n");
    exit(0);
}

$limit = 50;
$maxSeconds = 240;
foreach (array_slice($argv, 2) as $argument) {
    if (0 === strpos($argument, '--limit=')) {
        $limit = (int) substr($argument, 8);
    } elseif (0 === strpos($argument, '--max-seconds=')) {
        $maxSeconds = (int) substr($argument, 14);
    } elseif ('--due' !== $argument) {
        fwrite(STDERR, "Unknown option: {$argument}\n");
        exit(2);
    }
}

if (!\Typecho\Plugin::exists('FriendLinks')) {
    fwrite(STDOUT, json_encode([
        'status' => 'disabled',
        'message' => 'FriendLinks is disabled; nothing to do.',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
}

$result = (new Worker())->run('cli', $limit, $maxSeconds);
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($result['failed'] > 0 && 0 === $result['completed'] ? 1 : 0);
