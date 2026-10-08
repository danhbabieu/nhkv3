(() => {
  const root = document.querySelector('.music-dossier');
  if (!root) return;
  root.querySelectorAll('[data-music-audio]').forEach((card) => {
    const audio = card.querySelector('audio[data-music-audio-source]');
    if (!audio) return;
    const progress = card.querySelector('[data-music-progress]');
    const update = () => {
      if (progress && Number.isFinite(audio.duration) && audio.duration > 0) progress.value = audio.currentTime / audio.duration;
      root.querySelectorAll('.music-score-event').forEach((event) => {
        const active = audio.currentTime * 1000 >= Number(event.dataset.startMs || 0) && audio.currentTime * 1000 < Number(event.dataset.endMs || 0);
        event.toggleAttribute('aria-current', active);
      });
    };
    audio.addEventListener('timeupdate', update);
    card.querySelectorAll('[data-music-action="play"]').forEach((button) => button.addEventListener('click', () => audio.play()));
    card.querySelectorAll('[data-music-action="stop"]').forEach((button) => button.addEventListener('click', () => { audio.pause(); audio.currentTime = 0; update(); }));
    card.querySelectorAll('[data-music-action="rate"]').forEach((select) => select.addEventListener('change', () => { audio.playbackRate = Number(select.value) || 1; }));
    card.querySelectorAll('[data-music-action="segment"]').forEach((button) => button.addEventListener('click', () => { audio.currentTime = Number(button.dataset.startMs || 0) / 1000; audio.play(); }));
  });
})();
