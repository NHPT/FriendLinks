<?php

namespace TypechoPlugin\FriendLinks\Presentation;

use TypechoPlugin\FriendLinks\Application\Settings;
use TypechoPlugin\FriendLinks\Infrastructure\Repositories;
use Widget\Archive;
use Widget\Options;

final class ContentInjector
{
    /** @var string|null */
    private static $lastRenderedHtml;

    public static function injectLinks($content, $widget, $lastResult = null)
    {
        $baseContent = null === $lastResult ? $content : $lastResult;
        if (!self::isTargetPage($widget)) {
            return $baseContent;
        }

        try {
            $html = self::renderLinks();
            return (string) $baseContent . $html;
        } catch (\Throwable $error) {
            self::logRenderFailure($error);
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
            $assetBase . 'frontend.css?v=' . AssetVersion::forFile($cssPath),
            ENT_QUOTES,
            'UTF-8'
        ) . '">' . "\n";
        $catalog = new TemplateCatalog();
        $template = $catalog->get((string) Settings::get('frontend_template', 'cards'));
        $stylesheetPath = $catalog->stylesheetPath($template);
        if (null !== $stylesheetPath) {
            $templateUrl = $pluginBase . 'templates/'
                . rawurlencode($template['id']) . '/style.css?v=' . AssetVersion::forFile($stylesheetPath);
            echo '<link rel="stylesheet" href="' . htmlspecialchars($templateUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }
        echo '<script defer src="' . htmlspecialchars(
            $assetBase . 'frontend.js?v=' . AssetVersion::forFile($jsPath),
            ENT_QUOTES,
            'UTF-8'
        ) . '"></script>' . "\n";
        return null === $lastResult ? $header : $lastResult;
    }

    public static function footer($widget, $lastResult = null)
    {
        if (!self::isTargetPage($widget)) {
            return $lastResult;
        }

        try {
            $html = self::$lastRenderedHtml ?? self::renderLinks();
        } catch (\Throwable $error) {
            self::logRenderFailure($error);
            return $lastResult;
        }

        echo '<template id="flm-footer-fallback-template">' . $html . '</template>' . "\n";
        echo '<script>(function(){'
            . 'var t=document.getElementById("flm-footer-fallback-template");'
            . 'if(!t)return;'
            . 'if(document.querySelector(".flm-root")){t.remove();return;}'
            . 'var target=document.querySelector(".post-content")'
            . '||document.querySelector(".entry-content")'
            . '||document.querySelector("article")'
            . '||document.querySelector("main");'
            . 'var fragment=t.content?t.content.cloneNode(true):null;'
            . 'if(fragment&&target){target.appendChild(fragment);}'
            . 'else if(fragment&&t.parentNode){t.parentNode.insertBefore(fragment,t);}'
            . 't.remove();'
            . '})();</script>' . "\n";

        return $lastResult;
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

    private static function renderLinks(): string
    {
        self::$lastRenderedHtml = (new Renderer())->render((new Repositories())->frontendLinks());
        return self::$lastRenderedHtml;
    }

    private static function logRenderFailure(\Throwable $error): void
    {
        error_log('[FriendLinks] frontend render failed: ' . $error->getMessage());
    }
}
