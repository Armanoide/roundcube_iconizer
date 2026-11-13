<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/**
 * Local sprite mirror of the allowlisted icon packs (ADR 0004).
 *
 * A pack is downloaded once, server-side, from jsDelivr and written to
 * sprites/<pack>.svg (<symbol> per icon) + data/<pack>.json (searchable names).
 * After install the browser never calls api.iconify.design again. The fetcher is
 * injectable so tests never hit the network.
 */
final class IconSpriteCache
{
    /** Full-pack data source: npm @iconify-json/* served by jsDelivr. */
    public const CDN = 'https://cdn.jsdelivr.net/npm';

    /** @var callable(string):?string fetches a URL, null on failure */
    private $fetcher;

    /**
     * @param callable(string):?string|null $fetcher injectable URL fetcher
     *        (tests stub it); production uses PHP curl with a hard timeout.
     */
    public function __construct(
        private string $spriteDir,
        private string $dataDir,
        ?callable $fetcher = null,
    ) {
        $this->fetcher = $fetcher ?? [$this, 'curl'];
    }

    public function spritePath(string $pack): string
    {
        return "{$this->spriteDir}/{$pack}.svg";
    }

    public function dataPath(string $pack): string
    {
        return "{$this->dataDir}/{$pack}.json";
    }

    public function isInstalled(string $pack): bool
    {
        return is_file($this->spritePath($pack)) && is_file($this->dataPath($pack));
    }

    /** @return list<string> installed pack prefixes */
    public function installed(): array
    {
        $out = [];
        foreach (IconPacks::prefixes() as $pack) {
            if ($this->isInstalled($pack)) {
                $out[] = $pack;
            }
        }

        return $out;
    }

    /** Icon count of an installed pack, 0 when missing or unreadable. */
    public function count(string $pack): int
    {
        $meta = $this->meta($pack);

        return count($meta['names'] ?? []);
    }

    /** @return array{version:?string,names:list<string>} data file contents */
    public function meta(string $pack): array
    {
        if (!is_file($this->dataPath($pack))) {
            return ['version' => null, 'names' => []];
        }

        $json = json_decode((string) @file_get_contents($this->dataPath($pack)), true);
        if (!is_array($json)) {
            return ['version' => null, 'names' => []];
        }

        return [
            'version' => isset($json['version']) ? (string) $json['version'] : null,
            'names'   => array_values(array_filter((array) ($json['names'] ?? []), 'is_string')),
        ];
    }

    /** Download a pack's dataset, build its sprite + name list. Atomic: nothing is written before the data parses. */
    public function install(string $pack): array
    {
        if (!in_array($pack, IconPacks::prefixes(), true)) {
            return ['ok' => false, 'count' => 0, 'error' => 'pack not allowlisted'];
        }

        $raw = ($this->fetcher)(self::CDN . "/@iconify-json/{$pack}@latest/icons.json");
        if (!is_string($raw) || $raw === '') {
            return ['ok' => false, 'count' => 0, 'error' => 'download failed'];
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['icons']) || !is_array($json['icons'])) {
            return ['ok' => false, 'count' => 0, 'error' => 'malformed pack data'];
        }

        $symbols = [];
        foreach ($json['icons'] as $name => $icon) {
            $body = is_array($icon) ? (string) ($icon['body'] ?? '') : '';
            if ($body === '' || !is_string($name) || $name === '') {
                continue;
            }
            $symbols[$name] = $this->symbol($name, $body, (array) $icon);
        }

        if (!$symbols) {
            return ['ok' => false, 'count' => 0, 'error' => 'no icon bodies'];
        }

        if (!is_dir($this->spriteDir)) {
            mkdir($this->spriteDir, 0755, true);
        }
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }

        file_put_contents($this->spritePath($pack), $this->renderSprite($symbols));
        file_put_contents(
            $this->dataPath($pack),
            json_encode([
                'prefix'  => $pack,
                // @iconify-json/icons.json carries no version field; the
                // "@latest" redirect resolved server-side, unknown here.
                'version' => isset($json['version']) ? (string) $json['version'] : null,
                'names'   => array_keys($symbols),
            ], JSON_UNESCAPED_SLASHES)
        );

        return ['ok' => true, 'count' => count($symbols), 'error' => null];
    }

    /** Remove a pack's sprite + data files. */
    public function uninstall(string $pack): void
    {
        foreach ([$this->spritePath($pack), $this->dataPath($pack)] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    /** Fetch a URL with curl; null on failure. Generous timeout + gzip: full packs reach ~5MB. */
    public function curl(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING       => '',        // accept gzip/deflate: ~4x smaller over the wire
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 180,       // 5MB packs over a slow link
            CURLOPT_USERAGENT      => 'roundcube-iconizer/1.0',
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw) || $raw === '' || $code !== 200) {
            error_log("iconizer: fetch {$url} failed (http {$code}) {$err}");

            return null;
        }

        return $raw;
    }

    /** One <symbol> per icon: viewBox from the data, default 24 (Iconify spec). */
    private function symbol(string $name, string $body, array $icon): string
    {
        $id   = htmlspecialchars($name, ENT_QUOTES);
        $view = htmlspecialchars((string) ($icon['width'] ?? 24) . ' ' . (string) ($icon['height'] ?? 24), ENT_QUOTES);

        return "<symbol id=\"{$id}\" viewBox=\"0 0 {$view}\">{$body}</symbol>";
    }

    /** @param array<string,string> $symbols */
    private function renderSprite(array $symbols): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg">' . implode('', $symbols) . '</svg>';
    }
}
