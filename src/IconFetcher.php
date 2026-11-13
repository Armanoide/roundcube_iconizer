<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/** Iconify fetch with a hard timeout: null instead of throwing — an unreachable API must never blank a row. */
final class IconFetcher
{
    public function __construct(private int $timeoutMs = 1500)
    {
    }

    public function fetch(string $reference, string $host = 'https://api.iconify.design'): ?string
    {
        try {
            $url = IconRenderer::apiUrl($reference, $host);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'timeout'          => $this->timeoutMs / 1000,
                'header'         => "User-Agent: roundcube_iconizer/1.0\r\n",
                'ignore_errors'  => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false || $body === '') {
            return null;
        }

        // Iconify answers with the SVG document; keep only its inner markup.
        if (preg_match('#<svg[^>]*>(.*)</svg>#s', $body, $m) !== 1) {
            return null;
        }

        return self::sanitize($m[1]);
    }

    /** XSS: the result is inlined into our own DOM — scripts, foreignObject/iframes, event handlers and external hrefs never survive. */
    public static function sanitize(string $inner): string
    {
        $inner = preg_replace('#<(script|foreignObject|iframe|embed|object)\b[^>]*>.*?</\1>#si', '', $inner) ?? '';
        $inner = preg_replace('#<(script|foreignObject|iframe|embed|object)\b[^>]*/?>#si', '', $inner) ?? '';
        $inner = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $inner) ?? '';
        $inner = preg_replace('#\s(?:xlink:)?href\s*=\s*(?:"https?://[^"]*"|\'https?://[^\']*\'|https?://[^\s>]+)#i', '', $inner) ?? '';

        return trim($inner);
    }
}
