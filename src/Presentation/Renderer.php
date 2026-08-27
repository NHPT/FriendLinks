<?php

namespace TypechoPlugin\FriendLinks\Presentation;

use TypechoPlugin\FriendLinks\Application\Settings;
use TypechoPlugin\FriendLinks\Domain\Text;

final class Renderer
{
    public function render(array $links, ?string $templateId = null): string
    {
        $settings = Settings::all();
        $template = (new TemplateCatalog())->get($templateId ?? (string) ($settings['frontend_template'] ?? 'cards'));
        $groups = [];
        foreach ($links as $link) {
            $name = $link['category_name'] ?: '未分类';
            $slug = $link['category_slug'] ?: 'uncategorized';
            $groupKey = $link['category_slug'] ? 'category-' . $slug : 'uncategorized';
            $groups[$groupKey]['name'] = $name;
            $groups[$groupKey]['links'][] = $link;
        }

        $html = '<section class="flm-root flm-layout-' . $this->escape($template['layout'])
            . ' flm-template-' . $this->escape($template['id'])
            . '" data-flm-template="' . $this->escape($template['id']) . '" aria-label="友情链接">';
        if (!$links) {
            return $html . '<p class="flm-empty">暂无公开友链。</p></section>';
        }

        if (count($groups) > 1) {
            $html .= '<div class="flm-filters" role="group" aria-label="按分类筛选">';
            $html .= '<button class="flm-filter is-active" type="button" data-flm-filter="all" aria-pressed="true">全部</button>';
            foreach ($groups as $slug => $group) {
                $html .= '<button class="flm-filter" type="button" data-flm-filter="' . $this->escape($slug)
                    . '" aria-pressed="false">' . $this->escape($group['name']) . '</button>';
            }
            $html .= '</div>';
        }

        foreach ($groups as $slug => $group) {
            $html .= '<section class="flm-group" data-flm-group="' . $this->escape($slug) . '">';
            $html .= '<h2 class="flm-group-title">' . $this->escape($group['name']) . '</h2>';
            $html .= '<ul class="flm-list">';
            foreach ($group['links'] as $link) {
                $html .= $this->renderLink($link, $settings);
            }
            $html .= '</ul></section>';
        }

        return $html . '</section>';
    }

    private function renderLink(array $link, array $settings): string
    {
        $state = (string) ($link['overall_state'] ?: 'pending');
        $reason = (string) ($link['reason_code'] ?? '');
        $checkedAt = (int) ($link['checked_at'] ?? 0);
        $freshnessInterval = max(
            (int) $settings['http_interval'],
            Settings::cronIntervalSeconds($settings)
        );
        if (
            !in_array($state, ['pending', 'disabled'], true)
            && $checkedAt > 0
            && $checkedAt < time() - (2 * $freshnessInterval)
        ) {
            $state = 'unknown';
            $reason = 'data_stale';
        }
        if (!isset(StatusLabels::states()[$state])) {
            $state = 'unknown';
        }

        $rel = ['noopener'];
        if (!empty($settings['rel_noreferrer'])) {
            $rel[] = 'noreferrer';
        }
        if (!empty($settings['rel_nofollow'])) {
            $rel[] = 'nofollow';
        }

        $name = (string) $link['name'];
        $html = '<li class="flm-item flm-item-state-' . $this->escape($state) . '">';
        $html .= '<a class="flm-link" href="' . $this->escape((string) $link['url'])
            . '" target="_blank" rel="' . implode(' ', $rel) . '">';
        if (!empty($link['logo_url'])) {
            $html .= '<span class="flm-logo"><img src="' . $this->escape((string) $link['logo_url'])
                . '" alt="' . $this->escape($name . ' Logo')
                . '" loading="lazy" referrerpolicy="no-referrer"></span>';
        } else {
            $initial = Text::firstCharacter($name);
            $html .= '<span class="flm-logo flm-logo-placeholder" aria-hidden="true">'
                . $this->escape(strtoupper($initial)) . '</span>';
        }
        $html .= '<span class="flm-copy"><strong class="flm-name">' . $this->escape($name) . '</strong>';
        if ('' !== trim((string) $link['description'])) {
            $html .= '<span class="flm-description">' . $this->escape((string) $link['description']) . '</span>';
        }
        $html .= '</span></a>';

        $publicReason = $reason;
        if (empty($settings['show_expiration_warning']) && in_array($reason, ['tls_expiring', 'domain_expiring'], true)) {
            $publicReason = null;
        }
        $statusText = StatusLabels::summary($state, $publicReason);
        $html .= '<div class="flm-meta"><span class="flm-status flm-status-' . $this->escape($state)
            . '" aria-label="' . $this->escape($statusText) . '">';
        $html .= '<span class="flm-status-summary">';
        $html .= '<span class="flm-status-dot" aria-hidden="true"></span>';
        $html .= '<span class="flm-status-short" aria-hidden="true">'
            . $this->escape(StatusLabels::shortState($state)) . '</span></span>';
        $html .= '<span class="flm-status-detail" aria-hidden="true">'
            . $this->escape($statusText) . '</span></span>';
        $html .= '<time class="flm-checked" datetime="' . ($checkedAt > 0 ? date('c', $checkedAt) : '') . '">';
        $html .= $checkedAt > 0 ? '检测于 ' . $this->escape(date('Y-m-d H:i', $checkedAt)) : '尚未检测';
        $html .= '</time></div></li>';
        return $html;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
