<?php

namespace TypechoPlugin\FriendLinks\Presentation;

final class TemplateCatalog
{
    private const DEFAULT_TEMPLATE = 'cards';
    private const ALLOWED_LAYOUTS = ['cards', 'compact', 'logo-grid', 'directory', 'minimal'];

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
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($manifest)) {
                continue;
            }

            $layout = (string) ($manifest['layout'] ?? '');
            $title = trim((string) ($manifest['title'] ?? ''));
            if (!in_array($layout, self::ALLOWED_LAYOUTS, true) || '' === $title) {
                continue;
            }

            $templates[$id] = [
                'id' => $id,
                'title' => $title,
                'description' => trim((string) ($manifest['description'] ?? '')),
                'layout' => $layout,
                'stylesheet' => is_file($directory . '/style.css') ? 'style.css' : null,
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
            'title' => '卡片网格',
            'description' => '',
            'layout' => 'cards',
            'stylesheet' => null,
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
}
