<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/** Bundled Tabler fallback names — generated subset kept to what a mail sidebar needs; regenerate with bin/build-offline-list.php. */
final class OfflineIcons
{
    /** @var list<string> */
    public const REFERENCES = [
        'bi:folder',
        'bi:folder-open',
        'bi:inbox',
        'bi:send',
        'bi:pencil',
        'bi:archive',
        'bi:trash',
        'bi:exclamation-triangle',
        'bi:star',
        'bi:people',
        'bi:person',
        'bi:file-text',
        'bi:calendar',
        'bi:briefcase',
        'bi:credit-card',
        'bi:receipt',
        'bi:house',
        'bi:book',
        'bi:camera',
        'bi:music-note-beamed',
        'bi:image',
        'bi:geo-alt',
        'bi:cart',
        'bi:envelope',
        'bi:bell',
        'bi:lock',
        'bi:shield',
        'bi:cloud',
        'bi:database',
        'bi:gear',
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
