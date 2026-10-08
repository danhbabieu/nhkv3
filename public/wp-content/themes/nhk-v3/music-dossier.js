(() => {
  const root = document.querySelector('.music-dossier');
  if (!root) return;

  const isLoopbackHost = () => ['localhost', '127.0.0.1', '[::1]'].includes(window.location.hostname);
  const localPreview = root.dataset.localPreview === 'true' && isLoopbackHost();
  let selectedAudio = null;

  const svgElement = (name, attributes = {}) => {
    const element = document.createElementNS('http://www.w3.org/2000/svg', name);
    Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, String(value)));
    return element;
  };

  const renderScore = () => {
    const host = root.querySelector('[data-music-score]');
    if (!host) return;
    let events = [];
    try { events = JSON.parse(host.dataset.scoreEvents || '[]'); } catch (error) { events = []; }
    events = events.filter((event) => event && typeof event === 'object' && Number.isFinite(Number(event.start_ms)) && Number.isFinite(Number(event.duration_ms)));
    if (!events.length) return;

    const stepByLetter = { C: 0, D: 1, E: 2, F: 3, G: 4, A: 5, B: 6 };
    const anchor = 4 * 7 + stepByLetter.D;
    const staffTop = 42;
    const lineGap = 12;
    const xStart = 96;
    const noteGap = 26;
    const phraseGap = 18;
    let x = xStart;
    let previousPhrase = '';
    const positions = [];
    events.forEach((event, index) => {
      const phrase = String(event.phrase || '');
      if (index > 0 && phrase !== previousPhrase) x += phraseGap;
      const pitch = String(event.pitch_class || 'C');
      const letter = pitch.charAt(0).toUpperCase();
      const octave = Number(event.octave || 4);
      const diatonic = octave * 7 + (stepByLetter[letter] ?? 0);
      positions.push({ event, x, y: staffTop + 4 * lineGap - (diatonic - anchor) * (lineGap / 2), phrase, previousPhrase, previousBar: index ? events[index - 1].bar : null });
      x += noteGap;
      previousPhrase = phrase;
    });

    const width = Math.max(760, x + 44);
    const svg = svgElement('svg', { viewBox: `0 0 ${width} 122`, role: 'group', 'aria-labelledby': 'music-score-svg-title music-score-svg-description', class: 'music-score-svg' });
    const title = svgElement('title', { id: 'music-score-svg-title' });
    title.textContent = 'Khuông nhạc tham chiếu';
    const description = svgElement('desc', { id: 'music-score-svg-description' });
    description.textContent = 'Khóa Sol, nhịp bốn phần tư và các nốt chuẩn hóa từ dữ liệu sự kiện đã được kiểm tra.';
    svg.append(title, description);
    for (let line = 0; line < 5; line += 1) {
      svg.appendChild(svgElement('line', { x1: 24, y1: staffTop + line * lineGap, x2: width - 18, y2: staffTop + line * lineGap, class: 'music-score-staff-line' }));
    }
    const clef = svgElement('text', { x: 36, y: 83, class: 'music-score-clef', 'aria-hidden': 'true' });
    clef.textContent = '𝄞';
    svg.appendChild(clef);
    const meter = svgElement('text', { x: 70, y: 58, class: 'music-score-meter', 'aria-hidden': 'true' });
    meter.textContent = '4';
    const meterBottom = svgElement('text', { x: 70, y: 79, class: 'music-score-meter', 'aria-hidden': 'true' });
    meterBottom.textContent = '4';
    svg.append(meter, meterBottom);

    positions.forEach(({ event, x: noteX, y, phrase, previousPhrase: prior, previousBar }) => {
      const barChanged = previousBar !== null && String(event.bar ?? '') !== String(previousBar ?? '');
      if (prior && (phrase !== prior || barChanged)) svg.appendChild(svgElement('line', { x1: noteX - (phrase !== prior ? phraseGap : 2), y1: staffTop - 8, x2: noteX - (phrase !== prior ? phraseGap : 2), y2: staffTop + 4 * lineGap + 8, class: 'music-score-barline' }));
      const durationLabel = Number(event.duration_quarters) >= 4 ? 'whole note' : `${event.duration_quarters || 1} beat`;
      const group = svgElement('g', { class: 'music-score-note', 'data-music-score-note': '', 'data-start-ms': Number(event.start_ms), 'data-end-ms': Number(event.start_ms) + Number(event.duration_ms), tabindex: '0', role: 'button', 'aria-label': `${event.pitch_class || ''}${event.octave || ''}, ${event.phrase || ''}, ${durationLabel}` });
      const pitch = String(event.pitch_class || '');
      if (pitch.includes('#') || pitch.includes('b')) {
        const accidental = svgElement('text', { x: noteX - 15, y: y + 5, class: 'music-score-accidental', 'aria-hidden': 'true' });
        accidental.textContent = pitch.includes('#') ? '♯' : '♭';
        group.appendChild(accidental);
      }
      group.appendChild(svgElement('ellipse', { cx: noteX, cy: y, rx: 6, ry: 4, transform: `rotate(-20 ${noteX} ${y})`, class: 'music-score-note-head' }));
      if (Number(event.duration_quarters) < 4) group.appendChild(svgElement('line', { x1: noteX + 5, y1: y, x2: noteX + 5, y2: y - 28, class: 'music-score-stem' }));
      const label = svgElement('text', { x: noteX - 9, y: 112, class: 'music-score-event-label', 'aria-hidden': 'true' });
      label.textContent = String(event.phrase || '');
      group.appendChild(label);
      group.addEventListener('click', () => { if (typeof root.__musicSeekTo === 'function') root.__musicSeekTo(Number(event.start_ms)); });
      group.addEventListener('keydown', (keyboardEvent) => {
        if (keyboardEvent.key === 'Enter' || keyboardEvent.key === ' ') {
          keyboardEvent.preventDefault();
          if (typeof root.__musicSeekTo === 'function') root.__musicSeekTo(Number(event.start_ms));
        }
      });
      svg.appendChild(group);
    });
    host.replaceChildren(svg);
    root.querySelectorAll('.music-score-event').forEach((event) => {
      const seek = () => { if (typeof root.__musicSeekTo === 'function') root.__musicSeekTo(Number(event.dataset.startMs || 0)); };
      event.addEventListener('click', seek);
      event.addEventListener('keydown', (keyboardEvent) => {
        if (keyboardEvent.key === 'Enter' || keyboardEvent.key === ' ') { keyboardEvent.preventDefault(); seek(); }
      });
    });
  };

  const formatTime = (seconds) => {
    if (!Number.isFinite(seconds) || seconds < 0) return '0:00';
    const minutes = Math.floor(seconds / 60);
    const remainder = Math.floor(seconds % 60).toString().padStart(2, '0');
    return `${minutes}:${remainder}`;
  };

  renderScore();
  const cards = Array.from(root.querySelectorAll('[data-music-audio]'));
  const instrumentSelector = root.querySelector('[data-music-instrument]');
  const audios = [];
  cards.forEach((card) => {
    const audio = card.querySelector('audio[data-music-audio-source]');
    if (!audio) return;
    const previewSrc = audio.getAttribute('data-preview-src') || card.getAttribute('data-preview-src');
    const previewSource = previewSrc;
    if (previewSource) {
      if (localPreview) audio.src = previewSource;
      else audio.removeAttribute('src');
    }
    if (!audio.getAttribute('src')) return;
    audios.push({ card, audio });
    const progress = card.querySelector('[data-music-progress]');
    const seek = card.querySelector('[data-music-seek]');
    const time = card.querySelector('[data-music-time]');
    const originalRate = audio.playbackRate || 1;
    let activeSegmentStart = 0;
    let activeSegmentEnd = 0;
    const selectAudio = () => { selectedAudio = audio; card.setAttribute('aria-current', 'true'); cards.filter((other) => other !== card).forEach((other) => other.removeAttribute('aria-current')); };
    const update = () => {
      const duration = Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : 0;
      const ratio = duration ? Math.min(1, Math.max(0, audio.currentTime / duration)) : 0;
      if (progress) progress.value = ratio;
      if (seek) seek.value = String(ratio);
      if (time) time.textContent = `${formatTime(audio.currentTime)} / ${formatTime(duration)}`;
      const now = audio.currentTime * 1000;
      root.querySelectorAll('.music-score-event, [data-music-score-note]').forEach((event) => {
        const active = now >= Number(event.dataset.startMs || 0) && now < Number(event.dataset.endMs || 0);
        event.toggleAttribute('aria-current', active);
        event.classList.toggle('is-active', active);
      });
      if (activeSegmentEnd > 0 && audio.currentTime * 1000 >= activeSegmentEnd) {
        if (card.querySelector('[data-music-action="repeat"]')?.checked) {
          audio.currentTime = activeSegmentStart / 1000;
          void audio.play();
        } else {
          audio.pause();
          activeSegmentEnd = 0;
        }
      }
    };
    audio.addEventListener('play', selectAudio);
    audio.addEventListener('timeupdate', update);
    audio.addEventListener('loadedmetadata', update);
    audio.addEventListener('durationchange', update);
    audio.addEventListener('pause', update);
    audio.addEventListener('ended', update);
    card.addEventListener('focusin', selectAudio);
    card.querySelectorAll('[data-music-action="play"]').forEach((button) => button.addEventListener('click', () => { selectAudio(); void audio.play(); }));
    card.querySelectorAll('[data-music-action="pause"]').forEach((button) => button.addEventListener('click', () => audio.pause()));
    card.querySelectorAll('[data-music-action="stop"]').forEach((button) => button.addEventListener('click', () => { audio.pause(); audio.currentTime = 0; activeSegmentEnd = 0; update(); }));
    card.querySelectorAll('[data-music-action="rate"]').forEach((select) => select.addEventListener('change', () => { audio.playbackRate = Number(select.value) || 1; }));
    card.querySelectorAll('[data-music-action="reset-rate"]').forEach((button) => button.addEventListener('click', () => { audio.playbackRate = originalRate; const rate = card.querySelector('[data-music-action="rate"]'); if (rate) rate.value = String(originalRate); }));
    card.querySelectorAll('[data-music-action="repeat"]').forEach((input) => input.addEventListener('change', () => { audio.loop = input.checked; }));
    if (seek) seek.addEventListener('input', () => { if (Number.isFinite(audio.duration)) { selectAudio(); audio.currentTime = Number(seek.value) * audio.duration; update(); } });
    card.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') { audio.pause(); audio.currentTime = 0; activeSegmentEnd = 0; update(); }
      if (event.key === 'ArrowLeft') audio.currentTime = Math.max(0, audio.currentTime - 5);
      if (event.key === 'ArrowRight' && Number.isFinite(audio.duration)) audio.currentTime = Math.min(audio.duration, audio.currentTime + 5);
      update();
    });
    card.__musicSegment = (start, end) => { selectAudio(); activeSegmentStart = start; activeSegmentEnd = end; audio.currentTime = start / 1000; void audio.play(); };
    if (!selectedAudio) selectAudio();
  });

  root.__musicSeekTo = (startMs) => {
    const target = selectedAudio || audios[0]?.audio;
    if (!target) return;
    const owner = audios.find((entry) => entry.audio === target);
    if (owner) owner.card.__musicSegment(startMs, 0);
    else { target.currentTime = startMs / 1000; void target.play(); }
  };
  root.querySelectorAll('[data-music-action="segment"]').forEach((button) => button.addEventListener('click', () => {
    const target = selectedAudio || audios[0]?.audio;
    const owner = audios.find((entry) => entry.audio === target);
    if (owner) owner.card.__musicSegment(Number(button.dataset.startMs || 0), Number(button.dataset.endMs || 0));
  }));
  if (instrumentSelector) {
    const activateInstrument = () => {
      const selected = audios.find((entry) => entry.card.dataset.musicInstrument === instrumentSelector.value);
      if (!selected) return;
      audios.forEach((entry) => { if (entry !== selected) entry.audio.pause(); entry.card.toggleAttribute('hidden', entry !== selected); });
      selectedAudio = selected.audio;
      selected.card.setAttribute('aria-current', 'true');
      cards.filter((card) => card !== selected.card).forEach((card) => card.removeAttribute('aria-current'));
    };
    instrumentSelector.addEventListener('change', activateInstrument);
    activateInstrument();
  }
})();
