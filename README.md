# roundcube_iconizer

Custom per-folder icons in the Roundcube Elastic sidebar, Obsidian-style.
Standalone plugin — independent from `roundcube_catppuccin` (which owns colours only).

## What it does
- Each mailbox folder can carry its own icon, chosen from a curated set of Iconify packs
  (Bootstrap Icons by default, Font Awesome Solid/Regular/Brands, Google
  Material Icons, Material Design Icons, Simple Icons).
- Icons render from **local SVG sprites** (`sprites/<pack>.svg`), referenced with
  `<use href>` and `currentColor`, so the active theme controls their colour. Once a
  pack is installed there are **zero runtime calls to api.iconify.design** (ADR 0004).
- Packs are installed/uninstalled from **Settings → Icon packs**; each install is one
  server-side download of the full `@iconify-json/<pack>` dataset from jsDelivr.

## Install (node1)
```bash
cd /home/norbert/docker/roundcubemail/www/plugins
git clone https://github.com/Armanoide/roundcube_iconizer.git
# enable in config.inc.php plugins array, then recreate the container (PHP changes)
```

## Requirements & deployment gotchas

These are the three things that silently break this plugin — read them before
debugging a "Not installed" that never resolves:

1. **`sprites/` and `data/` must be writable by the web-server user** (`www-data` in
   the official Roundcube image). The plugin creates them on first install; if they
   already exist owned by `root` (e.g. created by a manual `docker exec`), the web
   process cannot write the `.svg` files there and every install fails **silently**.
   Fix: `chown -R www-data:www-data sprites data` inside the container.
2. **Sprite URLs must go through `static.php`.** Roundcube serves plugin assets at
   `static.php/plugins/roundcube_iconizer/...`, not `/plugins/...` (which 404s). The
   plugin builds the base URL with the core's `asset_url()` — don't hardcode `/plugins/`.
3. **`install()` verifies its writes.** A failed `file_put_contents` (read-only dir)
   returns `ok=false` with the offending path, rather than reporting success for files
   that don't exist. If an install fails, the message names the path to fix.

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
