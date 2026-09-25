(function () {
  'use strict';

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
}());
