<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/**
 * Store of user folder icon choices: reference => ["icon" => pack:name, "svg" => body].
 *
 * Deliberately in-memory and file-backed so the resolver seam is testable
 * without Roundcube. The plugin hands this to a real user_prefs table in card 02.
 */
final class IconStore
{
    /** @var array<string, array{icon: string, svg: ?string}> */
    private array $rows = [];

    public function __construct(private string $path = '')
    {
        if ($this->path !== '' && is_readable($this->path)) {
            $decoded = json_decode((string) file_get_contents($this->path), true);
            if (is_array($decoded)) {
                $this->rows = $decoded;
            }
        }
    }

    public function get(string $folder): ?string
    {
        return $this->rows[$folder]['icon'] ?? null;
    }

    public function svg(string $folder): ?string
    {
        return $this->rows[$folder]['svg'] ?? null;
    }

    public function put(string $folder, string $icon, ?string $svg = null): void
    {
        $this->rows[$folder] = ['icon' => $icon, 'svg' => $svg];
        $this->persist();
    }

    public function forget(string $folder): void
    {
        unset($this->rows[$folder]);
        $this->persist();
    }

    /** @return array<string, string> folder => icon reference, for the resolver */
    public function overrides(): array
    {
        $out = [];
        foreach ($this->rows as $folder => $row) {
            $out[$folder] = $row['icon'];
        }

        return $out;
    }

    private function persist(): void
    {
        if ($this->path === '') {
            return;
        }

        file_put_contents($this->path, json_encode($this->rows, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
