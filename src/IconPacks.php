<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/**
 * The packs the Icon field may offer (ADR 0002): prefix => human label,
 * lucide first — Obsidian's native look is the default (spec 00, story 5).
 */
final class IconPacks
{
    /** @var array<string, string> */
    public const PACKS = [
        'lucide'  => 'Lucide',
        'tabler'  => 'Tabler',
        'mdi'     => 'Material Design Icons',
        'ph'      => 'Phosphor',
        'ri'      => 'Remix',
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
