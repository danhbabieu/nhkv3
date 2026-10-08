#!/usr/bin/env python3
"""Loopback-only Music dossier preview; never writes canonical or public data."""

from __future__ import annotations

import argparse
import html
import json
import mimetypes
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

ROOT = Path(__file__).resolve().parents[2]
SCORE = ROOT / "docs/research/westminster/score-editions/grove-cambridge-quarters-d-major-v1.json"
PIANO = ROOT / "docs/research/westminster/reference-audio/grove-cambridge-quarters-d-major-v1-piano-reference.wav"
BELL = ROOT / "docs/research/westminster/reference-audio/grove-cambridge-quarters-d-major-v1-bell-simulation.wav"


def normalize_grove() -> dict:
    source = json.loads(SCORE.read_text(encoding="utf-8"))
    quarter_ms = 600.0
    events = []
    segments = []
    cursor = 0.0
    current_phrase = None
    phrase_start = 0.0
    for event in source["events"]:
        phrase = event["phrase"]
        if current_phrase is not None and phrase != current_phrase:
            segments.append({"label": current_phrase, "start_ms": phrase_start, "end_ms": cursor})
            cursor += 350.0 if phrase != "Hour" else 800.0
            phrase_start = cursor
        current_phrase = phrase
        duration = float(event.get("duration_quarters", 1)) * quarter_ms
        normalized = dict(event)
        normalized.update({
            "octave": int(event["midi"]) // 12 - 1,
            "start_ms": cursor,
            "duration_ms": duration,
        })
        events.append(normalized)
        cursor += duration
    if current_phrase is not None:
        segments.append({"label": current_phrase, "start_ms": phrase_start, "end_ms": cursor})
    return {"edition_key": source["edition_key"], "events": events, "segments": segments}


def demo_fixture() -> dict:
    pitches = [("C", 4, 60), ("D", 4, 62), ("E", 4, 64), ("G", 4, 67),
               ("G", 4, 67), ("E", 4, 64), ("D", 4, 62), ("C", 4, 60)]
    events = []
    for index, (pitch, octave, midi) in enumerate(pitches):
        events.append({"id": f"demo-{index + 1}", "phrase": "Demo A" if index < 4 else "Demo B",
                       "pitch_class": pitch, "octave": octave, "midi": midi,
                       "start_ms": index * 500.0, "duration_ms": 500.0})
    return {"edition_key": "NON_CANONICAL_TEST_FIXTURE", "events": events,
            "segments": [{"label": "Demo A", "start_ms": 0, "end_ms": 2000},
                         {"label": "Demo B", "start_ms": 2000, "end_ms": 4000}]}


def event_markup(events: list[dict]) -> str:
    return "".join(
        f'<li><button type="button" class="music-score-event" data-start-ms="{event["start_ms"]}" aria-label="{html.escape(str(event["pitch_class"]) + str(event["octave"]) + ", " + str(event["phrase"]))}" '
        f'data-end-ms="{event["start_ms"] + event["duration_ms"]}">'
        f'<strong>{html.escape(str(event["pitch_class"]))}{event["octave"]}</strong>'
        f'<span>{html.escape(str(event["phrase"]))}</span></button></li>' for event in events
    )


def segment_markup(segments: list[dict]) -> str:
    return "".join(
        f'<button type="button" data-music-action="segment" data-start-ms="{segment["start_ms"]}" '
        f'data-end-ms="{segment["end_ms"]}">{html.escape(segment["label"])}</button>' for segment in segments
    )


def audio_card(label: str, mode: str, src: str, instrument: str) -> str:
    return f'''<article class="music-audio-card" data-music-audio data-music-instrument="{instrument}" data-preview-src="{src}">
      <div><h3>{html.escape(label)}</h3><p>{html.escape(mode)}</p></div>
      <audio controls preload="metadata" data-music-audio-source data-preview-src="{src}" aria-label="{html.escape(label)}"></audio>
      <div class="music-audio-controls" aria-label="Điều khiển phát lại"><button type="button" data-music-action="play">Phát</button><button type="button" data-music-action="pause">Tạm dừng</button><button type="button" data-music-action="stop">Dừng</button><label><input type="checkbox" data-music-action="repeat"> Lặp lại</label><label>Tốc độ <select data-music-action="rate"><option value="0.5">0,5×</option><option value="1" selected>1×</option><option value="1.5">1,5×</option><option value="2">2×</option></select></label><button type="button" data-music-action="reset-rate">Về tốc độ gốc</button></div>
      <div class="music-audio-seek-row"><label>Tua tới <input type="range" min="0" max="1" step="0.001" value="0" data-music-seek aria-label="Tua tới trong bản phát"></label></div><progress max="1" value="0" data-music-progress aria-label="Tiến độ phát lại"></progress><span class="music-audio-time" data-music-time aria-live="off">0:00 / 0:00</span>
    </article>'''


def page(fixture: str) -> str:
    data = demo_fixture() if fixture == "demo" else normalize_grove()
    title = "Development demo fixture — NON_CANONICAL_TEST_FIXTURE" if fixture == "demo" else "Grove Cambridge Quarters — loopback reference"
    events = json.dumps(data["events"], ensure_ascii=False, separators=(",", ":"))
    return f'''<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{html.escape(title)}</title><style>
body{{font:16px system-ui,sans-serif;line-height:1.45;margin:0;background:#f7f4ed;color:#17202a}}main{{max-width:1100px;margin:auto;padding:24px}}section,article{{background:#fff;border:1px solid #ccd4da;border-radius:8px;padding:16px;margin:16px 0}}.music-score-staff{{overflow:auto;border:1px solid #ccd4da;padding:12px}}.music-score-svg{{min-width:760px;width:100%;height:auto}}.music-score-staff-line,.music-score-barline{{stroke:#17202a;stroke-width:1}}.music-score-clef{{fill:#17202a;font:48px Georgia,serif}}.music-score-meter,.music-score-event-label{{fill:#54616b;font:700 12px sans-serif}}.music-score-note{{cursor:pointer}}.music-score-note-head{{fill:#17202a;stroke:#17202a}}.music-score-stem{{stroke:#17202a;stroke-width:1.5}}.music-score-accidental{{fill:#17202a;font:18px Georgia,serif}}.music-score-note[aria-current=true] .music-score-note-head,.music-score-note.is-active .music-score-note-head{{fill:#a33b20;stroke:#a33b20}}.music-score-events{{columns:4}}.music-score-segments,.music-audio-controls{{display:flex;flex-wrap:wrap;gap:8px;align-items:center}}button,select,input{{font:inherit}}button{{padding:6px 10px}}.music-audio-seek-row{{display:flex;gap:8px;align-items:center}}.music-audio-seek-row input{{width:min(80vw,700px)}}progress{{display:block;width:100%}}.music-audio-time{{font-variant-numeric:tabular-nums;color:#54616b}}@media(max-width:600px){{main{{padding:12px}}.music-score-events{{columns:2}}}}
</style></head><body><main class="music-dossier" data-local-preview="true"><h1>{html.escape(title)}</h1><p>Loopback-only preview. Geen canonical mutation of public delivery.</p><section id="music-score"><h2>Bản nhạc</h2><div class="music-score-staff" data-music-score data-score-events='{html.escape(events, quote=True)}' role="region" aria-label="Bản xem dạng khuông nhạc chuẩn hóa"></div><ol class="music-score-events" aria-label="Các sự kiện trong bản nhạc">{event_markup(data["events"])}</ol><div class="music-score-segments" aria-label="Các đoạn trong bản nhạc">{segment_markup(data["segments"])}</div></section><section id="music-audio"><h2>Audio reference</h2><label>Nhạc cụ <select data-music-instrument aria-label="Chọn nhạc cụ"><option value="piano">Piano reference</option><option value="bell">Bell simulation</option></select></label>{audio_card("Piano reference", "PIANO_REFERENCE", "/audio/piano.wav", "piano")}{audio_card("Bell simulation", "BELL_SIMULATION", "/audio/bell.wav", "bell")}</section></main><script src="/music-dossier.js"></script></body></html>'''


class PreviewHandler(BaseHTTPRequestHandler):
    server_version = "NHKMusicPreview/1.0"

    def do_POST(self):  # Explicitly reject writes; preview has no mutation endpoint.
        self.send_error(405, "Loopback preview is read-only")

    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path in ("/", ""):
            fixture = parse_qs(parsed.query).get("fixture", ["grove"])[0]
            self.send_bytes(page(fixture).encode("utf-8"), "text/html; charset=utf-8")
        elif parsed.path == "/music-dossier.js":
            self.send_bytes((ROOT / "public/wp-content/themes/nhk-v3/music-dossier.js").read_bytes(), "text/javascript; charset=utf-8")
        elif parsed.path == "/audio/piano.wav":
            self.send_bytes(PIANO.read_bytes(), "audio/wav")
        elif parsed.path == "/audio/bell.wav":
            self.send_bytes(BELL.read_bytes(), "audio/wav")
        elif parsed.path == "/health":
            self.send_bytes(b"ok\n", "text/plain; charset=utf-8")
        else:
            self.send_error(404)

    def do_HEAD(self):
        self.do_GET()

    def send_bytes(self, payload: bytes, content_type: str):
        self.send_response(200)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(payload)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(payload)

    def log_message(self, *_args):
        return


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=8765)
    args = parser.parse_args()
    if args.host not in {"127.0.0.1", "localhost", "::1"}:
        raise SystemExit("This preview server is loopback-only")
    ThreadingHTTPServer((args.host, args.port), PreviewHandler).serve_forever()


if __name__ == "__main__":
    main()
