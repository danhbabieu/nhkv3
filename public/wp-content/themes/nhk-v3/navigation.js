(function () {
  'use strict';

  var toggle = document.getElementById('nav-toggle');
  if (!toggle) return;

  var sync = function () {
    toggle.setAttribute('aria-expanded', toggle.checked ? 'true' : 'false');
  };

  toggle.addEventListener('change', sync);
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && toggle.checked) {
      toggle.checked = false;
      sync();
      toggle.focus();
    }
  });
  document.addEventListener('click', function (event) {
    if (!toggle.checked) return;
    var target = event.target;
    if (!(target instanceof Node) || target === toggle || target.closest('.header-actions') || target.closest('.nav-toggle-label')) return;
    toggle.checked = false;
    sync();
  });
  document.querySelectorAll('#primary-navigation a').forEach(function (link) {
    link.addEventListener('click', function () {
      toggle.checked = false;
      sync();
    });
  });
  document.querySelectorAll('[data-video-load]').forEach(function (button) {
    button.addEventListener('click', function () {
      var frame = button.closest('[data-video-player]');
      var source = button.getAttribute('data-video-embed');
      if (!frame || !source) return;
      var iframe = document.createElement('iframe');
      iframe.src = source;
      iframe.title = button.getAttribute('aria-label') || 'Video NHK';
      iframe.loading = 'eager';
      iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
      iframe.allowFullscreen = true;
      frame.replaceChildren(iframe);
    });
  });
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
