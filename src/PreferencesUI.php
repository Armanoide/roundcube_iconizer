<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/**
 * Preferences → "Icon packs" (ADR 0004): install/remove the local sprite mirrors.
 *
 * Extracted from roundcube_iconizer.php so the plugin entry point stays focused on
 * wiring and folder-icon persistence. This class owns three core hooks:
 *   - preferences_sections_list : registers the "Icon packs" section
 *   - preferences_list          : renders one checkbox per allowlisted pack
 *   - preferences_save         : installs/removes the packs whose box changed
 *
 * Why checkboxes and not buttons: the section renders inside Roundcube's save-prefs
 * form (prefs_edit.php wraps every block in it). A nested <form> is invalid HTML and
 * the browser drops it, so custom submit buttons fired the parent form WITHOUT our
 * fields — "Successfully saved" with nothing installed. Real preference options are the
 * supported path: the core serializes them, its Save button posts them, and
 * preferences_save receives them in $p['prefs'].
 */
final class PreferencesUI
{
    /** @var IconSpriteCache the plugin's local sprite mirror */
    private $sprites;

    public function __construct(IconSpriteCache $sprites)
    {
        $this->sprites = $sprites;
    }

    /** Register the "Icon packs" section under User interface. */
    public function sectionsList($p)
    {
        $p['list']['iconizer'] = ['id' => 'iconizer', 'section' => 'Icon packs'];

        return $p;
    }

    /**
     * Render one checkbox per allowlisted pack. Checked = installed. The current
     * install state comes from the sprite mirror, not from prefs: prefs only carry the
     * user's intent for THIS request; the filesystem is the source of truth.
     */
    public function list($p)
    {
        if ($p['section'] !== 'iconizer') {
            return $p;
        }

        // On the sections overview (not current), signal our block exists, render nothing.
        if (!$p['current']) {
            $p['blocks']['main']['content'] = true;

            return $p;
        }

        $options = [];
        foreach (IconPacks::PACKS as $prefix => $label) {
            $installed = $this->sprites->isInstalled($prefix);
            $count     = $installed ? $this->sprites->count($prefix) : 0;

            $field = new \html_checkbox([
                'name'  => '_iconizer_pack_' . $prefix,
                'id'    => 'rcmfd_iconizer_' . $prefix,
                'value' => 1,
            ]);

            $title = rcube::Q($label)
                . ($installed ? ' <span class="iconizer-pack-ok">(' . $count . ' icons installed)</span>' : '');

            $options['pack_' . $prefix] = [
                'key'     => 'pack_' . $prefix,
                'title'   => $title,
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

    /**
     * Install/remove packs based on which boxes are checked in this save. A checked
     * box whose pack is not yet installed triggers a download; an unchecked box whose
     * pack IS installed removes it. Idempotent: unchanged boxes do nothing.
     */
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

        // Prefs themselves carry nothing here; the filesystem is the store. Tell the
        // core not to try saving an empty prefs array as a failure.
        $p['prefs'] = [];

        return $p;
    }
}
