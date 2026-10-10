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
      const pitch = String(event.pitch_class || '');
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
      const isRest = event.rest === true;
      const group = svgElement('g', { class: 'music-score-note', 'data-music-score-note': '', 'data-start-ms': Number(event.start_ms), 'data-end-ms': Number(event.start_ms) + Number(event.duration_ms), tabindex: '0', role: 'button', 'aria-label': `${isRest ? 'rest' : `${event.pitch_class || ''}${event.octave || ''}`}, ${event.phrase || ''}, ${durationLabel}` });
      if (isRest) {
        const rest = svgElement('text', { x: noteX - 7, y: y + 7, class: 'music-score-clef', 'aria-hidden': 'true' });
        rest.textContent = '𝄽';
        group.appendChild(rest);
      } else if (pitch.includes('#') || pitch.includes('b')) {
        const accidental = svgElement('text', { x: noteX - 15, y: y + 5, class: 'music-score-accidental', 'aria-hidden': 'true' });
        accidental.textContent = pitch.includes('#') ? '♯' : '♭';
        group.appendChild(accidental);
      }
      if (!isRest) {
        group.appendChild(svgElement('ellipse', { cx: noteX, cy: y, rx: 6, ry: 4, transform: `rotate(-20 ${noteX} ${y})`, class: 'music-score-note-head' }));
        if (Number(event.duration_quarters) < 4) group.appendChild(svgElement('line', { x1: noteX + 5, y1: y, x2: noteX + 5, y2: y - 28, class: 'music-score-stem' }));
      }
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

  // Interactive playback is deliberately score-driven. It never uses the
  // historic/reference audio elements above, and tempo changes event spacing,
  // not oscillator frequency, so pitch remains invariant.
  const playback = root.querySelector('[data-music-playback]');
  const scoreHost = root.querySelector('[data-music-score]');
  const AudioContextClass = window.AudioContext || window.webkitAudioContext;
  if (playback && scoreHost && AudioContextClass) {
    let scoreEvents = [];
    try { scoreEvents = JSON.parse(scoreHost.dataset.scoreEvents || '[]'); } catch (error) { scoreEvents = []; }
    scoreEvents = scoreEvents.filter((event) => event && Number.isFinite(Number(event.start_ms)) && Number.isFinite(Number(event.duration_ms)));
    const playableEvents = scoreEvents.filter((event) => !event.rest && Number.isInteger(Number(event.octave)));
    const instrument = playback.querySelector('[data-playback-instrument]');
    const tempo = playback.querySelector('[data-playback-tempo]');
    const tempoValue = playback.querySelector('[data-playback-tempo-value]');
    const duration = playback.querySelector('[data-playback-duration]');
    const progress = playback.querySelector('[data-playback-progress]');
    const time = playback.querySelector('[data-playback-time]');
    let context = null;
    let master = null;
    let scheduled = [];
    let startedAt = 0;
    let pausedAt = 0;
    let raf = 0;
    let state = 'stopped';
    const midi = (event) => {
      const base = { C: 0, 'C#': 1, Db: 1, D: 2, 'D#': 3, Eb: 3, E: 4, F: 5, 'F#': 6, Gb: 6, G: 7, 'G#': 8, Ab: 8, A: 9, 'A#': 10, Bb: 10, B: 11 };
      return 12 * (Number(event.octave) + 1) + (base[String(event.pitch_class)] ?? 0);
    };
    const frequency = (event) => 440 * Math.pow(2, (midi(event) - 69) / 12);
    const secondsPerSequence = () => {
      if (!scoreEvents.length) return 0;
      return Math.max(...scoreEvents.map((event) => Number(event.start_ms) + Number(event.duration_ms))) / 1000;
    };
    const selectedDuration = () => Math.max(15, Math.min(60, Number(duration?.value || 15))) * 60;
    const tempoScale = () => Math.max(0.5, Math.min(1.5, Number(tempo?.value || 100) / 100));
    const clearScheduled = () => { scheduled.forEach((node) => { try { node.stop(); } catch (error) {} try { node.disconnect(); } catch (error) {} }); scheduled = []; };
    const envelope = (gain, start, length, peak) => { gain.gain.setValueAtTime(0.0001, start); gain.gain.exponentialRampToValueAtTime(peak, start + 0.012); gain.gain.exponentialRampToValueAtTime(0.0001, start + Math.max(0.03, length)); };
    const voice = (event, when, length, kind) => {
      if (!context || !master) return;
      const fundamental = frequency(event);
      const partials = kind === 'PIANO' ? [[1, 1], [2, 0.28], [3, 0.12], [4, 0.06]] : kind === 'BELL' ? [[1, 0.72], [2.01, 0.32], [2.74, 0.2], [4.07, 0.12]] : [[1, 0.8], [1.48, 0.38], [2.02, 0.22], [2.91, 0.13]];
      partials.forEach(([ratio, level]) => {
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        oscillator.type = kind === 'PIANO' ? 'triangle' : 'sine';
        oscillator.frequency.setValueAtTime(fundamental * ratio, when);
        gain.connect(master);
        oscillator.connect(gain);
        envelope(gain, when, Math.min(length * (kind === 'PIANO' ? 1.25 : 1.8), kind === 'PIANO' ? 2.2 : 3.5), 0.16 * level);
        oscillator.start(when);
        oscillator.stop(when + Math.min(length * (kind === 'PIANO' ? 1.25 : 1.8), kind === 'PIANO' ? 2.2 : 3.5) + 0.04);
        scheduled.push(oscillator);
      });
    };
    const update = () => {
      if (!context || state === 'stopped') return;
      const elapsed = state === 'paused' ? pausedAt : Math.max(0, context.currentTime - startedAt);
      const total = selectedDuration();
      const ratio = Math.min(1, elapsed / total);
      if (progress) progress.value = String(ratio);
      if (time) time.textContent = `${formatTime(elapsed)} / ${formatTime(total)}`;
      const activeMs = (elapsed % Math.max(1, secondsPerSequence() / tempoScale())) * 1000;
      root.querySelectorAll('.music-score-event, [data-music-score-note]').forEach((event) => { const active = activeMs >= Number(event.dataset.startMs || 0) && activeMs < Number(event.dataset.endMs || 0); event.toggleAttribute('aria-current', active); event.classList.toggle('is-active', active); });
      if (state === 'playing' && elapsed >= total) { stop(); return; }
      raf = window.requestAnimationFrame(update);
    };
    const schedule = (offsetSeconds = 0) => {
      if (!context || !playableEvents.length) return;
      clearScheduled();
      const scale = tempoScale();
      const sequence = secondsPerSequence() / scale;
      const limit = selectedDuration();
      const start = context.currentTime + 0.04;
      const first = Math.floor(offsetSeconds / sequence);
      for (let loop = first; loop * sequence < limit; loop += 1) {
        playableEvents.forEach((event) => {
          const when = start + loop * sequence + (Number(event.start_ms) / 1000) / scale;
          const length = (Number(event.duration_ms) / 1000) / scale;
          if (when < start + limit && when + length > start) voice(event, when, length, instrument?.value || 'PIANO');
        });
      }
      startedAt = context.currentTime - offsetSeconds;
    };
    const ensureContext = async () => { if (!context) { context = new AudioContextClass(); master = context.createGain(); master.gain.value = 0.72; master.connect(context.destination); } if (context.state === 'suspended') await context.resume(); };
    const play = async (restart = false) => { if (!scoreEvents.length) return; await ensureContext(); if (restart) pausedAt = 0; if (state === 'playing') return; schedule(pausedAt); state = 'playing'; window.cancelAnimationFrame(raf); raf = window.requestAnimationFrame(update); };
    const pause = () => { if (state !== 'playing' || !context) return; pausedAt = Math.max(0, context.currentTime - startedAt); clearScheduled(); state = 'paused'; update(); };
    const stop = () => { clearScheduled(); window.cancelAnimationFrame(raf); pausedAt = 0; state = 'stopped'; if (progress) progress.value = '0'; if (time) time.textContent = `0:00 / ${formatTime(selectedDuration())}`; root.querySelectorAll('.music-score-event, [data-music-score-note]').forEach((event) => { event.removeAttribute('aria-current'); event.classList.remove('is-active'); }); };
    const replay = () => { stop(); void play(true); };
    playback.querySelectorAll('[data-playback-action="play"]').forEach((button) => button.addEventListener('click', () => { void play(); }));
    playback.querySelectorAll('[data-playback-action="pause"]').forEach((button) => button.addEventListener('click', pause));
    playback.querySelectorAll('[data-playback-action="stop"]').forEach((button) => button.addEventListener('click', stop));
    playback.querySelectorAll('[data-playback-action="replay"]').forEach((button) => button.addEventListener('click', replay));
    tempo?.addEventListener('input', () => { if (tempoValue) tempoValue.value = `${tempo.value}%`; if (state === 'playing') { pausedAt = Math.max(0, context.currentTime - startedAt); schedule(pausedAt); } });
    duration?.addEventListener('change', () => { if (time && state !== 'playing') time.textContent = `0:00 / ${formatTime(selectedDuration())}`; if (state === 'playing') { pausedAt = Math.max(0, context.currentTime - startedAt); schedule(pausedAt); } });
    instrument?.addEventListener('change', () => { if (state === 'playing') { pausedAt = Math.max(0, context.currentTime - startedAt); schedule(pausedAt); } });
    root.__musicPlayback = { play, pause, stop, replay, getState: () => state, getScheduledCount: () => scheduled.length, getScorePitches: () => playableEvents.map((event) => `${event.pitch_class}${event.octave}`) };
    if (tempoValue) tempoValue.value = '100%';
  }
})();
