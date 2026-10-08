# Universal Music Dossier — Westminster-First Design

**Date:** 2026-10-08
**Status:** Design approved in conversation; implementation pending written-spec review
**Scope:** Read-only public projection and frontend presentation

## 1. Outcome

NHK V3 will expose one reusable Music dossier presentation for every public
canonical Music Authority entity. Westminster is the first readback target, but
the implementation will not contain Westminster-specific rendering branches,
hardcoded quarter-hour data, guessed clock models, or fabricated research.

The first implementation slice will assemble existing canonical owners into a
Music-shaped public read model and provide an explicit, validated seam for
future score and audio references. It will not mutate the Westminster Authority
record, create Graph edges, create Knowledge/Source/Evidence records, upload
media, or introduce a parallel semantic store.

## 2. Contract boundaries

The Constitution remains authoritative. The design follows these ownership
rules:

- Authority owns Music identity and canonical payload.
- Knowledge owns atomic historical, technical, and research claims.
- Source and Evidence own provenance and support.
- Graph owns only registered relationships and their path context.
- Media and Video retain their existing asset/reference boundaries.
- Dictionary remains lexical curation and is consumed only through its public
  projection when available.
- WordPress owns editorial article prose and permalinks.
- The Music dossier is a read-only projection over those owners.

The current Graph registry already permits the relevant musical relationships:
`configured_with_music`, `supports_music`, and `observed_playing_music`, plus
the approved structural paths through Variant, Model, and Brand. The dossier
will not register a new relation merely to make a UI section easier to fill.

## 3. Projection design

### 3.1 Profile registration

The Music profile in `EntityProfileRegistry` will declare a reusable dossier
recipe and an ordered set of the twelve reader-facing section slots:

1. identity and overview;
2. audio reference;
3. score and notation;
4. introduction and significance;
5. origin, history, authorship and timeline;
6. musical structure and technical analysis;
7. application in clock mechanisms;
8. verified clocks, variants, models and brands;
9. image, video, audio and document library;
10. Dictionary, Knowledge and related research;
11. Sources, Evidence and uncertainty;
12. related melodies and further exploration.

The declaration is presentation metadata, not a new semantic vocabulary. A
section is rendered only when its projection contains eligible content.

### 3.2 MusicDossierProjection

`MusicDossierProjection` will enrich the existing detail-only dossier. It will
consume the base identity, public-safe Knowledge/Evidence, bounded relation
sections, Media/Video/Article projections, and any existing Dictionary detail
projection. It will preserve relation origin (`DIRECT` or `DERIVED`), hop count,
ordered predicates, and via types.

The projection will:

- use the canonical Music name and existing public description for identity;
- expose only direct subject-scoped claims as Music claims;
- use existing registered relations for clock/application context;
- keep Variant/Model/Specimen observations at their original scope;
- expose eligible Media/Video/Article items through their owner URLs;
- omit private provenance, lifecycle fields, UUIDs, stable keys, revisions, and
  internal diagnostics from the public presentation;
- preserve honest unavailable/empty states without creating placeholders.

No reachability-only path will be treated as truth. In particular, a Music
configuration on one Variant will not become a Brand-wide capability.

### 3.3 Score and audio seam

A pure score/audio reference contract will normalize and validate optional
future input without persisting it. A valid score reference must identify:

- a score version and source/provenance;
- ordered note events with pitch, octave, duration and timing;
- phrase/segment boundaries;
- tempo and tuning/pitch reference;
- verification state and edition/variant notes where applicable.

A valid audio reference must identify:

- mode (`PIANO`, `BELL_SIMULATION`, or a separately verified recording);
- source score version;
- instrument/sample/render method;
- tuning, pitch reference, tempo and duration;
- provenance, rights/license and verification state.

The frontend may expose playback only for validated, public-eligible references.
Piano and Bell renderings may share score events, but rendering fidelity and
historical recording authenticity remain separate assertions. A synthesized
bell must say that it is a simulation. There is no arbitrary MP3, browser beep,
or generative fallback.

This slice deliberately does not add a new Authority payload field or
database migration. The normalized seam is an extension point until the
canonical storage owner and governed ingestion path for audio/score assets are
approved. Existing image Media cannot be repurposed as an audio binary owner.

## 4. Frontend design

The existing entity route remains the canonical route and identity source. A
reusable `music-dossier.php` partial will render the Music profile from the
projection packet. Westminster, Sonodo, and any future Music entity will use
the same partial and section ordering.

The partial will:

- render Vietnamese-first accessible headings and labels;
- include audio controls only when validated references exist;
- support segment selection, progress, speed/reset, and repeat only where the
  browser/runtime packet supplies those capabilities;
- keep score rendering responsive and omit it when no validated notation is
  available;
- preserve keyboard focus, semantic labels, mobile layout, and honest empty
  states;
- reuse the existing public URL, SEO, media and relation helpers.

The template must contain no stable-key or name-specific Westminster branch.

## 5. Westminster research policy

The known Westminster identity and route are readback inputs only. Historical
claims remain unverified until supported by eligible international primary or
recognized scholarly sources. The implementation will distinguish composition,
earliest documented use, selection for Westminster, installation, first
performance, and later dissemination.

The following are not accepted as score-generation facts without verification:

- a single composer attribution;
- a settled Handel's *Messiah* relationship;
- a precise 1793/1859 chronology;
- absolute octave or concert-pitch frequencies;
- exact durations, rests, tempo, tuning, or edition choice;
- a claim that every related clock or Brand uses Westminster.

The commonly cited pitch classes G-sharp, F-sharp, E, and B are insufficient
for absolute playback generation until octave and tuning are sourced.

## 6. Failure and safety behavior

- unavailable Graph, Knowledge, Media, Video, Dictionary, score, or audio data
  remains unavailable and does not trigger writes;
- invalid or private records are omitted from public sections;
- malformed score/audio packets fail closed and expose no playback controls;
- unsupported audio ownership remains a documented blocker;
- no frontend test is allowed to claim musical or historical accuracy;
- staging, production, V2, and existing semantic records remain untouched.

## 7. Verification

The implementation will add focused tests for:

- Music profile registration and twelve-section reuse;
- section omission and partial-data behavior;
- direct-versus-derived relation scope and deterministic deduplication;
- public redaction and source-display policy;
- score event/metadata validation;
- audio reference metadata, rights, and verification gating;
- Westminster-shaped and unrelated Music entities using the same template;
- no Westminster-specific branch or fabricated content.

Verification gates are changed-file PHP lint, focused PHPUnit tests, applicable
integration checks, `git diff --check`, secret review, and actual browser/runtime
QA where a local runtime is available. Deployment is outside this slice.

## 8. Deferred work

The following require a later contract/storage decision and source-approved
governed workflow:

- canonical score/notation persistence;
- audio binary/reference ownership and ingestion;
- verified Westminster score data;
- Piano/Bell asset generation or licensed sample selection;
- primary-source research capture into Knowledge/Source/Evidence;
- approved related-melody semantics if existing registered relations are not
  sufficient;
- live staging readback and deployment.
