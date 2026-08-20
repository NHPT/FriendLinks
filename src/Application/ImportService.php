<?php

namespace TypechoPlugin\FriendLinks\Application;

use TypechoPlugin\FriendLinks\Domain\UrlNormalizer;
use TypechoPlugin\FriendLinks\Infrastructure\Repositories;

final class ImportService
{
    /** @var Repositories */
    private $repositories;

    /** @var LinkService */
    private $links;

    /** @var UrlNormalizer */
    private $normalizer;

    public function __construct(
        ?Repositories $repositories = null,
        ?LinkService $links = null,
        ?UrlNormalizer $normalizer = null
    ) {
        $this->repositories = $repositories ?: new Repositories();
        $this->links = $links ?: new LinkService($this->repositories);
        $this->normalizer = $normalizer ?: new UrlNormalizer();
    }

    public function preview(string $format, string $payload): array
    {
        if (strlen($payload) > 1024 * 1024) {
            throw new \InvalidArgumentException('导入内容不能超过 1 MiB。');
        }

        if ('json' === $format) {
            $decoded = json_decode($payload, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('JSON 必须是友链对象数组。');
            }
            $rows = $decoded;
        } elseif ('csv' === $format) {
            $rows = $this->parseCsv($payload);
        } else {
            throw new \InvalidArgumentException('不支持的导入格式。');
        }
        if (count($rows) > 500) {
            throw new \InvalidArgumentException('单次最多导入 500 条友链，请拆分文件后重试。');
        }

        $result = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $item = $this->normalizeRow((array) $row);
            $item['line'] = $index + 1;
            $item['errors'] = [];
            try {
                $normalized = $this->normalizer->normalize((string) $item['url']);
                $hash = $this->normalizer->hash($normalized);
                if (isset($seen[$hash]) || $this->repositories->findByHash($hash)) {
                    $item['errors'][] = 'URL 重复';
                }
                $seen[$hash] = true;
            } catch (\Throwable $error) {
                $item['errors'][] = $error->getMessage();
            }
            if ('' === trim((string) $item['name'])) {
                $item['errors'][] = '名称为空';
            }
            $result[] = $item;
        }
        return $result;
    }

    public function import(array $rows): array
    {
        if (count($rows) > 500) {
            throw new \InvalidArgumentException('单次最多导入 500 条友链，请拆分后重试。');
        }
        $created = 0;
        $skipped = 0;
        $categoryMap = [];
        foreach ($this->repositories->categories() as $category) {
            $categoryMap[$category['name']] = (int) $category['id'];
        }

        foreach ($rows as $row) {
            $row = $this->normalizeRow((array) $row);
            try {
                $category = trim((string) $row['category']);
                $validationRow = $row;
                $validationRow['category_id'] = 0;
                $this->links->validate($validationRow);
                if ('' !== $category && !isset($categoryMap[$category])) {
                    $categoryMap[$category] = $this->repositories->transaction(function () use (
                        $category,
                        $categoryMap,
                        $row
                    ) {
                        $slug = trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($category)), '-');
                        if ('' === $slug) {
                            $slug = 'category-' . substr(hash('sha256', $category), 0, 10);
                        }
                        $categoryId = $this->links->saveCategory([
                            'name' => $category,
                            'slug' => $slug,
                            'enabled' => 1,
                            'sort_order' => count($categoryMap),
                        ]);
                        $row['category_id'] = $categoryId;
                        $this->links->save($row);
                        return $categoryId;
                    });
                    $created++;
                    continue;
                }
                $row['category_id'] = '' === $category ? 0 : $categoryMap[$category];
                $this->links->save($row);
                $created++;
            } catch (\Throwable $error) {
                $skipped++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    public function export(string $format): string
    {
        $rows = [];
        foreach ($this->repositories->exportLinks() as $link) {
            $rows[] = [
                'name' => $link['name'],
                'url' => $link['url'],
                'description' => $link['description'],
                'logo_url' => $link['logo_url'],
                'category' => $link['category_name'],
                'sort_order' => (int) $link['sort_order'],
                'visibility' => $link['visibility'],
                'check_enabled' => (int) $link['check_enabled'],
            ];
        }

        if ('json' === $format) {
            return (string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        if ('csv' !== $format) {
            throw new \InvalidArgumentException('不支持的导出格式。');
        }

        $stream = fopen('php://temp', 'w+');
        $csvRows = array_map(static function (array $row): array {
            $row['_flm_csv_encoding'] = 'formula-safe-v1';
            return $row;
        }, $rows);
        fputcsv($stream, array_keys($csvRows[0] ?? [
            'name' => '', 'url' => '', 'description' => '', 'logo_url' => '',
            'category' => '', 'sort_order' => '', 'visibility' => '', 'check_enabled' => '',
            '_flm_csv_encoding' => '',
        ]), ',', '"', '');
        foreach ($csvRows as $row) {
            fputcsv($stream, array_map([$this, 'safeCsvCell'], $row), ',', '"', '');
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);
        return $csv;
    }

    private function parseCsv(string $payload): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $payload);
        rewind($stream);
        $header = fgetcsv($stream, 0, ',', '"', '');
        if (!$header) {
            return [];
        }
        $header = array_map(static function ($value) {
            return strtolower(trim((string) $value));
        }, $header);
        $allowed = [
            'name',
            'url',
            'description',
            'logo_url',
            'category',
            'sort_order',
            'visibility',
            'check_enabled',
            '_flm_csv_encoding',
        ];
        if (!in_array('name', $header, true) || !in_array('url', $header, true)) {
            throw new \InvalidArgumentException('CSV 表头必须包含 name 和 url。');
        }
        foreach ($header as $column) {
            if (!in_array($column, $allowed, true)) {
                throw new \InvalidArgumentException('CSV 包含未知列：' . $column);
            }
        }

        $rows = [];
        while (false !== ($values = fgetcsv($stream, 0, ',', '"', ''))) {
            if ([null] === $values) {
                continue;
            }
            $values = array_pad($values, count($header), '');
            $row = array_combine($header, array_slice($values, 0, count($header)));
            if ('formula-safe-v1' === ($row['_flm_csv_encoding'] ?? null)) {
                $row = array_map([$this, 'restoreCsvCell'], $row);
            }
            unset($row['_flm_csv_encoding']);
            $rows[] = $row;
        }
        fclose($stream);
        return $rows;
    }

    private function normalizeRow(array $row): array
    {
        return [
            'name' => trim((string) ($row['name'] ?? '')),
            'url' => trim((string) ($row['url'] ?? '')),
            'description' => trim((string) ($row['description'] ?? '')),
            'logo_url' => trim((string) ($row['logo_url'] ?? ($row['logo'] ?? ''))),
            'category' => trim((string) ($row['category'] ?? ($row['sort'] ?? ''))),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'visibility' => in_array(($row['visibility'] ?? ''), ['published', 'draft', 'archived'], true)
                ? $row['visibility']
                : 'published',
            'check_enabled' => array_key_exists('check_enabled', $row) ? (int) (bool) $row['check_enabled'] : 1,
        ];
    }

    private function safeCsvCell($value)
    {
        if (is_string($value) && $this->isFormulaLike($value)) {
            return "'" . $value;
        }
        return $value;
    }

    private function restoreCsvCell($value)
    {
        $value = (string) $value;
        return "'" === substr($value, 0, 1) && $this->isFormulaLike(substr($value, 1))
            ? substr($value, 1)
            : $value;
    }

    private function isFormulaLike(string $value): bool
    {
        $length = strlen($value);
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $value[$offset];
            if ("'" === $character || ord($character) <= 0x20) {
                continue;
            }
            return in_array($character, ['=', '+', '-', '@'], true);
        }
        return false;
    }
}
