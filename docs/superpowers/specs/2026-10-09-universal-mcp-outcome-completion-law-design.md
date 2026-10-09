# Universal MCP Intake → Verified Outcome Completion Law

## Status and authority

This specification defines the next NHK V3 implementation slice under the
Constitution and the ACTIVE owning contracts. It is a subordinate
cross-domain orchestration design, not a second Constitution and not a new
semantic owner. It authorizes local code, tests and ACTIVE contract updates
only. It authorizes no migration, seed, backfill, staging or production
mutation, deployment, push, merge or cutover.

The proposal commit supplied by the user (`e4352d280a17410fd5e97b7acf591c332b233e08`)
was not present in the local repository. This design therefore relies only on
the local executable code, local contracts and the approved user request; it
does not claim to reproduce that unavailable proposal.

## Problem statement

NHK V3 already has domain-native canonical owners, Governance Controlled Apply,
Capture continuation and several public/frontend read-back services. The
missing cross-domain seam is the compilation and enforcement of the requested
outcome.

At present, Capture aggregation can verify a canonical owner while losing the
fact that the request also required public publication or frontend proof. The
current aggregation filters `NOT_APPLICABLE` public/frontend states and emits a
top-level `publication_readiness=NOT_APPLICABLE`. A non-Article path can also
complete without carrying the original publish/public intent into the final
completion evidence. This permits a result shaped like:

```text
Capture COMPLETE
canonical owner read-back verified
publication = null
frontend_public_state = NOT_APPLICABLE
```

That result conflates canonical completion with the user-requested verified
outcome. It also makes adapter behavior diverge: Article publication, Video
frontend verification, Media projection, Dictionary routes, Authority
projection and Knowledge visibility are evaluated at different orchestration
layers.

## Goals

- Compile outcome obligations at Capture/admission from existing intent,
  publish/public input, owner capability and domain contracts.
- Preserve the existing domain ownership model and Governance lifecycle.
- Distinguish canonical completion, owner/editorial completion, public
  projection completion, frontend verification and the aggregate verified
  outcome.
- Ensure `publish=true` or an equivalent explicit public request cannot be
  downgraded to canonical-only `COMPLETE`.
- Keep private/internal Knowledge, Source, Evidence and semantic dependencies
  from acquiring an invented public requirement.
- Treat homepage inclusion as optional unless explicitly requested or required
  by an existing policy.
- Make `NOT_APPLICABLE` truthful and attach a deterministic reason.
- Preserve Capture UUID, canonical owner identity, revisions, source identity,
  idempotency, receipts and bounded recovery semantics.
- Prove parity across all registered MCP intake adapters without creating a
  duplicate parser, registry, pipeline or semantic store.
- Confirm server-side `nhk.video.frontend.reconcile` capability separately from
  connector/client exposure.

## Non-goals and fixed boundaries

- No new entity type, endpoint type, predicate, relation type, taxonomy,
  semantic owner, public route namespace or persistence owner.
- No new public status enum when an existing status or receipt field expresses
  the state; `REQUIRED`, `CONDITIONAL` and `OPTIONAL` are derived obligation
  classifications, not domain lifecycle statuses.
- No direct WordPress writer, direct database write, Governance bypass or
  generic adapter fallback.
- No automatic Evidence creation from OCR, transcript, captions, metadata,
  generated prose or user hints.
- No automatic Graph edge creation outside registered predicates, evidence,
  revisions and Governance.
- No public requirement for private/internal semantic dependencies.
- No homepage requirement unless the request or existing domain policy makes
  it applicable.
- No live fixture replay, staging mutation, production mutation, deployment,
  push or external publish.
- No Constitution amendment is proposed by this design. Any discovered
  conflict must be reported as `CONSTITUTION_CONFLICT` and stop the affected
  implementation slice.

## Confirmed code-side root cause

The current failure boundary is distributed but deterministic:

1. `EditorialCaptureCoordinator` carries `publish` into Article publication,
   but the non-Article completion path does not compile it into an aggregate
   public/frontend obligation.
2. `CompletionCoordinator::finalize()` determines public applicability from
   owner type and dependency-role flags rather than from the exact request
   obligation.
3. `CompletionCoordinator::aggregateCapture()` aggregates only non-
   `NOT_APPLICABLE` child public/frontend states and hardcodes aggregate
   publication readiness to `NOT_APPLICABLE`.
4. Capture status is then derived from the aggregate `complete` field, so a
   canonical read-back can be promoted to Capture `COMPLETE` without the
   requested public/frontend proof.

The existing Video frontend reconciler itself is owner-bound and read-only. It
checks canonical Video identity, persisted Public Identity, derived projection,
detail, route, archive and homepage sources. Its catalog, dispatch and Ability
registration are present locally. A client-side `Unknown tool` result is not
evidence that the server capability is absent; it is a separate exposure fact
that must be freshly discovered and recorded as `CLIENT_EXPOSURE_GAP` when
applicable.

## Design

### 1. Shared outcome-obligation compiler

Add one application-level, orchestration-only compiler invoked immediately
after Capture admission and Content Intent resolution. The compiler consumes:

- normalized Capture purpose and Content Intent;
- the existing `publish`/public-request signal and any already-registered
  public visibility input;
- canonical owner type and resolved owner capability;
- submitted Media/Video/Article/Authority/Knowledge context;
- existing route, SEO, projection, rights and frontend policies.

It produces an in-memory derived obligation plan and a deterministic
receipt-safe projection. The plan contains the existing owner identities and
the obligation class for each dimension:

```text
canonical        REQUIRED for every accepted owner
governance       REQUIRED when a semantic mutation is requested
relations        REQUIRED or CONDITIONAL according to the owning contract
public           REQUIRED / CONDITIONAL / OPTIONAL / NOT_APPLICABLE
frontend         REQUIRED / CONDITIONAL / OPTIONAL / NOT_APPLICABLE
homepage         OPTIONAL unless explicitly requested or policy-required
publication      REQUIRED only for an explicit publication request
```

The compiler never creates an owner or decides semantic truth. It only records
what the already-selected owner workflow must prove. It must fail closed when
intent or owner applicability is ambiguous rather than silently selecting a
weaker outcome.

The derived plan may be carried in the existing Capture context/diagnostics and
result receipts as an optional field. No database column or migration is
required. Its fingerprint must include Capture/request identity, resolved
intent, owner IDs, relevant revisions, registered policy inputs and the
documentation/build checkpoint already required by Capture.

### 2. Completion coordinator integration

Extend the existing `CompletionCoordinator` seam rather than creating a second
completion system.

`finalize()` and `aggregateCapture()` must accept the derived obligation
evidence and apply these rules:

- canonical owner read-back is independent from public/frontend proof;
- a semantic dependency may remain public/frontend `NOT_APPLICABLE` only when
  the obligation plan marks it as a dependency rather than a public owner;
- an explicit public obligation converts missing/unknown public read-back into
  `BLOCKED`, `PARTIAL` or `REVIEW_REQUIRED` according to the existing outcome
  and blocker vocabulary;
- `NOT_APPLICABLE` must carry a deterministic reason such as an existing
  applicability/readiness reason, never an empty fallback;
- a required public/frontend blocker participates in aggregate Capture
  completion and prevents Capture `COMPLETE`;
- optional enrichment remains a warning or continuation gap and does not
  become a universal blocker;
- canonical-only owner completion remains visible in its own canonical fields
  even when the aggregate verified outcome is incomplete.

The implementation must preserve compatibility for callers that do not supply
obligations: their behavior remains contract-derived, and no public obligation
is invented for private/internal operations. New Capture calls always supply
the compiled plan.

### 3. Domain obligation recipes

The compiler delegates applicability to existing owner contracts and registries.
It does not duplicate domain validation.

| Domain | Canonical owner proof | Public/frontend proof when explicitly requested | Default non-public behavior |
|---|---|---|---|
| Dictionary Entry/Form/Sense | Dictionary identity, mapping and revision read-back | Registered Entry route/detail projection only when public visibility is requested | lexical/private curation remains non-public |
| Media/MediaAsset/MediaUsage | Media and asset read-back, checksum/visibility/readiness and usage reconciliation | public derivative/attachment and exact consumer read-back when required | source-original and internal usage remain private |
| Video | Video owner, source identity, governed relations and Public Identity | exact detail/route/archive read-back; homepage only if requested/policy-required | canonical-only Video remains distinct from public availability |
| Article/News | native `wp_posts` owner and editorial state token | publication receipt, canonical URL and exact rendered/public frontend read-back | draft/editorial preparation remains non-published |
| Authority | registered entity identity/revision and governed relation read-back | Public Identity/dossier projection only if public is requested/applicable | Authority-only planning/private entities remain non-public |
| Knowledge/Source/Evidence | canonical identity, provenance, scope, dependency and Governance read-back | only when owning visibility/public dossier contract explicitly applies | private/internal semantic dependencies are `NOT_APPLICABLE` with reason |
| Graph | registered endpoints/predicate, revision/evidence and governed edge read-back | public relation eligibility/projection when required by the consuming owner | relation-only internal work has no invented public route |

Every adapter must return its existing owner receipt and diagnostics to the
shared compiler/coordinator. Missing adapter evidence is an unavailable or
review outcome, not permission to synthesize completion.

### 4. Admission, recovery and replay

Admission stores the compiled obligation fingerprint alongside existing Capture
decision inputs. Recovery must:

1. reconcile `OUTCOME_UNKNOWN` against the original Capture and canonical owner
   identities before any replay;
2. reuse verified canonical owners, Source identities, Media binaries, Video
   identities and Public Identity records;
3. compare the current obligation fingerprint and dependency revisions;
4. reopen only the bounded continuation that is still unresolved;
5. preserve append-only phase receipts and deterministic diagnostics;
6. stop on changed payload, stale binding, identity conflict, CAS/revision
   conflict, Governance denial, capability denial or other immutable failure.

No retry may create a second owner or re-apply a completed Governance
operation. A client exposure gap may produce a continuation/review hint, but
must not cause a generic writer fallback.

### 5. Public and frontend verification

Public verification must read the exact canonical owner and the exact consumer
source. HTTP 200, SEO preview, a generated URL or a projection object alone is
insufficient.

For Video, the existing `VideoFrontendReconciliationService` remains the
read-only verifier. Its local catalog/dispatch/Ability tests are server-side
capability evidence; connector discovery must be tested separately when a
connector is available. Homepage inclusion remains an independent optional
obligation and may not be inferred as required merely because detail/archive
publication was requested.

For Article, publication remains the typed WordPress owner lifecycle. A draft
does not become public merely because the native post exists; the final
publication and rendered read-back must satisfy the obligation plan.

For Media, public binary/derivative/usage read-back stays distinct from
semantic Media identity and from representative selection. For Authority and
Dictionary, public dossier/detail output must use the existing Public Identity,
route and projection boundaries.

## Contract changes

Create one ACTIVE subordinate contract for this cross-domain law and update
the existing owning contracts by reference, not by duplicating the complete
rule. The contract updates must cover:

- MCP Control Plane and Content Operations: admission, outcome compilation,
  recovery, completion and exposure gap semantics;
- Universal Structured Semantic Intake: intent/provenance/scope handoff to
  outcome planning;
- Article, Media, Video, Authority, Knowledge/Source/Evidence, Dictionary,
  Graph and Public Identity/SEO contracts: domain-specific obligation recipes;
- frontend/public projection contract: exact owner/source read-back and
  truthful `NOT_APPLICABLE` reasons;
- Current Documentation Status Index: status/link metadata only.

No contract update may claim live runtime acceptance, external publish or
deployment without fresh evidence from the authorized TEST runtime and exact
read-back.

## Regression strategy

The implementation must use red-green tests for each behavior change.

### Shared completion tests

- `publish=true` with canonical read-back but missing public read-back is not
  Capture `COMPLETE`.
- `publish=true` with public read-back but missing exact frontend read-back is
  not Capture `COMPLETE`.
- canonical-only private Knowledge/Source/Evidence succeeds with public and
  frontend `NOT_APPLICABLE` plus a reason.
- optional homepage absence does not block detail/archive completion.
- required versus conditional versus optional obligations aggregate
  deterministically.
- historical child projections cannot overwrite the current obligation-bound
  owner result.

### Domain adapter parity tests

Use runtime registries and neutral synthetic identities to cover Dictionary,
Media, Video, Article, Authority, Knowledge, Source, Evidence and Graph. Each
adapter must prove canonical read-back, duplicate/reuse, missing dependency,
ambiguous subject, governed relation, replay and interrupted recovery behavior
without creating live records.

### Public/frontend tests

- exact Video detail/route/archive owner mismatch remains blocked;
- homepage is optional unless explicitly required;
- `nhk.video.frontend.reconcile` remains present in catalog and executable
  dispatch;
- missing client exposure is classified separately from missing runtime
  capability;
- SEO preview or HTTP success cannot substitute for exact frontend read-back;
- `NOT_APPLICABLE` always includes a truthful reason.

### Safety and regression tests

- no duplicate Video, Article, Dictionary, Authority or Media on replay;
- no Evidence fabricated from metadata/transcript/OCR/caption/generated copy;
- no weak or unregistered Graph relation is applied;
- short Video relevance excludes unrelated Knowledge even when Graph-reachable;
- missing transcript alone does not block when the existing metadata/source
  contract is sufficient;
- private/internal entities are not forced through public requirements;
- real-world supplied Capture IDs remain read-only fixtures only and are never
  created or mutated in local tests.

## Verification and acceptance

Before claiming completion, run and record:

- focused shared completion and adapter parity tests;
- related MCP, Capture, Governance, Media, Video, Article, Dictionary,
  Authority, Knowledge, Graph, public identity, SEO and frontend tests;
- full configured Unit suite with the documented memory setting and baseline
  comparison;
- changed-file PHP lint and applicable syntax checks;
- `git diff --check`;
- scoped secret review;
- documentation registry/bootstrap checks when ACTIVE files change.

Integration/runtime acceptance is a separate gate. It requires fresh
documentation bootstrap, exact authorized TEST runtime identity, deployed build
identity, duplicate/read-only audit and canonical read-back. No live mutation
is implied by this specification.

## Report mapping

The eventual implementation report must include the user-requested sections:

`STATUS`, `ROOT_CAUSE`, `UNIFIED_LAW`, `DOMAIN_COVERAGE`, `FILES_CHANGED`,
`CONTRACT_CHANGES`, `TEST_RESULTS`, `REAL_VIDEO_FIXTURE_EXPECTATIONS`,
`REMAINING_BLOCKERS`, `COMMIT`, `DEPLOYMENT_STATUS`, `NEXT`.

It must explicitly state which domains were implemented and tested, which are
contract-only or blocked, and must not claim public verification without exact
frontend read-back.
