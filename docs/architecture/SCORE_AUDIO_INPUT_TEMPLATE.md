# NHK V3 — Score and Audio Input Template

**Trạng thái:** transient readiness worksheet — không phải score store, MediaAsset
writer hay public URL contract.

## 1. Shared boundary

Score and audio preparation reuses the existing Music reference,
Media/MediaAsset/MediaUsage and public eligibility contracts. It must not create
a new schema or owner. A file in `docs/research/`, a local JSON score or a
local WAV is not a canonical MediaAsset.

The packet is not complete until source, scope, provenance, identity,
verification, rights and governed read-back are separately evaluated.

## 2. Score worksheet

| Field | Preparation |
|---|---|
| work/edition identity | |
| notation witness and edition | |
| source title/publisher/archive | |
| page/figure/locator | |
| notation form | score / facsimile / transcription / arrangement |
| phrase/segment order | |
| clef/key signature/meter/tempo | |
| pitch class/octave/duration | record only what source supports |
| transposition/arrangement | |
| source conflict | `DISPUTED` with each source retained |
| file identity/checksum | only when a governed asset candidate exists |
| rights/licence/reuse permission | |
| verification status | `MISSING` / `UNKNOWN` / `CANDIDATE` / `DISPUTED` / `VERIFIED` / `BLOCKED` |
| public readiness | `PUBLIC_READY` only after owning projection/read-back |

A notation witness is not automatically a canonical score. Pitch class alone does
not establish octave, tuning, tempo, historical arrangement or mechanical timing.

## 3. Audio/recording worksheet

| Classification | Required distinction |
|---|---|
| `HISTORICAL_RECORDING` | exact recording identity, chain of custody, provenance, checksum, licence and authenticity review |
| `MECHANICAL_CLOCK_RECORDING` | exact specimen/clock context and recording provenance; not universal Music capability |
| `PIANO_REFERENCE` | score/version reference and synthetic/reference disclosure |
| `BELL_SIMULATION` | simulation disclosure; never call it an authentic Big Ben or historical bell recording |
| `SYNTHETIC_RENDERING` | render method, parameters, score lineage and non-historical disclosure |

| Metadata | Preparation |
|---|---|
| Music/score version | |
| asset candidate and canonical Media/MediaAsset read-back | |
| source/recording provenance and locator | |
| duration, sample rate, channels, format | |
| pitch/tuning/tempo assumptions | |
| render method/instrument/model | |
| rights holder/licence/reuse permission | |
| checksum/file identity | |
| public delivery reference | governed MediaAsset route only |
| review and verification | |

A URL is only a locator until the Media/MediaAsset owner confirms identity,
readiness, rights and delivery. Synthetic output is not Evidence of historical
sound.

## 4. Media readiness gates

| Gate | State and evidence |
|---|---|
| source and owner identity | `MISSING` / `CANDIDATE` / `VERIFIED` |
| integrity/checksum | `MISSING` / `VERIFIED` / `BLOCKED` |
| rights/licence | `MISSING` / `REVIEW_REQUIRED` / `VERIFIED` / `BLOCKED` |
| MediaAsset/MediaUsage readiness | `MISSING` / `VERIFIED` / `BLOCKED` |
| public-safe delivery | `MISSING` / `TEMPORARILY_UNAVAILABLE` / `PUBLIC_READY` |

No missing rights or ungoverned delivery may reach `PUBLIC_READY`.

## 5. Public score/audio checklist

Before projection, verify canonical Music subject and score/recording scope were
read back; public packets contain only allowlisted reader-safe fields; private
Source/Evidence metadata, UUIDs, stable keys and raw storage paths are omitted;
score/audio status is separate from Music identity completeness; public players
use governed MediaAsset delivery, never a raw source URL; and missing evidence,
rights, feature or runtime dependency renders an honest unavailable/blocked state.

## 6. Example safety

Westminster, Sonodo and Ave Maria may be used as documentation examples only.
Any local reference audio, synthetic render, unresolved pitch, disputed source or
unreviewed licence remains `CANDIDATE`, `DISPUTED` or `BLOCKED` and cannot
authorize canonical write or public delivery.
