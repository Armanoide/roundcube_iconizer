// Sidebar painter: replaces Elastic's glyph on mapped rows. Rows keyed by anchor rel (raw IMAP name) — no id decoding.
(function () {
    'use strict';

    function painter() {
        // this = the frame whose tree gets painted; its rcmail carries the env.
        var self = this || window;
        var env = self.rcmail && self.rcmail.env ? self.rcmail.env : null;
        if (!env || !env.iconizer_icons) {
            return;
        }

        var ICONS = env.iconizer_icons;
        // Installed packs paint via same-origin <use> sprites (currentColor, no CDN); entry.svg is the fallback.
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

        function paintRow(li) {
            var entry = entryFor(li);
            if (!entry) return;

            var anchor = li.querySelector(':scope > a');
            if (!anchor) return;

            // Idempotent: many events trigger repaint — drop the old holder before inserting one.
            anchor.querySelectorAll(':scope > .iconizer-holder').forEach(function (n) { n.remove(); });

            var markup = hasSprite(entry.ref) ? spriteUse(entry.ref) : (entry.svg || '');
            if (!markup) {
                // No sprite, no inline cache: the Elastic glyph stays until the pack installs.
                return;
            }
            anchor.insertAdjacentHTML('afterbegin', '<span class="iconizer-holder">' + markup + '</span>');
            li.classList.add('iconizer-painted');
        }

        // Emitted by the classic save-folder POST toward the parent frame (twin of replace_folder_row): paint one row, or the whole tree with no target.
        self.rcmail.iconizer_paint = function (folder) {
            ICONS = (self.rcmail.env && self.rcmail.env.iconizer_icons) || ICONS;

            // Rows live in the parent frame — on an edit-folder deep-link self.document is the iframe's and getElementById misses.
            var doc = (self.parent && self.parent !== self && self.parent.document) ? self.parent.document : self.document;
            var li  = folder ? doc.getElementById('rcmli' + self.rcmail.html_identifier_encode(folder)) : null;

            if (folder && !li) {
                // No row under the submitted name: a rename (observer catches up) or an icon removal — unpaint painted rows.
                if (ICONS[folder]) return;
                doc.querySelectorAll('.folderlist li.mailbox.iconizer-painted').forEach(unpaintRow);
                return;
            }

            if (li) {
                // A target with no existing row is a no-op, not a wipe — renames go through replace_folder_row + the observer.
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
            // The plugin returns the fresh map in every AJAX response env — the sidebar follows without a reload.
            if (e && e.response && e.response.env && e.response.env.iconizer_icons) {
                ICONS = e.response.env.iconizer_icons;
            }
            paintAll();
        }

        if (self.rcmail && self.rcmail.addEventListener) {
            // Exact core event names: "responsebefore"/"responseafter" — the spaced variants do not exist.
            self.rcmail.addEventListener('responsebefore', paintAll);
            self.rcmail.addEventListener('responseafter', paintAll);
            self.rcmail.addEventListener('folderUpdated', repaintFromPrefs);
            self.rcmail.addEventListener('imap_folders_changed', paintAll);
        }

        // treelist rebuilds rows on search/expand/AJAX; the observer disconnects while mutating so our edits never retrigger it.
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

    // iframe save: the core drops scripts and rewrites commands as parent.rcmail.* — arm and repaint in the parent frame.
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
        // window.rcmail, not the core's bare global — safer in strict mode.
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
