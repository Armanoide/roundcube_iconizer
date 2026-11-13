<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/**
 * Names of icons shipped in the bundled Tabler webfont fallback.
 *
 * Generated subset: keep this list to what a mail sidebar plausibly needs so
 * the offline path stays small. Regenerate with bin/build-offline-list.php.
 */
final class OfflineIcons
{
    /** @var list<string> */
    public const REFERENCES = [
        'tabler:folder',
        'tabler:folder-open',
        'tabler:inbox',
        'tabler:send',
        'tabler:pencil',
        'tabler:archive',
        'tabler:trash',
        'tabler:alert-triangle',
        'tabler:star',
        'tabler:users',
        'tabler:user',
        'tabler:file-text',
        'tabler:calendar',
        'tabler:briefcase',
        'tabler:credit-card',
        'tabler:receipt',
        'tabler:home',
        'tabler:book',
        'tabler:camera',
        'tabler:music',
        'tabler:photo',
        'tabler:map-pin',
        'tabler:shopping-cart',
        'tabler:mail',
        'tabler:bell',
        'tabler:lock',
        'tabler:shield',
        'tabler:cloud',
        'tabler:database',
        'tabler:settings',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return self::REFERENCES;
    }

    public static function has(string $reference): bool
    {
        return in_array($reference, self::REFERENCES, true);
    }
}
