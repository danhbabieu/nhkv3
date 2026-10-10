#!/usr/bin/env python3
"""Resolve the Grove LilyPond witness without inferring octaves from pitch class.

This is a research validator, not a runtime writer.  It preserves the exact
relative-octave source and emits a deterministic, non-canonical event packet
for review.  LilyPond relative mode chooses the nearest diatonic octave with
an interval strictly smaller than a fifth; an apostrophe/comma then shifts the
calculated pitch by an extra octave.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
from pathlib import Path


LILY_SOURCE = r"""\relative f'' { fis4^\"First quarter.\" e d a \bar \"||\" d fis^\"Second quarter.\" e a, d e fis d \bar \"||\" fis d^\"Third quarter.\" e a, a e' fis d fis e d a \bar \"||\" d fis^\"Fourth quarter.\" e a, d e fis d fis d e a, a e' fis d \bar \"||\" d,1^\"Hour.\" \bar \"||\" }"""

TOKENS = [
    ("Q1", "fis e d a"),
    ("Q2", "d fis e a, d e fis d"),
    ("Q3", "fis d e a, a e' fis d fis e d a"),
    ("Q4", "d fis e a, d e fis d fis d e a, a e' fis d"),
    ("Hour", "d,"),
]
DIATONIC = {"c": 0, "d": 1, "e": 2, "f": 3, "g": 4, "a": 5, "b": 6}
SEMITONES = {"c": 0, "d": 2, "e": 4, "f": 5, "g": 7, "a": 9, "b": 11}
PITCH_CLASS = {"is": "#", "es": "b"}


def resolve_note(token: str, previous: tuple[str, int] | None) -> tuple[str, int]:
    match = re.fullmatch(r"([a-g])((?:is|es)?)([',]*)", token)
    if match is None:
        raise ValueError(f"invalid LilyPond note token: {token}")
    letter, accidental, marks = match.groups()
    if previous is None:
        octave = 5
    else:
        previous_letter, previous_octave = previous
        diatonic_delta = DIATONIC[letter] - DIATONIC[previous_letter]
        octave = previous_octave
        while diatonic_delta >= 4:
            octave -= 1
            diatonic_delta -= 7
        while diatonic_delta <= -4:
            octave += 1
            diatonic_delta += 7
    octave += marks.count("'") - marks.count(",")
    return f"{letter.upper()}{PITCH_CLASS.get(accidental, '')}", octave


def midi(pitch_class: str, octave: int) -> int:
    semitone = {"C": 0, "C#": 1, "D": 2, "D#": 3, "E": 4, "F": 5, "F#": 6, "G": 7, "G#": 8, "A": 9, "A#": 10, "B": 11}[pitch_class]
    return 12 * (octave + 1) + semitone


def events(quarter_ms: int = 600) -> list[dict]:
    result: list[dict] = []
    previous: tuple[str, int] | None = None
    cursor = 0
    for phrase, notation in TOKENS:
        bar = 1
        for index, token in enumerate(notation.split(), 1):
            pitch_class, octave = resolve_note(token, previous)
            duration_quarters = 4 if phrase == "Hour" else 1
            result.append({
                "id": f"{phrase.lower()}-{index:02d}",
                "phrase": phrase,
                "bar": bar,
                "index": index,
                "source_token": token,
                "pitch_class": pitch_class,
                "octave": octave,
                "midi": midi(pitch_class, octave),
                "duration_quarters": duration_quarters,
                "start_ms": cursor,
                "duration_ms": duration_quarters * quarter_ms,
                "rest": False,
            })
            cursor += duration_quarters * quarter_ms
            previous = (token[0], octave)
            if phrase != "Hour" and index in {4, 8, 12, 16}:
                bar += 1
    return result


def packet() -> dict:
    resolved = events()
    return {
        "edition_key": "grove-cambridge-quarters-d-major-v1",
        "status": "NOTATION_WITNESS_VERIFIED_PERFORMANCE_REFERENCE_NON_CANONICAL",
        "source_notation": LILY_SOURCE,
        "resolution_policy": "LilyPond relative f''; nearest diatonic octave interval < fifth; explicit octave marks applied after calculation",
        "reference_tempo": {"bpm": 100, "quarter_ms": 600, "historical_authenticity": False},
        "source_facts": {"key": "D major", "meter": "4/4", "melodic_value": "quarter", "hour_value": "whole", "printed_rests": False},
        "events": resolved,
        "segments": [
            {"key": phrase, "label": phrase, "start_ms": next(event["start_ms"] for event in resolved if event["phrase"] == phrase), "end_ms": max(event["start_ms"] + event["duration_ms"] for event in resolved if event["phrase"] == phrase)}
            for phrase, _ in TOKENS
        ],
        "lineage": {"source_url": "https://en.wikisource.org/wiki/A_Dictionary_of_Music_and_Musicians/Cambridge_Quarters", "source_checksum": hashlib.sha256(LILY_SOURCE.encode()).hexdigest(), "supersedes": None},
        "rights_status": "REVIEW_REQUIRED_BEFORE_GOVERNED_INGEST",
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()
    value = packet()
    if args.json:
        print(json.dumps(value, ensure_ascii=False, indent=2))
        return
    assert len(value["events"]) == 41
    assert [event["phrase"] for event in value["events"]].count("Q1") == 4
    assert value["events"][0]["pitch_class"] == "F#" and value["events"][0]["octave"] == 5
    assert value["events"][16]["pitch_class"] == "A" and value["events"][16]["octave"] == 4
    assert value["events"][17]["pitch_class"] == "E" and value["events"][17]["octave"] == 5
    assert value["events"][23]["pitch_class"] == "A" and value["events"][23]["octave"] == 4
    assert value["events"][39]["pitch_class"] == "D" and value["events"][39]["octave"] == 5
    assert value["events"][40]["pitch_class"] == "D" and value["events"][40]["octave"] == 4
    print("PASS Grove relative-octave resolution: 41 events; Q1–Q4 + Hour; no rests; D-major witness")
    print("pitch-range=" + ",".join(f"{event['pitch_class']}{event['octave']}" for event in value["events"][:4]) + " … " + f"{value['events'][-1]['pitch_class']}{value['events'][-1]['octave']}")


if __name__ == "__main__":
    main()
