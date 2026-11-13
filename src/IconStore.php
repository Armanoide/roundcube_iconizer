<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/** folder→icon choices keyed by raw IMAP names, never re-encoded — the client matches each row anchor's rel. Prefs are the source; this is the testable working copy. */
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

    /** Hydrate from a prefs-shaped array (folder => ["icon" => ..., "svg" => ...]). */
    public function hydrate(array $rows): void
    {
        $this->rows = [];
        foreach ($rows as $folder => $row) {
            if (is_array($row) && isset($row['icon']) && is_string($row['icon'])) {
                $this->rows[(string) $folder] = [
                    'icon' => $row['icon'],
                    'svg'  => isset($row['svg']) && is_string($row['svg']) ? $row['svg'] : null,
                ];
            }
        }
    }

    /** @return array<string, array{icon: string, svg: ?string}> prefs-shaped rows */
    public function all(): array
    {
        return $this->rows;
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

    /** Move a renamed folder's entry and every subfolder below it — same prefix rewrite the core applies in folder_rename. */
    public function rename(string $oldFolder, string $newFolder, string $delimiter = '/'): void
    {
        $prefix = $oldFolder . $delimiter;
        foreach (array_keys($this->rows) as $folder) {
            if ($folder === $oldFolder || str_starts_with($folder, $prefix)) {
                $target = $newFolder . substr($folder, strlen($oldFolder));
                if ($target !== $folder) {
                    $this->rows[$target] = $this->rows[$folder];
                    unset($this->rows[$folder]);
                }
            }
        }

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

    /** Does the store hold any subfolder entry below $folder? */
    public function hasChildren(string $folder, string $delimiter = '/'): bool
    {
        $prefix = $folder . $delimiter;

        foreach (array_keys($this->rows) as $key) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Forget $folder and every subfolder below it. */
    public function forgetTree(string $folder, string $delimiter = '/'): void
    {
        $prefix = $folder . $delimiter;

        foreach (array_keys($this->rows) as $key) {
            if ($key === $folder || str_starts_with($key, $prefix)) {
                unset($this->rows[$key]);
            }
        }

        $this->persist();
    }

    private function persist(): void
    {
        if ($this->path === '') {
            return;
        }

        file_put_contents($this->path, json_encode($this->rows, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
