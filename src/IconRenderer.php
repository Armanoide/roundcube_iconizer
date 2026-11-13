<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/** Body → inline SVG markup only, never fetches — caching the body is the caller's job (user prefs). */
final class IconRenderer
{
    /** CSS classes Roundcube/Elastic uses to place the row icon. */
    public const ROW_CLASS = 'iconizer-icon';

    /** currentColor keeps the Catppuccin theme in charge of colour. */
    public static function svg(string $body, int $size = 16): string
    {
        return sprintf(
            '<svg class="%s" xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" '
            . 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
            . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
            self::ROW_CLASS,
            $size,
            $size,
            $body,
        );
    }

    /** Bundled Tabler webfont glyph fallback: used when the API is unreachable and the icon exists in the local font. */
    public static function webfont(string $iconName, int $size = 16): string
    {
        return sprintf(
            '<i class="ti ti-%s %s" aria-hidden="true" style="font-size:%dpx"></i>',
            htmlspecialchars($iconName, ENT_QUOTES),
            self::ROW_CLASS,
            $size,
        );
    }

    /** JSON_HEX_TAG: a user-controlled folder named "</script>" would close the tag (core bug #6207 class). */
    public static function inlineJson(mixed $data): string
    {
        return (string) json_encode($data, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);
    }

    /** One Iconify URL shape, kept here so the fetcher and the tests agree. */
    public static function apiUrl(string $reference, string $host = 'https://api.iconify.design'): string
    {
        $parts = IconResolver::parse($reference);
        if ($parts === null) {
            throw new \InvalidArgumentException("Malformed icon reference: {$reference}");
        }

        return sprintf('%s/%s/%s.svg', rtrim($host, '/'), $parts[0], $parts[1]);
    }
}
