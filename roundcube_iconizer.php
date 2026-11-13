<?php
/**
 * Plugin entry point. Roundcube loads this file and expects a class named
 * exactly "roundcube_iconizer" in the global namespace (rcube_plugin_api
 * checks class_exists($name, false) right after the include), so no
 * declare(strict_types) and no namespace here — the implementation lives in
 * src/ under Armanoide\Iconizer.
 */

require_once __DIR__ . '/src/IconResolver.php';
require_once __DIR__ . '/src/IconRenderer.php';
require_once __DIR__ . '/src/IconFetcher.php';
require_once __DIR__ . '/src/IconStore.php';
require_once __DIR__ . '/src/OfflineIcons.php';

use Armanoide\Iconizer\IconFetcher;
use Armanoide\Iconizer\IconRenderer;
use Armanoide\Iconizer\IconResolver;
use Armanoide\Iconizer\IconStore;
use Armanoide\Iconizer\OfflineIcons;

/**
 * Roundcube plugin: custom per-folder icons in the Elastic sidebar.
 *
 * Card 01 scope — the tracer bullet: a folder with a stored mapping gets its
 * icon rendered as inline SVG in the sidebar, replacing Elastic's glyph. No
 * picker UI yet (card 02). The map is keyed by raw IMAP folder name and the
 * client reads each row's anchor rel attribute — no id encoding on either side.
 * Storage is a JSON file per user so the seam stays testable without a schema.
 */
class roundcube_iconizer extends rcube_plugin
{
    public $task = '.*';

    /** @var IconStore */
    public $store;

    /** @var IconResolver */
    public $resolver;

    /** @var string user id the store is currently bound to */
    private $bound_user = '';

    /** @var bool la carte a-t-elle été publiée pendant cette requête */
    private $map_published = false;

    /** folder→icon map keyed by raw IMAP folder name — the same key the client
     *  reads from each row anchor's rel attribute. No encoding on either side. */
    private function icon_map(): array
    {
        $icons = [];
        foreach ($this->store->overrides() as $folder => $ref) {
            $svg = $this->store->svg($folder);
            $icons[$folder] = [
                'ref' => $ref,
                'svg' => $svg !== null && $svg !== '' ? IconRenderer::svg($svg) : '',
            ];
        }

        return $icons;
    }

    public function init(): void
    {
        $rcmail = rcmail::get_instance();

        // Assets are included at init: Roundcube flushes plugin JS/CSS when the
        // page is sent, so they land on every html page.
        $this->include_script('https://code.iconify.design/3/3.1.0/iconify.min.js');
        $this->include_stylesheet('styles/iconizer.css');
        $this->include_script('iconizer.js');

        // ready fires post-authentication, before write(): the env map lands in
        // the normal js_env commands. send_page is the last-resort safety net —
        // it fires with the final HTML, after any plugin output->reset() wiped env.
        $this->add_hook('ready', [$this, 'publish_icons']);
        $this->add_hook('send_page', [$this, 'ensure_map_in_html']);

        if ($rcmail->task === 'settings') {
            $this->add_hook('folder_form', [$this, 'folder_form']);
        }

        $this->register_action('plugin.iconizer-icon', [$this, 'icon_svg']);
    }

    /** Bind the store/resolver to the authenticated user (id exists post-login). */
    private function bind_store(): void
    {
        $rcmail = rcmail::get_instance();
        // get_instance() can return null before the session exists; a null user
        // would fatal on ->ID and silently kill every hook, leaving no map.
        $user   = (string) ($rcmail && $rcmail->user ? $rcmail->user->ID : 'guest');
        if ($this->store && $user === $this->bound_user) {
            return;
        }

        $this->bound_user = $user;
        $this->store = new IconStore($this->storePath());
        $this->resolver = new IconResolver($this->store->overrides());
    }

    /** Publish the folder→icon map to the client, keyed by raw folder name. */
    public function publish_icons($args = [])
    {
        $this->bind_store();

        $rcmail = rcmail::get_instance();
        if ($rcmail->output && $rcmail->output->type === 'html') {
            $rcmail->output->set_env('iconizer_icons', $this->icon_map());
            $this->map_published = true;
        }

        return is_array($args) ? $args : true;
    }

    /**
     * Safety net on the final HTML (fires after every reset/env cycle): if the
     * env command never made it, assign the map inline just before </head>.
     */
    public function ensure_map_in_html($args)
    {
        $this->bind_store();

        $content = (string) ($args['content'] ?? '');
        if ($this->map_published || strpos($content, 'iconizer_icons') !== false) {
            return $args;
        }

        $json = json_encode($this->icon_map());
        $pos  = strripos($content, '</head>');
        if ($pos === false) {
            return $args;
        }

        $args['content'] = substr_replace($content, "<script>window.rcmail&&(rcmail.env.iconizer_icons={$json});</script>", $pos, 0);

        return $args;
    }

    /**
     * Show the current icon inside the folder edit form (card 02 grows this).
     */
    public function folder_form($args)
    {
        $this->bind_store();

        $folder = $args['name'] ?? '';
        if ($folder === '') {
            return $args;
        }

        $ref = $this->resolver ? $this->resolver->resolve($folder, false, true, OfflineIcons::all()) : IconResolver::DEFAULT_ICON;
        $svg = $this->store ? $this->store->svg($folder) : null;

        $args['form']['props']['fieldsets']['location']['content']['icon'] = [
            'label' => 'Icon',
            'value' => $svg !== null && $svg !== ''
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
        $this->bind_store();

        $folder = rcube_utils::get_input_string('_folder', rcube_utils::INPUT_GET, true);
        $ref    = rcube_utils::get_input_string('_icon', rcube_utils::INPUT_GET);

        if ($folder === '' || !$this->resolver->isAllowed($ref)) {
            http_response_code(400);
            exit;
        }

        $svg = $this->store->svg($folder);
        if ($svg === null || $svg === '') {
            $body = (new IconFetcher())->fetch($ref);
            if ($body === null) {
                http_response_code(502);
                exit;
            }
            $this->store->put($folder, $ref, $body);
        }

        header('Content-Type: image/svg+xml');
        echo IconRenderer::svg((string) $this->store->svg($folder));
        exit;
    }

    private function storePath(): string
    {
        $rcmail = rcmail::get_instance();
        $user   = (string) ($rcmail->user ? $rcmail->user->ID : 'guest');
        $dir    = __DIR__ . '/data';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return "{$dir}/user-{$user}.json";
    }
}
