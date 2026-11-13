<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/**
 * Decides which icon a sidebar folder row should show.
 *
 * Pure: no Roundcube, no database, no network. This is the single seam the
 * tests target (spec 00, "Testing Decisions").
 */
final class IconResolver
{
    /** Packs the picker is allowed to offer (ADR 0002). */
    public const ALLOWED_PACKS = ['bi', 'fa-solid', 'fa-regular', 'fa-brands', 'ic', 'mdi', 'simple-icons'];

    /** Fallback reference when nothing is configured or nothing resolves. */
    public const DEFAULT_ICON = 'bi:folder';

    /**
     * @param array<string, string> $overrides folder reference (UTF-7-IMAP) => "pack:name"
     * @param list<string>          $allowedPacks
     */
    public function __construct(
        private array $overrides = [],
        private array $allowedPacks = self::ALLOWED_PACKS,
    ) {
    }

    /**
     * Resolve the icon reference for one folder row.
     *
     * Precedence: explicit override (if the pack is allowed and resolvable)
     * > system default for known special folders > generic folder icon.
     *
     * @param string $folder    folder reference, UTF-7-IMAP encoded as Roundcube stores it
     * @param bool   $isSystem  true for Elastic special folders (inbox, trash, ...)
     * @param bool   $online    false when the Iconify API is unreachable
     * @param list<string> $offlineIcons references present in the bundled fallback font
     */
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
        if (!preg_match('/^([a-z0-9]+):([a-z0-9-]+)$/', $reference, $m)) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    public function isAllowed(string $reference): bool
    {
        $parts = self::parse($reference);

        return $parts !== null && in_array($parts[0], $this->allowedPacks, true);
    }

    /** @return list<string> the packs the picker may offer (ADR 0002) */
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
