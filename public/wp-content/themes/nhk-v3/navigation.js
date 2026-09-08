(function () {
  'use strict';

  var toggle = document.getElementById('nav-toggle');
  if (!toggle) return;

  var sync = function () {
    toggle.setAttribute('aria-expanded', toggle.checked ? 'true' : 'false');
  };

  toggle.addEventListener('change', sync);
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
