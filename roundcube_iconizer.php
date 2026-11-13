<?php
// Roundcube loads this file and expects a global class named "roundcube_iconizer",
// so no namespace here — the implementation lives in src/ under Armanoide\Iconizer.

require_once __DIR__ . '/src/IconResolver.php';
require_once __DIR__ . '/src/IconPacks.php';
require_once __DIR__ . '/src/IconRenderer.php';
require_once __DIR__ . '/src/IconFetcher.php';
require_once __DIR__ . '/src/IconStore.php';
require_once __DIR__ . '/src/OfflineIcons.php';
require_once __DIR__ . '/src/IconSpriteCache.php';
require_once __DIR__ . '/src/PreferencesUI.php';

use Armanoide\Iconizer\IconFetcher;
use Armanoide\Iconizer\IconPacks;
use Armanoide\Iconizer\IconRenderer;
use Armanoide\Iconizer\IconResolver;
use Armanoide\Iconizer\IconStore;
use Armanoide\Iconizer\OfflineIcons;
use Armanoide\Iconizer\IconSpriteCache;
use Armanoide\Iconizer\PreferencesUI;

// Custom per-folder icons in the Elastic sidebar. The map is keyed by raw IMAP
// folder name; rename migrates entries, delete forgets them.
class roundcube_iconizer extends rcube_plugin
{
    public $task = '.*';

    private const PREFS_KEY = 'iconizer';

    /** @var IconStore */
    public $store;

    /** @var IconResolver */
    public $resolver;

    /** @var string user id the store is bound to */
    private $bound_user = '';

    /** @var string|null icon chosen in the folder form being saved */
    private $pending_icon = null;

    /** @var string|null folder being edited (save-folder POST) */
    private $pending_folder = null;

    /** @var bool guard: never persist a half-saved rename */
    private $save_ran = false;

    /** @var string|null folder to repaint after this save (rides inline, iframe drops scripts) */
    private $pending_paint_folder = null;

    /** @var string|null SVG for $pending_paint_folder */
    private $pending_paint_svg = null;

    /** @var bool whether the map was published this request */
    private $map_published = false;

    /** folder→icon map keyed by raw IMAP folder name — the same key the client
     *  reads from each row anchor's rel attribute. No encoding on either side. */
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

            // Installed packs paint from the local sprite (ADR 0004); inline SVG is the
            // fallback only when the pack has no sprite.
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

    public function init(): void
    {
        $rcmail = rcmail::get_instance();

        // iconizer.js is inlined, not include_script: the iframe response to a folder
        // save drops registered scripts, inline ones survive (same reason picker.js is).
        $this->include_stylesheet('styles/iconizer.css');
        $this->inline_script((string) @file_get_contents(__DIR__ . '/iconizer.js'));

        $this->add_hook('ready', [$this, 'publish_icons']);
        // send_page is the safety net: it fires with the final HTML even if a plugin's
        // output->reset() wiped env before write().
        $this->add_hook('send_page', [$this, 'ensure_map_in_html']);
        // AJAX only; the folder form posts as a classic POST, so persistence lives in
        // on_folder_update. This just piggybacks the fresh map onto real AJAX replies.
        $this->add_hook('render_response', [$this, 'on_render_response']);

        if ($rcmail->task === 'settings') {
            $this->add_hook('folder_form', [$this, 'folder_form']);
            // Preferences → "Icon packs": install/remove the sprite mirrors (ADR 0004).
            $prefs = $this->prefsUI();
            $this->add_hook('preferences_sections_list', [$prefs, 'sectionsList']);
            $this->add_hook('preferences_list', [$prefs, 'list']);
            $this->add_hook('preferences_save', [$prefs, 'save']);
            // A plugin cannot shadow the core save-folder action (register_action forces a
            // "plugin." prefix), so we commit the icon in folder_update instead.
            $this->add_hook('folder_update', [$this, 'on_folder_update']);
        }

        $this->add_hook('folder_rename', [$this, 'on_folder_rename']);
        $this->add_hook('folder_delete', [$this, 'on_folder_delete']);

        // Shutdown net for paths that reach it before on_folder_update flushes.
        rcube::get_instance()->add_shutdown_function([$this, 'flush_pending_icon']);

        $this->register_action('plugin.iconizer-icon', [$this, 'icon_svg']);
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

    /** @var PreferencesUI|null built lazily per request */
    private $prefsui = null;

    /** Sprite URL base, routed through the core's static.php asset endpoint. */
    private function sprite_base(): string
    {
        $out = rcmail::get_instance()->output;

        return $out && method_exists($out, 'asset_url')
            ? $out->asset_url($this->urlbase . 'sprites/')
            : $this->urlbase . 'sprites/';
    }

    private function prefsUI(): PreferencesUI
    {
        if ($this->prefsui === null) {
            $this->prefsui = new PreferencesUI($this->sprites());
        }

        return $this->prefsui;
    }

    /** Bind the store/resolver to the authenticated user (id exists post-login). */
    private function bind_store(): void
    {
        $rcmail = rcmail::get_instance();
        // get_instance() can return null before the session exists; a null user
        // would fatal on ->ID and silently kill every hook, leaving no map.
        $user = (string) ($rcmail && $rcmail->user ? $rcmail->user->ID : 'guest');
        if ($this->store && $user === $this->bound_user) {
            return;
        }

        $this->bound_user = $user;
        $this->store = new IconStore();

        // The server prefs are the source of truth (survives reload, logout,
        // other browsers); the store is this request's working copy.
        $prefs = ($rcmail && $rcmail->user) ? (array) $rcmail->user->get_prefs() : [];
        $this->store->hydrate(isset($prefs[self::PREFS_KEY]) ? (array) $prefs[self::PREFS_KEY] : []);

        $this->resolver = new IconResolver($this->store->overrides());
    }

    /** Write the store back to the user's preferences (server-side persistence). */
    private function save_prefs(): bool
    {
        $rcmail = rcmail::get_instance();

        return (bool) ($rcmail && $rcmail->user && $rcmail->user->save_prefs([self::PREFS_KEY => $this->store->all()]));
    }

    /** Inline a plugin script into the page head (survives the core's reset()). */
    private function inline_script(string $js): void
    {
        $rcmail = rcmail::get_instance();
        if ($js !== '' && $rcmail->output && method_exists($rcmail->output, 'add_script')) {
            $rcmail->output->add_script($js);
        }

        // The sidebar lives in the main window, not this settings iframe. Mirror the
        // painter into the parent so iconizer_paint exists where the tree actually is.
        // Payload is a JSON string literal, NOT wrapped in <script>: add_script() emits
        // the element itself, so literal tags would close it early ("Unexpected token").
        $js      = json_encode($js);
        $mirror  = "(function(){try{var p=window.parent;if(p&&p!==window&&p.document&&!p.__iconizerArmed){"
            . "var d=p.document.createElement('script');d.textContent=JSON.parse({$js});d.id='iconizer-painter';p.document.body.appendChild(d);p.__iconizerArmed=1;}}catch(e){}})();";
        if ($rcmail->output && method_exists($rcmail->output, 'add_script')) {
            $rcmail->output->add_script($mirror);
        }
    }

    /** Publish the folder→icon map to the client, keyed by raw folder name. */
    public function publish_icons($args = [])
    {
        $this->bind_store();

        $rcmail = rcmail::get_instance();
        if ($rcmail->output && $rcmail->output->type === 'html') {
            $rcmail->output->set_env('iconizer_icons', $this->icon_map());
            // Installed packs + sprite base drive the picker and <use href> (ADR 0004).
            $rcmail->output->set_env('iconizer_installed', $this->sprites()->installed());
            $rcmail->output->set_env('iconizer_sprite_base', $this->sprite_base());
            $this->map_published = true;
        }

        return is_array($args) ? $args : true;
    }

    /** Republish the map after a write so AJAX responses repaint the sidebar. */
    private function republish(): void
    {
        $rcmail = rcmail::get_instance();
        if ($rcmail->output) {
            $rcmail->output->set_env('iconizer_icons', $this->icon_map());
            $rcmail->output->set_env('iconizer_installed', $this->sprites()->installed());
            $rcmail->output->set_env('iconizer_sprite_base', $this->sprite_base());
        }
    }

    /**
     * Safety net on the final HTML, and paint hint on the classic-POST path: the
     * iframe response drops registered scripts but keeps inline ones, so the fresh
     * map and the row-repaint hint ride inline here.
     */
    public function ensure_map_in_html($args)
    {
        $this->bind_store();

        $content = (string) ($args['content'] ?? '');
        $hint    = $this->pending_paint_folder !== null && $this->pending_paint_folder !== '';

        if (!$hint && ($this->map_published || strpos($content, 'iconizer_icons') !== false)) {
            return $args;
        }

        $json = json_encode($this->icon_map());
        $pos  = strripos($content, '</head>');
        if ($pos === false) {
            return $args;
        }

        // This inline hint runs last, after the core's replace_folder_row/add_folder_row:
        // it refreshes the parent env and repaints the row. If iconizer.js never ran in
        // the parent (deep-link into edit-folder), the fallback paints after a tick that
        // lets add_folder_row place the new row first.
        $script = "<script>window.rcmail&&(rcmail.env.iconizer_icons={$json});";
        if ($hint) {
            $folder = json_encode($this->pending_paint_folder);
            $svg    = json_encode((string) $this->pending_paint_svg);
            $script .= "window.setTimeout(function(){var r=window.rcmail;if(!r)return;"
                . "var f=" . $folder . ",svg=" . $svg . ";"
                . "if(svg)r.env.iconizer_icons[f]={ref:(r.env.iconizer_icons[f]||{}).ref||'',svg:svg};"
                . "else delete r.env.iconizer_icons[f];"
                . "if(r.iconizer_paint){r.iconizer_paint(f);return;}"
                . "var p=window.parent;if(!p||p===window||!p.document)return;"
                . "var li=p.document.getElementById('rcmli'+r.html_identifier_encode(f));if(!li)return;"
                . "var a=li.querySelector(':scope > a');if(!a)return;"
                . "var o=a.querySelector(':scope > .iconizer-holder');if(o)o.remove();"
                . "if(svg){a.insertAdjacentHTML('afterbegin','<span class=\"iconizer-holder\">'+svg+'</span>');li.classList.add('iconizer-painted');}"
                . "else li.classList.remove('iconizer-painted');},0);";
        }
        $script .= "</script>";

        $args['content'] = substr_replace($content, $script, $pos, 0);

        return $args;
    }

    /**
     * Interactive Icon field in the folder edit form: a button opens picker.js,
     * the submitted value is a hidden input. Markup and runtime are inlined because
     * the core re-renders this form after a failed save without registered scripts.
     */
    public function folder_form($args)
    {
        $this->bind_store();

        $folder = (string) ($args['name'] ?? '');
        if ($folder === '') {
            return $args; // create mode: no folder to key an icon by yet
        }

        $current = (string) ($this->store->get($folder) ?? '');
        $svg     = (string) ($this->store->svg($folder) ?? '');

        // Pack preselected on click: the current choice's pack, otherwise "All"
        // (empty value). An empty data-pack must yield "", not the first pack.
        $parsed = IconResolver::parse($current);
        $pack   = $parsed ? $parsed[0] : '';

        $preview = $svg !== ''
            ? $svg
            : '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" '
                . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                . '<path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>';

        $hidden = new html_hiddenfield(['name' => '_iconizer_icon', 'id' => '_iconizer_icon', 'value' => $current]);

        // id="_icon" so the core's <label for="_icon"> resolves; the value stays hidden.
        $button = '<button type="button" id="_icon" class="btn iconizer-form-icon"'
            . ' data-pack="' . htmlspecialchars($pack, ENT_QUOTES) . '"'
            . ' title="' . htmlspecialchars($current !== '' ? $current : 'Choose an icon', ENT_QUOTES) . '">'
            . $preview
            . '<span class="iconizer-ref">' . htmlspecialchars($current !== '' ? $current : 'None', ENT_QUOTES) . '</span>'
            . '<span class="d-none" id="iconizer-form-svg">' . htmlspecialchars($svg, ENT_QUOTES) . '</span>'
            . '</button>';

        $args['form']['props']['fieldsets']['location']['content']['icon'] = [
            'label' => 'Icon',
            'value' => $button . $hidden->show() . $this->render_picker(),
        ];

        $rcmail = rcmail::get_instance();
        // "save" already exists in the core; the iconizer* labels are hardcoded on
        // the picker.js side (the core loads no plugin localization here).
        $rcmail->output->add_label('save');

        return $args;
    }

    /**
     * Stash the icon chosen in the folder form and commit it immediately. Committing
     * here (not at shutdown) is required: the response HTML is generated before any
     * shutdown function runs, so a late flush would show the old icon until reload.
     */
    public function on_folder_update($args)
    {
        $this->bind_store();

        $icon = trim(rcube_utils::get_input_string('_iconizer_icon', rcube_utils::INPUT_POST));

        if ($icon !== '' && !$this->resolver->isAllowed($icon)) {
            $rcmail = rcmail::get_instance();
            $rcmail->output->command('display_message', $rcmail->gettext('errorsaving'), 'error');
            $args['abort']   = true;
            $this->pending_icon  = null;
            $this->pending_folder = null;

            return $args; // pack outside the allowlist: the core writes nothing
        }

        $this->pending_icon   = $icon;
        $this->pending_folder = (string) ($args['record']['name'] ?? '');

        // Reaching here means the core's checks passed: commit now so the re-rendered
        // form and env carry the fresh icon. flush_pending_icon stays as a safety net.
        $this->save_ran = true;
        // The iframe response relays parent.* commands to the main window; with no tree
        // there (deep-link into edit-folder) the paint rides ensure_map_in_html instead.
        $this->pending_paint_folder = (string) ($args['record']['oldname'] ?: $args['record']['name'] ?? '');
        $this->flush_pending_icon();

        return $args;
    }

    /**
     * Piggyback the fresh map onto genuine AJAX responses. on_folder_update flushes
     * persistence immediately; this is the idempotent net for AJAX paths, guarded by
     * $save_ran so a half-saved rename never persists.
     */
    public function on_render_response($args)
    {
        $this->flush_pending_icon();

        if (!empty($args['response']) && is_array($args['response'])) {
            $this->bind_store();
            $args['response']['env'] = ($args['response']['env'] ?? []) + ['iconizer_icons' => $this->icon_map()];
        }

        return $args;
    }

    /** Commit the stashed icon after a completed save-folder (see $save_ran). */
    public function flush_pending_icon(): void
    {
        if (!$this->save_ran) {
            return;
        }

        $this->save_ran = false;

        if ($this->pending_icon === null || $this->pending_folder === '') {
            return;
        }

        $rcmail    = rcmail::get_instance();
        $delimiter = $rcmail->get_storage()->get_hierarchy_delimiter();
        $old       = rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_POST, true);

        if ($old !== '' && $old !== $this->pending_folder) {
            // The form renamed the folder: the icon follows (card 02, AC 7).
            $this->store->rename($old, $this->pending_folder, $delimiter);
        }

        if ($this->pending_icon === '') {
            $this->store->forget($this->pending_folder); // remove the icon -> standard glyph
        } else {
            // ADR 0004: the sprite paints installed packs, so no inline fetch is
            // needed. Keep the per-icon cache ONLY when the pack has no sprite, so a
            // ref whose pack is uninstalled still renders via the inline fallback.
            $parsed = IconResolver::parse($this->pending_icon);
            $pack   = $parsed ? $parsed[0] : '';
            $cached = $this->sprites()->isInstalled($pack) ? null : $this->fetchBody($this->pending_icon);
            $this->store->put($this->pending_folder, $this->pending_icon, $cached);
        }

        // Paint target: on a rename the parent still carries the OLD row
        // (replace_folder_row swaps it synchronously, then add_folder_row places the
        // new one under the new name — the painter's observer catches up on repaint).
        $paint_target = $old !== '' && $old !== $this->pending_folder ? $old : $this->pending_folder;
        $svg          = $this->store->svg($this->pending_folder);

        if ($this->save_prefs()) {
            $this->republish(); // the AJAX response reports the fresh map to the client

            // 'parent.' prefix guarantees the paint reaches the main frame whether or not
            // this request answered with an iframe.
            $rcmail->output->command('parent.iconizer_paint', $paint_target);

            // Without a rename the core stays silent (it only says 'folderupdated' on
            // create/rename), so we emit the confirmation for an icon-only change.
            $rcmail->output->show_message('folderupdated', 'confirmation');

            // The parent does not receive this request's env (iframe): the fresh SVG
            // travels with the target for ensure_map_in_html.
            $this->pending_paint_folder = $paint_target;
            $this->pending_paint_svg    = $svg !== null && $svg !== '' ? IconRenderer::svg($svg) : '';
        }

        $this->pending_icon   = null;
        $this->pending_folder = null;
    }

    /** Migrate the icon of a renamed folder (and its subfolders) to the new name. */
    public function on_folder_rename($args)
    {
        $old = (string) ($args['oldname'] ?? '');
        $new = (string) ($args['newname'] ?? '');

        if ($old !== '' && $new !== '' && $old !== $new) {
            $this->bind_store();

            if ($this->store->get($old) !== null || $this->hasChildren($old)) {
                $delimiter = rcube::get_instance()->get_storage()->get_hierarchy_delimiter();
                $this->store->rename($old, $new, $delimiter);
                if ($this->save_prefs()) {
                    $this->republish(); // the icon follows the rename without a reload
                }
            }
        }

        return $args;
    }

    /** Forget the icon of a deleted folder (and its subfolders). */
    public function on_folder_delete($args)
    {
        $name = (string) ($args['name'] ?? '');
        if ($name === '') {
            return $args;
        }

        $this->bind_store();

        if ($this->store->get($name) === null && !$this->hasChildren($name)) {
            return $args; // nothing to clean, avoid a needless prefs write
        }

        $delimiter = rcube::get_instance()->get_storage()->get_hierarchy_delimiter();
        $prefix    = $name . $delimiter;

        foreach (array_keys($this->store->overrides()) as $folder) {
            if ($folder === $name || str_starts_with($folder, $prefix)) {
                $this->store->forget($folder);
            }
        }

        if ($this->save_prefs()) {
            $this->republish(); // la ligne retrouve son glyphe standard sans rechargement
        }

        return $args;
    }

    /** Does the store hold any subfolder entry below $folder? */
    private function hasChildren(string $folder): bool
    {
        $delimiter = rcube::get_instance()->get_storage()->get_hierarchy_delimiter();
        $prefix    = $folder . $delimiter;

        foreach (array_keys($this->store->overrides()) as $key) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Resolve the SVG body for caching; null when the API is unreachable. */
    private function fetchBody(string $ref): ?string
    {
        return (new IconFetcher())->fetch($ref);
    }

    /** Inline picker.js: the core drops registered scripts when it re-renders this
     *  form after a failed save, inline ones survive. */
    private function render_picker(): string
    {
        $path = __DIR__ . '/picker.js';
        if (!is_readable($path)) {
            return '';
        }

        return '<script>' . (string) file_get_contents($path) . '</script>';
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

        // ADR 0004: installed packs paint from the local sprite, so no inline fetch.
        // Cache per-icon only when the pack has no sprite (inline fallback path).
        $parsed = IconResolver::parse($ref);
        $pack   = $parsed ? $parsed[0] : '';
        if ($this->sprites()->isInstalled($pack)) {
            http_response_code(404); // the sprite serves this ref; no inline endpoint
            exit;
        }

        $svg = $this->store->svg($folder);
        if ($svg === null || $svg === '') {
            $body = $this->fetchBody($ref);
            if ($body === null) {
                http_response_code(502);
                exit;
            }
            $this->store->put($folder, $ref, $body);
            $this->save_prefs();
        }

        header('Content-Type: image/svg+xml');
        echo IconRenderer::svg((string) $this->store->svg($folder));
        exit;
    }
}
