/**
 * roundcube_iconizer — sidebar painter.
 *
 * Replaces Elastic's folder glyph with the user's icon on rows that have a
 * mapping. Runs after every sidebar render so refreshes keep icons in place.
 */
(function () {
    'use strict';

    var env = window.rcmail && rcmail.env ? rcmail.env : null;
    if (!env || !env.iconizer_icons) {
        return;
    }

    var ICONS = env.iconizer_icons;

    function folderOf(row) {
        if (!row || !row.getAttribute) return null;
        return row.getAttribute('data-folder') || row.id || null;
    }

    function paintRow(li) {
        var folder = folderOf(li);
        if (!folder || !ICONS[folder]) return;

        var entry = ICONS[folder];
        var anchor = li.querySelector(':scope > a');
        if (!anchor) return;

        var existing = anchor.querySelector('.iconizer-icon');
        if (existing) existing.remove();

        var holder = document.createElement('span');
        holder.className = 'iconizer-holder';

        if (entry.svg) {
            holder.innerHTML = entry.svg;
        } else if (window.Iconify && Iconify.renderSVG) {
            var parsed = entry.ref.split(':');
            var node = Iconify.renderSVG(entry.ref, { height: 16, width: 16 });
            if (node) {
                node.classList.add('iconizer-icon');
                holder.appendChild(node);
            } else {
                // Not cached and not yet rendered: fetch once, paint on success.
                holder.innerHTML = '<span class="iconify" data-icon="' + entry.ref + '" data-inline="false"></span>';
                Iconify.scan(holder);
            }
        } else {
            holder.innerHTML = '<span class="iconify" data-icon="' + entry.ref + '"></span>';
            if (window.Iconify && Iconify.scan) Iconify.scan(holder);
        }

        anchor.insertBefore(holder, anchor.firstChild);
        li.classList.add('iconizer-painted');
    }

    function paintAll() {
        document.querySelectorAll('.folderlist li.mailbox').forEach(paintRow);
    }

    if (window.rcmail && rcmail.addEventListener) {
        rcmail.addEventListener('response after script', paintAll);
        rcmail.addEventListener('folderUpdated', paintAll);
        rcmail.addEventListener('imap_folders_changed', paintAll);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', paintAll);
    } else {
        paintAll();
    }
})();
