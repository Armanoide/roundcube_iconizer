<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/**
 * The packs the Icon field may offer (ADR 0002): prefix => human label,
 * Bootstrap first — the default pack shown by the picker.
 *
 * Verified against https://api.iconify.design/collections (2026-11-13):
 * RPG Awesome (`ra`) no longer exists on Iconify (replaced upstream by
 * `game-icons`); Simple Icons is `simple-icons`, not `si` (`si` = Sargam).
 */
final class IconPacks
{
    /** @var array<string, string> */
    public const PACKS = [
        'bi'           => 'Bootstrap Icons',
        'fa-solid'    => 'Font Awesome Solid',
        'fa-regular'  => 'Font Awesome Regular',
        'fa-brands'   => 'Font Awesome Brands',
        'ic'          => 'Google Material Icons',
        'mdi'         => 'Material Design Icons',
        'simple-icons' => 'Simple Icons',
    ];

    /** @return list<string> pack prefixes, default first */
    public static function prefixes(): array
    {
        return array_keys(self::PACKS);
    }

    public static function label(string $prefix): string
    {
        return self::PACKS[$prefix] ?? $prefix;
    }
}
