# Universal Music Intake, Dictionary and Public Display Design

**Date:** 2026-10-09  
**Status:** Design approved in conversation; written-spec review pending  
**Scope:** Local documentation, transient intake metadata, executable parity tests and public projection evidence only.

## Goal

Provide one reusable, evidence-aware preparation standard for every canonical
Music entity, including future Music entities, while preserving the existing
Authority, Knowledge, Source/Evidence, Dictionary, Graph, Media, Video,
Governance and WordPress ownership boundaries.

The completed slice must make the Music A–Z standard executable enough to
produce a complete research worksheet and public-display decision matrix,
without creating a new semantic field, entity type, predicate, schema, writer,
permission or mutation path.

## Constitutional boundaries

- `MusicDataCollectionStandard` remains read-only intake vocabulary.
- `MusicCoverageAssessment` remains a read-only diagnostic and never mutates
  canonical owners.
- Music identity remains owned by Authority/Public Identity.
- Atomic facts remain Knowledge claims supported by Source/Evidence.
- Dictionary owns Entry/Form/Sense and lexical observations; lexical mention is
  not Knowledge Evidence or a Graph relation.
- Graph remains the only semantic relation system and only registered
  endpoints/predicates may be considered.
- Media/MediaAsset/MediaUsage and Video retain their existing boundaries.
- Canonical changes continue through Capture → Proposal/Approval → Eligibility
  → Controlled Apply → canonical read-back.
- `docs/research/` files, local score JSON and local WAVs remain non-canonical.
- No migration, seed, backfill, deployment, push, staging mutation or
  production mutation is part of this design.

## Current state and gap

The repository already contains:

1. an ACTIVE 26-category `MUSIC_DATA_COLLECTION_STANDARD.md`;
2. executable `MusicDataCollectionStandard` metadata and status vocabulary;
3. read-only `MusicCoverageAssessment` diagnostics;
4. shared `UniversalInputEnvelope`, `StructuredSemanticInterpreter` and
   `TextInputInterpreter` boundaries;
5. Dictionary lexical Entry/Form/Sense contracts and a public Dictionary
   projection; and
6. a generic profile-driven Music dossier with public-safe allowlists for
   claims, evidence, relations, score, audio, media, video and articles.

The gap is parity and operating usability: field metadata uses broad shared
defaults, the common Music/Dictionary/Score/Audio worksheets are not yet
published as reusable artifacts, and there is no single field-to-public-view
matrix or machine-checked evidence bundle tying the templates to the
executable registry.

## Design

### 1. Executable field metadata

Extend only the existing intake metadata returned by
`MusicDataCollectionStandard::fields()`. Every one of the 26 categories and
every registered intake field will expose:

- current intake key and Vietnamese label;
- purpose and exact subject scope;
- canonical owner and existing related entity types;
- scalar/claim/locator/relation/asset/packet value type;
- applicability: `CORE`, `RECOMMENDED`, `CONDITIONAL` or `OPTIONAL`;
- evidence and allowed-source requirements;
- validation and uncertainty behavior;
- duplicate/reuse rule;
- review/Governance requirement;
- public eligibility/redaction rule;
- public/frontend consumer or explicit capability gap;
- valid, invalid and missing examples; and
- Westminster as a bounded worked example only where useful.

These are intake labels and diagnostics. They are not persisted Authority
fields, canonical score fields, Dictionary records or permission grants.

### 2. One input preparation model

The reusable templates will map the twelve input classes to existing shared
interpretation boundaries:

1. explicit user factual assertion;
2. source locator;
3. evidence excerpt candidate;
4. factual research statement;
5. historical hypothesis;
6. system inference;
7. editorial instruction;
8. operational instruction;
9. media metadata;
10. Dictionary lexical observation;
11. score/edition metadata; and
12. audio/recording metadata.

Each factual candidate keeps original text, subject or unresolved subject
candidate, provenance, locator/evidence candidate, facet/scope,
uncertainty/status and source/date qualification. URLs, instructions,
metadata, mentions and excerpts remain non-claims unless their owning
contracted workflow separately promotes them.

No new runtime packet or pipeline is introduced. The templates are transient
preparation artifacts that can be translated into the existing
`UniversalInputEnvelope` and Capture-owned workflows.

### 3. Dictionary boundary

The Dictionary template covers Entry/Form/Sense, language, usage context,
lexical attestation, owner reference, ambiguity and public canonical mode.
Music names and aliases remain Authority-owned identity data; they do not
automatically become Dictionary Entries. Music terminology may be recorded as
lexical candidates or Dictionary content only when the lexical contract is
met. Dictionary definitions retain lexical provenance and do not prove
Knowledge claims or semantic relations.

### 4. Score and audio boundary

The Score/Audio template records source edition, notation witness, event and
performance metadata, asset identity/checksum, provenance, rights and
verification state. It distinguishes historical recording, mechanical-clock
recording, piano reference, bell simulation and other synthetic rendering.

The template reuses existing Music reference, Media and MediaAsset readiness
contracts. It does not create a score store, audio writer or public URL
shortcut. `docs/research/` artifacts are explicitly non-canonical, and a bell
simulation cannot be labelled as an authentic historical recording.

### 5. Public display matrix

The matrix will map each Music intake field to:

`field → canonical owner → evidence/readiness → public projection → frontend component → verification`

It will distinguish:

- canonical data available;
- public-eligible data;
- rendered frontend data;
- missing feature/capability;
- missing evidence;
- missing rights; and
- temporarily unavailable/runtime failure.

The matrix follows `MusicDossierProjection` and the existing generic Music
template. It will not expose internal UUIDs, stable keys, revisions, raw
metadata or private evidence. A route existing is not treated as proof that a
Music dossier is complete.

### 6. Examples and safety

Westminster, Sonodo and Ave Maria may appear as documentation examples only.
Examples must retain `MISSING`, `UNKNOWN`, `DISPUTED`, `BLOCKED` or
`CANDIDATE` when evidence/readiness is incomplete. They must not be written to
canonical owners or treated as authorization packets. No record-specific code
branch or hardcoded registry vocabulary will be introduced.

## Planned artifacts

- `MUSIC_AZ_CONTRACT_GAP_REPORT.md`: current law, executable parity, gaps and
  constitutional classification.
- `MUSIC_INPUT_TEMPLATE.md`: reusable A–Z research worksheet and input-class
  preparation guide.
- `DICTIONARY_INPUT_TEMPLATE.md`: Entry/Form/Sense and lexical-observation
  worksheet for Music-related terms.
- `SCORE_AUDIO_INPUT_TEMPLATE.md`: score edition, recording and governed Media
  readiness worksheet.
- `MUSIC_PUBLIC_DISPLAY_MATRIX.md`: field-to-owner-to-frontend verification
  matrix and honest missing/error states.
- `DOCUMENTATION_CHANGES.md`: file ownership and documentation registry
  impact.
- `EXECUTABLE_PARITY_RESULTS.md`: focused parity/test evidence and blockers.

The ACTIVE owner documents remain the source of each rule. These artifacts are
operating guides and evidence; they do not duplicate or override the
Constitution or active contracts.

## Verification design

Focused tests will prove:

- all 26 categories and every field expose the required metadata;
- the templates use only executable field keys and approved status vocabulary;
- source URLs remain locators, evidence excerpts remain candidates and
  instructions remain non-semantic context;
- explicit user facts are not incorrectly blocked;
- Dictionary lexical observations do not become semantic claims;
- duplicate Music/alias resolution remains reuse-first;
- score/audio without required rights or governed delivery cannot be
  `PUBLIC_READY`;
- claims without eligible Evidence are not public;
- public dossier output remains canonical-subject scoped and redacted;
- examples contain no write or mutation path; and
- no implementation or test introduces a Westminster-specific branch.

Verification includes focused PHPUnit tests, documentation registry/bootstrap
checks, PHP lint, JavaScript syntax where touched, `git diff --check` and a
changed-scope secret review. Runtime acceptance remains separately blocked
unless the authorized TEST identity and deployed build are proven.

## Explicit non-goals

- no new Music fields in Authority storage;
- no new Dictionary/Score/Audio schema;
- no new semantic owner, endpoint, predicate or Graph store;
- no legacy article parsing or migration;
- no data import, backfill, seed or repair;
- no live Westminster/Sonodo/Ave Maria acceptance; and
- no production cutover or public completeness claim.
