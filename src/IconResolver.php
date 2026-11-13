<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/** Which icon a row shows. Pure seam for the tests: no Roundcube, no database, no network. */
final class IconResolver
{
    /** Packs the picker is allowed to offer. */
    public const ALLOWED_PACKS = ['bi', 'fa-solid', 'fa-regular', 'fa-brands', 'ic', 'mdi', 'simple-icons'];

    /** Fallback reference when nothing is configured or nothing resolves. */
    public const DEFAULT_ICON = 'bi:folder';

    /** @param array<string,string> $overrides folder ref (UTF-7-IMAP) => "pack:name" */
    public function __construct(
        private array $overrides = [],
        private array $allowedPacks = self::ALLOWED_PACKS,
    ) {
    }

    /** Precedence: allowed override (offline: only if the bundled font carries it) > generic folder icon. */
    public function resolve(string $folder, bool $isSystem = false, bool $online = true, array $offlineIcons = []): string
    {
        $override = $this->overrides[$folder] ?? null;

        if ($override !== null && $this->isAllowed($override)) {
            if ($online) {
                return $override;
            }

            // Offline: honour the override only if the bundled font carries it.
            return in_array($override, $offlineIcons, true) ? $override : self::DEFAULT_ICON;
        }

        return self::DEFAULT_ICON;
    }

    /** Split "pack:name" into its two halves; null when malformed. */
    public static function parse(string $reference): ?array
    {
        // Packs contain dashes (fa-solid, fa-brands, simple-icons) — [a-z0-9]+ alone dropped them to the default.
        if (!preg_match('/^([a-z0-9-]+):([a-z0-9-]+)$/', $reference, $m)) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    public function isAllowed(string $reference): bool
    {
        $parts = self::parse($reference);

        return $parts !== null && in_array($parts[0], $this->allowedPacks, true);
    }

    /** @return list<string> the packs the picker may offer */
    public function allowedPacks(): array
    {
        return $this->allowedPacks;
    }

    /** @return array<string, string> */
    public function overrides(): array
    {
        return $this->overrides;
    }
}
