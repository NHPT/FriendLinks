<?php

namespace TypechoPlugin\FriendLinks\Infrastructure;

use TypechoPlugin\FriendLinks\Domain\Text;

final class SystemCronManager
{
    private const CRON_ID_OPTION = 'friendlinks_cron_id';
    private const CRON_OWNER_OPTION = 'friendlinks_cron_owner';
    private const CRON_PHP_OPTION = 'friendlinks_cron_php';
    private const MAX_CRONTAB_BYTES = 1048576;
    private const SCHEDULE = '*/5 * * * *';

    /** @var Database */
    private $database;

    /** @var callable|null */
    private $runner;

    /** @var string */
    private $pluginRoot;

    /** @var string|null */
    private $crontabOverride;

    /** @var string|null */
    private $phpOverride;

    /** @var string */
    private $osFamily;

    /** @var bool */
    private $writeStateUncertain = false;

    public function __construct(
        ?Database $database = null,
        ?callable $runner = null,
        ?string $pluginRoot = null,
        ?string $crontabBinary = null,
        ?string $phpBinary = null,
        ?string $osFamily = null
    ) {
        $this->database = $database ?: new Database();
        $this->runner = $runner;
        $this->pluginRoot = $pluginRoot ?: dirname(__DIR__, 2);
        $this->crontabOverride = $crontabBinary ?: $this->environmentPath('FRIENDLINKS_CRONTAB_BINARY');
        $this->phpOverride = $phpBinary ?: $this->environmentPath('FRIENDLINKS_PHP_CLI');
        $this->osFamily = $osFamily ?: (defined('PHP_OS_FAMILY') ? PHP_OS_FAMILY : PHP_OS);
    }

    public function install(): array
    {
        $this->assertSupported();
        $crontab = $this->findCrontabBinary();
        $php = $this->findPhpCli();
        $this->writeStateUncertain = false;
        return $this->withCrontabLock($crontab, function () use ($crontab, $php) {
            $ownerCreated = $this->assertOwner(true);
            try {
                $cronId = $this->loadOrCreateCronId();
                $this->persistPhpCli($php);
                $current = $this->readCrontab($crontab);
                $updated = $this->appendBlock($this->stripBlock($current, $cronId), $cronId, $php);

                if ($updated !== $this->normalizeCrontab($current)) {
                    $this->replaceAndVerify($crontab, $current, $updated, $cronId, true);
                } elseif (!$this->containsExpectedBlock($current, $cronId, $php)) {
                    throw new \RuntimeException('FriendLinks 自动 Cron 校验失败。');
                }

                return [
                    'installed' => true,
                    'cron_id' => $cronId,
                    'schedule' => self::SCHEDULE,
                ];
            } catch (\Throwable $error) {
                if ($ownerCreated && !$this->writeStateUncertain) {
                    try {
                        $this->releaseOwner();
                    } catch (\Throwable $rollback) {
                        throw new \RuntimeException(
                            $error->getMessage() . '；Cron 安装用户回滚失败：' . $rollback->getMessage(),
                            0,
                            $error
                        );
                    }
                }
                throw $error;
            }
        });
    }

    public function remove(): void
    {
        $cronId = $this->storedCronId(true);
        if (null === $cronId) {
            return;
        }

        $this->assertSupported();
        $this->assertOwner(false);
        $crontab = $this->findCrontabBinary();
        $this->withCrontabLock($crontab, function () use ($cronId, $crontab) {
            $current = $this->readCrontab($crontab);
            $updated = $this->normalizeCrontab($this->stripBlock($current, $cronId));
            if ($updated !== $this->normalizeCrontab($current)) {
                $this->replaceAndVerify($crontab, $current, $updated, $cronId, false);
            }
            return null;
        });
    }

    public function inspect(): array
    {
        $cronId = $this->storedCronId(true);
        if (null === $cronId) {
            return ['installed' => false, 'schedule' => self::SCHEDULE];
        }

        $this->assertSupported();
        $this->assertOwner(false);
        $crontab = $this->findCrontabBinary();
        $php = $this->storedPhpCli();
        return $this->withCrontabLock($crontab, function () use ($cronId, $crontab, $php) {
            $current = $this->readCrontab($crontab);
            return [
                'installed' => $this->containsExpectedBlock($current, $cronId, $php),
                'schedule' => self::SCHEDULE,
            ];
        });
    }

    private function assertSupported(): void
    {
        if ('Linux' !== $this->osFamily) {
            throw new \RuntimeException('自动 Cron 仅支持 Linux。');
        }
        if (null === $this->runner && !is_callable('proc_open')) {
            throw new \RuntimeException('PHP proc_open 已禁用，无法自动管理系统 Cron。');
        }
    }

    private function findCrontabBinary(): string
    {
        return $this->findExecutable(
            $this->crontabOverride,
            array_merge(
                ['/usr/bin/crontab', '/usr/local/bin/crontab', '/bin/crontab'],
                $this->pathCandidates('crontab')
            ),
            'crontab'
        );
    }

    private function findPhpCli(): string
    {
        $candidates = array_merge(
            [
                defined('PHP_BINDIR') ? PHP_BINDIR . '/php' : '',
                dirname(PHP_BINARY) . '/php',
                '/usr/bin/php',
                '/usr/local/bin/php',
            ],
            $this->pathCandidates('php')
        );
        if (null !== $this->phpOverride) {
            array_unshift($candidates, $this->phpOverride);
        }

        $errors = [];
        foreach (array_values(array_unique(array_filter($candidates))) as $candidate) {
            if (!$this->validExecutable($candidate)) {
                continue;
            }
            $sapi = $this->run([$candidate, '-r', 'echo PHP_SAPI;'], '', 10);
            if (0 !== $sapi['code'] || 'cli' !== trim($sapi['stdout'])) {
                $errors[] = basename($candidate) . ' 不是 PHP CLI';
                continue;
            }
            $console = $this->consolePath();
            $selfTest = $this->run([$candidate, $console, 'help'], '', 15);
            if (0 !== $selfTest['code']) {
                $errors[] = basename($candidate) . ' 无法启动 FriendLinks CLI';
                continue;
            }
            return $candidate;
        }

        $detail = $errors ? '：' . implode('；', array_unique($errors)) : '';
        throw new \RuntimeException('未找到可用的 PHP CLI' . $detail . '。');
    }

    private function findExecutable(?string $override, array $candidates, string $name): string
    {
        if (null !== $override) {
            if (!$this->validExecutable($override)) {
                throw new \RuntimeException($name . ' 路径不可执行。');
            }
            return $override;
        }
        foreach (array_values(array_unique(array_filter($candidates))) as $candidate) {
            if ($this->validExecutable($candidate)) {
                return $candidate;
            }
        }
        throw new \RuntimeException('未找到可执行的 ' . $name . '。');
    }

    private function validExecutable(string $path): bool
    {
        return '' !== $path
            && '/' === $path[0]
            && !preg_match('/[\x00-\x1F\x7F]/', $path)
            && is_file($path)
            && is_executable($path);
    }

    private function pathCandidates(string $binary): array
    {
        $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));
        $candidates = [];
        foreach ($paths as $path) {
            if ('' !== $path && '/' === $path[0] && !preg_match('/[\x00-\x1F\x7F]/', $path)) {
                $candidates[] = rtrim($path, '/') . '/' . $binary;
            }
        }
        return $candidates;
    }

    private function environmentPath(string $name): ?string
    {
        $value = trim((string) getenv($name));
        return '' === $value ? null : $value;
    }

    private function readCrontab(string $crontab): string
    {
        $result = $this->run([$crontab, '-l'], '', 10);
        if (0 === $result['code']) {
            if (strlen($result['stdout']) > self::MAX_CRONTAB_BYTES) {
                throw new \RuntimeException('当前 crontab 超过 1 MiB，已拒绝自动修改。');
            }
            return $result['stdout'];
        }

        $message = trim($result['stdout'] . "\n" . $result['stderr']);
        if (1 === $result['code'] && preg_match('/\bno crontab\b/i', $message)) {
            return '';
        }
        throw new \RuntimeException('无法读取当前 crontab：' . $this->summary($message));
    }

    private function writeCrontab(string $crontab, string $content): void
    {
        if (strlen($content) > self::MAX_CRONTAB_BYTES) {
            throw new \RuntimeException('写入后的 crontab 超过 1 MiB。');
        }
        $result = $this->run([$crontab, '-'], $content, 10);
        if (0 !== $result['code']) {
            throw new \RuntimeException(
                '无法写入当前用户 crontab：' . $this->summary($result['stderr'])
            );
        }
    }

    private function replaceAndVerify(
        string $crontab,
        string $original,
        string $updated,
        string $cronId,
        bool $expected
    ): void {
        $written = false;
        try {
            $latest = $this->readCrontab($crontab);
            if ($this->normalizeCrontab($latest) !== $this->normalizeCrontab($original)) {
                throw new \RuntimeException('crontab 在更新前已被其他进程修改，请重试。');
            }
            $this->writeCrontab($crontab, $updated);
            $written = true;
            $this->writeStateUncertain = true;
            $verified = $this->readCrontab($crontab);
            if (
                $this->normalizeCrontab($verified) !== $this->normalizeCrontab($updated)
                || $expected !== $this->containsValidBlock($verified, $cronId)
            ) {
                throw new \RuntimeException('写入后的 Cron 状态与预期不一致。');
            }
            $this->writeStateUncertain = false;
        } catch (\Throwable $error) {
            if ($written) {
                try {
                    $current = $this->readCrontab($crontab);
                    $rollback = $this->mergeRollback($current, $original, $cronId);
                    if ($this->normalizeCrontab($current) !== $rollback) {
                        $this->writeCrontab($crontab, $rollback);
                    }
                    $restored = $this->readCrontab($crontab);
                    if ($this->normalizeCrontab($restored) !== $rollback) {
                        throw new \RuntimeException('回滚后的 crontab 与预期不一致。');
                    }
                    $this->writeStateUncertain = false;
                } catch (\Throwable $rollbackError) {
                    throw new \RuntimeException(
                        $error->getMessage() . '；Cron 回滚失败：' . $rollbackError->getMessage(),
                        0,
                        $error
                    );
                }
            }
            throw $error;
        }
    }

    private function appendBlock(string $crontab, string $cronId, string $php): string
    {
        $content = rtrim($this->normalizeCrontab($crontab), "\n");
        if ('' !== $content) {
            $content .= "\n\n";
        }
        return $content . $this->managedBlock($cronId, $php);
    }

    private function managedBlock(string $cronId, string $php): string
    {
        return implode("\n", [
            $this->beginMarker($cronId),
            '# Managed automatically by FriendLinks. Do not edit this block.',
            self::SCHEDULE . ' ' . $this->command($php),
            $this->endMarker($cronId),
            '',
        ]);
    }

    private function mergeRollback(string $current, string $original, string $cronId): string
    {
        $content = rtrim($this->normalizeCrontab($this->stripBlock($current, $cronId)), "\n");
        $blocks = $this->extractBlocks($original, $cronId);
        if ('' !== $blocks) {
            if ('' !== $content) {
                $content .= "\n\n";
            }
            $content .= $blocks;
        }
        return $this->normalizeCrontab($content);
    }

    private function extractBlocks(string $crontab, string $cronId): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $crontab);
        $begin = $this->beginMarker($cronId);
        $end = $this->endMarker($cronId);
        $inside = false;
        $block = [];
        $blocks = [];
        foreach ($lines ?: [] as $line) {
            if ($begin === $line) {
                if ($inside) {
                    throw new \RuntimeException('FriendLinks Cron 标记发生嵌套，已拒绝修改。');
                }
                $inside = true;
                $block = [$line];
                continue;
            }
            if ($end === $line) {
                if (!$inside) {
                    throw new \RuntimeException('FriendLinks Cron 结束标记缺少起始标记。');
                }
                $block[] = $line;
                $blocks[] = implode("\n", $block);
                $inside = false;
                $block = [];
                continue;
            }
            if ($inside) {
                $block[] = $line;
            }
        }
        if ($inside) {
            throw new \RuntimeException('FriendLinks Cron 起始标记缺少结束标记。');
        }
        return implode("\n\n", $blocks);
    }

    private function stripBlock(string $crontab, string $cronId): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $crontab);
        $begin = $this->beginMarker($cronId);
        $end = $this->endMarker($cronId);
        $inside = false;
        $output = [];
        foreach ($lines ?: [] as $line) {
            if ($begin === $line) {
                if ($inside) {
                    throw new \RuntimeException('FriendLinks Cron 标记发生嵌套，已拒绝修改。');
                }
                $inside = true;
                continue;
            }
            if ($end === $line) {
                if (!$inside) {
                    throw new \RuntimeException('FriendLinks Cron 结束标记缺少起始标记。');
                }
                $inside = false;
                continue;
            }
            if (!$inside) {
                $output[] = $line;
            }
        }
        if ($inside) {
            throw new \RuntimeException('FriendLinks Cron 起始标记缺少结束标记。');
        }
        return implode("\n", $output);
    }

    private function containsValidBlock(string $crontab, string $cronId): bool
    {
        $beginMarker = $this->beginMarker($cronId);
        $endMarker = $this->endMarker($cronId);
        if (1 !== substr_count($crontab, $beginMarker) || 1 !== substr_count($crontab, $endMarker)) {
            return false;
        }
        $begin = preg_quote($beginMarker, '/');
        $end = preg_quote($endMarker, '/');
        return 1 === preg_match(
            '/^' . $begin . '$.*^' . $end . '$/ms',
            $this->normalizeCrontab($crontab)
        );
    }

    private function containsExpectedBlock(string $crontab, string $cronId, string $php): bool
    {
        return $this->containsValidBlock($crontab, $cronId)
            && false !== strpos(
                $this->normalizeCrontab($crontab),
                $this->managedBlock($cronId, $php)
            );
    }

    private function command(string $php): string
    {
        $console = $this->consolePath();
        foreach ([$php, $console] as $path) {
            if (preg_match('/[\x00-\x1F\x7F]/', $path)) {
                throw new \RuntimeException('Cron 命令路径包含不支持的控制字符。');
            }
        }
        return $this->cronEscapePath($php)
            . ' ' . $this->cronEscapePath($console)
            . ' check --due --limit=50 --max-seconds=240 >/dev/null 2>&1';
    }

    private function cronEscapePath(string $path): string
    {
        return str_replace('%', '\\%', escapeshellarg($path));
    }

    private function consolePath(): string
    {
        $path = $this->pluginRoot . '/bin/console.php';
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('FriendLinks CLI 入口不存在或不可读。');
        }
        return $path;
    }

    private function beginMarker(string $cronId): string
    {
        return '# BEGIN FriendLinks ' . $cronId;
    }

    private function endMarker(string $cronId): string
    {
        return '# END FriendLinks ' . $cronId;
    }

    private function normalizeCrontab(string $crontab): string
    {
        $crontab = str_replace(["\r\n", "\r"], "\n", $crontab);
        return '' === trim($crontab) ? '' : rtrim($crontab, "\n") . "\n";
    }

    private function assertOwner(bool $create): bool
    {
        $current = $this->currentOwner();
        $db = $this->database->native();
        $row = $this->database->fetchRowWrite($db->select('value')->from('table.options')
            ->where('name = ?', self::CRON_OWNER_OPTION)
            ->where('user = ?', 0)
            ->limit(1));
        if ($row && preg_match('/^\d+$/D', (string) $row['value'])) {
            if (!hash_equals((string) $row['value'], $current)) {
                throw new \RuntimeException('当前 PHP 系统用户不是 FriendLinks Cron 的安装用户。');
            }
            return false;
        }
        if (!$create) {
            throw new \RuntimeException('FriendLinks Cron 安装用户记录缺失或无效。');
        }

        $updated = $db->query($db->update('table.options')->rows(['value' => $current])
            ->where('name = ?', self::CRON_OWNER_OPTION)
            ->where('user = ?', 0));
        $created = 0 !== $updated;
        if (0 === $updated) {
            try {
                $db->query($db->insert('table.options')->rows([
                    'name' => self::CRON_OWNER_OPTION,
                    'user' => 0,
                    'value' => $current,
                ]));
                $created = true;
            } catch (\Throwable $error) {
            }
        }

        $row = $this->database->fetchRowWrite($db->select('value')->from('table.options')
            ->where('name = ?', self::CRON_OWNER_OPTION)
            ->where('user = ?', 0)
            ->limit(1));
        if (!$row || !hash_equals((string) $row['value'], $current)) {
            throw new \RuntimeException('无法持久化 FriendLinks Cron 安装用户。');
        }
        return $created;
    }

    private function releaseOwner(): void
    {
        $current = $this->currentOwner();
        $db = $this->database->native();
        $db->query($db->delete('table.options')
            ->where('name = ?', self::CRON_OWNER_OPTION)
            ->where('user = ?', 0)
            ->where('value = ?', $current));
        $row = $this->database->fetchRowWrite($db->select('value')->from('table.options')
            ->where('name = ?', self::CRON_OWNER_OPTION)
            ->where('user = ?', 0)
            ->limit(1));
        if ($row && hash_equals((string) $row['value'], $current)) {
            throw new \RuntimeException('无法删除本次新建的 Cron 安装用户记录。');
        }
    }

    private function currentOwner(): string
    {
        if (function_exists('posix_geteuid')) {
            return (string) posix_geteuid();
        }
        $owner = @fileowner('/proc/self');
        if (false === $owner) {
            throw new \RuntimeException('无法识别当前 PHP 系统用户。');
        }
        return (string) $owner;
    }

    private function storedPhpCli(): string
    {
        $db = $this->database->native();
        $row = $this->database->fetchRowWrite($db->select('value')->from('table.options')
            ->where('name = ?', self::CRON_PHP_OPTION)
            ->where('user = ?', 0)
            ->limit(1));
        $path = $row ? (string) $row['value'] : '';
        if (!$this->validExecutable($path)) {
            throw new \RuntimeException('FriendLinks Cron 的 PHP CLI 记录缺失或不可执行。');
        }
        return $path;
    }

    private function persistPhpCli(string $php): void
    {
        $db = $this->database->native();
        $updated = $db->query($db->update('table.options')->rows(['value' => $php])
            ->where('name = ?', self::CRON_PHP_OPTION)
            ->where('user = ?', 0));
        if (0 === $updated) {
            try {
                $db->query($db->insert('table.options')->rows([
                    'name' => self::CRON_PHP_OPTION,
                    'user' => 0,
                    'value' => $php,
                ]));
            } catch (\Throwable $error) {
            }
        }
        $stored = $this->database->fetchRowWrite($db->select('value')->from('table.options')
            ->where('name = ?', self::CRON_PHP_OPTION)
            ->where('user = ?', 0)
            ->limit(1));
        if (!$stored || !hash_equals((string) $stored['value'], $php)) {
            throw new \RuntimeException('无法持久化 FriendLinks Cron 的 PHP CLI 路径。');
        }
    }

    private function storedCronId(bool $strict = false): ?string
    {
        $db = $this->database->native();
        $row = $this->database->fetchRowWrite($db->select('value')->from('table.options')
            ->where('name = ?', self::CRON_ID_OPTION)
            ->where('user = ?', 0)
            ->limit(1));
        if (!$row) {
            return null;
        }
        if (!preg_match('/^[a-f0-9]{32}$/D', (string) $row['value'])) {
            if ($strict) {
                throw new \RuntimeException('FriendLinks Cron 实例标识无效，已拒绝修改 crontab。');
            }
            return null;
        }
        return $this->cronIdFromSeed((string) $row['value']);
    }

    private function loadOrCreateCronId(): string
    {
        $stored = $this->storedCronId();
        if (null !== $stored) {
            return $stored;
        }

        $cronId = bin2hex(random_bytes(16));
        $db = $this->database->native();
        $updated = $db->query($db->update('table.options')->rows(['value' => $cronId])
            ->where('name = ?', self::CRON_ID_OPTION)
            ->where('user = ?', 0));
        if (1 === $updated) {
            return $this->cronIdFromSeed($cronId);
        }
        $winner = $this->storedCronId();
        if (null !== $winner) {
            return $winner;
        }
        try {
            $db->query($db->insert('table.options')->rows([
                'name' => self::CRON_ID_OPTION,
                'user' => 0,
                'value' => $cronId,
            ]));
            return $this->cronIdFromSeed($cronId);
        } catch (\Throwable $error) {
            $winner = $this->storedCronId();
            if (null !== $winner) {
                return $winner;
            }
            throw $error;
        }
    }

    private function cronIdFromSeed(string $seed): string
    {
        $root = rtrim(str_replace('\\', '/', $this->pluginRoot), '/');
        return substr(hash('sha256', $seed . "\0" . $root), 0, 32);
    }

    private function withCrontabLock(string $crontab, callable $callback)
    {
        $identity = $this->currentOwner();
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'friendlinks-crontab-' . substr(hash('sha256', $crontab . '|' . $identity), 0, 20) . '.lock';
        $handle = @fopen($path, 'c');
        if (false === $handle) {
            throw new \RuntimeException('无法创建 FriendLinks Cron 并发锁。');
        }

        $deadline = microtime(true) + 10;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);
                throw new \RuntimeException('等待 FriendLinks Cron 并发锁超时。');
            }
            usleep(10000);
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function run(array $command, string $input, int $timeout): array
    {
        if (null !== $this->runner) {
            $result = call_user_func($this->runner, $command, $input, $timeout);
            if (
                !is_array($result)
                || !isset($result['code'], $result['stdout'], $result['stderr'])
            ) {
                throw new \RuntimeException('Cron 命令执行器返回了无效结果。');
            }
            return [
                'code' => (int) $result['code'],
                'stdout' => (string) $result['stdout'],
                'stderr' => (string) $result['stderr'],
            ];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $environment['LC_ALL'] = 'C';
        $environment['LANG'] = 'C';
        $process = @proc_open(
            $command,
            $descriptors,
            $pipes,
            null,
            $environment,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('无法启动 Cron 管理命令。');
        }

        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $inputLength = strlen($input);
        $inputOffset = 0;
        $stdinOpen = true;
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + max(1, $timeout);
        $status = null;
        try {
            while (true) {
                if ($stdinOpen) {
                    if ($inputOffset < $inputLength) {
                        $written = @fwrite($pipes[0], substr($input, $inputOffset, 8192));
                        if (false === $written) {
                            throw new \RuntimeException('无法向 Cron 管理命令写入数据。');
                        }
                        $inputOffset += $written;
                    }
                    if ($inputOffset >= $inputLength) {
                        fclose($pipes[0]);
                        $stdinOpen = false;
                    }
                }
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (empty($status['running'])) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Cron 管理命令执行超时。');
                }
                usleep(10000);
            }
        } catch (\Throwable $error) {
            if ($stdinOpen) {
                fclose($pipes[0]);
            }
            proc_terminate($process);
            $stopDeadline = microtime(true) + 0.25;
            do {
                $status = proc_get_status($process);
                if (empty($status['running'])) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $stopDeadline);
            if (!empty($status['running'])) {
                proc_terminate($process, 9);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw $error;
        }
        if ($stdinOpen) {
            fclose($pipes[0]);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
        $code = isset($status['exitcode']) && $status['exitcode'] >= 0
            ? (int) $status['exitcode']
            : (int) $closed;

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function summary(string $message): string
    {
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', trim($message));
        return '' === $message ? '未提供错误详情' : Text::truncateUtf8($message, 300);
    }
}
