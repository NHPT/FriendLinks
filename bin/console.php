#!/usr/bin/env php
<?php

if ('cli' !== PHP_SAPI) {
    http_response_code(404);
    exit;
}

$command = $argv[1] ?? 'help';
if (!in_array($command, ['check', 'help', 'self-test'], true)) {
    fwrite(STDERR, "Unknown command.\n");
    exit(2);
}
if ('help' === $command) {
    fwrite(
        STDOUT,
        "Usage: php bin/console.php check [--scheduled] --due [--limit=50] [--max-seconds=240]\n"
    );
    exit(0);
}

require_once dirname(__DIR__) . '/bootstrap.php';

if ('self-test' === $command) {
    fwrite(STDOUT, "FriendLinks CLI ready\n");
    exit(0);
}

$limit = null;
$maxSeconds = null;
$scheduled = false;
foreach (array_slice($argv, 2) as $argument) {
    if (0 === strpos($argument, '--limit=')) {
        $limit = (int) substr($argument, 8);
    } elseif (0 === strpos($argument, '--max-seconds=')) {
        $maxSeconds = (int) substr($argument, 14);
    } elseif ('--scheduled' === $argument) {
        $scheduled = true;
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

$settings = \TypechoPlugin\FriendLinks\Application\Settings::all();
$limit = null === $limit ? (int) $settings['cli_worker_limit'] : $limit;
$maxSeconds = null === $maxSeconds ? (int) $settings['cli_worker_max_seconds'] : $maxSeconds;
$limit = max(1, min(500, $limit));
$maxSeconds = max(1, min(3600, $maxSeconds));
$scheduledRunId = null;
if ($scheduled) {
    $now = time();
    $interval = \TypechoPlugin\FriendLinks\Application\Settings::cronIntervalSeconds($settings);
    $decision = (new \TypechoPlugin\FriendLinks\Infrastructure\Repositories())
        ->claimCliSchedule($now, $interval, $maxSeconds);
    if (empty($decision['due'])) {
        fwrite(STDOUT, json_encode([
            'status' => 'skipped',
            'reason' => $decision['reason'],
            'interval_seconds' => $interval,
            'next_run_at' => $decision['next_run_at'],
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }
    $scheduledRunId = $decision['run_id'];
}

$result = (new \TypechoPlugin\FriendLinks\Application\Worker())
    ->run('cli', $limit, $maxSeconds, [], $scheduledRunId);
if ($scheduled) {
    $result['interval_seconds'] = $interval;
}
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($result['failed'] > 0 && 0 === $result['completed'] ? 1 : 0);
