(function () {
    'use strict';

    var aliases = {
        'system': 'nhk-migration-ledger-heading',
        'semantic-read': 'nhk-semantic-lookup-heading',
        'governance': 'nhk-proposal-lookup-heading',
        'video': 'nhk-proposal-composer-heading'
    };

    function focusHashTarget() {
        var hash = window.location.hash.replace(/^#/, '');
        if (!hash) return;

        var target = document.getElementById(hash) || document.getElementById(aliases[hash] || '');
        if (!target) return;

        target.setAttribute('tabindex', '-1');
        target.classList.add('nhk-admin-focus-target');
        target.focus({ preventScroll: true });
        target.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        target.addEventListener('blur', function cleanup() {
            target.classList.remove('nhk-admin-focus-target');
            target.removeAttribute('tabindex');
            target.removeEventListener('blur', cleanup);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', focusHashTarget);
    } else {
        focusHashTarget();
    }

    window.addEventListener('hashchange', focusHashTarget);
}());
