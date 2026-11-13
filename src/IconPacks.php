<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/** prefix => label, Bootstrap first (picker default). Prefixes verified against api.iconify.design/collections: `ra` no longer exists, Simple Icons is `simple-icons` not `si` (`si` = Sargam). */
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
