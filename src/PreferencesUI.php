<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/**
 * Preferences → "Icon packs" (ADR 0004): install/remove the local sprite mirrors.
 *
 * Checkboxes, not buttons: this section renders inside Roundcube's save-prefs form,
 * so a nested <form> is dropped by the browser. Real preference options are serialized
 * and posted by the core's own Save button.
 */
final class PreferencesUI
{
    private $sprites;

    public function __construct(IconSpriteCache $sprites)
    {
        $this->sprites = $sprites;
    }

    public function sectionsList($p)
    {
        $p['list']['iconizer'] = ['id' => 'iconizer', 'section' => 'Icon packs'];

        return $p;
    }

    public function list($p)
    {
        if ($p['section'] !== 'iconizer') {
            return $p;
        }

        if (!$p['current']) {
            $p['blocks']['main']['content'] = true;

            return $p;
        }

        // Install state is read from the filesystem, not prefs: prefs carry intent, disk is truth.
        $options = [];
        foreach (IconPacks::PACKS as $prefix => $label) {
            $installed = $this->sprites->isInstalled($prefix);
            $count     = $installed ? $this->sprites->count($prefix) : 0;

            $field = new \html_checkbox([
                'name'  => '_iconizer_pack_' . $prefix,
                'id'    => 'rcmfd_iconizer_' . $prefix,
                'value' => 1,
            ]);

            $options['pack_' . $prefix] = [
                'key'     => 'pack_' . $prefix,
                'title'   => \rcube::Q($label)
                    . ($installed ? ' <span class="iconizer-pack-ok">(' . $count . ' icons installed)</span>' : ''),
                'content' => $field->show($installed ? 1 : 0),
            ];
        }

        $p['blocks']['main'] = [
            'id'      => 'iconizer',
            'name'    => 'Icon packs',
            'options' => $options,
        ];

        return $p;
    }

    public function save($p)
    {
        if ($p['section'] !== 'iconizer') {
            return $p;
        }

        $rcmail  = \rcube::get_instance();
        $results = [];

        foreach (IconPacks::PACKS as $prefix => $label) {
            $checked = isset($_POST['_iconizer_pack_' . $prefix]);

            if ($checked && !$this->sprites->isInstalled($prefix)) {
                $r = $this->sprites->install($prefix);
                if ($r['ok']) {
                    $results[] = "'{$label}' installed ({$r['count']} icons).";
                } else {
                    $rcmail->output->show_message("Install of '{$label}' failed: {$r['error']}", 'error');
                }
            } elseif (!$checked && $this->sprites->isInstalled($prefix)) {
                $this->sprites->uninstall($prefix);
                $results[] = "'{$label}' removed.";
            }
        }

        if ($results) {
            $rcmail->output->show_message(implode(' ', $results), 'confirmation');
        }

        // Nothing to persist in prefs; the sprite files are the store.
        $p['prefs'] = [];

        return $p;
    }
}
