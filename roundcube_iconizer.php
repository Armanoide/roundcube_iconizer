<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/**
 * Roundcube plugin: custom per-folder icons in the Elastic sidebar.
 *
 * Card 01 scope — the tracer bullet: a folder named in plugin config gets its
 * icon rendered as inline SVG in the sidebar, replacing Elastic's glyph. No
 * picker UI, no user table yet (cards 02+). Storage is a JSON file per user so
 * the seam stays testable without a schema migration.
 */
class roundcube_iconizer extends \rcube_plugin
{
    public $task = '.*';

    private IconStore $store;

    private IconResolver $resolver;

    public function init(): void
    {
        $rcmail = \rcmail::get_instance();

        $this->store = new IconStore($this->storePath());
        $this->resolver = new IconResolver($this->store->overrides());

        // Sidebar rows are (re)rendered client-side; paint after each render.
        $this->add_hook('template_after_logo', [$this, 'inject_assets']);

        if ($rcmail->task === 'settings') {
            $this->add_hook('folder_form', [$this, 'folder_form']);
        }

        $this->register_action('plugin.iconizer-icon', [$this, 'icon_svg']);
    }

    /**
     * Add the Iconify loader plus our sidebar painter.
     */
    public function inject_assets(): void
    {
        $this->include_script('https://code.iconify.design/3/3.1.0/iconify.min.js');
        $this->include_stylesheet('styles/iconizer.css');
        $this->include_script('iconizer.js');

        $icons = [];
        foreach ($this->store->overrides() as $folder => $ref) {
            $icons[$folder] = [
                'ref'  => $ref,
                'svg'  => $this->store->svg($folder),
                'url'  => IconRenderer::apiUrl($ref),
            ];
        }

        $rcmail = \rcmail::get_instance();
        $rcmail->output->add_label('iconizer.icon');
        $rcmail->output->set_env('iconizer_icons', $icons);
    }

    /**
     * Show the current icon inside the folder edit form (card 02 grows this).
     */
    public function folder_form($args)
    {
        $folder = $args['name'] ?? '';
        if ($folder === '') {
            return $args;
        }

        $ref = $this->resolver->resolve($folder, false, true, OfflineIcons::all());
        $svg = $this->store->svg($folder);

        $args['form']['props']['fieldsets']['location']['content']['icon'] = [
            'label' => 'Icon',
            'value' => $svg !== null
                ? IconRenderer::svg($svg) . ' <code>' . htmlspecialchars($ref, ENT_QUOTES) . '</code>'
                : '<span class="iconizer-placeholder">' . htmlspecialchars($ref, ENT_QUOTES) . '</span>',
        ];

        return $args;
    }

    /**
     * Serve a resolved SVG for one folder, caching the body in the store.
     */
    public function icon_svg(): void
    {
        $folder = rcube_utils::get_input_string('_folder', \rcube_utils::INPUT_GET, true);
        $ref    = rcube_utils::get_input_string('_icon', \rcube_utils::INPUT_GET);

        if ($folder === '' || !$this->resolver->isAllowed($ref)) {
            http_response_code(400);
            exit;
        }

        $svg = $this->store->svg($folder);
        if ($svg === null) {
            $body = (new IconFetcher())->fetch($ref);
            if ($body === null) {
                http_response_code(502);
                exit;
            }
            $this->store->put($folder, $ref, $body);
        }

        header('Content-Type: image/svg+xml');
        echo IconRenderer::svg($this->store->svg($folder) ?? '');
        exit;
    }

    private function storePath(): string
    {
        $rcmail = \rcmail::get_instance();
        $user   = (string) ($rcmail->user->get_id() ?? 'guest');
        $dir    = __DIR__ . '/data';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return "{$dir}/user-{$user}.json";
    }
}
