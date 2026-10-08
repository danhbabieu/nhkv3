#!/usr/bin/env python3
"""Reproducibly render and validate the non-canonical Grove reference edition.

This uses only Python's standard library and original additive synthesis. It
does not read, copy, or transform a historical recording or third-party sample.
The score JSON is the sole event source for both renderers.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import struct
import wave
from pathlib import Path


ROOT = Path(__file__).resolve().parent
SCORE = ROOT.parent / "score-editions" / "grove-cambridge-quarters-d-major-v1.json"
SAMPLE_RATE = 44_100
AMPLITUDE = 0.72


def load_score() -> dict:
    score = json.loads(SCORE.read_text(encoding="utf-8"))
    expected = {
        "Q1": ["F#", "E", "D", "A"],
        "Q2": ["D", "F#", "E", "A", "D", "E", "F#", "D"],
        "Q3": ["F#", "D", "E", "A", "A", "E", "F#", "D", "F#", "E", "D", "A"],
        "Q4": ["D", "F#", "E", "A", "D", "E", "F#", "D", "F#", "D", "E", "A", "A", "E", "F#", "D"],
        "Hour": ["D"],
    }
    actual = {phrase: score["source_facts"]["source_note_spelling"][phrase] for phrase in expected}
    if actual != expected:
        raise ValueError("source pitch order differs from the validated Grove witness")
    if len(score["events"]) != 41:
        raise ValueError("expected 41 monophonic source events")
    for event in score["events"]:
        if event["duration_quarters"] not in (1, 4):
            raise ValueError(f"unsupported duration for {event['id']}")
    return score


def midi_hz(midi: int) -> float:
    return 440.0 * (2.0 ** ((midi - 69) / 12.0))


def event_schedule(score: dict) -> list[dict]:
    assumptions = score["render_assumptions"]
    quarter = float(assumptions["quarter_seconds"])
    phrase_gap = float(assumptions["phrase_gap_seconds"])
    hour_gap = float(assumptions["hour_gap_seconds"])
    scheduled = []
    cursor = 0.0
    previous_phrase = None
    for event in score["events"]:
        if previous_phrase is not None and event["phrase"] != previous_phrase:
            cursor += hour_gap if event["phrase"] == "Hour" else phrase_gap
        duration = quarter * event["duration_quarters"]
        scheduled.append({**event, "onset_seconds": round(cursor, 6), "duration_seconds": duration})
        cursor += duration
        previous_phrase = event["phrase"]
    return scheduled


def add_tone(buffer: list[float], onset: float, duration: float, frequency: float, kind: str) -> None:
    start = int(onset * SAMPLE_RATE)
    length = int(duration * SAMPLE_RATE)
    partials = (
        ((1.0, 1.00), (0.48, 2.01), (0.25, 3.02), (0.12, 4.07), (0.06, 5.11))
        if kind == "piano"
        else ((1.0, 1.00), (0.62, 2.01), (0.36, 2.93), (0.22, 4.16), (0.12, 5.40), (0.06, 7.20))
    )
    attack = max(1, int(0.018 * SAMPLE_RATE))
    for index in range(length):
        t = index / SAMPLE_RATE
        if kind == "piano":
            envelope = (min(1.0, index / attack) * math.exp(-2.4 * t))
        else:
            envelope = min(1.0, index / attack) * math.exp(-1.65 * t) * (0.96 + 0.04 * math.exp(-18 * t))
        sample = sum(weight * math.sin(2 * math.pi * frequency * ratio * t) for weight, ratio in partials)
        target = start + index
        if target < len(buffer):
            buffer[target] += sample * envelope * AMPLITUDE / sum(weight for weight, _ in partials)


def write_wav(path: Path, score: dict, kind: str) -> dict:
    scheduled = event_schedule(score)
    end = max(event["onset_seconds"] + event["duration_seconds"] for event in scheduled)
    buffer = [0.0] * (int((end + 0.4) * SAMPLE_RATE) + 1)
    for event in scheduled:
        add_tone(buffer, event["onset_seconds"], event["duration_seconds"], midi_hz(event["midi"]), kind)
    peak = max(abs(value) for value in buffer) or 1.0
    scale = min(1.0, 0.96 / peak)
    pcm = b"".join(struct.pack("<h", max(-32768, min(32767, int(value * scale * 32767)))) for value in buffer)
    path.parent.mkdir(parents=True, exist_ok=True)
    with wave.open(str(path), "wb") as handle:
        handle.setnchannels(1)
        handle.setsampwidth(2)
        handle.setframerate(SAMPLE_RATE)
        handle.writeframes(pcm)
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    with wave.open(str(path), "rb") as handle:
        frames = handle.getnframes()
        rate = handle.getframerate()
    return {
        "file": path.name,
        "label": "PIANO_REFERENCE" if kind == "piano" else "BELL_SIMULATION",
        "mime_type": "audio/wav",
        "sha256": digest,
        "file_size_bytes": path.stat().st_size,
        "duration_seconds": round(frames / rate, 6),
        "sample_rate": rate,
        "channels": 1,
        "frames": frames,
        "event_count": len(scheduled),
        "score_edition": score["edition_key"],
        "render_engine": "NHK original additive synthesis / Python standard library",
        "source_audio": "none",
        "rights_status": "RIGHTS_REVIEW_REQUIRED_BEFORE_GOVERNED_INGEST",
    }


def validate(score: dict, manifest: dict) -> None:
    scheduled = event_schedule(score)
    expected_pitches = [event["pitch_class"] for event in score["events"]]
    actual_pitches = [event["pitch_class"] for event in scheduled]
    if expected_pitches != actual_pitches or len(scheduled) != 41:
        raise ValueError("score-to-render event correspondence failed")
    for event in scheduled:
        if event["duration_seconds"] <= 0 or event["midi"] < 0 or event["midi"] > 127:
            raise ValueError(f"invalid normalized event {event['id']}")
    for item in manifest["files"]:
        path = ROOT / item["file"]
        with wave.open(str(path), "rb") as handle:
            if handle.getnchannels() != 1 or handle.getsampwidth() != 2 or handle.getframerate() != SAMPLE_RATE:
                raise ValueError(f"invalid WAV format {path.name}")
            if handle.getnframes() != item["frames"]:
                raise ValueError(f"WAV frame count changed {path.name}")
        if hashlib.sha256(path.read_bytes()).hexdigest() != item["sha256"]:
            raise ValueError(f"SHA-256 changed {path.name}")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--validate-only", action="store_true")
    args = parser.parse_args()
    score = load_score()
    manifest_path = ROOT / "manifest.json"
    if args.validate_only:
        validate(score, json.loads(manifest_path.read_text(encoding="utf-8")))
        print("PASS score/event/WAV/checksum validation")
        return
    manifest = {
        "status": "NON_PUBLIC_RESEARCH_OUTPUT",
        "score_edition": score["edition_key"],
        "source_facts_vs_render_assumptions": "separated in score JSON",
        "files": [
            write_wav(ROOT / "grove-cambridge-quarters-d-major-v1-piano-reference.wav", score, "piano"),
            write_wav(ROOT / "grove-cambridge-quarters-d-major-v1-bell-simulation.wav", score, "bell"),
        ],
    }
    validate(score, manifest)
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(manifest, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
