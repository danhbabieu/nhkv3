(function () {
  'use strict';

  var toggle = document.getElementById('nav-toggle');
  if (!toggle) return;

  var sync = function () {
    toggle.setAttribute('aria-expanded', toggle.checked ? 'true' : 'false');
  };

  toggle.addEventListener('change', sync);
  sync();
}());
