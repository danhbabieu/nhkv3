(function () {
  'use strict';

  var toggle = document.getElementById('nav-toggle');
  if (!toggle) return;
  var trigger = document.querySelector('.nav-toggle-label');
  var panel = document.querySelector('.header-actions');
  var mobileQuery = window.matchMedia('(max-width: 48rem)');

  var sync = function () {
    var open = toggle.checked;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.classList.toggle('nhk-nav-open', open && mobileQuery.matches);
    if (panel) panel.hidden = !open && mobileQuery.matches;
  };

  var close = function (restoreFocus) {
    toggle.checked = false;
    sync();
    if (restoreFocus && trigger) trigger.focus();
  };

  toggle.addEventListener('change', sync);
  document.addEventListener('keydown', function (event) {
    if (!toggle.checked || !mobileQuery.matches) return;
    if (event.key === 'Escape') { event.preventDefault(); close(true); return; }
    if (event.key !== 'Tab' || !panel) return;
    var focusable = panel.querySelectorAll('a[href], summary, input, button, select, textarea, [tabindex]:not([tabindex="-1"])');
    if (!focusable.length) return;
    var first = focusable[0];
    var last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });
  document.addEventListener('click', function (event) {
    if (!toggle.checked || !mobileQuery.matches) return;
    var target = event.target;
    if (!(target instanceof Node) || target === toggle || target.closest('.header-actions') || target.closest('.nav-toggle-label')) return;
    close(false);
  });
  document.querySelectorAll('#primary-navigation a').forEach(function (link) {
    link.addEventListener('click', function () {
      close(false);
    });
  });
  document.querySelectorAll('#primary-navigation details').forEach(function (details) {
    details.addEventListener('toggle', function () {
      if (details.open) details.querySelector('summary').setAttribute('aria-expanded', 'true');
      else details.querySelector('summary').setAttribute('aria-expanded', 'false');
    });
  });
  mobileQuery.addEventListener('change', function () { if (!mobileQuery.matches) close(false); else sync(); });
  sync();

  document.querySelectorAll('.claim-ledger-section').forEach(function (section) {
    var summary = section.querySelector('summary');
    if (!summary) return;
    var syncLedger = function () {
      summary.setAttribute('aria-expanded', section.open ? 'true' : 'false');
    };
    section.addEventListener('toggle', syncLedger);
    syncLedger();
  });
}());
