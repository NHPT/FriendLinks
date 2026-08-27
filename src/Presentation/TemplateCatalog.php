<?php

namespace TypechoPlugin\FriendLinks\Presentation;

final class TemplateCatalog
{
    private const DEFAULT_TEMPLATE = 'cards';
    private const SCHEMA_VERSION = 1;

    /** @var string */
    private $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?: dirname(__DIR__, 2) . '/templates';
    }

    public function all(): array
    {
        $templates = [];
        $directories = glob($this->root . '/*', GLOB_ONLYDIR) ?: [];
        sort($directories, SORT_STRING);

        foreach ($directories as $directory) {
            $id = basename($directory);
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $id)) {
                continue;
            }

            $manifestPath = $directory . '/manifest.json';
            if (!is_file($manifestPath)) {
                continue;
            }
            $manifestJson = @file_get_contents($manifestPath);
            if (false === $manifestJson) {
                continue;
            }
            $manifest = json_decode($manifestJson, true);
            if (!is_array($manifest)) {
                continue;
            }

            $schema = (int) ($manifest['schema'] ?? self::SCHEMA_VERSION);
            $layout = (string) ($manifest['layout'] ?? $id);
            $title = trim((string) ($manifest['title'] ?? ''));
            $stylesheetPath = $directory . '/style.css';
            if (
                self::SCHEMA_VERSION !== $schema
                || !$this->isValidIdentifier($layout)
                || '' === $title
                || !is_file($stylesheetPath)
            ) {
                continue;
            }

            $templates[$id] = [
                'id' => $id,
                'schema' => $schema,
                'title' => $title,
                'description' => trim((string) ($manifest['description'] ?? '')),
                'layout' => $layout,
                'stylesheet' => 'style.css',
            ];
        }

        return $templates;
    }

    public function get(string $id): array
    {
        $templates = $this->all();
        if (isset($templates[$id])) {
            return $templates[$id];
        }
        if (isset($templates[self::DEFAULT_TEMPLATE])) {
            return $templates[self::DEFAULT_TEMPLATE];
        }

        return [
            'id' => self::DEFAULT_TEMPLATE,
            'schema' => self::SCHEMA_VERSION,
            'title' => '卡片网格',
            'description' => '',
            'layout' => 'cards',
            'stylesheet' => 'style.css',
        ];
    }

    public function exists(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function stylesheetPath(array $template): ?string
    {
        if (empty($template['stylesheet'])) {
            return null;
        }

        $path = $this->root . '/' . $template['id'] . '/' . $template['stylesheet'];
        return is_file($path) ? $path : null;
    }

    private function isValidIdentifier(string $value): bool
    {
        return 1 === preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $value);
    }
}
