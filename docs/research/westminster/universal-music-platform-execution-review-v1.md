# NHK V3 Universal Music Platform — execution review v1

Status: `LOCAL_IMPLEMENTATION_REVIEWED / NON_CANONICAL / NOT_DEPLOYED`

## Current state versus mandate baseline

Implemented locally: one type-driven Music dossier projection, one generic
theme renderer, strict optional score/audio reference validation, existing
Media-owner-backed public audio delivery, relation-origin presentation,
Westminster research artifacts, Grove event JSON, Piano WAV and Bell WAV.

The Grove files remain research outputs. They are not Authority, Knowledge,
Source, Evidence, MediaAsset, MediaUsage or Graph records. No new Music or
audio owner was introduced.

The previously observed Westminster stable key is `nhk:music:westminster` and
the previously observed UUID is `1ffade21-4cb8-44b5-ac1f-16de4ee533f6`. A fresh
current revision read-back was not possible: the demo REST endpoint was blocked
by the browser client and the authorized NHK V3 TEST runtime identity is not
available. This is reported as unavailable, not as a current revision claim.

## Architecture and reuse verdict

The generic path is type/profile driven. The Music partial contains no
Westminster, Sonodo or Ave Maria branch. Unit fixtures assert that Westminster,
Sonodo and Ave Maria use the same profile key and section recipe. Media,
MediaAsset and MediaUsage remain separate owners; public audio is emitted only
from `PublicMediaAssetDelivery` after public/readiness/path/checksum/signature
validation.

No duplicate Music entity, semantic writer, Graph store, raw URL shortcut or
production mutation was added.

## Music score contract

The runtime contract validates monophonic public reference events, octave,
pitch spelling, millisecond order, duration, phrase and optional segments. The
Grove research JSON intentionally remains richer research input with source
pitch classes, editorial MIDI/octave mapping, quarter values and render
assumptions; it is not silently promoted into the runtime `VERIFIED` packet.

This preserves the distinction between SOURCE_NOTATION,
NORMALIZED_EDITION, PERFORMANCE_REFERENCE and RENDERED_AUDIO. A future
MusicXML/MIDI/SVG adapter remains follow-up work; no notation image was
fabricated from AI/OCR.

## Westminster research

Great St Mary's currently describes the original Cambridge Quarters as
composed in 1793 and often misnamed Westminster Chimes. The Parliament virtual
tour scopes the Elizabeth Tower installation to four quarter bells, Big Ben as
the hour bell, separate Chiming/Strike Trains and 15-minute operation. Grove
and Starmer support the D-major notation witness and arrangement history.

The official G-natural/G-sharp conflict remains unresolved. The Grove render
therefore remains an editorial D-major reference and is not historical
Westminster tuning.

## Audio and segmentation

Both WAVs use the same 41 JSON events. Independent analysis confirmed 41/41
active event windows and 0-cent maximum scanned fundamental error for the
selected editorial octave. The sequence is Q1/Q2/Q3/Q4/Hour once, with a
28.650023-second output including a 0.40-second tail. It is a demonstration,
not a 60-minute clock-cycle simulation.

Pitch/event accuracy passes. Piano timbre is low-realism additive synthesis;
Bell timbre is synthetic inharmonic synthesis, not a measured Big Ben or
Cambridge model. Higher fidelity requires a commissioned rights-cleared
performance or a redistributable physical-model renderer.

## Media, Governance and public status

The non-public packet is ready at
`media-ingest-packet-grove-reference-v1.md`. No Capture ID, signed scope,
owner confirmation or authorized TEST runtime identity exists, so no Capture,
Governance proposal, MediaAsset, MediaUsage or canonical read-back was
submitted/applied. The deployed Westminster page has no public audio element;
browser playback remains unavailable until governed delivery exists.

## Review classification

- **CRITICAL:** none found in the changed universal Music/Media path.
- **HIGH:** canonical MediaAsset/public delivery, rights approval, current
  canonical revision read-back and authorized integration runtime are missing.
- **MEDIUM:** no runtime MusicXML/MIDI/SVG import/export adapter; score JSON is
  research-format rather than a canonical runtime packet; deployed/browser
  audio and notation QA unavailable.
- **LOW:** current reference timbres are intentionally synthetic and could be
  improved after rights/model approval.

## Test evidence

- Focused Music/Media/frontend/template: 184 tests, 1,498 assertions, exit 0;
  5 warnings and 57 PHPUnit deprecations.
- Full Unit: 3,587 tests, 22,182 assertions, 14 errors, 5 failures. The exact
  current error/failure identities match the pre-Music baseline set recorded in
  `V3_EXECUTION_STATE.md`; no changed Music/frontend test failed.
- Integration: 147 tests, 22 failures, 125 skips. Failures are environment
  gates requiring `NHK_WP_TEST_PATH=public` and the authorized TEST runtime;
  no mutation was attempted.
- Renderer/event/WAV/checksum validation: pass.
- PHP/JS syntax and `git diff --check`: pass for the completed implementation.
