# NHK V3 pre-master system readiness

## Goal

Close or evidence the pre-master blockers without starting site-wide frontend
architecture or mutating semantic data outside the approved bounded staging
scope.

## Tasks

1. **Documentation contract parity**
   - Test: parse every `docs/*.md` reference in `docs/constitution/READ_FIRST.md`
     and assert it is represented by the runtime documentation registry.
   - Test: assert Public Entity, Public Identity, Public Route, and SEO
     contracts are retrievable through `McpDocumentationRegistry::get()`.
   - Implementation: add the missing canonical definitions, classify historical
     evidence explicitly, regenerate the immutable MCP documentation snapshot.

2. **Article optional-media diagnostics**
   - Test: reproduce a text Article with absent optional visual support and
     assert it remains plan-ready with `OPTIONAL_MEDIA_MISSING`.
   - Test: assert invalid blueprints and media pipeline failures remain distinct
     blocking diagnostics.
   - Implementation: make `ArticleSeoGate` media-aware without treating optional
     absence as infrastructure failure.

3. **Shared newest-first ordering**
   - Test: published, created, and stable-key tie ordering; updated mode; and
     pagination after ordering.
   - Implementation: apply the existing shared `LatestFirstOrder` to all
     content-feed query boundaries, while leaving structural/hierarchy queries
     deterministic but outside the newest-first feed contract.

4. **Generic presentation readiness**
   - Test: keep semantic activity independent from route/content readiness and
     distinguish ready, incomplete, blocked, and unavailable projections.
   - Implementation: add a reusable readiness result consumed by public
     projection eligibility without deactivating semantic entities.

5. **Acceptance and deployment evidence**
   - Read-only audit: connector catalog/allowlist and live admin state.
   - Read-only audit: exact PC-ROOT public URL owner scope and collision/readiness
     preconditions; do not reproject without the required staging identity and
     duplicate audit.
   - Acceptance: media/video only if the canonical transport and exact approved
     staging scope are available; otherwise record the fail-closed blocker.
   - Verification: focused tests, integration tests, lint/static checks, docs
     parity, deployment preflight, and fresh live read-back when deployment
     credentials/configuration are present.

## Checkpoints

- Checkpoint 1: registry and article tests plus implementation.
- Checkpoint 2: ordering and presentation-readiness tests plus implementation.
- Checkpoint 3: full verification and evidence report; update execution state.
