(function () {
  'use strict';
  var root = document.querySelector('[data-nhk-hero-slider]');
  if (!root) return;
  var slides = Array.prototype.slice.call(root.querySelectorAll('[data-hero-slide]'));
  if (slides.length < 2) return;
  var status = root.querySelector('[data-hero-status]');
  var dots = Array.prototype.slice.call(root.querySelectorAll('[data-hero-dot]'));
  var index = 0;
  var timer = null;
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function show(next) {
    index = (next + slides.length) % slides.length;
    slides.forEach(function (slide, i) { slide.hidden = i !== index; slide.classList.toggle('is-active', i === index); });
    dots.forEach(function (dot, i) { dot.setAttribute('aria-selected', i === index ? 'true' : 'false'); });
    if (status) status.textContent = (index + 1) + ' / ' + slides.length;
  }
  function stop() { if (timer) window.clearInterval(timer); timer = null; }
  function start() { if (!reduced && !timer) timer = window.setInterval(function () { show(index + 1); }, 6000); }
  root.querySelector('[data-hero-prev]')?.addEventListener('click', function () { show(index - 1); });
  root.querySelector('[data-hero-next]')?.addEventListener('click', function () { show(index + 1); });
  dots.forEach(function (dot, i) { dot.addEventListener('click', function () { show(i); }); });
  root.addEventListener('mouseenter', stop); root.addEventListener('mouseleave', start); root.addEventListener('focusin', stop); root.addEventListener('focusout', start);
  root.addEventListener('keydown', function (event) { if (event.key === 'ArrowLeft') show(index - 1); if (event.key === 'ArrowRight') show(index + 1); });
  start();
}());
