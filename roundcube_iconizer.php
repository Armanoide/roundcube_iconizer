<?php
// No namespace: Roundcube requires the global class name "roundcube_iconizer" — implementation lives in src/ (Armanoide\Iconizer).

require_once __DIR__ . '/src/IconResolver.php';
require_once __DIR__ . '/src/IconPacks.php';
require_once __DIR__ . '/src/IconRenderer.php';
require_once __DIR__ . '/src/IconFetcher.php';
require_once __DIR__ . '/src/IconStore.php';
require_once __DIR__ . '/src/OfflineIcons.php';
require_once __DIR__ . '/src/IconSpriteCache.php';
require_once __DIR__ . '/src/PreferencesUI.php';
require_once __DIR__ . '/src/ClientBridge.php';
require_once __DIR__ . '/src/FolderFormUI.php';
require_once __DIR__ . '/src/FolderIconCommit.php';

use Armanoide\Iconizer\ClientBridge;
use Armanoide\Iconizer\FolderFormUI;
use Armanoide\Iconizer\FolderIconCommit;
use Armanoide\Iconizer\IconRenderer;
use Armanoide\Iconizer\IconResolver;
use Armanoide\Iconizer\IconStore;
use Armanoide\Iconizer\IconSpriteCache;
use Armanoide\Iconizer\PreferencesUI;

// Map keyed by raw IMAP folder name — rename migrates entries, delete forgets them.
class roundcube_iconizer extends rcube_plugin
{
    public $task = '.*';

    /** @var IconStore */
    public $store;

    /** @var IconResolver */
    public $resolver;

    /** @var string user id the store is bound to */
    private $bound_user = '';

    public function init(): void
    {
        $rcmail = rcmail::get_instance();

        // Inline, not include_script: iframe save responses drop registered scripts (same for picker.js).
        $this->include_stylesheet('styles/iconizer.css');
        $this->bridge()->arm((string) @file_get_contents(__DIR__ . '/iconizer.js'));

        // One provider for every publish/republish/rescue — no stale snapshots.
        $this->bridge()->provideMap(fn(): array => [
            'map'       => $this->icon_map(),
            'installed' => $this->sprites()->installed(),
            'base'      => $this->sprite_base(),
        ]);

        $this->add_hook('ready', [$this, 'publish_icons']);
        // send_page net: fires with the final HTML even if another plugin's output->reset() wiped env.
        $this->add_hook('send_page', [$this, 'ensure_map_in_html']);
        // AJAX replies only — the folder form posts as a classic POST, persisted in on_folder_update.
        $this->add_hook('render_response', [$this, 'on_render_response']);

        if ($rcmail->task === 'settings') {
            $this->add_hook('folder_form', [$this, 'folder_form']);
            // Preferences → "Icon packs": install/remove the sprite mirrors.
            $prefs = $this->prefsUI();
            $this->add_hook('preferences_sections_list', [$prefs, 'sectionsList']);
            $this->add_hook('preferences_list', [$prefs, 'list']);
            $this->add_hook('preferences_save', [$prefs, 'save']);
            // register_action forces a "plugin." prefix — cannot shadow save-folder, so commit in folder_update.
            $this->add_hook('folder_update', [$this, 'on_folder_update']);
        }

        $this->add_hook('folder_rename', [$this, 'on_folder_rename']);
        $this->add_hook('folder_delete', [$this, 'on_folder_delete']);

        // Shutdown net for paths that reach it before on_folder_update flushes.
        rcube::get_instance()->add_shutdown_function([$this, 'flush_pending_icon']);

        $this->register_action('plugin.iconizer-icon', [$this, 'icon_svg']);
    }

    /** Bind the store/resolver to the authenticated user (id exists post-login). */
    private function bind_store(): void
    {
        $rcmail = rcmail::get_instance();
        // A null rcmail/user pre-session would fatal on ->ID and silently kill every hook.
        $user = (string) ($rcmail && $rcmail->user ? $rcmail->user->ID : 'guest');
        if ($this->store && $user === $this->bound_user) {
            return;
        }

        $this->bound_user = $user;
        $this->store = new IconStore();

        // Server prefs are the source of truth; the store is this request's working copy.
        $prefs = ($rcmail && $rcmail->user) ? (array) $rcmail->user->get_prefs() : [];
        $this->store->hydrate(isset($prefs[FolderIconCommit::PREFS_KEY]) ? (array) $prefs[FolderIconCommit::PREFS_KEY] : []);

        $this->resolver = new IconResolver($this->store->overrides());
    }

    /** @return array<string, array{ref:string,svg:string}> folder→icon, raw IMAP keys. */
    private function icon_map(): array
    {
        $icons = [];
        foreach ($this->store->overrides() as $folder => $ref) {
            // Ref outside the allowlist: publish nothing, Elastic's glyph takes over.
            if (!$this->resolver || !$this->resolver->isAllowed($ref)) {
                continue;
            }

            $parsed = IconResolver::parse($ref);
            $pack   = $parsed ? $parsed[0] : '';

            // Installed packs paint from the local sprite; inline SVG is the no-sprite fallback.
            $svg = '';
            if (!$this->sprites()->isInstalled($pack)) {
                $cached = $this->store->svg($folder);
                // A cached SVG is valid only for its own ref; a stale cache must not override.
                $svg = $cached !== null && $cached !== '' ? IconRenderer::svg($cached) : '';
            }

            $icons[$folder] = [
                'ref' => $ref,
                'svg' => $svg,
            ];
        }

        return $icons;
    }

    /** Publish the folder→icon map to env. */
    public function publish_icons($args = [])
    {
        $this->bind_store();
        $this->bridge()->publish();

        return is_array($args) ? $args : true;
    }

    /** Safety net + paint hint on the final HTML (see ClientBridge::rescueMap). */
    public function ensure_map_in_html($args)
    {
        $this->bind_store();

        return $this->bridge()->rescueMap($args);
    }

    /** Interactive Icon field in the folder edit form (see FolderFormUI). */
    public function folder_form($args)
    {
        $this->bind_store();

        return $this->formUI()->form($this->store, $args);
    }

    /** Commit the icon chosen in the folder form (see FolderIconCommit). */
    public function on_folder_update($args)
    {
        $this->bind_store();

        return $this->commit()->fromFolderForm($this->store, $this->resolver, $args);
    }

    /** Piggyback the fresh map onto genuine AJAX responses. */
    public function on_render_response($args)
    {
        $this->bind_store();
        $this->commit()->flush($this->store);

        if (!empty($args['response']) && is_array($args['response'])) {
            $args['response']['env'] = ($args['response']['env'] ?? []) + ['iconizer_icons' => $this->icon_map()];
        }

        return $args;
    }

    /** Shutdown net: commit any icon still pending (see FolderIconCommit::flush). */
    public function flush_pending_icon(): void
    {
        $this->bind_store();
        $this->commit()->flush($this->store);
    }

    /** Migrate the icon of a renamed folder (and its subfolders) to the new name. */
    public function on_folder_rename($args)
    {
        $this->bind_store();

        return $this->commit()->renamed($this->store, $args);
    }

    /** Forget the icon of a deleted folder (and its subfolders). */
    public function on_folder_delete($args)
    {
        $this->bind_store();

        return $this->commit()->deleted($this->store, $args);
    }

    /** Serve a resolved SVG for one folder, caching the body in the store. */
    public function icon_svg(): void
    {
        $this->bind_store();

        $folder = rcube_utils::get_input_string('_folder', rcube_utils::INPUT_GET, true);
        $ref    = rcube_utils::get_input_string('_icon', rcube_utils::INPUT_GET);

        if ($folder === '' || !$this->resolver->isAllowed($ref)) {
            http_response_code(400);
            exit;
        }

        // Installed packs paint from the local sprite; inline fetch is the fallback only.
        $parsed = IconResolver::parse($ref);
        $pack   = $parsed ? $parsed[0] : '';
        if ($this->sprites()->isInstalled($pack)) {
            http_response_code(404); // the sprite serves this ref; no inline endpoint
            exit;
        }

        $svg = $this->store->svg($folder);
        if ($svg === null || $svg === '') {
            $body = $this->commit()->cacheBodyFor($ref);
            if ($body === null) {
                http_response_code(502);
                exit;
            }
            $this->store->put($folder, $ref, $body);
            $this->commit()->savePrefs($this->store);
        }

        header('Content-Type: image/svg+xml');
        echo IconRenderer::svg((string) $this->store->svg($folder));
        exit;
    }

    /** @var IconSpriteCache|null built lazily per request */
    private $sprites = null;

    private function sprites(): IconSpriteCache
    {
        if ($this->sprites === null) {
            $this->sprites = new IconSpriteCache(__DIR__ . '/sprites', __DIR__ . '/data');
        }

        return $this->sprites;
    }

    /** @var ClientBridge|null built lazily per request */
    private $bridge = null;

    private function bridge(): ClientBridge
    {
        if ($this->bridge === null) {
            $this->bridge = new ClientBridge();
        }

        return $this->bridge;
    }

    /** @var FolderFormUI|null built lazily per request */
    private $formUI = null;

    private function formUI(): FolderFormUI
    {
        if ($this->formUI === null) {
            $this->formUI = new FolderFormUI();
        }

        return $this->formUI;
    }

    /** @var FolderIconCommit|null built lazily per request */
    private $commit = null;

    private function commit(): FolderIconCommit
    {
        if ($this->commit === null) {
            $this->commit = new FolderIconCommit($this->sprites(), $this->bridge());
        }

        return $this->commit;
    }

    /** Sprite URL base, routed through the core's static.php asset endpoint. */
    private function sprite_base(): string
    {
        $out = rcmail::get_instance()->output;

        return $out && method_exists($out, 'asset_url')
            ? $out->asset_url($this->urlbase . 'sprites/')
            : $this->urlbase . 'sprites/';
    }

    /** @var PreferencesUI|null built lazily per request */
    private $prefsui = null;

    private function prefsUI(): PreferencesUI
    {
        if ($this->prefsui === null) {
            $this->prefsui = new PreferencesUI($this->sprites());
        }

        return $this->prefsui;
    }
}
