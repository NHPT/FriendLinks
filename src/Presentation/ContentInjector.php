<?php

namespace TypechoPlugin\FriendLinks\Presentation;

use TypechoPlugin\FriendLinks\Application\Settings;
use TypechoPlugin\FriendLinks\Infrastructure\Repositories;
use Widget\Archive;
use Widget\Options;

final class ContentInjector
{
    public static function injectLinks($content, $widget, $lastResult = null)
    {
        $baseContent = null === $lastResult ? $content : $lastResult;
        if (!self::isTargetPage($widget)) {
            return $baseContent;
        }

        try {
            $html = (new Renderer())->render((new Repositories())->frontendLinks());
            return (string) $baseContent . $html;
        } catch (\Throwable $error) {
            return $baseContent;
        }
    }

    public static function header($header, $widget, $lastResult = null)
    {
        if (!self::isTargetPage($widget)) {
            return null === $lastResult ? $header : $lastResult;
        }

        $pluginBase = rtrim((string) Options::alloc()->pluginUrl, '/') . '/FriendLinks/';
        $assetBase = $pluginBase . 'assets/';
        $cssPath = dirname(__DIR__, 2) . '/assets/frontend.css';
        $jsPath = dirname(__DIR__, 2) . '/assets/frontend.js';
        echo '<link rel="stylesheet" href="' . htmlspecialchars(
            $assetBase . 'frontend.css?v=' . filemtime($cssPath),
            ENT_QUOTES,
            'UTF-8'
        ) . '">' . "\n";
        $catalog = new TemplateCatalog();
        $template = $catalog->get((string) Settings::get('frontend_template', 'cards'));
        $stylesheetPath = $catalog->stylesheetPath($template);
        if (null !== $stylesheetPath) {
            $templateUrl = $pluginBase . 'templates/'
                . rawurlencode($template['id']) . '/style.css?v=' . filemtime($stylesheetPath);
            echo '<link rel="stylesheet" href="' . htmlspecialchars($templateUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }
        echo '<script defer src="' . htmlspecialchars(
            $assetBase . 'frontend.js?v=' . filemtime($jsPath),
            ENT_QUOTES,
            'UTF-8'
        ) . '"></script>' . "\n";
        return null === $lastResult ? $header : $lastResult;
    }

    private static function isTargetPage($widget): bool
    {
        if (!$widget instanceof Archive || !$widget->is('page')) {
            return false;
        }

        $cid = (int) Settings::get('page_cid', 0);
        if ($cid < 1 || (int) $widget->cid !== $cid) {
            return false;
        }

        try {
            Settings::assertPage($cid);
            return true;
        } catch (\Throwable $error) {
            return false;
        }
    }
}
