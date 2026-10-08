(() => {
  const root = document.querySelector('.music-dossier');
  if (!root) return;
  root.querySelectorAll('[data-music-audio]').forEach((card) => {
    const audio = card.querySelector('audio[data-music-audio-source]');
    if (!audio) return;
    const progress = card.querySelector('[data-music-progress]');
    const originalRate = audio.playbackRate || 1;
    const update = () => {
      if (progress && Number.isFinite(audio.duration) && audio.duration > 0) progress.value = audio.currentTime / audio.duration;
      root.querySelectorAll('.music-score-event').forEach((event) => {
        const active = audio.currentTime * 1000 >= Number(event.dataset.startMs || 0) && audio.currentTime * 1000 < Number(event.dataset.endMs || 0);
        event.toggleAttribute('aria-current', active);
      });
    };
    audio.addEventListener('timeupdate', update);
    audio.addEventListener('loadedmetadata', update);
    audio.addEventListener('ended', update);
    card.querySelectorAll('[data-music-action="play"]').forEach((button) => button.addEventListener('click', () => { void audio.play(); }));
    card.querySelectorAll('[data-music-action="pause"]').forEach((button) => button.addEventListener('click', () => audio.pause()));
    card.querySelectorAll('[data-music-action="stop"]').forEach((button) => button.addEventListener('click', () => { audio.pause(); audio.currentTime = 0; update(); }));
    card.querySelectorAll('[data-music-action="rate"]').forEach((select) => select.addEventListener('change', () => { audio.playbackRate = Number(select.value) || 1; }));
    card.querySelectorAll('[data-music-action="reset-rate"]').forEach((button) => button.addEventListener('click', () => { audio.playbackRate = originalRate; const rate = card.querySelector('[data-music-action="rate"]'); if (rate) rate.value = String(originalRate); }));
    card.querySelectorAll('[data-music-action="repeat"]').forEach((input) => input.addEventListener('change', () => { audio.loop = input.checked; }));
    card.querySelectorAll('[data-music-action="segment"]').forEach((button) => button.addEventListener('click', () => { audio.currentTime = Number(button.dataset.startMs || 0) / 1000; void audio.play(); }));
    card.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        audio.pause();
        audio.currentTime = 0;
        update();
      }
      if (event.key === 'ArrowLeft') audio.currentTime = Math.max(0, audio.currentTime - 5);
      if (event.key === 'ArrowRight' && Number.isFinite(audio.duration)) audio.currentTime = Math.min(audio.duration, audio.currentTime + 5);
      update();
    });
  });
})();
