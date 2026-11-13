# roundcube_iconizer

Custom per-folder icons in the Roundcube Elastic sidebar, Obsidian-style.
Standalone plugin — independent from `roundcube_catppuccin` (which owns colours only).

## What it does
- Each mailbox folder can carry its own icon, chosen from a curated set of Iconify packs
  (Lucide by default, Tabler, Material Design Icons, Phosphor, Remix).
- Icons render as inline SVG using `currentColor`, so the active theme controls their colour.
- Unreachable icon service never leaves a blank row: a bundled Tabler webfont is the fallback,
  then Elastic's standard folder glyph.

## Install (node1)
```bash
cd /home/norbert/docker/roundcubemail/www/plugins
git clone https://github.com/Armanoide/roundcube_iconizer.git
# enable in config.inc.php plugins array, then recreate the container (PHP changes)
```

## Test
```bash
php bin/run-tests.php
```

Card 02 (form field + persistence): the Icon field lives in Settings > Folders >
edit a folder; saving writes `iconizer` into the user's Roundcube preferences
(`users.preferences`) — no schema, per-user, survives logout and other browsers.
Renaming migrates the entry (and subfolders), deleting forgets it, an empty pick
restores Elastic's glyph.

Status: cards 01–02. See the `Roundcube-Iconizer` kanban board.
