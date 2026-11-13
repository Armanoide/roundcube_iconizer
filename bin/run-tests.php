<?php

declare(strict_types=1);

/**
 * Test runner — no framework, no Roundcube. Run: php bin/run-tests.php
 *
 * Targets the IconResolver seam (spec 00 "Testing Decisions"): external
 * behaviour only, never internals.
 */

require __DIR__ . '/../src/IconResolver.php';
require __DIR__ . '/../src/IconPacks.php';
require __DIR__ . '/../src/IconRenderer.php';
require __DIR__ . '/../src/OfflineIcons.php';
require __DIR__ . '/../src/IconStore.php';

use Armanoide\Iconizer\IconResolver;
use Armanoide\Iconizer\IconPacks;
use Armanoide\Iconizer\IconRenderer;
use Armanoide\Iconizer\OfflineIcons;
use Armanoide\Iconizer\IconStore;

$pass = 0;
$fail = 0;

function check(string $name, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   {$name}\n";
    } else {
        $fail++;
        echo "  FAIL {$name}\n";
    }
}

echo "IconResolver\n";

$r = new IconResolver();
check('unmapped folder falls back to default', $r->resolve('Projets') === IconResolver::DEFAULT_ICON);
check('system folder falls back to default when unmapped', $r->resolve('INBOX', true) === IconResolver::DEFAULT_ICON);

$r = new IconResolver(['Projets' => 'lucide:folder-open']);
check('override wins over default', $r->resolve('Projets') === 'lucide:folder-open');
check('other folders unaffected', $r->resolve('Autre') === IconResolver::DEFAULT_ICON);
check('system folder can be overridden too', (new IconResolver(['INBOX' => 'tabler:inbox']))->resolve('INBOX', true) === 'tabler:inbox');

$r = new IconResolver(['X' => 'fa:sun']);
check('pack outside the allowlist is rejected', $r->resolve('X') === IconResolver::DEFAULT_ICON);

$r = new IconResolver(['X' => 'lucide:folder-open']);
check('offline keeps override when bundled font has it', $r->resolve('X', false, false, ['lucide:folder-open']) === 'lucide:folder-open');
check('offline drops override not in bundled font', $r->resolve('X', false, false, []) === IconResolver::DEFAULT_ICON);

$r = new IconResolver(['Dépôts & Factures' => 'mdi:file-invoice'], ['mdi']);
check('utf-8 folder name with accents and spaces resolves', $r->resolve('Dépôts & Factures') === 'mdi:file-invoice');

$r = new IconResolver(['INBOX/Notes' => 'tabler:note'], ['tabler']);
check('nested folder reference resolves', $r->resolve('INBOX/Notes') === 'tabler:note');

echo "\nIconResolver::parse / isAllowed\n";
check('parses pack:name', IconResolver::parse('tabler:folder') === ['tabler', 'folder']);
check('rejects malformed (no colon)', IconResolver::parse('tabler-folder') === null);
check('rejects malformed (empty name)', IconResolver::parse('tabler:') === null);
check('rejects uppercase pack', IconResolver::parse('Tabler:folder') === null);
check('isAllowed accepts allowlisted pack', (new IconResolver())->isAllowed('lucide:x') === true);
check('isAllowed rejects unknown pack', (new IconResolver())->isAllowed('hugeicons:x') === false);
check('allowedPacks lists the curated packs, lucide first (ADR 0002)', IconResolver::ALLOWED_PACKS === ['lucide', 'tabler', 'mdi', 'ph', 'ri']);
check('allowedPacks() exposes them to the form', (new IconResolver())->allowedPacks() === IconResolver::ALLOWED_PACKS);
check('picker packs match the resolver allowlist (ADR 0002)', IconPacks::prefixes() === IconResolver::ALLOWED_PACKS);
check('picker labels the packs for humans', IconPacks::label('mdi') === 'Material Design Icons');
check('parse anchors the picker pack on the current choice', (IconResolver::parse('mdi:file-invoice') ?? [null])[0] === 'mdi');

echo "\nIconRenderer\n";
$svg = IconRenderer::svg('<path d="M3 7h18"/>');
check('svg uses currentColor so theme controls colour', str_contains($svg, 'stroke="currentColor"'));
check('svg carries the row class', str_contains($svg, IconRenderer::ROW_CLASS));
check('svg keeps the body markup', str_contains($svg, '<path d="M3 7h18"/>'));
check('svg is aria-hidden', str_contains($svg, 'aria-hidden="true"'));

$wf = IconRenderer::webfont('folder-open');
check('webfont fallback targets tabler class', str_contains($wf, 'ti ti-folder-open'));
check('webfont escapes the name', str_contains(IconRenderer::webfont('a"b'), '&quot;'));

check('api url shape', IconRenderer::apiUrl('tabler:folder') === 'https://api.iconify.design/tabler/folder.svg');
check('api url trims trailing slash', IconRenderer::apiUrl('lucide:x', 'https://x.example/') === 'https://x.example/lucide/x.svg');

$threw = false;
try {
    IconRenderer::apiUrl('bogus');
} catch (\InvalidArgumentException) {
    $threw = true;
}
check('api url rejects malformed reference', $threw);

echo "\nOfflineIcons\n";
check('default icon is available offline', OfflineIcons::has(IconResolver::DEFAULT_ICON));
$offline = OfflineIcons::all();
check('offline list is not empty', count($offline) > 0);
check('offline list holds only tabler refs', !array_filter($offline, fn ($x) => !str_starts_with($x, 'tabler:')));

echo "\nIconStore\n";
$tmp = tempnam(sys_get_temp_dir(), 'iconizer') . '.json';
$s = new IconStore($tmp);
check('empty store has no override', $s->get('Projets') === null);
$s->put('Projets', 'lucide:folder-open', '<path/>');
check('stored icon is readable', $s->get('Projets') === 'lucide:folder-open');
check('stored svg is readable', $s->svg('Projets') === '<path/>');
check('overrides expose the resolver shape', $s->overrides() === ['Projets' => 'lucide:folder-open']);

$s2 = new IconStore($tmp);
check('store survives reload (persistence)', $s2->get('Projets') === 'lucide:folder-open');
$s2->forget('Projets');
check('forget removes the entry', (new IconStore($tmp))->get('Projets') === null);
unlink($tmp);

echo "\nIconStore — prefs round-trip (source = users.preferences, card 02)\n";
$prefs_rows = ['Administration' => ['icon' => 'tabler:briefcase', 'svg' => '<rect/>']];
$p = new IconStore();
$p->hydrate($prefs_rows);
check('hydrate reads prefs-shaped rows', $p->get('Administration') === 'tabler:briefcase');
$p_bad = new IconStore();
$p_bad->hydrate(['X' => 'nope', 'Ok' => ['icon' => 'lucide:folder']]);
check('hydrate drops malformed rows', $p_bad->overrides() === ['Ok' => 'lucide:folder']);
check('all() round-trips back to prefs shape', $p->all() === $prefs_rows);
$p_empty = new IconStore();
$p_empty->hydrate([]);
check('empty prefs hydrate to empty store', $p_empty->overrides() === []);

echo "\nIconStore — rename migration (carte 02: renommer conserve l'icône)\n";
$s = new IconStore();
$s->hydrate([
    'Projets'          => ['icon' => 'lucide:folder-open', 'svg' => '<a/>'],
    'Projets/Notes'   => ['icon' => 'tabler:note', 'svg' => '<b/>'],
    'ProjetsX'        => ['icon' => 'mdi:alpha-x', 'svg' => '<c/>'],
    'Autre'           => ['icon' => 'ph:planet', 'svg' => '<d/>'],
]);
$s->rename('Projets', 'Chantiers');
check('renamed folder keeps its icon', $s->get('Chantiers') === 'lucide:folder-open');
check('renamed folder keeps its svg cache', $s->svg('Chantiers') === '<a/>');
check('old key is gone', $s->get('Projets') === null);
check('subfolders migrate with the parent', $s->get('Chantiers/Notes') === 'tabler:note');
check('prefix look-alike folders are untouched', $s->get('ProjetsX') === 'mdi:alpha-x');
check('unrelated folders are untouched', $s->get('Autre') === 'ph:planet');

echo "\nIconStore — delete cleanup (carte 02: supprimer nettoie)\n";
$s = new IconStore();
$s->hydrate([
    'Projets'        => ['icon' => 'lucide:folder-open', 'svg' => '<a/>'],
    'Projets/Notes' => ['icon' => 'tabler:note', 'svg' => '<b/>'],
    'ProjetsX'      => ['icon' => 'mdi:alpha-x', 'svg' => '<c/>'],
]);
$prefix = 'Projets/';
foreach (array_keys($s->overrides()) as $key) {
    if ($key === 'Projets' || str_starts_with($key, $prefix)) {
        $s->forget($key);
    }
}
check('deleted folder entry is gone', $s->get('Projets') === null);
check('deleted subfolder entry is gone', $s->get('Projets/Notes') === null);
check('prefix look-alike survives delete', $s->get('ProjetsX') === 'mdi:alpha-x');

echo "\nEnd-to-end seam (store -> resolver -> renderer)\n";
$tmp = tempnam(sys_get_temp_dir(), 'iconizer') . '.json';
$store = new IconStore($tmp);
$store->put('Administration', 'tabler:briefcase', '<rect x="2" y="7" width="20" height="14"/>');
$resolver = new IconResolver($store->overrides());

$ref = $resolver->resolve('Administration', false, true, OfflineIcons::all());
check('configured folder resolves to its icon', $ref === 'tabler:briefcase');
$out = IconRenderer::svg((string) $store->svg('Administration'));
check('rendered markup is a currentColor svg', str_contains($out, 'currentColor') && str_contains($out, '<rect'));

$refOffline = $resolver->resolve('Administration', false, false, OfflineIcons::all());
check('offline resolves to the same icon when bundled', $refOffline === 'tabler:briefcase');

$store->put('LongTail', 'ph:planet', '<circle/>');
check(
    'offline drops a non-bundled override to the default',
    (new IconResolver($store->overrides()))->resolve('LongTail', false, false, OfflineIcons::all()) === IconResolver::DEFAULT_ICON
);
unlink($tmp);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
