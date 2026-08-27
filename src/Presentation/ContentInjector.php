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

    /** @var bool */
    private static $scriptRendered = false;

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

        if (!self::$scriptRendered) {
            echo self::scriptTag();
            self::$scriptRendered = true;
        }
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
            . 'if(document.querySelector("friend-links-widget[data-flm-host]")){t.remove();'
            . 'if(window.FriendLinksFrontend){window.FriendLinksFrontend.mountAll();}return;}'
            . 'var target=document.querySelector(".post-content")'
            . '||document.querySelector(".entry-content")'
            . '||document.querySelector("article")'
            . '||document.querySelector("main");'
            . 'var fragment=t.content||null;'
            . 'if(fragment&&target){target.appendChild(fragment);}'
            . 'else if(fragment&&t.parentNode){t.parentNode.insertBefore(fragment,t);}'
            . 't.remove();if(window.FriendLinksFrontend){window.FriendLinksFrontend.mountAll();}'
            . '})();</script>' . "\n";
        if (!self::$scriptRendered) {
            echo self::scriptTag();
            self::$scriptRendered = true;
        }

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
        $catalog = new TemplateCatalog();
        $template = $catalog->get((string) Settings::get('frontend_template', 'cards'));
        $pluginBase = rtrim((string) Options::alloc()->pluginUrl, '/') . '/FriendLinks/';
        $basePath = dirname(__DIR__, 2) . '/assets/frontend.css';
        $templatePath = $catalog->stylesheetPath($template);
        if (null === $templatePath) {
            throw new \RuntimeException('当前展示模板缺少 style.css。');
        }
        $baseUrl = $pluginBase . 'assets/frontend.css?v=' . AssetVersion::forFile($basePath);
        $templateUrl = $pluginBase . 'templates/' . rawurlencode($template['id'])
            . '/style.css?v=' . AssetVersion::forFile($templatePath);
        $content = (new Renderer())->render((new Repositories())->frontendLinks(), $template['id']);

        self::$lastRenderedHtml = '<friend-links-widget data-flm-host'
            . ' style="display:block !important;max-width:100% !important;width:100% !important">'
            . '<template shadowrootmode="open" data-flm-shadow>'
            . '<link rel="stylesheet" href="' . self::escape($baseUrl) . '">'
            . '<link rel="stylesheet" href="' . self::escape($templateUrl) . '">'
            . $content
            . '</template></friend-links-widget>';
        return self::$lastRenderedHtml;
    }

    private static function scriptTag(): string
    {
        $pluginBase = rtrim((string) Options::alloc()->pluginUrl, '/') . '/FriendLinks/';
        $scriptPath = dirname(__DIR__, 2) . '/assets/frontend.js';
        return '<script defer data-flm-frontend src="' . self::escape(
            $pluginBase . 'assets/frontend.js?v=' . AssetVersion::forFile($scriptPath)
        ) . '"></script>' . "\n";
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function logRenderFailure(\Throwable $error): void
    {
        error_log('[FriendLinks] frontend render failed: ' . $error->getMessage());
    }
}
