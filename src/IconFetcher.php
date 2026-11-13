<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/**
 * Iconify fetch with a hard timeout and graceful degradation.
 *
 * Returns null instead of throwing: an unreachable API must never blank a
 * sidebar row (spec 00, story 13). The caller falls back to the bundled font.
 */
final class IconFetcher
{
    public function __construct(private int $timeoutMs = 1500)
    {
    }

    /**
     * Fetch the raw SVG body for an icon reference.
     *
     * @return string|null SVG inner markup, or null when unavailable
     */
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
        if (preg_match('#<svg[^>]*>(.*)</svg>#s', $body, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
