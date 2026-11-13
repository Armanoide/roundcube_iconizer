<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/**
 * Renders an Iconify reference as inline SVG markup for one folder row.
 *
 * The fetched body is cached by the caller (user preference) so repaints do
 * not hit the network — spec 00, "cached resolved SVG". This class only turns
 * a body into markup; it never fetches.
 */
final class IconRenderer
{
    /** CSS classes Roundcube/Elastic uses to place the row icon. */
    public const ROW_CLASS = 'iconizer-icon';

    /**
     * Build the inline SVG for a folder row from an Iconify body payload.
     *
     * currentColor keeps the Catppuccin theme in charge of colour (ADR 0003).
     *
     * @param string $body raw SVG body from the Iconify API (inner markup)
     */
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

    /**
     * Build a <span> that uses the bundled Tabler webfont glyph instead of SVG.
     * Used when the API is unreachable and the icon exists in the local font.
     */
    public static function webfont(string $iconName, int $size = 16): string
    {
        return sprintf(
            '<i class="ti ti-%s %s" aria-hidden="true" style="font-size:%dpx"></i>',
            htmlspecialchars($iconName, ENT_QUOTES),
            self::ROW_CLASS,
            $size,
        );
    }

    /**
     * Iconify API URL for one reference. Kept here so the fetcher and the
     * tests agree on one shape.
     */
    public static function apiUrl(string $reference, string $host = 'https://api.iconify.design'): string
    {
        $parts = IconResolver::parse($reference);
        if ($parts === null) {
            throw new \InvalidArgumentException("Malformed icon reference: {$reference}");
        }

        return sprintf('%s/%s/%s.svg', rtrim($host, '/'), $parts[0], $parts[1]);
    }
}
