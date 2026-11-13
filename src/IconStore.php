<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/**
 * User's folder→icon choices, keyed by raw IMAP folder name.
 *
 * Deliberately storage-agnostic so the seam is testable without Roundcube:
 * in production the plugin hydrates it from `$rcmail->user->get_prefs()`
 * (users.preferences, JSON) and writes back with save_prefs() — the prefs are
 * the source, this object is the working copy (card 02). Keys are never
 * re-encoded: same raw name the client reads from each row anchor's rel.
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

    /**
     * Move the entry of a renamed folder, and of every subfolder below it, to
     * the new path — same prefix rewrite the core applies to message_threading
     * in folder_rename (program/actions/settings/folder_rename.php).
     */
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

    private function persist(): void
    {
        if ($this->path === '') {
            return;
        }

        file_put_contents($this->path, json_encode($this->rows, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
