<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/** Icon field of the folder form, fully inlined: the core re-renders this form after a failed save without registered scripts. */
final class FolderFormUI
{
    /** Fallback folder glyph shown when the folder has no icon yet. */
    private const PLACEHOLDER_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>';

    /** folder_form hook: inject the Icon field; store passed per call so a rebind can never go stale. */
    public function form(IconStore $store, $args)
    {
        $folder = (string) ($args['name'] ?? '');
        if ($folder === '') {
            return $args; // create mode: no folder to key an icon by yet
        }

        $current = (string) ($store->get($folder) ?? '');
        $svg     = (string) ($store->svg($folder) ?? '');

        // An empty data-pack must yield "" ("All" preselected), not the first pack.
        $parsed = IconResolver::parse($current);
        $pack   = $parsed ? $parsed[0] : '';

        $hidden = new \html_hiddenfield(['name' => '_iconizer_icon', 'id' => '_iconizer_icon', 'value' => $current]);

        // id="_icon" so the core's <label for="_icon"> resolves; the value stays hidden.
        $button = '<button type="button" id="_icon" class="btn iconizer-form-icon"'
            . ' data-pack="' . htmlspecialchars($pack, ENT_QUOTES) . '"'
            . ' title="' . htmlspecialchars($current !== '' ? $current : 'Choose an icon', ENT_QUOTES) . '">'
            . ($svg !== '' ? $svg : self::PLACEHOLDER_SVG)
            . '<span class="iconizer-ref">' . htmlspecialchars($current !== '' ? $current : 'None', ENT_QUOTES) . '</span>'
            . '<span class="d-none" id="iconizer-form-svg">' . htmlspecialchars($svg, ENT_QUOTES) . '</span>'
            . '</button>';

        $args['form']['props']['fieldsets']['location']['content']['icon'] = [
            'label' => 'Icon',
            'value' => $button . $hidden->show() . self::pickerMarkup(),
        ];

        // The core loads no plugin localization on this form — iconizer* labels are hardcoded in picker.js.
        \rcmail::get_instance()->output->add_label('save');

        return $args;
    }

    /** Inline picker.js — registered scripts die when the core re-renders this form after a failed save. */
    public static function pickerMarkup(): string
    {
        $path = __DIR__ . '/../picker.js';
        if (!is_readable($path)) {
            return '';
        }

        return '<script>' . (string) file_get_contents($path) . '</script>';
    }
}
