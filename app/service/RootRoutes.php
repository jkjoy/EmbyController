<?php

namespace app\service;

/** Canonical website paths, including return URLs saved before the /media migration. */
class RootRoutes
{
    public static function removeMediaPrefix(string $path): string
    {
        return preg_replace('~^/(?:index\.php/)?media(?:\.html)?(?=/|$)~i', '', $path) ?: '/';
    }

    /** Extract ThinkPHP's compatibility route without changing other query bytes. */
    public static function pathinfoQuery(string $query): array
    {
        $key = request()->pathinfoVariable();
        parse_str($query, $params);
        if (!array_key_exists($key, $params)) return [null, $query];
        $pairs = preg_split('~[' . preg_quote(ini_get('arg_separator.input') ?: '&', '~') . ']~', $query);
        $pairs = array_filter($pairs, static function ($pair) use ($key) {
            parse_str($pair, $value);
            return !array_key_exists($key, $value);
        });
        return [$params[$key], implode('&', $pairs)];
    }

    public static function loginTarget($target): string
    {
        $fallback = '/user/index';
        if (!is_string($target) || $target === '' || preg_match('~[\x00-\x20\\\\]~', $target)) return $fallback;
        $parts = parse_url($target);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) return $fallback;
        if (isset($parts['scheme']) || isset($parts['host'])) {
            $origin = parse_url(request()->domain());
            if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || !isset($parts['host'], $origin['host']) || strcasecmp($parts['host'], $origin['host']) !== 0
                || ($parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80))
                    !== ($origin['port'] ?? (strtolower($origin['scheme']) === 'https' ? 443 : 80))) return $fallback;
        }
        $path = $parts['path'] ?? '/';
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) return $fallback;
        [$compatibilityPath, $query] = self::pathinfoQuery($parts['query'] ?? '');
        if ($compatibilityPath !== null) {
            if (!is_string($compatibilityPath) || preg_match('~[\x00-\x20\\\\]~', $compatibilityPath)) return $fallback;
            $path = '/' . ltrim($compatibilityPath, '/');
        }
        // Stored legacy targets may also contain the explicit front controller.
        $path = preg_replace('~^/index\.php(?=/|$)~i', '', $path) ?: '/';
        do {
            $previous = $path;
            $path = self::removeMediaPrefix($path);
        } while ($path !== $previous);
        if (str_starts_with($path, '//') || preg_match('~^/user/(?:login|register|logout)(?:\.html)?(?:/|$)~i', $path)) return $fallback;
        return $path . ($query !== '' ? '?' . $query : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }
}
