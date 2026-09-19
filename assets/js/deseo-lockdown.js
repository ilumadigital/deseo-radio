(function () {
    'use strict';

    if (window.__DESEO_UI_LOCKDOWN__) return;
    window.__DESEO_UI_LOCKDOWN__ = true;

    function normalizedKey(event) {
        return String(event.key || '').toLowerCase();
    }

    function isBlockedShortcut(event) {
        var key = normalizedKey(event);
        var ctrl = !!event.ctrlKey;
        var meta = !!event.metaKey;
        var shift = !!event.shiftKey;
        var alt = !!event.altKey;
        var ctrlOrMeta = ctrl || meta;

        if (event.key === 'F12' || event.keyCode === 123) return true;

        // View source / save source-like access.
        if (ctrlOrMeta && !shift && !alt && (key === 'u' || key === 's')) return true;

        // Chromium / Firefox developer tooling shortcuts.
        if (ctrlOrMeta && shift && ['i', 'j', 'c', 'k', 'e', 'm', 'p'].indexOf(key) !== -1) {
            return true;
        }

        // macOS developer tooling / page-source shortcuts.
        if (meta && alt && ['i', 'j', 'c', 'u'].indexOf(key) !== -1) {
            return true;
        }

        return false;
    }

    document.addEventListener('keydown', function (event) {
        if (!isBlockedShortcut(event)) return;

        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
        }
        return false;
    }, true);

    // Suppress the browser's native context menu. The public Deseo site can
    // still render its own custom right-click menu because we do not stop
    // propagation for this event.
    document.addEventListener('contextmenu', function (event) {
        event.preventDefault();
    }, true);
}());
