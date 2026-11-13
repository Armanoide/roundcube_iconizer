/**
 * roundcube_iconizer — searchable icon picker for the folder edit form (card 02).
 *
 * Inlined by the plugin into the folder-edit page because the core drops
 * registered script files when it re-renders that form after a failed save.
 * Vanilla JS, no build step (ADR 0001).
 *
 * ADR 0004: search runs against the LOCAL sprite mirror — data/<pack>.json is
 * fetched once per installed pack and filtered client-side; icons render from
 * <svg><use href="sprites/<pack>.svg#<name>">. The picker offers only installed
 * packs, so no request ever leaves the browser to api.iconify.design. A pack
 * outside the allowlist can never appear, and the server re-checks on save anyway.
 */
(function () {
    'use strict';

    // ADR 0002 — mirrors IconResolver::ALLOWED_PACKS / IconPacks::PACKS.
    var PACKS = { bi: 'Bootstrap Icons', 'fa-solid': 'Font Awesome Solid', 'fa-regular': 'Font Awesome Regular', 'fa-brands': 'Font Awesome Brands', ic: 'Google Material Icons', mdi: 'Material Design Icons', 'simple-icons': 'Simple Icons' };

    // Sprite base + installed packs, published by the plugin (ADR 0004).
    var env = (window.rcmail && window.rcmail.env) || {};
    var SPRITE_BASE = env.iconizer_sprite_base || 'plugins/roundcube_iconizer/sprites/';
    var INSTALLED = env.iconizer_installed || [];

    // name lists per pack, fetched once from data/<pack>.json and cached here.
    var NAMES = {};

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39' }[c];
        });
    }

    function debounce(fn, ms) {
        var t;
        return function () {
            var args = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(null, args); }, ms);
        };
    }

    function label(name, fallback) {
        if (name === 'save' && window.rcmail && rcmail.get_label) {
            return rcmail.get_label('save') || fallback;
        }

        return fallback;
    }

    function spriteUse(ref, size) {
        var parts = (ref || '').split(':');
        return '<svg class="iconizer-icon" width="' + size + '" height="' + size + '" aria-hidden="true">'
            + '<use href="' + SPRITE_BASE + parts[0] + '.svg#' + parts.slice(1).join(':') + '"></use></svg>';
    }

    // Fetch a pack's searchable name list once; resolve to [] when unavailable.
    function namesOf(p) {
        if (NAMES[p]) return Promise.resolve(NAMES[p]);
        var url = SPRITE_BASE.replace(/sprites\/$/, '') + 'data/' + p + '.json';
        return fetch(url)
            .then(function (r) { return r.ok ? r.json() : { names: [] }; })
            .then(function (d) { NAMES[p] = d.names || []; return NAMES[p]; })
            .catch(function () { return []; });
    }

    function picker() {
        // id="_icon" so the core's <label for="_icon">Icon</label> resolves to it.
        var button = document.getElementById('_icon');
        var hidden = document.getElementById('_iconizer_icon');
        if (!button || !hidden) {
            return;
        }

        var pack = button.getAttribute('data-pack') || '';
        var selected = hidden.value || '';

        // Only installed packs are selectable (ADR 0004): no sprite, no icon.
        var available = INSTALLED.filter(function (p) { return PACKS[p]; });

        // One overlay for the whole document; opened on every button click.
        var overlay = document.getElementById('iconizer-overlay');
        var select;

        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'iconizer-overlay';
            overlay.hidden = true;
            overlay.innerHTML =
                '<div class="iconizer-dialog" role="dialog" aria-modal="true">'
                + '<div class="iconizer-head">'
                + '<input type="text" class="iconizer-search form-control" autocomplete="off">'
                + '<select class="iconizer-pack form-control"></select>'
                + '</div>'
                + '<div class="iconizer-preview"></div>'
                + '<div class="iconizer-grid"></div>'
                + '<div class="iconizer-foot">'
                + '<button type="button" class="btn iconizer-clear"></button>'
                + '<button type="button" class="btn btn-primary iconizer-apply"></button>'
                + '</div></div>';
            document.body.appendChild(overlay);

            select = overlay.querySelector('.iconizer-pack');
            // "All installed" first and the default: multi-pack search is the norm.
            var all = document.createElement('option');
            all.value = '';
            all.textContent = label('iconizerall', 'All packs');
            select.appendChild(all);
            available.forEach(function (p) {
                var opt = document.createElement('option');
                opt.value = p;
                opt.textContent = PACKS[p];
                select.appendChild(opt);
            });

            select.addEventListener('change', function () { load(select.value, ''); });
            overlay.querySelector('.iconizer-search').addEventListener('input', debounce(function (e) {
                load(select.value, e.target.value.trim());
            }, 200));
            overlay.querySelector('.iconizer-grid').addEventListener('click', function (e) {
                var cell = e.target.closest('.iconizer-cell');
                if (!cell) return;
                selected = cell.getAttribute('data-ref') || '';
                markSelected();
            });
            overlay.querySelector('.iconizer-clear').addEventListener('click', function () {
                selected = '';
                commit(); // clear the icon -> standard glyph after save
            });
            overlay.querySelector('.iconizer-apply').addEventListener('click', commit);
            overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.hidden = true; });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !overlay.hidden) overlay.hidden = true;
            });
        }

        function commit() {
            hidden.value = selected;
            var svgSpan = document.getElementById('iconizer-form-svg');
            if (svgSpan) svgSpan.textContent = ''; // sprite paints it; inline cache unused
            button.setAttribute('title', selected || label('iconizerselect', 'Choose an icon'));
            var ref = button.querySelector('.iconizer-ref');
            if (ref && ref.parentNode === button) {
                ref.textContent = selected || label('iconizernone', 'None');
            }
            paintButton();
            overlay.hidden = true;
        }

        function markSelected() {
            overlay.querySelectorAll('.iconizer-cell').forEach(function (cell) {
                cell.classList.toggle('selected', cell.getAttribute('data-ref') === selected);
            });
            var preview = overlay.querySelector('.iconizer-preview');
            preview.innerHTML = selected
                ? spriteUse(selected, 24) + ' <code>' + esc(selected) + '</code>'
                : '<span class="iconizer-placeholder">' + esc(label('iconizernone', 'None')) + '</span>';
        }

        function paintButton() {
            var old = button.querySelector('.iconizer-form-svg svg, img');
            if (old) old.remove();
            if (!selected) {
                return;
            }

            var span = document.createElement('span');
            span.innerHTML = spriteUse(selected, 16);
            var svg = span.firstChild;
            var ref = button.querySelector('.iconizer-ref');
            if (ref && ref.parentNode === button) {
                button.insertBefore(svg, ref);
            } else {
                button.appendChild(svg);
            }
        }

        // Filter a pack's cached names by substring (local, no network).
        function filterPack(p, term) {
            return namesOf(p).then(function (names) {
                var t = term.toLowerCase();
                return names.filter(function (n) { return n.indexOf(t) >= 0; })
                    .slice(0, 96).map(function (n) { return p + ':' + n; });
            });
        }

        function load(p, term) {
            var grid = overlay.querySelector('.iconizer-grid');
            grid.innerHTML = '';

            if (!available.length) {
                grid.innerHTML = '<span class="iconizer-placeholder">No packs installed — install one in Preferences › Icon packs.</span>';
                return;
            }

            term = (term || '').trim();
            var targets = p ? [p] : available;

            if (!term) {
                if (!p) {
                    // "All" is the default and must show something: preview every
                    // installed pack round-robin so each one appears in the grid.
                    Promise.all(available.map(function (t) { return namesOf(t); }))
                        .then(function (chunks) {
                            var refs = [], i = 0, more = true;
                            while (more && refs.length < 120) {
                                more = false;
                                chunks.forEach(function (c, k) {
                                    if (i < c.length) { refs.push(available[k] + ':' + c[i]); more = true; }
                                });
                                i++;
                            }
                            show(grid, refs);
                        });
                    return;
                }
                namesOf(p).then(function (names) { show(grid, names.slice(0, 120).map(function (n) { return p + ':' + n; })); });
                return;
            }

            Promise.all(targets.map(function (t) { return filterPack(t, term); }))
                .then(function (chunks) { show(grid, flatten(chunks)); });
        }

        function flatten(chunks) {
            var seen = {}, out = [];
            chunks.forEach(function (refs) {
                refs.forEach(function (ref) {
                    if (!seen[ref]) { seen[ref] = 1; out.push(ref); }
                });
            });
            return out.slice(0, 196);
        }

        function show(grid, refs) {
            refs = refs.slice(0, 120); // hard cap: the grid scrolls, it never explodes
            refs.forEach(function (ref) {
                var cell = document.createElement('button');
                cell.type = 'button';
                cell.className = 'iconizer-cell' + (ref === selected ? ' selected' : '');
                cell.setAttribute('data-ref', ref);
                cell.title = ref;
                // Local sprite <use>: same-origin, currentColor, zero CDN (ADR 0004).
                cell.innerHTML = spriteUse(ref, 24);
                grid.appendChild(cell);
            });
            if (!refs.length) {
                grid.innerHTML = '<span class="iconizer-placeholder">—</span>';
            }
        }

        button.addEventListener('click', function () {
            overlay.querySelector('.iconizer-clear').textContent = label('iconizerclear', 'Remove icon');
            overlay.querySelector('.iconizer-apply').textContent = label('save', 'Save');
            overlay.querySelector('.iconizer-search').placeholder = label('iconizersearch', 'Search icons…');
            select.value = pack;
            overlay.hidden = false;
            markSelected();
            load(pack, '');
            overlay.querySelector('.iconizer-search').focus();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', picker);
    } else {
        picker();
    }
})();
