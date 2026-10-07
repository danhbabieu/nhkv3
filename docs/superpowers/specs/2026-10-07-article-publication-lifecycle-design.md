# Global Article Publication Lifecycle Design

Date: 2026-10-07
Status: Design approved in conversation; awaiting written-spec review

## Intent and scope

Correct the Article publication lifecycle for every current and future
Capture-owned Article. The lifecycle must use native WordPress ownership and
the existing governed publication boundary, without a Post-specific branch,
Governance bypass, Authority weakening, invented Media, or relaxation of CAS
and idempotency invariants.

The change covers the shared Article publication gate, the governed owner
publication service, native route validation, post-publication verification,
stale MediaUsage cleanup, Capture primary-subject readiness, regression tests,
and the canonical Article/Capture/publication documentation. It does not
migrate or mutate existing semantic data and does not introduce a new Article
owner or status vocabulary.

## Invariants

- `wp_posts` remains the sole owner of Article title, body, dates, editorial
  status, and native editorial URL.
- `ArticlePublicationGate` remains the only readiness gate; all publication
  writes continue through the typed owner publication service.
- Governance, Capture ownership, canonical subject binding, CAS, and
  idempotency remain mandatory.
- `TEXT_ARTICLE` may publish with zero images. Optional Media gaps remain
  warnings/deferred enrichment; no placeholder or unrelated Media is created.
- `IMAGE_ARTICLE` retains its existing required Media behavior.
- Route defects remain system blockers. Draft frontend unavailability is not a
  route defect by itself.
- A successful native publish followed by failed frontend verification is a
  bounded partial/verification-required result, never `COMPLETE` and never a
  reason to create or publish a second Post.
- MediaUsage removal is logical retirement under the existing owner boundary;
  the usage must disappear from the active binding read model while its
  historical row remains auditable.

## Lifecycle

The shared lifecycle becomes:

```text
DRAFT
  -> publication preflight
  -> owner/Capture/subject/editorial readiness checks
  -> deterministic native wp_post route validation
  -> ArticlePublicationGate PASS
  -> governed native WordPress publish
  -> native owner read-back
  -> public/frontend read-back
  -> persisted publication evidence
  -> COMPLETE
```

The gate consumes pre-publication evidence only. It must not require a public
HTTP read of a draft. The final public read-back is performed after the native
status transition and is a completion check, not a precondition for the
transition.

## Components and responsibilities

### Native route preflight

Add or extend a shared Article route validation boundary used by all publication
callers. For `endpoint_type=wp_post`, it validates the expected native slug and
permalink deterministically and checks:

- the permalink can be determined;
- the slug is non-empty and valid;
- the route does not collide;
- route resolvers agree;
- the canonical URL matches the native WordPress route.

The result is structured evidence consumed by the gate. Missing, conflicting,
or invalid route evidence produces `PUBLIC_ROUTE_NOT_READY`. Semantic Public
Identity is not required for a native editorial Post.

### ArticlePublicationGate

Retain all existing owner, Capture, subject, Governance, compliance, SEO,
structured-data, CAS, and intent-specific checks. Change only the lifecycle
semantics required here:

- use deterministic native route evidence before publication;
- do not require rendered/public frontend verification for a draft;
- classify missing featured, inline, and real-image support as warnings for
  `TEXT_ARTICLE`;
- preserve hard blockers for actual invalid route evidence and required
  `IMAGE_ARTICLE` media failures.

### OwnerPublicationApplicationService

Keep the current idempotent review/approval/publish entry points. After the
controlled native publish, perform native read-back and then invoke an
injected, typed public/frontend verifier for the exact Post and canonical
permalink. Persist body-free publication evidence in the existing receipt and
decision boundary.

If native publishing is uncertain, read the Post before retrying. If the Post
is published but the final frontend verifier fails, return a bounded
verification-required/PARTIAL result containing the exact diagnostics and
current Post identity. Do not append a completed publication record and do not
issue another native publish for the same idempotency key.

The already-published/idempotent replay path must read the same Post and
publication receipt rather than creating a new Article or Post.

### MediaUsage cleanup

For an exact governed `remove` request, resolve the target and usage ID/revision
first. Do not resolve the referenced Media when the requested final state is
absence. Retire the usage through the existing `MediaUsageUpdater`, verify that
the target/role/placement has no active usage, and return the existing
idempotent result. A missing Media identity therefore cannot turn a valid
cleanup into `MEDIA_BINDING_MEDIA_NOT_FOUND`.

Add a no-op/idempotent path for a repeated removal whose exact usage is already
absent from active read-back, while retaining revision and target checks when a
current usage exists.

### Primary subject readiness

Reuse the existing canonical subject-resolution boundary used by Capture and
Article pre-create. Ensure the publication/pre-create evidence is derived from
one resolved primary subject:

- explicit editorial subject wins;
- related terms, aliases, examples, and contextual entities remain secondary;
- common or sentence-initial words cannot become subjects without semantic
  evidence;
- one high-confidence existing subject is bound;
- a missing subject goes through governed Authority planning/resolution;
- unresolved ambiguity remains `REVIEW_REQUIRED` and cannot create an Article;
- no UUID is invented.

No new Authority type, resolver, or persistence owner is introduced.

## Data flow and failure behavior

Pre-publication callers provide the current Post state token and Capture-owned
canonical evidence. The service canonicalizes that evidence, validates the
native route, runs the gate, and only then records the governed publication
attempt and calls the native WordPress writer.

Post-publication results distinguish:

- `COMPLETE`: native status, canonical permalink, public read-back, and
  publication evidence all verified;
- `PARTIAL`/verification-required: native status and identity verified but
  public/frontend read-back failed or is unavailable;
- `SYSTEM_BLOCKED`: pre-publication route, owner, CAS, Governance, identity,
  or other hard invariant failed;
- `OWNER_REVIEW_REQUIRED`: the existing bounded owner-review path remains
  unchanged.

The exact existing domain outcome/receipt vocabulary will be reused where
available. If a compatible diagnostic is missing, it must be registered in
the existing diagnostic registry rather than returned as an untyped generic
failure.

## Testing strategy

Tests are written first and must fail before production changes. Add or extend
focused tests for:

1. `TEXT_ARTICLE` with valid subject and no images passes preflight and allows
   publish.
2. A draft whose public URL is unavailable before publishing is not blocked
   solely for that reason.
3. Empty, invalid, colliding, disagreeing, or canonical-mismatched native
   routes remain `SYSTEM_BLOCKED`.
4. Missing featured/inline/real-image support for `TEXT_ARTICLE` produces
   warnings only and creates no placeholder.
5. Stale MediaUsage removal succeeds idempotently and verifies no active usage.
6. Ambiguous primary subject remains `REVIEW_REQUIRED` before Article creation.
7. One explicit primary subject remains sole primary while related terms are
   secondary.
8. Successful publish plus successful frontend read-back produces `COMPLETE`.
9. Successful native publish plus failed frontend verification produces
   bounded PARTIAL/verification-required state and no duplicate publish.
10. Repeated publication requests converge on the same Post and receipt.

Existing Article compatibility is covered by exercising an eligible existing
Capture-owned draft through the same shared service; no migration is required.

Verification also includes the relevant Capture, MCP, Media, contract, PHP
lint, static checks, `git diff --check`, and secret review. No staging or
production mutation is part of local verification.

## Documentation changes

After implementation and passing tests, update the current Article ingest and
MCP Content Operations publication sections to state:

- native `wp_post` route authority and deterministic preflight;
- no draft frontend prerequisite;
- post-publish frontend verification and bounded partial outcomes;
- intent-scoped TEXT_ARTICLE media warnings versus IMAGE_ARTICLE blockers;
- governed stale MediaUsage cleanup semantics;
- unchanged Capture subject, Governance, CAS, and idempotency requirements.

Documentation must describe only behavior demonstrated by the implemented
tests and runtime boundaries.

## Explicitly out of scope

- Post-specific logic for Post 766.
- Direct `post_status` writes outside the typed owner service.
- Authority mutation to make publication pass.
- Placeholder Media creation or semantic substitution.
- Legacy Article-body migration or V2/production data changes.
- Final production cutover or autonomous live publication.
