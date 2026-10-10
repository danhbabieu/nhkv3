# Grove Cambridge Quarters — relative-octave validation v1

Status: `NOTATION_WITNESS_VERIFIED / PERFORMANCE_REFERENCE_ONLY / NON_CANONICAL`

This report is a lineage-preserving companion to
`grove-cambridge-quarters-d-major-v1.json`. The original JSON and its WAV
manifest were not overwritten. This companion resolves the exact LilyPond
source into a separate deterministic candidate event packet for governed
review; it is not a canonical Music record, signed packet, approved status or
public authorization.

## Witness

The Wikisource score transcription is:

```lilypond
\relative f'' {
  fis4 e d a ||
  d fis e a, d e fis d ||
  fis d e a, a e' fis d fis e d a ||
  d fis e a, d e fis d fis d e a, a e' fis d ||
  d,1
}
```

The page identifies D major, 4/4 notation, quarter-note melodic values and a
whole-note hour value, and says there are no printed inter-phrase rests. The
same page separately records the Royal Exchange sequence alteration. The
access page states CC BY-SA for its text; derivative score distribution still
requires attribution/share-alike review and underlying-edition review.

## Resolution rule

The companion parser applies LilyPond relative mode in the documented order:
start at absolute `f''` (F5); for each later note choose the nearest diatonic
octave whose interval from the previous note is strictly less than a fifth;
then apply explicit apostrophe/comma octave marks. Accidentals do not choose
the relative octave. This is why pitch class alone is insufficient.

## Deterministic result

Reference timing is 100 BPM (`600 ms` per written quarter), solely as a
documented playback reference. It is not claimed to be historically authentic.
There are 41 events, no rests, and no invented phrase gaps:

| Segment | Count | Resolved pitches | Start–end |
|---|---:|---|---:|
| Q1 | 4 | F#5 E5 D5 A4 | 0–2400 ms |
| Q2 | 8 | D5 F#5 E5 A4 D5 E5 F#5 D5 | 2400–7200 ms |
| Q3 | 12 | F#5 D5 E5 A4 A4 E5 F#5 D5 F#5 E5 D5 A4 | 7200–14400 ms |
| Q4 | 16 | D5 F#5 E5 A4 D5 E5 F#5 D5 F#5 D5 E5 A4 A4 E5 F#5 D5 | 14400–24000 ms |
| Hour | 1 | D4 | 24000–26400 ms |

The event-stream checksum of the parser output is
`99a73ed9ae4d5d22886df10a06f50b7e9f57af1d871fecbcc47a4d1ec8222b79`.

The `d,1` is D4 relative to the preceding D5; it is not an inferred D3.
The `e'` tokens explicitly return to E5. The official Westminster G-sharp/G
conflict and any installation transposition/tuning remain separate
source/edition variants and are not changed by this notation resolution.

## Governed handoff state

No current server-issued signed Capture-bound packet, owner read-back, rights
decision, or governed Media/MediaAsset delivery was available. Therefore this
candidate must not be passed to the public `MusicReferenceContract` as
`VERIFIED`, and no Piano/Bell/Gong browser controls may be enabled from it.

The validator is `parse_grove_relative_octaves.py` and emits the structured
candidate packet with `--json` without writing or mutating canonical data.
