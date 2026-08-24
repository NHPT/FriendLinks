<?php

namespace TypechoPlugin\FriendLinks\Infrastructure;

final class MigrationManager
{
    public const SCHEMA_VERSION = 2;
    private const VERSION_OPTION = 'friendlinks_schema_version';

    /** @var Database */
    private $database;

    public function __construct(?Database $database = null)
    {
        $this->database = $database ?: new Database();
    }

    public function migrate(): void
    {
        $directory = dirname(__DIR__, 2) . '/migrations/' . $this->database->driver();
        $paths = glob($directory . '/[0-9][0-9][0-9]_*.sql') ?: [];
        sort($paths, SORT_STRING);
        if (!$paths) {
            throw new \RuntimeException('Missing FriendLinks migration for ' . $this->database->driver());
        }

        $current = $this->version();
        foreach ($paths as $path) {
            $version = (int) substr(basename($path), 0, 3);
            if ($version <= $current || $version > self::SCHEMA_VERSION) {
                continue;
            }

            $contents = @file_get_contents($path);
            if (false === $contents) {
                throw new \RuntimeException('FriendLinks 数据库迁移文件无法读取。');
            }
            $sql = str_replace('{{prefix}}', $this->database->prefix(), $contents);
            foreach ($this->statements($sql) as $statement) {
                $this->database->rawWrite($statement);
            }
            $this->setVersion($version);
            $current = $version;
        }

        $this->assertTables();
        $this->assertTransactionalStorage();
        if ($this->version() !== self::SCHEMA_VERSION) {
            throw new \RuntimeException('FriendLinks database schema is incomplete.');
        }
    }

    public function version(): int
    {
        $db = $this->database->native();
        $row = $this->database->fetchRowWrite($db->select('value')
            ->from('table.options')
            ->where('name = ?', self::VERSION_OPTION)
            ->where('user = ?', 0)
            ->limit(1));

        return $row ? (int) $row['value'] : 0;
    }

    public function uninstall(): void
    {
        $quote = 'mysql' === $this->database->driver() ? '`' : '"';
        foreach ([
            'flm_notification_outbox',
            'flm_cache',
            'flm_runs',
            'flm_check_history',
            'flm_current_status',
            'flm_links',
            'flm_categories',
        ] as $name) {
            $table = $this->database->prefix() . $name;
            $this->database->rawWrite('DROP TABLE IF EXISTS ' . $quote . $table . $quote);
        }

        $db = $this->database->native();
        $db->query($db->delete('table.options')->where(
            'name = ? OR name = ? OR name = ? OR name = ? OR name = ? OR name = ? OR name = ?',
            self::VERSION_OPTION,
            'friendlinks_settings_backup',
            'friendlinks_menu_index',
            'friendlinks_worker_secret',
            'friendlinks_cron_id',
            'friendlinks_cron_owner',
            'friendlinks_cron_php'
        ));
    }

    private function statements(string $sql): array
    {
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
        $parts = preg_split('/;\s*(?:\r?\n|$)/', trim((string) $sql));
        return array_values(array_filter(array_map('trim', $parts), static function ($statement) {
            return '' !== $statement;
        }));
    }

    private function assertTables(): void
    {
        $db = $this->database->native();
        foreach ([
            'flm_categories',
            'flm_links',
            'flm_current_status',
            'flm_check_history',
            'flm_runs',
            'flm_cache',
            'flm_notification_outbox',
        ] as $name) {
            $this->database->fetchRowWrite(
                $db->select('1')->from($this->database->table($name))->limit(1)
            );
        }
    }

    private function assertTransactionalStorage(): void
    {
        if ('mysql' !== $this->database->driver()) {
            return;
        }

        foreach ([
            'flm_categories',
            'flm_links',
            'flm_current_status',
            'flm_check_history',
            'flm_runs',
            'flm_cache',
            'flm_notification_outbox',
        ] as $name) {
            $table = $this->database->prefix() . $name;
            $row = $this->database->fetchRowWrite(
                "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
                . " AND TABLE_NAME = '" . $table . "'"
            );
            if (!$row || 'INNODB' !== strtoupper((string) $row['ENGINE'])) {
                throw new \RuntimeException('FriendLinks requires every plugin table to use InnoDB.');
            }
        }
    }

    private function setVersion(int $version): void
    {
        $db = $this->database->native();
        $updated = $db->query($db->update('table.options')
            ->rows(['value' => (string) $version])
            ->where('name = ?', self::VERSION_OPTION)
            ->where('user = ?', 0));
        if (0 === $updated) {
            try {
                $db->query($db->insert('table.options')->rows([
                    'name' => self::VERSION_OPTION,
                    'user' => 0,
                    'value' => (string) $version,
                ]));
            } catch (\Throwable $error) {
                $db->query($db->update('table.options')
                    ->rows(['value' => (string) $version])
                    ->where('name = ?', self::VERSION_OPTION)
                    ->where('user = ?', 0));
            }
        }
    }
}
