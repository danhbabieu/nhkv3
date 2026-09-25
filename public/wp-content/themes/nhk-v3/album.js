(function () {
  'use strict';
  document.documentElement.classList.add('nhk-album-ready');
  document.querySelectorAll('[data-nhk-album]').forEach(function (album) {
    var slides = Array.prototype.slice.call(album.querySelectorAll('[data-album-slide]'));
    var previous = album.querySelector('[data-album-prev]');
    var next = album.querySelector('[data-album-next]');
    var status = album.querySelector('[data-album-status]');
    var dialog = album.querySelector('[data-album-dialog]');
    var dialogImage = album.querySelector('[data-album-dialog-image]');
    var dialogCaption = album.querySelector('[data-album-dialog-caption]');
    var dialogClose = album.querySelector('[data-album-close]');
    var dialogPrevious = album.querySelector('[data-album-dialog-prev]');
    var dialogNext = album.querySelector('[data-album-dialog-next]');
    var current = 0;
    var invoker = null;
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
      if (dialog && dialog.open) syncDialog();
    }
    function syncDialog() {
      var image = slides[current].querySelector('img');
      var link = slides[current].querySelector('[data-album-open]');
      if (dialogImage && image) { dialogImage.src = link ? (link.dataset.fullSrc || link.href) : image.src; dialogImage.alt = image.alt; }
      if (dialogCaption) dialogCaption.textContent = (slides[current].querySelector('figcaption') || {}).textContent || '';
    }
    function openDialog(event, index) {
      if (!dialog || typeof dialog.showModal !== 'function') return;
      event.preventDefault();
      invoker = event.currentTarget;
      show(index);
      syncDialog();
      dialog.showModal();
      if (dialogClose) dialogClose.focus();
    }
    function closeDialog() {
      if (!dialog || !dialog.open) return;
      dialog.close();
      if (invoker && typeof invoker.focus === 'function') invoker.focus();
      invoker = null;
    }
    if (previous) previous.addEventListener('click', function () { show(current - 1); });
    if (next) next.addEventListener('click', function () { show(current + 1); });
    album.querySelectorAll('[data-album-open]').forEach(function (link, index) { link.addEventListener('click', function (event) { openDialog(event, index); }); });
    if (dialogClose) dialogClose.addEventListener('click', closeDialog);
    if (dialogPrevious) dialogPrevious.addEventListener('click', function () { show(current - 1); });
    if (dialogNext) dialogNext.addEventListener('click', function () { show(current + 1); });
    if (dialog) dialog.addEventListener('click', function (event) { if (event.target === dialog) closeDialog(); });
    album.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowLeft') { event.preventDefault(); show(current - 1); }
      if (event.key === 'ArrowRight') { event.preventDefault(); show(current + 1); }
    });
    if (dialog) dialog.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { event.preventDefault(); closeDialog(); return; }
      if (event.key === 'ArrowLeft') { event.preventDefault(); show(current - 1); return; }
      if (event.key === 'ArrowRight') { event.preventDefault(); show(current + 1); return; }
      if (event.key !== 'Tab') return;
      var focusable = Array.prototype.slice.call(dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')).filter(function (node) { return !node.disabled; });
      if (!focusable.length) return;
      var first = focusable[0]; var last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    show(0);
  });
}());
