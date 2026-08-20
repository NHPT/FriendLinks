<?php

namespace TypechoPlugin\FriendLinks\Application;

use TypechoPlugin\FriendLinks\Domain\UrlNormalizer;
use TypechoPlugin\FriendLinks\Infrastructure\Repositories;

final class LinkService
{
    /** @var Repositories */
    private $repositories;

    /** @var UrlNormalizer */
    private $normalizer;

    public function __construct(?Repositories $repositories = null, ?UrlNormalizer $normalizer = null)
    {
        $this->repositories = $repositories ?: new Repositories();
        $this->normalizer = $normalizer ?: new UrlNormalizer();
    }

    public function save(array $input, int $id = 0): int
    {
        [$row, $existing] = $this->prepareLink($input, $id);
        $now = time();
        $row['updated_at'] = $now;

        if ($id > 0) {
            $resetStatus = !empty($row['check_enabled'])
                && 'published' === $row['visibility']
                && (
                    (string) $existing['normalized_url'] !== $row['normalized_url']
                    || empty($existing['check_enabled'])
                    || 'published' !== (string) $existing['visibility']
                );
            $this->repositories->updateLink($id, $row, $resetStatus);
            return $id;
        }

        $row['created_at'] = $now;
        return $this->repositories->createLink($row);
    }

    public function validate(array $input, int $id = 0): void
    {
        $this->prepareLink($input, $id);
    }

    private function prepareLink(array $input, int $id): array
    {
        $existing = $id > 0 ? $this->repositories->link($id) : null;
        if ($id > 0 && !$existing) {
            throw new \InvalidArgumentException('友链不存在。');
        }

        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        if ('' === $name || $this->length($name) > 150) {
            throw new \InvalidArgumentException('名称不能为空且最多 150 个字符。');
        }
        if ($this->length($description) > 500) {
            throw new \InvalidArgumentException('描述最多 500 个字符。');
        }

        $url = trim((string) ($input['url'] ?? ''));
        $normalized = $this->normalizer->normalize($url);
        $hash = $this->normalizer->hash($normalized);
        if ($this->repositories->findByHash($hash, $id)) {
            throw new \InvalidArgumentException('规范化后的 URL 已存在。');
        }

        $logo = trim((string) ($input['logo_url'] ?? ''));
        if ('' !== $logo) {
            $logo = $this->normalizer->normalize($logo);
        } else {
            $logo = null;
        }

        $categoryId = max(0, (int) ($input['category_id'] ?? 0));
        if ($categoryId > 0 && !$this->repositories->category($categoryId)) {
            throw new \InvalidArgumentException('所选分类不存在。');
        }

        $visibility = (string) ($input['visibility'] ?? 'published');
        if (!in_array($visibility, ['published', 'draft', 'archived'], true)) {
            throw new \InvalidArgumentException('可见性取值无效。');
        }
        $checkEnabled = empty($input['check_enabled']) ? 0 : 1;
        if ($checkEnabled && !extension_loaded('curl')) {
            throw new \InvalidArgumentException('缺少 PHP cURL 扩展，不能启用自动检测。');
        }
        if ($checkEnabled && !$this->normalizer->canDetect($normalized)) {
            throw new \InvalidArgumentException('缺少 ext-intl，国际化域名不能启用自动检测。');
        }

        $row = [
            'category_id' => $categoryId > 0 ? $categoryId : null,
            'name' => $name,
            'url' => $url,
            'normalized_url' => $normalized,
            'url_hash' => $hash,
            'description' => $description,
            'logo_url' => $logo,
            'sort_order' => (int) ($input['sort_order'] ?? 0),
            'visibility' => $visibility,
            'check_enabled' => $checkEnabled,
        ];

        return [$row, $existing];
    }

    public function saveCategory(array $input, int $id = 0): int
    {
        if ($id > 0 && !$this->repositories->category($id)) {
            throw new \InvalidArgumentException('分类不存在。');
        }
        $name = trim((string) ($input['name'] ?? ''));
        if ('' === $name || $this->length($name) > 120) {
            throw new \InvalidArgumentException('分类名称不能为空且最多 120 个字符。');
        }
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        if ('' === $slug) {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-');
            if ('' === $slug) {
                $slug = 'category-' . substr(hash('sha256', $name), 0, 10);
            }
        }
        if ('' === $slug || !preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $slug)) {
            throw new \InvalidArgumentException('分类标识仅允许字母、数字、下划线和连字符。');
        }
        foreach ($this->repositories->categories() as $category) {
            if ($slug === $category['slug'] && (int) $category['id'] !== $id) {
                throw new \InvalidArgumentException('分类标识已存在。');
            }
        }

        $now = time();
        $row = [
            'name' => $name,
            'slug' => $slug,
            'sort_order' => (int) ($input['sort_order'] ?? 0),
            'enabled' => empty($input['enabled']) ? 0 : 1,
            'updated_at' => $now,
        ];
        if (0 === $id) {
            $row['created_at'] = $now;
        }
        return $this->repositories->saveCategory($row, $id);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
