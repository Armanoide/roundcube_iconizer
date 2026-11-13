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
        var env = window.rcmail && rcmail.env ? rcmail.env : null;
        if (!env || !env.iconizer_icons) {
            return;
        }

        var ICONS = env.iconizer_icons;

        function entryFor(li) {
            if (!li || !li.getAttribute) return null;
            var anchor = li.querySelector(':scope > a');
            if (anchor && anchor.getAttribute('rel')) {
                var ref = decodeURIComponent(anchor.getAttribute('rel'));
                if (ICONS[ref]) return ICONS[ref];
            }
            // Fallback: decode the row id with the core's own decoder.
            if (li.id && rcmail.folder_id2name) {
                var name = rcmail.folder_id2name(li.id);
                if (name && ICONS[name]) return ICONS[name];
            }
            return null;
        }

        function makeIconNode(entry) {
            var holder = document.createElement('span');
            holder.className = 'iconizer-holder';

            if (entry.svg) {
                holder.innerHTML = entry.svg;
                return holder;
            }

            if (window.Iconify) {
                var node = Iconify.renderSVG ? Iconify.renderSVG(entry.ref, { height: 16, width: 16 }) : null;
                if (node) {
                    node.classList.add('iconizer-icon');
                    holder.appendChild(node);
                } else {
                    var ph = document.createElement('span');
                    ph.className = 'iconify';
                    ph.setAttribute('data-icon', entry.ref);
                    holder.appendChild(ph);
                    if (Iconify.scan) Iconify.scan(holder);
                }
            }
            return holder;
        }

        function paintRow(li) {
            var entry = entryFor(li);
            if (!entry) return;

            var anchor = li.querySelector(':scope > a');
            if (!anchor) return;

            var old = anchor.querySelector(':scope > .iconizer-holder');
            if (old) old.remove();

            anchor.insertBefore(makeIconNode(entry), anchor.firstChild);
            li.classList.add('iconizer-painted');
        }

        function unpaintRow(li) {
            // Entrée retirée des prefs (icône supprimée) : le glyphe Elastic revient.
            var anchor = li.querySelector(':scope > a');
            if (!anchor) return;

            var old = anchor.querySelector(':scope > .iconizer-holder');
            if (old) old.remove();
            li.classList.remove('iconizer-painted');
        }

        function paintAll() {
            document.querySelectorAll('.folderlist li.mailbox').forEach(function (li) {
                if (entryFor(li)) paintRow(li); else unpaintRow(li);
            });
        }

        function repaintFromPrefs(e) {
            // Le plugin renvoie la carte à jour dans l'env de chaque réponse AJAX
            // (save/rename/delete) : la sidebar suit sans rechargement manuel.
            if (e && e.response && e.response.env && e.response.env.iconizer_icons) {
                ICONS = e.response.env.iconizer_icons;
            }
            paintAll();
        }

        if (window.rcmail && rcmail.addEventListener) {
            rcmail.addEventListener('response before script', paintAll);
            rcmail.addEventListener('response after script', paintAll);
            rcmail.addEventListener('folderUpdated', repaintFromPrefs);
            rcmail.addEventListener('imap_folders_changed', paintAll);
        }

        // treelist rebuilds rows on search/expand/AJAX; repaint on DOM changes.
        // The observer disconnects while mutating so our own edits never retrigger it.
        if (window.MutationObserver) {
            var list = document.querySelector('.folderlist');
            if (list) {
                var observer = new MutationObserver(function () {
                    observer.disconnect();
                    paintAll();
                    observer.observe(list, { childList: true, subtree: true });
                });
                observer.observe(list, { childList: true, subtree: true });
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', paintAll);
        } else {
            paintAll();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', painter);
    } else {
        painter();
    }
})();
