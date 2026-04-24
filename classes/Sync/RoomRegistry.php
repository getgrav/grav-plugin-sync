<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync;

use Grav\Common\Page\Interfaces\PageInterface;
use RuntimeException;

/**
 * Resolves page routes (+ language + template) into canonical room ids.
 *
 * A room id encodes enough to route storage deterministically:
 *   "<route>@<template>"                        — default language
 *   "<route>.<lang>@<template>"                 — explicit language
 *
 * The route portion is the page folder path under user/pages, without the
 * numeric ordering prefix (e.g. `blog/my-post` not `01.blog/03.my-post`).
 * Template defaults to `default` when the blueprint name isn't relevant.
 */
final class RoomRegistry
{
    public function __construct()
    {
    }

    /**
     * Build a room for a page route.
     *
     * @param string $route      Public page route, e.g. "/blog/my-post".
     * @param string|null $lang  Language code (e.g. "fr") or null for default.
     * @param string $template   Blueprint/template name, default "default".
     */
    public function roomFor(string $route, ?string $lang = null, string $template = 'default'): SyncRoom
    {
        $normalized = $this->normalizeRoute($route);
        if ($lang !== null && $lang !== '') {
            if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', $lang)) {
                throw new RuntimeException("sync: invalid language code: {$lang}");
            }
        }
        if (!preg_match('/^[a-z0-9_-]+$/i', $template)) {
            throw new RuntimeException("sync: invalid template name: {$template}");
        }

        $routePart = $normalized . ($lang ? '.' . strtolower($lang) : '');
        $id = $routePart . '@' . $template;

        return new SyncRoom(
            id: $id,
            route: $normalized,
            language: $lang,
            template: $template,
        );
    }

    /**
     * Resolve from a live Page object.
     */
    public function roomForPage(PageInterface $page, ?string $lang = null): SyncRoom
    {
        $route = $page->route() ?? '';
        if ($route === '') {
            throw new RuntimeException('sync: page has no route');
        }
        $template = $page->template() ?: 'default';
        return $this->roomFor($route, $lang, $template);
    }

    /**
     * Parse a previously-issued room id back into its parts.
     *
     * @return array{route: string, language: ?string, template: string}
     */
    public function parse(string $roomId): array
    {
        $at = strrpos($roomId, '@');
        if ($at === false) {
            throw new RuntimeException('sync: malformed room id');
        }
        $routePart = substr($roomId, 0, $at);
        $template = substr($roomId, $at + 1);

        // Split off trailing .<lang> if present.
        $lang = null;
        if (preg_match('/^(.*)\.([a-z]{2}(?:-[a-z]{2})?)$/i', $routePart, $m)) {
            $route = $m[1];
            $lang = strtolower($m[2]);
        } else {
            $route = $routePart;
        }
        return ['route' => $route, 'language' => $lang, 'template' => $template];
    }

    /**
     * Trim surrounding slashes/whitespace and validate.
     * Pages use relative-looking routes ("/blog/foo"); we store without
     * leading slash so it maps cleanly onto a relative folder path.
     */
    private function normalizeRoute(string $route): string
    {
        $route = trim($route, "/ \t\n\r\0\x0B");
        if ($route === '') {
            throw new RuntimeException('sync: empty route');
        }
        foreach (explode('/', $route) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                throw new RuntimeException("sync: unsafe route segment: {$seg}");
            }
            if (!preg_match('/^[a-z0-9._-]+$/i', $seg)) {
                throw new RuntimeException("sync: invalid route segment: {$seg}");
            }
        }
        return $route;
    }

}
