(function () {
    'use strict';

    var config = window.NHKAdminShell || {};
    var disclosureTrigger = null;

    function focusTarget(target) {
        if (!target) return;
        if (!target.hasAttribute('tabindex')) target.setAttribute('tabindex', '-1');
        target.focus({preventScroll: true});
        target.scrollIntoView({block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'});
    }

    function restoreHashFocus() {
        if (!window.location.hash) return;
        focusTarget(document.getElementById(window.location.hash.slice(1)));
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-nhk-disclosure]');
        if (!trigger) return;
        var panel = document.getElementById(trigger.getAttribute('aria-controls') || '');
        if (!panel) return;
        var expanded = trigger.getAttribute('aria-expanded') === 'true';
        trigger.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        panel.hidden = expanded;
        disclosureTrigger = expanded ? null : trigger;
        if (!expanded) focusTarget(panel);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !disclosureTrigger) return;
        var panel = document.getElementById(disclosureTrigger.getAttribute('aria-controls') || '');
        if (panel) panel.hidden = true;
        disclosureTrigger.setAttribute('aria-expanded', 'false');
        disclosureTrigger.focus();
        disclosureTrigger = null;
    });

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-nhk-status-url]');
        if (!trigger) return;
        var output = document.querySelector(trigger.getAttribute('data-nhk-status-target') || '[data-nhk-status]');
        if (!output) return;
        trigger.disabled = true;
        output.setAttribute('aria-busy', 'true');
        output.textContent = 'Đang tải trạng thái…';
        fetch(trigger.getAttribute('data-nhk-status-url'), {
            method: 'GET',
            headers: config.nonce ? {'X-WP-Nonce': config.nonce} : {}
        }).then(function (response) {
            return response.text().then(function (body) {
                output.textContent = body;
                output.dataset.fetchState = response.ok ? 'complete' : 'blocked';
            });
        }).catch(function () {
            output.textContent = 'Không thể xác minh trạng thái. Hãy thử lại.';
            output.dataset.fetchState = 'uncertain';
        }).finally(function () {
            trigger.disabled = false;
            output.removeAttribute('aria-busy');
            focusTarget(output);
        });
    });

    window.addEventListener('hashchange', restoreHashFocus);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restoreHashFocus);
    } else {
        restoreHashFocus();
    }
}());
