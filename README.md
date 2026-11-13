# Roundcube Iconizer

Custom per-folder icons in the Elastic sidebar — pick any icon from curated Iconify packs, stored per user.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Stars](https://img.shields.io/github/stars/Armanoide/roundcube_iconizer?style=flat-square&logo=github)](https://github.com/Armanoide/roundcube_iconizer/stargazers)
[![Issues](https://img.shields.io/github/issues/Armanoide/roundcube_iconizer?style=flat-square)](https://github.com/Armanoide/roundcube_iconizer/issues)

<!-- screenshot: sidebar with custom folder icons — add assets/screenshot.png -->

## Features

- 🔍 **Searchable icon picker** in the folder edit form — search across installed packs
- 🎨 **Curated packs**: Bootstrap Icons, Font Awesome, Material Design, Simple Icons
- 🖼️ **Local sprite mirror** — packs are mirrored server-side once; no runtime requests to api.iconify.design
- 👤 **Per-user persistence** — icons live in your Roundcube user preferences, synced across devices
- ♻️ **Follows your folders** — icons survive renames and are cleaned up on delete
- 🌈 **Theme-aware** — icons render in `currentColor`, so your skin stays in charge

## Requirements

- Roundcube **1.6+** with the **Elastic** skin
- PHP **8.0+**

## Installation

### Composer (recommended)

```bash
cd /path/to/roundcube
composer require armanoide/roundcube-iconizer
```

Then enable it in `config/config.inc.php` (step below).

### Manual

1. Clone the plugin into your Roundcube `plugins` directory:

```bash
git clone https://github.com/Armanoide/roundcube_iconizer.git \
    /var/www/html/plugins/roundcube_iconizer
```

2. Enable it in `config/config.inc.php`:

```php
$config['plugins'] = [
    // ... other plugins ...
    'roundcube_iconizer', // <-- add this line
];
```

3. Make sure `plugins/roundcube_iconizer/sprites/` and `data/` are writable by the web server user.

### Docker

```yaml
services:
  roundcubemail:
    image: roundcube/roundcubemail:latest
    volumes:
      - ./plugins/roundcube_iconizer:/var/www/html/plugins/roundcube_iconizer
```

## Usage

**Install icon packs** — Settings → *Icon packs*: install/remove the sprite mirror for each pack.

**Set a folder icon** — Settings → *Folders* → edit (or create) a folder → **Icon** field → search, pick, save. The sidebar repaints immediately — no reload.

Icons are referenced as `pack:name` (e.g. `bi:folder2-open`). Removing an icon restores the standard Elastic glyph.

## Thanks To

- [Iconify](https://iconify.design) — the unified icon infrastructure
- [Bootstrap Icons](https://icons.getbootstrap.com) (MIT), [Font Awesome](https://fontawesome.com) (CC BY 4.0 / OFL), [Material Design Icons](https://materialdesignicons.com) (Apache 2.0), [Simple Icons](https://simpleicons.org) (CC0) — the pack authors

## License

[MIT](LICENSE) © 2026 Armanoide
