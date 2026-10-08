# Westminster-First Universal Music Dossier V2 — Design Specification

**Date:** 2026-10-08  
**Status:** Approved in conversation; implementation pending plan review  
**Scope:** Read-only public Music projection, generic Music rendering, source-aware Westminster research preparation, and governed audio/score readiness seams

## 1. Outcome

NHK V3 will provide one reusable public Music dossier for every canonical Music
Authority entity. Westminster is the first research target, not a special
renderer or a special semantic owner. Sonodo, Ave Maria, and future Music
entities must use the same projection and template path.

The implementation must make the Westminster route reach the Music renderer
when a Music dossier is available, even when Dictionary detail data also exists.
Dictionary remains a lexical/read-support source and is a fallback or section,
not a higher-priority renderer for Music entities.

This slice remains read-only. It does not seed Westminster facts, write
Authority/Knowledge/Source/Evidence/Graph/Media/Video records, migrate data,
upload assets, deploy, or push.

## 2. Constitution and ownership boundaries

- Authority owns Music identity, aliases, canonical identity, and canonical URLs.
- Knowledge owns atomic historical, technical, musical, and research claims.
- Source and Evidence own provenance, support, extraction context, and uncertainty.
- Graph owns only registered semantic relations and their ordered path context.
- Media owns Media identity, MediaAsset binaries, and MediaUsage placement.
- Video owns video identity and public video URLs.
- WordPress owns editorial posts, article prose, dates, archives, search, RSS,
  sitemap, and editorial URLs.
- Dictionary owns lexical entries and dictionary presentation; it does not own
  Music identity or historical truth.
- The Music dossier is a read-only projection and never a second semantic store.

No relation, endpoint type, Authority field, audio owner, or database schema may
be invented to make a frontend section appear populated.

## 3. Westminster research model

The research model distinguishes a melody family from a physical clock and from
a product listing.

### 3.1 Music identity

The Music record may contain canonical name, aliases, public description, and
reader-safe identity data. “Westminster Quarters” and “Cambridge Quarters” are
aliases/identity candidates only after canonical Authority review.

### 3.2 Historical claim families

Claims are separate and source-backed rather than compressed into one paragraph:

- origin/composition and earliest documented use;
- adoption/selection for the Great Clock of Westminster;
- installation and first performance chronology;
- mechanism and bell configuration;
- later broadcast/dissemination history;
- variants, incomplete arrangements, and domestic clock adaptations;
- uncertainty, disagreement, and source limitations.

The known research sources are evidence candidates, not canonical facts until
they pass the governed Source/Evidence/Knowledge lifecycle:

- Great St Mary’s for the Cambridge Quarters institutional history;
- UK Parliament for Elizabeth Tower, the four quarter bells, Great Bell,
  mechanism, chronology, restoration, and broadcast context;
- historical chime literature such as W. W. Starmer for notation and variant
  analysis;
- a rights-reviewed Parliament recording or another explicitly licensed source
  for historical audio.

Commercial clock sellers may describe products, movements, or demo audio, but
their pages must not be treated as sole historical authority. A vendor sample
must remain a recording/product reference with its own rights and provenance.

### 3.3 Relationship and object distinctions

The projection must preserve the type and origin of every related object:

- Brand: manufacturer or brand authority entity;
- Model: model family;
- Variant: configuration/edition of a model;
- Movement: mechanical/electronic movement;
- Specimen: one concrete physical clock;
- Product: a listing or offer, never the physical object’s identity;
- Recording: one concrete audio artifact;
- Score edition: one notation/arrangement reference.

A Music relation observed through one Variant or Movement must never be promoted
to a universal Brand or Model capability without its own governed evidence.

## 4. Universal dossier sections

The public packet declares one ordered section recipe. The template consumes the
declared order instead of duplicating it in PHP.

1. `identity`
2. `introduction`
3. `history`
4. `structure`
5. `variants`
6. `clock_application`
7. `related_entities`
8. `score`
9. `audio`
10. `library`
11. `research`
12. `sources`
13. `related_melodies`

Sections with no eligible public content are omitted. “Unavailable” must not be
replaced with fabricated copy, default facts, generated score events, or fake
audio controls.

The projection may adapt existing dossier data into these reader-facing slots,
but it must retain direct/derived relation origin and the original object type.

## 5. Public projection safety

The Music projection must use strict allowlists for public fields rather than a
remove-only filter. It must omit canonical UUIDs, stable keys, revisions,
lifecycle/state fields, private metadata, internal diagnostics, database IDs,
and private provenance.

Knowledge claims must remain subject-scoped. Source/Evidence display must pass
through the existing public source-display policy and public active/eligibility
checks. A missing or blocked source is represented by an honest availability or
uncertainty state, never by a fabricated citation.

Relation items must retain enough reader-safe context to distinguish direct from
derived paths without exposing internal IDs.

## 6. Score contract

The score reference is a validated presentation input, not canonical storage.
It must include:

- edition/version;
- source/provenance reference;
- verification status accepted by the public policy;
- tuning and pitch reference;
- tempo;
- ordered events with pitch class, octave, start, duration, and phrase;
- optional bounded segments;
- variant/arrangement notes where applicable.

Validation must reject malformed or non-finite numeric values, invalid pitches,
unreasonable/missing octave values, negative or zero timing, overlapping or
out-of-order events when the selected score model requires a sequence, invalid
segment bounds, missing provenance, and unverified public status.

The UI must not label a score “đã kiểm chứng” unless the contract’s exact public
verification state permits that label. Pitch classes alone do not authorize
absolute octave, tuning, tempo, duration, or historical authenticity.

## 7. Audio and recording contract

Audio categories remain semantically distinct:

- `PIANO`: a piano interpretation/rendering of a verified score;
- `BELL_SIMULATION`: a synthesized or modeled bell rendering, explicitly labeled
  as simulation;
- `HISTORICAL_RECORDING`: a recording of an actual historical/physical source;
- future recording modes require a separately approved contract.

An audio card is playable only when its source resolves through the existing
Media/MediaAsset public delivery boundary. The Music packet must not trust or
emit an arbitrary raw URL. The public path must establish:

```text
score/reference provenance
→ governed Media identity
→ PUBLIC MediaAsset
→ supported MIME and public delivery route
→ rights/licence state
→ canonical read-back
→ native audio controls
```

Until that path exists, the UI may show verified metadata without a playable
control. No placeholder sound, browser-generated beep, or unverified external
download is permitted.

Parliament audio is not automatically reusable: its licence conditions must be
stored/evaluated by the appropriate owner before publication.

## 8. Frontend behavior

`entity.php` must select the Music renderer from canonical entity type and
available Music dossier state before Dictionary detail rendering. The generic
Music partial must:

- render Vietnamese-first semantic headings;
- iterate `section_order`;
- render score events and bounded segments accessibly;
- render audio only for public-eligible Media-backed sources;
- render Media, Video, Article, dictionary, research, and evidence entries using
  their existing public URL/SEO helpers;
- label direct and derived relations honestly;
- preserve keyboard operation and native audio fallback;
- remain responsive at mobile, tablet, and desktop widths;
- contain no name, slug, stable-key, or Westminster-specific branch.

## 9. Non-goals and deferred work

This implementation does not:

- insert Westminster historical facts;
- create or repair canonical Authority, Knowledge, Source, Evidence, Graph,
  Media, Video, or Dictionary records;
- create a score database or audio storage owner;
- generate Piano or Bell binaries;
- ingest Parliament recordings;
- infer brands, models, movements, specimens, products, or clock capabilities
  from names, search results, or vendor marketing copy;
- migrate, reset, truncate, or seed a runtime database;
- deploy or push.

Canonical data population is a later, separately governed Capture → Plan →
Confirmation → signed bounded packet → Governance → canonical read-back flow.

## 10. Acceptance criteria

The work is complete only when all of the following are true:

1. Westminster, Sonodo, Ave Maria, and a fixture Music entity with no research
   data use the same Music partial and projection recipe.
2. A Music entity with Dictionary detail still reaches the Music renderer.
3. No production Music code contains Westminster-specific names, slugs, UUIDs,
   musical facts, audio URLs, or hard-coded pitch assumptions.
4. Public field allowlists prevent internal identifiers and private metadata from
   reaching the dossier.
5. Product, Specimen, Variant, Model, Movement, and Brand remain distinguishable.
6. Score validation fails closed for malformed, unverified, non-finite, or
   unsupported data.
7. Audio controls appear only for valid public Media-backed sources.
8. The library renders all eligible Media, Video, and Article items.
9. Focused Unit/Contract tests pass; changed-file lint, diff, and secret checks
   pass; Integration is either green on the exact authorized runtime or reported
   as an environmental blocker.
10. Browser QA verifies the actual current build for route, HTML semantics, SEO,
    accessibility, responsive layout, score rendering, and audio behavior.
11. No production/staging semantic mutation, migration, deployment, or push is
    performed in this work.

