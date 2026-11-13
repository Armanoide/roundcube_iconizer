/**
 * roundcube_iconizer — sidebar painter.
 *
 * Replaces Elastic's folder glyph with the user's icon on rows that have a
 * mapping. Rows are keyed by the anchor's rel attribute — the raw IMAP folder
 * name, published as rcmail.env.iconizer_icons. No id decoding: the core's
 * html_identifier is not reimplemented here (ADR 0003).
 */
(function () {
    'use strict';

    function painter() {
        // this = the window whose tree must be painted (the frame itself, or the
        // parent via armParentFromIframe). window.rcmail carries the env there.
        var self = this || window;
        var env = self.rcmail && self.rcmail.env ? self.rcmail.env : null;
        if (!env || !env.iconizer_icons) {
            return;
        }

        var ICONS = env.iconizer_icons;
        // Packs with a local sprite + the sprite URL base (ADR 0004). When a ref's
        // pack is installed, the row paints from <use href="sprites/<pack>.svg#name">
        // — same-origin, currentColor, no CDN. entry.svg (inline cache) is the fallback.
        var INSTALLED = env.iconizer_installed || [];
        var SPRITE_BASE = env.iconizer_sprite_base || '';

        function hasSprite(ref) {
            var pack = (ref || '').split(':')[0];
            return SPRITE_BASE && INSTALLED.indexOf(pack) >= 0;
        }

        function spriteUse(ref) {
            var parts = (ref || '').split(':');
            return '<svg class="iconizer-icon" width="16" height="16" aria-hidden="true">'
                + '<use href="' + SPRITE_BASE + parts[0] + '.svg#' + parts.slice(1).join(':') + '"></use></svg>';
        }

        function entryFor(li) {
            if (!li || !li.getAttribute) return null;
            var anchor = li.querySelector(':scope > a');
            if (anchor && anchor.getAttribute('rel')) {
                var ref = decodeURIComponent(anchor.getAttribute('rel'));
                if (ICONS[ref]) return ICONS[ref];
            }
            // Fallback: decode the row id with the core's own decoder.
            if (li.id && self.rcmail.folder_id2name) {
                var name = self.rcmail.folder_id2name(li.id);
                if (name && ICONS[name]) return ICONS[name];
            }
            return null;
        }

        function makeIconNode(entry) {
            var holder = self.document.createElement('span');
            holder.className = 'iconizer-holder';

            // Local sprite first (ADR 0004): no CDN, currentColor themes for free.
            if (hasSprite(entry.ref)) {
                holder.innerHTML = spriteUse(entry.ref);
                return holder;
            }

            if (entry.svg) {
                holder.innerHTML = entry.svg;
            }
            // No sprite, no inline cache: leave the holder empty. ADR 0004 forbids a
            // runtime Iconify call; the Elastic glyph stays until the pack is installed.
            return holder;
        }

        function paintRow(li) {
            var entry = entryFor(li);
            if (!entry) return;

            var anchor = li.querySelector(':scope > a');
            if (!anchor) return;

            // Fresh save: the sprite (or inline cache) paints immediately. With neither,
            // leave the row for the Iconify fallback rather than shipping an empty holder.
            var markup = hasSprite(entry.ref) ? spriteUse(entry.ref) : (entry.svg || '');
            if (markup) {
                anchor.insertAdjacentHTML('afterbegin', '<span class="iconizer-holder">' + markup + '</span>');
            } else if (!anchor.querySelector(':scope > .iconizer-holder')) {
                anchor.insertBefore(makeIconNode(entry), anchor.firstChild);
            }
            li.classList.add('iconizer-painted');
        }

        // Plugin command: the classic save-folder POST emits it toward the parent
        // frame (rcmail_output_html.php:843-853) as the twin of replace_folder_row
        // (app.js:8064) which sets the name — same mechanism, on the icon. Paints
        // the targeted row; with no target it paints the whole tree.
        self.rcmail.iconizer_paint = function (folder) {
            ICONS = (self.rcmail.env && self.rcmail.env.iconizer_icons) || ICONS;

            // The tree row lives in the parent frame (subscription-table). On a
            // deep-link into edit-folder, self.document is the iframe's: getElementById
            // misses and nothing is painted. So target the parent document when present.
            var doc = (self.parent && self.parent !== self && self.parent.document) ? self.parent.document : self.document;
            var li  = folder ? doc.getElementById('rcmli' + self.rcmail.html_identifier_encode(folder)) : null;

            if (folder && !li) {
                // Two cases with no row under the submitted name: a rename (the core is
                // replacing the <li> — its add_folder_row + our observer catch up) and
                // removing the icon on an already-painted row (entryFor fails, unpaint).
                if (ICONS[folder]) return;
                doc.querySelectorAll('.folderlist li.mailbox.iconizer-painted').forEach(unpaintRow);
                return;
            }

            if (li) {
                // Self-check: a target with no existing row is a no-op, not a wipe —
                // renames go through replace_folder_row + the observer.
                if (!ICONS[folder] && !li.classList.contains('iconizer-painted')) return;
                if (ICONS[folder]) paintRow(li); else unpaintRow(li);
            } else {
                paintAll();
            }
        };

        function unpaintRow(li) {
            // Entry removed from prefs (icon deleted): the Elastic glyph comes back.
            var anchor = li.querySelector(':scope > a');
            if (!anchor) return;

            var old = anchor.querySelector(':scope > .iconizer-holder');
            if (old) old.remove();
            li.classList.remove('iconizer-painted');
        }

        function paintAll() {
            self.document.querySelectorAll('.folderlist li.mailbox').forEach(function (li) {
                if (entryFor(li)) paintRow(li); else unpaintRow(li);
            });
        }

        function repaintFromPrefs(e) {
            // The plugin returns the fresh map in every AJAX response env (save/rename/
            // delete): the sidebar follows without a manual reload.
            if (e && e.response && e.response.env && e.response.env.iconizer_icons) {
                ICONS = e.response.env.iconizer_icons;
            }
            paintAll();
        }

        if (self.rcmail && self.rcmail.addEventListener) {
            // Exact core event names (app.js:9652/9851): "responsebefore"/"responseafter"
            // — the spaced variants do not exist.
            self.rcmail.addEventListener('responsebefore', paintAll);
            self.rcmail.addEventListener('responseafter', paintAll);
            self.rcmail.addEventListener('folderUpdated', repaintFromPrefs);
            self.rcmail.addEventListener('imap_folders_changed', paintAll);
        }

        // treelist rebuilds rows on search/expand/AJAX; repaint on DOM changes.
        // The observer disconnects while mutating so our own edits never retrigger it.
        if (self.MutationObserver) {
            var list = self.document.querySelector('.folderlist');
            if (list) {
                var observer = new self.MutationObserver(function () {
                    observer.disconnect();
                    paintAll();
                    observer.observe(list, { childList: true, subtree: true });
                });
                observer.observe(list, { childList: true, subtree: true });
            }
        }

        if (self.document.readyState === 'loading') {
            self.document.addEventListener('DOMContentLoaded', paintAll);
        } else {
            paintAll();
        }
    }

    // The classic save-folder POST answers send('iframe'): every command is rewritten
    // as parent.rcmail.<cmd>() (rcmail_output_html.php:843-853) and write() empties
    // script_files AND $this->scripts (:655-660) — so the iframe carries neither this
    // painter nor its own rcmail (skins/elastic/ui.js: `rcmail=parent.rcmail` when
    // window.rcmail is absent). The visible tree (subscriptionlist, app.js:7882/8065)
    // lives in the parent frame, so that is where the painter must be armed. When this
    // script runs inside an iframe it installs its painter into the parent (this=parent:
    // window, document and the inner rcmail become the parent's) and repaints its tree
    // with the current env map — the frame's when it carries one (full page), otherwise
    // the one ensure_map_in_html's inline hint just wrote there.
    function armParentFromIframe() {
        var P = window.parent;
        try {
            if (!P || P === window || !P.rcmail || !P.document) return;
            if (!P.document.querySelector('.folderlist')) return; // no tree: nothing to paint

            var icons = (window.rcmail && window.rcmail.env && window.rcmail.env.iconizer_icons)
                || (P.rcmail.env && P.rcmail.env.iconizer_icons);
            if (!icons) return;

            P.rcmail.env.iconizer_icons = icons;
            painter.call(P); // defines P.rcmail.iconizer_paint and paints the parent tree
        } catch (e) {
            // cross-origin parent: nothing to repaint here
        }
    }

    function boot() {
        // The core's global `var rcmail` (rcmail_output_html.php:147) makes the bare
        // reference possible; window.rcmail is safer in strict mode.
        if (window.rcmail && window.rcmail.env && window.rcmail.env.iconizer_icons) {
            painter.call(window);
        } else if (window.parent && window.parent !== window) {
            armParentFromIframe();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
