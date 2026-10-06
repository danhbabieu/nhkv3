# Universal Article Media Semantic Selection — Architectural Design

**Date:** 2026-10-06
**Status:** Design approved for specification checkpoint; implementation pending plan approval
**Scope:** Universal Article automatic-media selection, semantic eligibility, ranking, persistence/readback, publication/frontend projections, and legacy audit planning

## 1. Intent and success criteria

Article automatic media must be semantically safe before it is technically or visually useful. A ready/public image, generic classification, historical usage, representative usage, lexical overlap, or image quality is not semantic authority.

The implementation must:

- use the persisted canonical Article/Capture subject binding as the authority for system selection;
- separate hard semantic eligibility from ranking;
- preserve `USER_EXPLICIT`/`PINNED` precedence;
- fail closed when no safe automatic candidate exists;
- use one explainable deterministic policy across selection, persistence, preflight, SEO and frontend readback;
- expose bounded, secret-safe diagnostics;
- provide a dry-run, resumable legacy audit/repair plan without mutating live data.

Reference articles are acceptance evidence only. No article, entity, title, slug, UUID, Media ID, brand or fixture-specific branch is permitted.

## 2. Investigation result

The current focused suite passes, but the code path contains a contract-level defect:

1. The WordPress reconciliation hook can call `ArticleMediaCoordinator` with title-only context and no persisted canonical subject ID.
2. `ArticleMediaCoordinator::findReusable()` enumerates the global Media repository and invokes `usableMedia()` without required subject scope when the context is unscoped.
3. `SemanticSuitabilityPolicy` classifies ready Article media with an empty expected subject as `COMPATIBLE` through `unscoped_article_media`, including `auto_select=true`.
4. Selection then scores only preferred view, dimensions and stable-key tie-breaking; semantic distance is not represented.
5. `MediaUsageReconciler` can persist that result as `SYSTEM_AUTO/AUTO`.
6. `EntityMediaProjection`, used by Article dossier/frontend media, currently renders active usage without revalidating system-selected Article media against the Article subject.

This proves the primary causes are persisted-subject binding loss, overly permissive eligibility, technical ranking without semantic distance, fallback selection, and a projection bypass. The Article path does not currently show candidate starvation from an early query window or a broad Graph traversal as the earliest leak; the repository list is broad and the automatic Article selector does not perform Graph traversal.

Existing subject-scoped Capture flows already reject several wrong-variant cases. The fix consolidates that safety law instead of creating a second semantic owner.

## 3. Architecture

### 3.1 Canonical subject context

Automatic selection receives a normalized subject context containing the exact persisted canonical subject ID/type/revision and, where available, the existing structural context reader's validated parent chain and relation path. Capture packets remain the source of truth. Title, body, dictionary labels, research topics, temporary interpretation packets and request hints may enrich diagnostics or editorial copy, but cannot create or replace semantic scope.

If a system-selected Article has no persisted canonical subject scope, automatic reuse is ineligible. Existing explicit Article placement behavior remains governed by its current explicit-placement contract.

The existing `SubjectStructuralContextReader`/`StructuralContextQuery` boundary is reused for registered structural context. Article selection does not introduce unrestricted neighborhood traversal, inferred sibling relations or new predicates.

### 3.2 Shared policy and semantic-distance vocabulary

`SemanticSuitabilityPolicy` remains the single suitability owner. It is strengthened to evaluate structured candidate evidence and return deterministic diagnostics containing:

- relationship class and path source;
- semantic-distance/specificity tier;
- hard eligibility result and rejection reason;
- selection source/policy;
- score components only for eligible candidates.

The policy distinguishes exact subject, exact represented model/specimen/product/variant, registered direct relationship, bounded structural parent/child compatibility, family compatibility where explicitly supported, broad classification/global scope, and unrelated/contradictory scope. Candidate facts must come from persisted Media provenance, active governed MediaUsage, or validated structural context; filenames, titles, lexical similarity and image recognition are never semantic proof.

For Article automatic featured/primary selection, broad ancestor, sibling, cousin, same-brand and generic/global candidates remain ineligible unless an existing registered compatibility rule explicitly proves the relationship for that target profile. A technically stronger image cannot cross this hard gate.

### 3.3 Candidate discovery and selection

Introduce one reusable Article candidate-selection boundary consumed by `ensureForPost()` and read-only diagnosis:

1. enumerate the complete bounded repository result without an early quality-ranking window;
2. derive candidate semantic evidence from canonical scope and active usage/provenance;
3. apply hard eligibility;
4. rank only eligible candidates;
5. use deterministic tie-breaking by canonical stable key/ID;
6. return a no-selection result when the eligible set is empty.

Ranking order is semantic tier and relationship specificity first, followed by registered representative/article role, provenance confidence, readiness/public asset suitability, dimensions/aspect/detail suitability and deterministic stable-key tie-breaking. Quality and recency cannot compensate for semantic rejection.

Diagnostics are bounded and safe: candidate identity, target scope, relationship/tier, eligibility/reason, score components and winner reason are exposed without raw SQL, secrets or article-body dumps. Selection remains deterministic regardless of repository insertion/query order.

### 3.4 Precedence and persistence

The effective precedence is:

`USER_EXPLICIT/PINNED` → valid persisted Article-specific usage → exact/registered subject representative → eligible automatic candidate → existing placeholder/no-selection.

An active pinned usage is not replaced by automatic reconciliation. Existing `SYSTEM_AUTO` usage is re-evaluated against the current persisted subject before it can satisfy a slot. Selection and persistence use the same candidate assessment. The governed Usage boundary, optimistic revision/CAS and idempotency behavior remain unchanged.

After Usage persistence, canonical readback must verify Media identity, target, role, revision and current semantic eligibility. Subject/revision drift between planning, apply or readback yields an explicit conflict/review/incomplete result and never a successful automatic selection.

### 3.5 Publication, SEO and frontend readback

Article publication/preflight continues to consume fresh Article Media diagnosis. A stale `SYSTEM_AUTO` usage is not trusted merely because it exists. Required Image Articles remain blocked/incomplete when the featured slot is semantically unsafe; optional Text Article enrichment remains an explicit missing/debt state.

`ArticleMediaSeoProjection` and Article dossier/frontend media projections must apply the same system-auto revalidation. Explicit pinned placements retain their current explicit contract. Raw active usage must not bypass the Article semantic policy for system-selected Article slots.

The existing placeholder/incomplete state and `MEDIA_CANDIDATE_INELIGIBLE` diagnostic family are reused; no parallel status enum is introduced. A no-candidate result carries a bounded reason such as `NO_SEMANTICALLY_ELIGIBLE_MEDIA` under that existing diagnostic boundary.

### 3.6 Legacy audit and repair planning

Add a read-only, bounded Article Media legacy audit service over existing Media, Asset, Usage, Blueprint and subject-context boundaries. It:

- inventories active `SYSTEM_AUTO` featured/representative usages by deterministic cursor/batch;
- re-evaluates each usage against current persisted subject context;
- leaves `USER_EXPLICIT/PINNED` untouched and reports them separately;
- reports invalid, missing-scope, stale, retired/inactive and no-subject cases with fingerprints;
- discovers a replacement only through the same selector and never guesses;
- emits a Governance-ready repair plan with expected Usage/Media/subject revisions when a replacement is proven;
- emits no-safe-media disposition when none is eligible;
- is dry-run by default, idempotent, resumable and bounded;
- requires the existing Governance → CAS → canonical readback lifecycle for any future apply.

No production-wide repair, direct SQL write, WordPress featured-image write, hard delete or schema migration is part of this implementation.

## 4. Expected implementation surface

The smallest coherent code slice is expected to touch:

- `SemanticSuitabilityPolicy` and a shared semantic-distance/candidate-selection boundary;
- `ArticleMediaCoordinator` and its read-only diagnosis/persistence/readback path;
- Article SEO/public dossier projection boundaries that currently read raw Article Usage;
- the existing subject-context wiring in the Plugin composition root;
- the bounded legacy audit planner;
- focused Unit/Contract tests and relevant publication/projection tests.

The implementation must preserve existing repository interfaces unless a proven contract gap requires an additive optional dependency. No schema change is planned.

## 5. Regression matrix

Synthetic neutral fixtures must cover:

- exact subject versus generic;
- exact model versus same-brand sibling;
- unsupported cross-brand and broad ancestor rejection;
- registered direct/structural compatibility;
- visually superior unrelated media rejection;
- no eligible candidate and no random fallback;
- pinned stability;
- stale auto revalidation and subject change invalidation;
- persistence/readback mismatch;
- deterministic ties and repository ordering independence;
- large candidate sets without exact-candidate starvation;
- missing metadata, duplicate usages and inactive/retired relationships;
- brand-only subject policy;
- legacy audit detection and explicit-usage protection;
- frontend/SEO projection fail-closed behavior.

Tests must use synthetic IDs/names only. Reference cases may be checked later as read-only acceptance evidence.

## 6. Safety and rollout

This design changes code-side selection and readback only. It does not deploy, push, mutate V2/production/staging data, backfill semantic records, or repair live Articles. Any future staging acceptance must use the repository's exact server-issued scope and Governance lifecycle.
