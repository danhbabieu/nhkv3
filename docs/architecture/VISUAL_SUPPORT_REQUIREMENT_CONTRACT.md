# Visual Support Requirement Contract

**Status:** ACTIVE — canonical NHK V3 application contract, 2026-09-11

## Purpose and owner

`VisualSupportRequirement` is an application-level persistent requirement
ledger. It records that semantic subject `X`, in exact scope `Y`, facet `Z`
and registered feature/detail `F`, needs a suitable visual with a registered
visual intent. It is not an Authority entity, Knowledge Claim, Source,
Evidence, Graph edge, Media identity or Article/Video page.

The smallest owner is the application ledger because `MediaUsage` cannot
represent a missing requirement without a Media, Knowledge owns claims rather
than work requirements, and projection dependencies own invalidation rather
than suitability/state. `MediaUsage` remains the owner of contextual usage
and binding once a canonical Media is selected. One canonical Media identity
may serve many exact requirements and consumers.

## Canonical fields and states

Every requirement contains canonical subject type/id, scope, facet,
registered feature/detail key, visual intent, state, optional selected Media
id/revision, provenance/context, unresolved reason, revision and deterministic
semantic/idempotency fingerprints. Its identity is:

`subject_type + subject_id + scope + facet + feature_key + visual_intent`

Consumer is contextual dependency metadata and is not repeated in the
semantic identity unless a contract proves separate semantic requirements are
necessary. The intent registry is closed and currently contains:
`representative`, `technical_detail`, `evidence_like_illustration` and
`contextual_illustration`. Feature/detail keys come from the existing Media
detail registry; unknown keys are a registry gap.

States are `MISSING`, `RESOLVED` and `REVIEW_REQUIRED`. `RESOLVED` means an
exact suitable Media is bound at semantic/internal level. It does not mean
that a public derivative is eligible or currently displayed. A missing
semantic visual is distinct from a node that lacks a representative image:
representative coverage describes whole-node presentation, while a feature
requirement describes technical/contextual illustration of a specific detail.

## Creation, reuse and reverse reconciliation

When Article, Knowledge/note, Video, Media annotation or a public projection
identifies a visually explainable feature, the application resolves the
canonical subject, validates exact scope/facet/feature/intent, computes the
fingerprint, searches canonical Media and then creates or reconciles one
ledger row. It must search and validate MediaUsage, subject scope and
contextual suitability before reporting missing. Filename, same brand/model,
keyword, checksum, article/gallery co-occurrence or visual similarity alone
never satisfies a requirement. Wrong Variant, Model or Specimen scope is
rejected; a specimen observation never broadens to a parent scope.

If no exact suitable Media exists, the requirement persists as `MISSING` (or
`REVIEW_REQUIRED` when ambiguity needs review). It is not infrastructure
corruption, and it is not silently filled by a near match or placeholder.

After every canonical Media ingest/read-back, including Media arriving later
through canonical Capture, the Media boundary performs bounded reverse
reconciliation:

`Media read-back → indexed MISSING/REVIEW_REQUIRED lookup → exact
subject/scope/facet/feature/intent validation → suitability ranking →
MediaUsage/contextual binding → Governance when semantic relation state is
affected → final read-back → affected projection invalidation/rebuild`.

The lookup is indexed by semantic candidate keys and limited by a runtime
budget; it never scans the database. Replay is idempotent. A better candidate
may replace a previous binding only through deterministic suitability and
optimistic revision; previous binding/provenance remains auditable. No Media
is duplicated, and no operator must edit every Article or Video body.

## Evidence, Claim and Graph separation

Visual support establishes only that a Media is suitable to illustrate a
detail in a context. It is not factual inference and does not create or
modify a Knowledge Claim, Source/Evidence record or Graph edge. Image
recognition, OCR, caption, alt text, filename and visual matching remain
observations/candidates. Media or an observation that should support a Claim
must separately pass Source/Evidence provenance, scope, eligibility and
Governance gates. A specimen-scoped image cannot broaden a Claim to a
Variant, Model or Brand.

## Consumers and public safety

Consumers resolve the semantic binding dynamically; they do not copy image
URLs into every Article body. WordPress `wp_posts` remains editorial owner of
title/body and editorial image ordering. Projection dependency fingerprints
include the requirement binding/revision when that visual section consumes
it, so binding changes invalidate only affected sections and rebuilds see the
new Media.

Internal `RESOLVED` may point to PRIVATE, review or otherwise non-public Media.
Public projections select a public-safe derivative only after the existing
MediaAsset readiness/visibility/placeholder policy. They omit missing,
review-required, private, unavailable, placeholder and ineligible Media; they
never use a near match or fake placeholder. Admin diagnostics may show state,
subject, scope, facet, feature, intent, candidate/resolved Media, reason and
affected consumers without requiring raw UUID/fingerprint/JSON entry.

Capture remains the normal input boundary. No direct MCP writer for this
ledger exists. Additive UP-only persistence is allowed; no legacy backfill or
production/staging/V2 mutation is part of this contract.
