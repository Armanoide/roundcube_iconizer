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
        // this = le window où le tree doit être peint (le frame lui-même, ou le
        // parent via armParentFromIframe). window.rcmail y porte l'env.
        var self = this || window;
        var env = self.rcmail && self.rcmail.env ? self.rcmail.env : null;
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
            if (li.id && self.rcmail.folder_id2name) {
                var name = self.rcmail.folder_id2name(li.id);
                if (name && ICONS[name]) return ICONS[name];
            }
            return null;
        }

        function makeIconNode(entry) {
            var holder = self.document.createElement('span');
            holder.className = 'iconizer-holder';

            if (entry.svg) {
                holder.innerHTML = entry.svg;
                return holder;
            }

            if (self.Iconify) {
                var node = self.Iconify.renderSVG ? self.Iconify.renderSVG(entry.ref, { height: 16, width: 16 }) : null;
                if (node) {
                    node.classList.add('iconizer-icon');
                    holder.appendChild(node);
                } else {
                    var ph = self.document.createElement('span');
                    ph.className = 'iconify';
                    ph.setAttribute('data-icon', entry.ref);
                    holder.appendChild(ph);
                    if (self.Iconify.scan) self.Iconify.scan(holder);
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

        // Commande du plugin : le POST classique save-folder l'émet vers le frame
        // parent (rcmail_output_html.php:843-853) en commande jumelle de
        // replace_folder_row (app.js:8064) qui pose le nom — même mécanique, sur
        // l'icône. Peint la ligne visée ; sans cible, peint tout le tree.
        self.rcmail.iconizer_paint = function (folder) {
            ICONS = (self.rcmail.env && self.rcmail.env.iconizer_icons) || ICONS;

            var li = folder ? self.document.getElementById('rcmli' + self.rcmail.html_identifier_encode(folder)) : null;

            if (folder && !li) {
                // Deux cas sans ligne au nom submitted : renommage (le cœur est en
                // train de remplacer le <li> — son add_folder_row + notre observer
                // rattrapent la peinture) et suppression de l'icône sur une ligne
                // déjà repeinte (entryFor échoue, il faut unpaint directement).
                if (ICONS[folder]) return;
                self.document.querySelectorAll('.folderlist li.mailbox.iconizer-painted').forEach(unpaintRow);
                return;
            }

            if (li) {
                // Self-check : une cible sans ligne existante est un no-op, pas un
                // effacement — le renommage passe par replace_folder_row + observer.
                if (!ICONS[folder] && !li.classList.contains('iconizer-painted')) return;
                if (ICONS[folder]) paintRow(li); else unpaintRow(li);
            } else {
                paintAll();
            }
        };

        function unpaintRow(li) {
            // Entrée retirée des prefs (icône supprimée) : le glyphe Elastic revient.
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
            // Le plugin renvoie la carte à jour dans l'env de chaque réponse AJAX
            // (save/rename/delete) : la sidebar suit sans rechargement manuel.
            if (e && e.response && e.response.env && e.response.env.iconizer_icons) {
                ICONS = e.response.env.iconizer_icons;
            }
            paintAll();
        }

        if (self.rcmail && self.rcmail.addEventListener) {
            // Nom d'événement exact du cœur (app.js:9652/9851) : "responsebefore"/
            // "responseafter" — les variantes avec espace n'existent pas.
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

    // Le POST classique save-folder répond send('iframe') : toutes ses commandes
    // sont réécrites en parent.rcmail.<cmd>() (rcmail_output_html.php:843-853) et
    // write() vide script_files ET $this->scripts (:655-660) — l'iframe ne porte
    // donc ni ce painter, ni un rcmail à elle (skins/elastic/ui.js :
    // `rcmail=parent.rcmail` quand window.rcmail est absent). Le tree visible
    // (subscriptionlist, app.js:7882/8065) vit dans le frame parent : c'est là
    // qu'il faut armer le painter. Ce script, quand il tourne dans une iframe,
    // installe donc son painter dans le parent (this=parent : window, document et
    // rcmail internes deviennent ceux du parent) et repeint son tree avec la carte
    // de l'env courant — celle du frame quand il en porte une (page complète),
    // sinon celle que le hint inline de ensure_map_in_html vient d'y écrire.
    function armParentFromIframe() {
        var P = window.parent;
        try {
            if (!P || P === window || !P.rcmail || !P.document) return;
            if (!P.document.querySelector('.folderlist')) return; // pas de tree : rien à peindre

            var icons = (window.rcmail && window.rcmail.env && window.rcmail.env.iconizer_icons)
                || (P.rcmail.env && P.rcmail.env.iconizer_icons);
            if (!icons) return;

            P.rcmail.env.iconizer_icons = icons;
            painter.call(P); // définit P.rcmail.iconizer_paint et peint le tree du parent
        } catch (e) {
            // cross-origin parent : rien à repeindre ici
        }
    }

    function boot() {
        // Le `var rcmail` global du cœur (rcmail_output_html.php:147) rend la
        // référence nue possible ; window.rcmail est plus sûr en mode strict.
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
