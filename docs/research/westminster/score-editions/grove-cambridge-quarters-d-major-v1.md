# Grove Cambridge Quarters — D-major notation witness v1

Status: `NOTATION_WITNESS_VERIFIED / PERFORMANCE_REFERENCE_RENDERED / NON_CANONICAL`

This is a versioned research artifact for the existing Westminster Music
investigation. It is not an Authority, Knowledge, Source, Evidence, score
MediaAsset, runtime reference packet or Governance authorization. The
accompanying renders are editorial listening references only; they are not
historically tuned Westminster bell audio and are not historical recordings.

## Edition identity

- Edition key: `grove-cambridge-quarters-d-major-v1`
- Witness: *A Dictionary of Music and Musicians*, “Cambridge Quarters”,
  Grove-derived public transcription and score image.
- Cross-check: W. W. Starmer, *Chimes and Chime Tunes* (1907), pp. 8–9,
  Cambridge Quarters engraving and arrangement history.
- Tonal witness: D major as printed/transcribed by Grove.
- Written duration witness: the Grove notation uses crotchet/quarter-note
  values for the melodic events and a semibreve/whole-note value for the hour.
- Printed rests: none are present in the Grove witness. No inter-phrase gap,
  strike envelope, tempo or clock-mechanism delay is added here.

## Notated events

The following preserves the witness order and bar grouping without assigning
absolute octaves or millisecond timing:

```text
Q1:    F#  E  D  A
Q2:    D  F#  E  A | D  E  F#  D
Q3:    F#  D  E  A | A  E  F#  D | F#  E  D  A
Q4:    D  F#  E  A | D  E  F#  D |
       F#  D  E  A | A  E  F#  D
Hour:  D (whole note)
```

The witness is intentionally recorded as pitch classes only. Its relative
engraving must be resolved into a formal octave policy before this artifact can
become a runtime score; no sounding octave is inferred here.

## What is verified at this label

- Q1–Q4 phrase order and individual pitch spelling in the Grove witness.
- Written quarter-note/whole-note durations and bar grouping.
- The distinction between the old Cambridge arrangement and the documented
  Royal Exchange sequence alteration.
- The source representation's explicit D-major key and final D whole-note
  hour event.

The event-level transcription is machine-checked in
`grove-cambridge-quarters-d-major-v1.json`. The JSON keeps source facts (the
printed pitch spelling, order, values and boundaries) separate from editorial
render assumptions (octave, A4 reference, tempo, phrase gaps and synthetic
instrument model).

## What remains unverified

- Absolute sounding octave, A4 reference, cents deviation, temperament and
  bell partials.
- The G-natural versus G-sharp Westminster source conflict.
- Any transposition from the D-major notation witness to an E-major or other
  Westminster installation pitch map.
- Inter-phrase rests/gaps, strike separation, attack/decay and hourly strike
  timing.
- Whether this historical Cambridge notation is the correct edition for a
  specific Westminster, Sonodo or commercial clock specimen.

## Reproducible reference renders

Two non-public local research outputs were rendered from the same normalized
event list:

- `reference-audio/grove-cambridge-quarters-d-major-v1-piano-reference.wav` —
  `PIANO_REFERENCE`, original additive equal-tempered synthesis.
- `reference-audio/grove-cambridge-quarters-d-major-v1-bell-simulation.wav` —
  `BELL_SIMULATION`, original additive inharmonic bell synthesis.

Both use A4=440 equal temperament, an editorial D4 melody / D3 hour octave,
100 BPM, 0.6-second written quarter slots, and explicit editorial phrase gaps.
Those are listening parameters, not claims about the Cambridge, Westminster or
Big Ben installations. The renderer is
`reference-audio/render_grove_reference.py`; checksums, sizes, duration,
format and score linkage are in `reference-audio/manifest.json`.

No external recording or sample was copied. Rights remain
`RIGHTS_REVIEW_REQUIRED_BEFORE_GOVERNED_INGEST` until the existing Media and
Governance workflow accepts the generated files.

## Prohibited use

Do not set this artifact to the runtime score contract's canonical `VERIFIED`
state, attach it to canonical Knowledge/Evidence, or label either render as
historical bell tuning, a Westminster installation recording, or a
transposition of Big Ben. The local renders are permitted only as explicitly
non-canonical performance references pending specialist and Governance review.
