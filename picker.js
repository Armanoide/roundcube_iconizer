/**
 * roundcube_iconizer — searchable icon picker for the folder edit form (card 02).
 *
 * Inlined by the plugin into the folder-edit page because the core drops
 * registered script files when it re-renders that form after a failed save.
 * Vanilla JS, no build step (ADR 0001). Keyword search runs against the Iconify
 * API, scoped to the plugin's allowed packs (ADR 0002) — a pack outside the
 * allowlist can never appear, and the server re-checks on save anyway.
 */
(function () {
    'use strict';

    var API = 'https://api.iconify.design';
    // ADR 0002 — mirrors IconResolver::ALLOWED_PACKS / IconPacks::PACKS.
    var PACKS = { bi: 'Bootstrap Icons', 'fa-solid': 'Font Awesome Solid', 'fa-regular': 'Font Awesome Regular', 'fa-brands': 'Font Awesome Brands', ic: 'Google Material Icons', mdi: 'Material Design Icons', 'simple-icons': 'Simple Icons' };

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
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

    function picker() {
        var button = document.getElementById('iconizer-picker-button');
        var hidden = document.getElementById('_iconizer_icon');
        if (!button || !hidden) {
            return;
        }

        var pack = button.getAttribute('data-pack') || '';
        var selected = hidden.value || '';

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
            // "All packs" first and the default: multi-pack search is the normal case.
            var all = document.createElement('option');
            all.value = '';
            all.textContent = label('iconizerall', 'All packs');
            select.appendChild(all);
            Object.keys(PACKS).forEach(function (p) {
                var opt = document.createElement('option');
                opt.value = p;
                opt.textContent = PACKS[p];
                select.appendChild(opt);
            });

            select.addEventListener('change', function () { load(select.value, ''); });
            overlay.querySelector('.iconizer-search').addEventListener('input', debounce(function (e) {
                load(select.value, e.target.value.trim());
            }, 300));
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
            if (svgSpan) svgSpan.textContent = ''; // stale: the server re-resolves on save
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
                ? '<img src="' + API + '/' + selected.replace(':', '/') + '.svg" alt=""> <code>' + esc(selected) + '</code>'
                : '<span class="iconizer-placeholder">' + esc(label('iconizernone', 'None')) + '</span>';
        }

        function paintButton() {
            var old = button.querySelector('img');
            if (old) old.remove();
            if (!selected) {
                return;
            }

            var img = document.createElement('img');
            img.src = API + '/' + selected.replace(':', '/') + '.svg';
            img.alt = '';
            img.width = 16;
            img.height = 16;
            // The core re-renders the form after save: .iconizer-ref may no longer be
            // a child of this button (orphaned or gone) -> insertBefore throws. Insert
            // before ref only when it really is a child, otherwise append.
            var ref = button.querySelector('.iconizer-ref');
            if (ref && ref.parentNode === button) {
                button.insertBefore(img, ref);
            } else {
                button.appendChild(img);
            }
        }

        function searchPack(p, term) {
            // One request per pack: Iconify caps results at 64 per call and takes a
            // single prefix= — a global search filtered afterwards never surfaces the
            // allowlisted packs (the "pro" bug).
            return fetch(API + '/search?query=' + encodeURIComponent(term) + '&prefix=' + encodeURIComponent(p) + '&limit=96')
                .then(function (r) { return r.json(); })
                .then(function (data) { return data.icons || []; })
                .catch(function () { return []; });
        }

        function load(p, term) {
            var grid = overlay.querySelector('.iconizer-grid');
            grid.innerHTML = '';
            term = (term || '').trim();

            // Empty pack ("All") -> every allowlisted pack, otherwise just the chosen one.
            var targets = p ? [p] : Object.keys(PACKS);

            if (!term) {
                // No search term: "All" would list thousands of icons, so it stays empty
                // and prompts for a query; samples only load for a single chosen pack.
                if (!p) {
                    grid.innerHTML = '<span class="iconizer-placeholder">' + esc(label('iconizertype', 'Type to search…')) + '</span>';
                    return;
                }
                fetch(API + '/collections?request=' + encodeURIComponent(JSON.stringify({ prefixes: [p] })))
                    .then(function (r) { return r.json(); })
                    .then(function (data) { show(grid, ((data[p] && data[p].samples) || []).map(function (n) { return p + ':' + n; })); })
                    .catch(function () { grid.textContent = 'Iconify API unreachable'; });
                return;
            }

            Promise.all(targets.map(function (t) { return searchPack(t, term); }))
                .then(function (chunks) { show(grid, flatten(chunks)); })
                .catch(function () { grid.textContent = 'Iconify API unreachable'; });
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
                // <img> only: one request per icon, browser-cached. The old inline-SVG
                // fetch doubled every request and tripped Iconify's 429 rate limit.
                var img = document.createElement('img');
                img.src = API + '/' + ref.replace(':', '/') + '.svg';
                img.alt = ref;
                img.loading = 'lazy';
                cell.appendChild(img);
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
