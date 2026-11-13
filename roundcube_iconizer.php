<?php
/**
 * Plugin entry point. Roundcube loads this file and expects a class named
 * exactly "roundcube_iconizer" in the global namespace (rcube_plugin_api
 * checks class_exists($name, false) right after the include), so no
 * declare(strict_types) and no namespace here — the implementation lives in
 * src/ under Armanoide\Iconizer.
 */

require_once __DIR__ . '/src/IconResolver.php';
require_once __DIR__ . '/src/IconPacks.php';
require_once __DIR__ . '/src/IconRenderer.php';
require_once __DIR__ . '/src/IconFetcher.php';
require_once __DIR__ . '/src/IconStore.php';
require_once __DIR__ . '/src/OfflineIcons.php';

use Armanoide\Iconizer\IconFetcher;
use Armanoide\Iconizer\IconPacks;
use Armanoide\Iconizer\IconRenderer;
use Armanoide\Iconizer\IconResolver;
use Armanoide\Iconizer\IconStore;
use Armanoide\Iconizer\OfflineIcons;

/**
 * Roundcube plugin: custom per-folder icons in the Elastic sidebar.
 *
 * Card 02 — the folder edit form carries an interactive Icon field (searchable
 * picker over the allowed packs, live preview); saving persists the choice in
 * the user's Roundcube preferences (`iconizer` key of users.preferences, JSON —
 * no schema, per the card constraints). The map is keyed by raw IMAP folder
 * name and the client reads each row's anchor rel attribute — no id encoding on
 * either side. Rename migrates the entries; delete forgets them; an empty pick
 * removes the override and Elastic's standard glyph comes back.
 */
class roundcube_iconizer extends rcube_plugin
{
    public $task = '.*';

    /** prefs namespace of this plugin (users.preferences key) */
    private const PREFS_KEY = 'iconizer';

    /** @var IconStore */
    public $store;

    /** @var IconResolver */
    public $resolver;

    /** @var string user id the store is currently bound to */
    private $bound_user = '';

    /** @var string|null icon chosen in the save-folder form being processed */
    private $pending_icon = null;

    /** @var string|null raw IMAP name of the folder being edited (save-folder POST) */
    private $pending_folder = null;

    /** @var bool save-folder ran to completion on this request (guard: never
     *  persist a half-saved rename) */
    private $save_ran = false;

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
        // render_response fires on AJAX responses only (rcmail_output_json::remote_-
        // response, rcmail_output_json.php:252). The folder edit form submits as a
        // CLASSIC POST — elastic's Save runs rcmail.command('save') → form.submit()
        // (program/js/app.js:1128) — so the output object stays rcmail_output_html
        // (rcmail.php:145-147: json_init only with the X-Roundcube-Request header)
        // and render_response NEVER fires on save-folder. Persistence therefore
        // happens in flush_pending_icon at request shutdown (see init below); this
        // hook only piggybacks the fresh map onto genuine AJAX responses.
        $this->add_hook('render_response', [$this, 'on_render_response']);

        if ($rcmail->task === 'settings') {
            $this->add_hook('folder_form', [$this, 'folder_form']);

            // The folder edit form submits to the core save-folder action. A plugin
            // cannot shadow a core action: rcube_plugin_api::register_action() force-
            // prefixes every registration with "plugin." (rcube_plugin_api.php:523),
            // and rcmail::dispatch() only routes a request to a plugin when the action
            // literally starts with "plugin." (rcmail.php:266) or the task is a plugin
            // task. "_action=save-folder" resolves to the core class via the settings
            // index alias table (settings/index.php:42 → rcmail_action_settings_folder_-
            // save), so our handler would never run. The folder_update hook IS fired on
            // this request (folder_save.php:156) with the submitted record, before the
            // IMAP write — so we stash the icon there and commit it at request
            // shutdown (flush_pending_icon), after the core's own save_prefs ran.
            $this->add_hook('folder_update', [$this, 'on_folder_update']);
        }

        // Sidebar renames (folder_rename) and deletes (folder_delete) are the
        // core's own rename paths — migrate/forget on success, mirroring how
        // the core migrates message_threading there.
        $this->add_hook('folder_rename', [$this, 'on_folder_rename']);
        $this->add_hook('folder_delete', [$this, 'on_folder_delete']);

        // Persistence net for the classic-POST save-folder path: rcube::shutdown()
        // (rcube.php:1087, register_shutdown_function at rcube.php:124) runs
        // rcmail::shutdown() before the DB/session close on EVERY request,
        // including the plain form POST where render_response and send_page never
        // fire (folder_save.php:217 hands off to edit-folder, whose send() exits
        // inside the dispatcher loop at rcmail.php:310).
        rcube::get_instance()->add_shutdown_function([$this, 'flush_pending_icon']);

        $this->register_action('plugin.iconizer-icon', [$this, 'icon_svg']);
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

    /** Republish the map after a write so AJAX responses repaint the sidebar. */
    private function republish(): void
    {
        $rcmail = rcmail::get_instance();
        if ($rcmail->output) {
            $rcmail->output->set_env('iconizer_icons', $this->icon_map());
        }
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
     * Interactive Icon field in the folder edit form (card 02).
     *
     * The visible control is a button showing the current icon; the submitted
     * value is a hidden input, so the core's get_form_part() (label + raw
     * value) stays untouched. Clicking the button opens the plugin's searchable
     * picker (picker.js) over the allowed packs (ADR 0002), with live preview
     * before save. The core re-renders this form after a failed save without the
     * plugin's registered scripts, so both markup and runtime are inlined here.
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

        // Le pack affiché par défaut au clic : celui du choix courant, lucide sinon.
        $pack = IconResolver::parse($current)[0] ?? IconPacks::prefixes()[0];

        $preview = $svg !== ''
            ? $svg
            : '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" '
                . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                . '<path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>';

        $hidden = new html_hiddenfield(['name' => '_iconizer_icon', 'id' => '_iconizer_icon', 'value' => $current]);

        $button = '<button type="button" id="iconizer-picker-button" class="btn iconizer-form-icon"'
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
        // "save" existe déjà dans le cœur ; les libellés iconizer* sont définis
        // en dur côté picker.js (le cœur ne charge pas de localization plugin ici).
        $rcmail->output->add_label('save');

        return $args;
    }

    /**
     * Stash the icon chosen in the folder edit form. Fired by the core's
     * save-folder action (folder_save.php:156) with the submitted record before
     * the IMAP write. We do not persist here: a failed create/rename must not
     * leave prefs behind (the core's own message_threading pattern commits only
     * after success). The name is UTF7-IMAP — the same raw key the client reads
     * from each row anchor's rel attribute.
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

            return $args; // pack hors allowlist : le cœur n'écrit rien
        }

        $this->pending_icon   = $icon;
        $this->pending_folder = (string) ($args['record']['name'] ?? '');

        // save-folder always ends at overwrite_action('edit-folder') (the last
        // statement of folder_save.php's run()) — reaching here means the core's
        // checks passed; the icon is committed in flush_pending_icon at request
        // shutdown, after the core's own prefs writes (message_threading).
        $this->save_ran = true;

        return $args;
    }

    /**
     * Persist the stashed icon once the core's save-folder has run. Two paths:
     * AJAX (render_response, rcmail_output_json::remote_response) and classic
     * POST (rcube shutdown — the form submits via form.submit(), app.js:1128,
     * so no JSON output object exists on that request and render_response never
     * fires; the html path re-renders edit-folder and exits inside the
     * dispatcher, rcmail.php:310). Idempotent via $save_ran.
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
            // Le formulaire a renommé le dossier : l'icône suit (carte 02, AC 7).
            $this->store->rename($old, $this->pending_folder, $delimiter);
        }

        if ($this->pending_icon === '') {
            $this->store->forget($this->pending_folder); // retirer l'icône → glyphe standard
        } else {
            $this->store->put($this->pending_folder, $this->pending_icon, $this->fetchBody($this->pending_icon));
        }

        if ($this->save_prefs()) {
            $this->republish(); // la réponse AJAX rapporte la carte à jour au client
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
                    $this->republish(); // l'icône suit le renommage sans rechargement
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
            return $args; // rien à nettoyer, pas d'écriture de prefs inutile
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

    /**
     * Inline the picker runtime: the iconify.min.js loader never registers a
     * searchable picker component, so the plugin ships its own (picker.js),
     * inlined into the folder-edit page. Inlining is deliberate: the core drops
     * registered script files when it re-renders this form after a failed save
     * (rcmail_output_html::write(), framed branch), inline scripts survive.
     */
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
