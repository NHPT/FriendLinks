<?php

namespace TypechoPlugin\FriendLinks\Infrastructure;

use Typecho\Db;
use Typecho\Db\Query;

final class Database
{
    /** @var Db */
    private $db;

    /** @var string */
    private $driver;

    /** @var int */
    private $transactionDepth = 0;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?: Db::get();
        $this->driver = $this->normalizeDriver($this->db->getAdapterName());
    }

    public function native(): Db
    {
        return $this->db;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function prefix(): string
    {
        $prefix = $this->db->getPrefix();
        if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
            throw new \RuntimeException('Typecho database prefix contains unsupported characters.');
        }

        return $prefix;
    }

    public function table(string $name): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException('Invalid table name.');
        }

        return 'table.' . $name;
    }

    public function rawWrite(string $sql)
    {
        return $this->db->query($sql, Db::WRITE, '');
    }

    public function fetchRowWrite($query): ?array
    {
        $sql = $query instanceof Query ? $query->prepare($query) : (string) $query;
        $resource = $this->db->query($sql, Db::WRITE, Db::SELECT);
        return $this->db->getAdapter()->fetch($resource);
    }

    public function fetchAllWrite($query): array
    {
        $sql = $query instanceof Query ? $query->prepare($query) : (string) $query;
        $resource = $this->db->query($sql, Db::WRITE, Db::SELECT);
        return $this->db->getAdapter()->fetchAll($resource);
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    public function begin(): void
    {
        $this->rawWrite('sqlite' === $this->driver ? 'BEGIN IMMEDIATE' : ('mysql' === $this->driver
            ? 'START TRANSACTION'
            : 'BEGIN'));
    }

    public function commit(): void
    {
        $this->rawWrite('COMMIT');
    }

    public function rollback(): void
    {
        try {
            $this->rawWrite('ROLLBACK');
        } catch (\Throwable $ignored) {
        }
    }

    public function transaction(callable $callback)
    {
        if ($this->transactionDepth > 0) {
            $this->transactionDepth++;
            try {
                return $callback();
            } finally {
                $this->transactionDepth--;
            }
        }

        $this->begin();
        $this->transactionDepth = 1;
        try {
            $result = $callback();
            $this->commit();
            $this->transactionDepth = 0;
            return $result;
        } catch (\Throwable $error) {
            $this->rollback();
            $this->transactionDepth = 0;
            throw $error;
        }
    }

    private function normalizeDriver(string $adapter): string
    {
        $normalized = strtolower(str_replace(['\\', '-'], '_', $adapter));
        if (false !== strpos($normalized, 'sqlite')) {
            return 'sqlite';
        }
        if (false !== strpos($normalized, 'pgsql') || false !== strpos($normalized, 'postgres')) {
            return 'pgsql';
        }
        if (false !== strpos($normalized, 'mysql') || false !== strpos($normalized, 'mysqli')) {
            return 'mysql';
        }

        throw new \RuntimeException('FriendLinks does not support database adapter: ' . $adapter);
    }
}
