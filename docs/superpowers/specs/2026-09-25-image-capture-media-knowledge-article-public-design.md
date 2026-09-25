# Image Capture → Media → Knowledge/Entity → Article → Public Design

**Status:** Design approved by user on 2026-09-25; implementation plan review pending.

**Scope:** Redesign the semantic/editorial interpretation of one Capture containing one or more images. This document does not authorize code changes, schema migrations, data repair, Governance Apply, staging mutation or production publication.

## Goal

Make one user submission a coherent NHK V3 knowledge/content unit. The number of files must not determine the number of Articles, Models, Variants or Specimens.

The design must preserve the existing owners:

- Capture owns the submission and orchestration context.
- Media and MediaAsset own physical image identity and binary lifecycle.
- MediaUsage owns contextual Media placement.
- Authority owns Brand, Model, Variant, Specimen and related canonical entities.
- Knowledge owns atomic claims.
- Source/Evidence owns provenance and support.
- Governance owns durable semantic mutation.
- WordPress `wp_posts` owns Article editorial state and permalink.
- Public Identity and public read models own public route projection.

No Album entity is introduced.

## Current model

`nhk.capture.ingest` is the canonical new-submission entry point. `IMAGE_ARTICLE` creates at most one native Article draft. Multiple assets create multiple Media identities but must remain children of the same Capture publication unit.

The existing runtime already provides:

- Capture idempotency, request fingerprint, asset manifest, intent, subject packet and phase receipts;
- physical `MediaIngestBatch` retry state;
- Media, MediaAsset and MediaUsage boundaries;
- MediaUsage roles for representative, featured, inline, evidence and technical detail;
- Authority entity types including `model`, `variant`, `specimen` and `product`;
- scoped Knowledge claim retrieval and body-free Article claim trace;
- Entity dossier media gallery and Article projections;
- `/anh/...` public image asset projection and reverse Article linking through `wp_post` MediaUsage.

The current gap is not a missing storage owner. It is the lack of one enforced semantic contract from Capture asset manifest through MediaUsage, Article composition, WordPress read-back and public read-back.

## Business gap and root causes

1. `TextInputInterpreter` can classify instructions, but Article composition can still receive raw input and use it as title/body.
2. A physical batch is not consistently treated as one ordered Capture publication unit by every downstream stage.
3. Article media reconciliation concentrates on mandatory featured/inline slots; supporting images need an explicit complete disposition.
4. WordPress featured or Gutenberg attachment state is a presentation candidate, not canonical MediaUsage truth. Reading it before canonical planning creates the observed mismatch diagnostics.
5. A Media appearing in Gutenberg does not prove that its canonical MediaUsage, scope, role, placement and revision are valid.
6. A real object assertion is not the same thing as a Model or Variant resolution. Specimen creation must be explicit, governed and at most one per physical object.

## Design options

### Option A — persisted Album entity

An Album would own a Media list, subject, Article and public route. This is rejected because it introduces a new semantic owner, duplicates Capture/Article/MediaUsage responsibilities, and does not solve instruction classification or canonical-first completion.

### Option B — Capture publication unit, recommended

Capture owns the ordered submission manifest. Media remains independently identified. Article remains the single editorial owner when the intent requires one. MediaUsage binds each Media to Article and/or exact canonical semantic owners. Public galleries are read-only projections. This reuses current boundaries and supports Article-free Media enrichment.

### Option C — Article as image-set owner

Article would own the full image set while Capture only orchestrates intake. This fails for `MEDIA_ENRICHMENT`, `KNOWLEDGE_DELTA`, Model/Variant/Specimen media contexts without an Article, and reverse semantic projection.

The recommended design is Option B.

## Recommended design

The invariant is:

```text
one submission
→ one Capture
→ ordered Capture asset manifest
→ N canonical Media identities
→ one locked subject context
→ optional one governed Specimen
→ at most one Article
→ canonical MediaUsage plan/read-back
→ WordPress/public projection
```

`MediaIngestBatch` remains a physical upload/retry object. It is not an Album and not a semantic owner. The Capture asset manifest is the canonical grouping context for the submission.

Each asset must have a durable or reconstructible disposition:

- bound to a canonical owner and/or Article;
- intentionally unbound with an explicit reason;
- review-required;
- failed and retryable.

An asset may not silently disappear between physical ingest and public projection.

## Entity ownership and scope

### Model

“Junghans W64” resolves to the canonical Model when existing Authority resolution proves it. Model media may receive `representative` or gallery usage when exact subject relevance is established.

### Variant

Configuration such as “5 côn đồng bạch, Westminster” is not automatically a Model fact. It is classified through the registered Knowledge facet/scope rules and may belong to Variant, Movement, Music or another registered owner.

### Specimen

“Đây là một chiếc Junghans W64 thực tế” is a physical-object assertion. Capture records a typed specimen intent; it does not create a Specimen merely because multiple images exist. Resolution and creation/reuse follow:

```text
Capture → exact plan → owner confirmation when required
→ Proposal → Approval → Eligibility → Controlled Apply → Authority read-back
```

Four images of one object must resolve to at most one Specimen. A Model-only submission must not create a Specimen. `Product` remains an offer/listing and is never the identity of the physical object.

### Knowledge and observations

User statements, OCR, visual recognition and Media observations remain scoped candidates. They do not become universal Knowledge claims, Evidence or Graph edges without the existing provenance, scope, evidence and Governance rules.

## Media grouping and MediaUsage

No Album persistence is required. Grouping has three layers:

1. `MediaIngestBatch` — physical upload/retry state.
2. Capture asset manifest — one ordered semantic submission unit.
3. MediaUsage projections — contextual bindings for Article, Model, Variant, Specimen, Knowledge-related evidence context or other registered endpoints.

The manifest must carry enough context to produce a deterministic MediaUsage plan:

- Media UUID;
- attachment mapping where applicable;
- stable order;
- view/context hint;
- requested placement where explicit;
- exact scope proof or review state;
- idempotency binding.

Existing MediaUsage roles are sufficient for the first implementation:

- `representative`;
- `featured_primary`;
- `inline_primary`;
- `inline_supporting`;
- `technical_detail`;
- `evidence`.

`gallery` should be treated as an explicit registered projection/category rather than an untyped legacy fallback. No role expansion is required until a concrete registered consumer proves that existing placement and supporting semantics are insufficient.

Representative and Article featured status are distinct:

- a Model/Variant/Specimen representative serves semantic identity presentation;
- Article `featured_primary` serves editorial presentation;
- one Media may have both usages when independently justified.

Canonical MediaUsage planning and read-back must precede WordPress featured/content projection. WordPress state may be read as a compatibility candidate, never as the replacement for canonical MediaUsage.

## Input classification

The Capture interpretation boundary must produce typed context classes:

| Class | Example | Canonical treatment |
|---|---|---|
| `INSTRUCTION` | “Tạo bài viết về Junghans W64” | workflow control only |
| `EDITORIAL_COPY` | personal descriptive prose | Article-owned prose if accepted by the user intent |
| `USER_FACT_CANDIDATE` | “W64 có 5 côn đồng bạch” | scoped Knowledge candidate |
| `SPECIMEN_OBSERVATION` | “Chiếc này bị xước” | specimen-scoped observation |
| `MEDIA_OBSERVATION` | visible dial/back/detail | `OBSERVED_FROM_MEDIA`, not automatically canonical fact |
| `SEMANTIC_ENRICHMENT_REQUEST` | update Knowledge | governed proposal path |
| `COMPLIANCE_INSTRUCTION` | do not publish unsupported claim | non-semantic control metadata |

Article composer must receive editorial copy and selected semantic context, not the unclassified raw instruction string.

## Article composition

Composition occurs only after:

```text
Capture persisted
→ physical asset read-back
→ Content Intent resolved
→ input interpreted
→ subject packet locked
→ canonical Claims retrieved and scope-filtered
→ user statements classified
→ Media manifest reconciled
→ governed semantic decisions recorded
→ Article composed
→ MediaUsage applied/read back
→ WordPress projected/read back
→ public read-back
```

Article title is derived from the resolved subject and editorial context, not from an instruction. Article body is a bounded synthesis of accepted editorial copy, selected canonical Claims and eligible Media observations. Sparse Knowledge produces a short article; it must never be padded with invented claims.

The Article may retain a body-free `claim_trace`/research snapshot containing canonical claim IDs, revisions, subject, scope, relation path and composition reason. Generated Article prose is never Evidence and is not copied into Knowledge.

## Knowledge enrichment

The reuse-first sequence is:

```text
resolve subject
→ retrieve same-subject/same-scope Claims
→ compare semantic equivalence
→ reuse compatible Claim
→ add support/qualification when governed
→ propose a new Claim only when genuinely new
```

Scope is monotonic. A specimen observation cannot silently widen to Variant, Model or Brand. A relation path discovers candidates but does not authorize Claim reuse by itself.

## Public context

Media keeps `/anh/<slug>.webp` as the public binary projection. Media does not become a new semantic detail owner merely to make reverse navigation possible.

Public Media read models should expose bounded, public-safe context derived from active MediaUsage:

- primary Article when exactly one published Article is appropriate;
- bounded related Articles when multiple are valid;
- exact semantic Entity context;
- gallery/placement context where public-safe.

Entity dossier selection remains direct-subject scoped. Model pages must not absorb Specimen media merely through reachability. Specimen pages show exact Specimen usages. Article pages show Article usages in canonical placement order.

No Article is created per image.

## Completion contract

Completion exposes independent dimensions:

- `canonical_existence`;
- `enrichment_readiness`;
- `publication_readiness`;
- `frontend_readback`.

`IMAGE_ARTICLE` cannot be `COMPLETE` until:

1. all physical Media identities are canonically read back;
2. the subject packet is locked;
3. every asset has a terminal disposition;
4. required semantic reconciliation is complete or explicitly review-gated;
5. Article exists;
6. the canonical MediaUsage plan is applied and read back;
7. WordPress featured/inline/gallery projection is synchronized;
8. WordPress read-back matches the canonical plan;
9. public Article/Media/Entity read-back is verified where required.

`PARTIAL` is retained when an owner has committed but a dependent reconciliation has not. `PUBLICATION_READY` and `ENRICHMENT_COMPLETE` remain independent. Ingest success or attachment existence alone is never `COMPLETE`.

## Compatibility and migration

No Album migration is required. Existing records remain readable. Existing WordPress attachments and Gutenberg references are historical/presentation candidates until exact Media identity, scope, role, placement and revision are verified.

Existing legacy roles remain read-compatible but cannot override the current Capture publication-unit plan. No bulk repair, backfill, Article rewrite, semantic merge, staging mutation or production mutation is authorized by this design.

## Case 711 interpretation

The supplied case should be treated as one Capture publication unit:

- one resolved Model subject;
- one Article;
- three independent Media identities;
- one canonical MediaUsage plan covering featured and supporting/detail placements.

Attachment 708 does not prove canonical `featured_primary`. Gutenberg 709 does not prove canonical inline usage. Media 710 requires an explicit supporting/detail disposition. The observed `PARTIAL`, `MEDIAUSAGE_INCOMPLETE` and missing-slot diagnostics are consistent with canonical planning/read-back lagging behind native WordPress state.

The repair must first be a read-only diagnostic packet comparing Capture manifest, Media identities, attachment mappings, MediaUsage, WordPress editorial state and public projections. Any later mutation requires a separate governed, exact, user-approved acceptance scope.

## Risks and tests

Tests must cover:

- one file and three files producing one Capture and at most one Article;
- idempotent replay and per-child retry;
- instruction exclusion from title/body;
- preservation of user-authored editorial copy;
- Claim reuse, scope protection and no duplicate Knowledge;
- Media observation not becoming Evidence automatically;
- all manifest assets receiving a disposition;
- canonical MediaUsage preceding WordPress projection;
- WP featured/Gutenberg presence without canonical usage remaining incomplete;
- Model-only, Variant and explicit Specimen flows;
- four images never creating four Specimens;
- exact direct-subject public galleries and reverse Media→Article context;
- independent canonical, enrichment, publication and frontend completion states.

## Proposed implementation slices

1. Lock the Capture publication-unit and input-classification contracts.
2. Separate editorial copy from raw instruction before Article composition.
3. Normalize and persist/reconstruct the ordered Capture asset manifest.
4. Generate a complete MediaUsage plan with explicit asset dispositions.
5. Compose Article only after subject, Claim and Media preparation.
6. Reconcile all supporting/gallery Media, not only featured/inline slots.
7. Add typed Specimen intent and governed create/reuse flow without a new entity.
8. Extend public Media reverse context and direct-subject gallery projections.
9. Converge completion/read-back states.
10. Perform a read-only audit of Capture `01a0d5c4-35b1-7ac6-b060-971bdf6dad85` and Article `711` before any separately approved repair.
