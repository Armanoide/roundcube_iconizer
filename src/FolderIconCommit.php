<?php

declare(strict_types=1);

namespace Armanoide\Iconizer;

/** Persistence for folder icons: a plugin cannot shadow save-folder (register_action forces a "plugin." prefix), so the icon rides the form as _iconizer_icon and commits in folder_update. */
final class FolderIconCommit
{
    /** User preference key holding the whole folder→icon map. */
    public const PREFS_KEY = 'iconizer';

    /** @var string|null icon chosen in the folder form being saved */
    private ?string $pendingIcon = null;

    /** @var string|null folder being edited (save-folder POST) */
    private ?string $pendingFolder = null;

    /** @var bool guard: never persist a half-saved rename */
    private bool $saveRan = false;

    public function __construct(
        private IconSpriteCache $sprites,
        private ClientBridge $bridge,
    ) {
    }

    /** Commit at folder_update, not shutdown: the response HTML is generated before shutdown functions run — a late flush would show the old icon until reload. */
    public function fromFolderForm(IconStore $store, IconResolver $resolver, array $args): array
    {
        $icon = trim(\rcube_utils::get_input_string('_iconizer_icon', \rcube_utils::INPUT_POST));

        if ($icon !== '' && !$resolver->isAllowed($icon)) {
            $rcmail = \rcmail::get_instance();
            $rcmail->output->command('display_message', $rcmail->gettext('errorsaving'), 'error');
            $args['abort']    = true;
            $this->pendingIcon   = null;
            $this->pendingFolder = null;

            return $args; // pack outside the allowlist: the core writes nothing
        }

        $this->pendingIcon   = $icon;
        $this->pendingFolder = (string) ($args['record']['name'] ?? '');

        // Deep-link iframes have no tree in the main window — the paint hint rides rescueMap instead.
        $this->saveRan = true;
        $this->bridge->notePaintHint((string) ($args['record']['oldname'] ?: $args['record']['name'] ?? ''));
        $this->flush($store);

        return $args;
    }

    /** Commit the stashed icon after a completed save-folder (see $saveRan). */
    public function flush(IconStore $store): void
    {
        if (!$this->saveRan) {
            return;
        }

        $this->saveRan = false;

        if ($this->pendingIcon === null || $this->pendingFolder === '') {
            return;
        }

        $rcmail    = \rcmail::get_instance();
        $delimiter = $rcmail->get_storage()->get_hierarchy_delimiter();
        $old       = \rcube_utils::get_input_string('_mbox', \rcube_utils::INPUT_POST, true);

        if ($old !== '' && $old !== $this->pendingFolder) {
            // The form renamed the folder: the icon follows.
            $store->rename($old, $this->pendingFolder, $delimiter);
        }

        if ($this->pendingIcon === '') {
            $store->forget($this->pendingFolder); // remove the icon -> standard glyph
        } else {
            $store->put($this->pendingFolder, $this->pendingIcon, $this->cacheBodyFor($this->pendingIcon));
        }

        // On a rename the parent still carries the OLD row — paint that; the observer catches the new one.
        $paintTarget = $old !== '' && $old !== $this->pendingFolder ? $old : $this->pendingFolder;

        if ($this->savePrefs($store)) {
            $this->bridge->republish(); // the AJAX response reports the fresh map

            // 'parent.' prefix: the paint reaches the main frame whether or not this request answered with an iframe.
            $rcmail->output->command('parent.iconizer_paint', $paintTarget);

            // The core stays silent on icon-only changes (it says 'folderupdated' only on create/rename).
            $rcmail->output->show_message('folderupdated', 'confirmation');

            $this->bridge->notePaintHint($paintTarget);
        }

        $this->pendingIcon   = null;
        $this->pendingFolder = null;
    }

    /** folder_rename hook: migrate the icon of a renamed folder (and subfolders). */
    public function renamed(IconStore $store, array $args): array
    {
        $old = (string) ($args['oldname'] ?? '');
        $new = (string) ($args['newname'] ?? '');

        if ($old !== '' && $new !== '' && $old !== $new) {
            $delimiter = $this->delimiter();

            if ($store->get($old) !== null || $store->hasChildren($old, $delimiter)) {
                $store->rename($old, $new, $delimiter);
                if ($this->savePrefs($store)) {
                    $this->bridge->republish(); // the icon follows the rename without a reload
                }
            }
        }

        return $args;
    }

    /** folder_delete hook: forget the icon of a deleted folder (and subfolders). */
    public function deleted(IconStore $store, array $args): array
    {
        $name = (string) ($args['name'] ?? '');
        if ($name === '') {
            return $args;
        }

        $delimiter = $this->delimiter();
        if ($store->get($name) === null && !$store->hasChildren($name, $delimiter)) {
            return $args; // nothing to clean, avoid a needless prefs write
        }

        $store->forgetTree($name, $delimiter);

        if ($this->savePrefs($store)) {
            $this->bridge->republish(); // the row regains its standard glyph, no reload
        }

        return $args;
    }

    /** Write the store back to the user's preferences (server-side persistence). */
    public function savePrefs(IconStore $store): bool
    {
        $rcmail = \rcmail::get_instance();

        return (bool) ($rcmail && $rcmail->user && $rcmail->user->save_prefs([self::PREFS_KEY => $store->all()]));
    }

    // Sprite packs cache nothing; an uninstalled pack re-fetches on every save.
    public function cacheBodyFor(string $ref): ?string
    {
        $parsed = IconResolver::parse($ref);
        if ($parsed && $this->sprites->isInstalled($parsed[0])) {
            return null;
        }

        return (new IconFetcher())->fetch($ref);
    }

    private function delimiter(): string
    {
        return \rcube::get_instance()->get_storage()->get_hierarchy_delimiter();
    }
}
