(function () {
  'use strict';
  document.documentElement.classList.add('nhk-album-ready');
  document.querySelectorAll('[data-nhk-album]').forEach(function (album) {
    var slides = Array.prototype.slice.call(album.querySelectorAll('[data-album-slide]'));
    var previous = album.querySelector('[data-album-prev]');
    var next = album.querySelector('[data-album-next]');
    var status = album.querySelector('[data-album-status]');
    var current = 0;
    if (!slides.length) return;
    function show(index) {
      current = (index + slides.length) % slides.length;
      slides.forEach(function (slide, position) {
        var active = position === current;
        slide.classList.toggle('is-active', active);
        slide.setAttribute('aria-hidden', active ? 'false' : 'true');
      });
      if (status) status.textContent = 'Ảnh ' + (current + 1) + ' / ' + slides.length;
      if (previous) previous.disabled = slides.length < 2;
      if (next) next.disabled = slides.length < 2;
    }
    if (previous) previous.addEventListener('click', function () { show(current - 1); });
    if (next) next.addEventListener('click', function () { show(current + 1); });
    album.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowLeft') { event.preventDefault(); show(current - 1); }
      if (event.key === 'ArrowRight') { event.preventDefault(); show(current + 1); }
    });
    show(0);
  });
}());
