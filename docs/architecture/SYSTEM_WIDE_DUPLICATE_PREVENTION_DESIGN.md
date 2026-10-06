# System-wide resolve-before-create design

Status: `LOCAL IMPLEMENTATION CHECKPOINT / NO DATA MUTATION`

This document records the owner-boundary review requested by the system-wide
duplicate-prevention task. It does not create a new semantic owner, vocabulary,
database table, merge operation or authorization to repair existing data.

## Invariant

Every durable create-capable owner follows:

`normalize owner identity → bounded search of canonical state → owner decision →
revision-bound mutation → canonical read-back`.

`CREATE_NEW` is valid only after the owner search completed, no deterministic
equivalent or unresolved candidate remains, and the resolver was available.
Unknown or unavailable lookup is review/fail-closed, never proof of newness.

Idempotency and identity resolution remain separate. A new request key may
still reuse the same canonical owner.

## Shared protocol decision

No global semantic matcher or generic dedupe service is introduced. Existing
owner-specific resolution packets already provide the useful shared shape:
decision, candidates, dependency revisions, diagnostics and fingerprint. The
new Knowledge packet follows that shape, but matching rules remain local to
Knowledge/Source/Evidence.

The shared layer owns only orchestration binding and fail-closed diagnostics.
It does not own text similarity, entity semantics, editorial intent, binary
identity or relation identity.

## Owner matrix

| Owner | Identity law before create | Current create boundary / reuse | Risk and decision |
|---|---|---|---|
| Dictionary | Entry/Form/Sense plus lexical context, semantic mapping and lifecycle | `DictionaryPreCreateResolver` and guarded mutation/materialization; exact and alternate-form reuse | Preserve. Existing canary is a regression fixture only; no repair here. |
| Authority | UUID, server stable key, exact name/alias, entity type/family and ambiguity | `AuthorityIntentPlanner` before governed plan; `AuthorityService` stable-key defense | Preserve planner. Low-level service remains defense-in-depth, not a planner replacement. |
| Knowledge Claim | Subject/facet/scope/type plus deterministic normalized proposition; same-scope non-exact candidates are review | `KnowledgePreCreateResolver` now gates `KnowledgeService::createClaim`; stable key remains structural defense | Fixed high-risk gap. Paraphrase is not auto-merged. |
| Source | Stable key, canonical locator, source type, explicit external identity | `KnowledgePreCreateResolver` now reuses canonical locator across request keys | Fixed high-risk gap. Locator identity does not silently version snapshots. |
| Evidence | Claim + Source + relation + locator/support unit + excerpt/metadata | `KnowledgePreCreateResolver` resolves support identity before `cite()` allocates a UUID; `citeWithId` preserves explicit replay identity | Fixed `EVIDENCE_IDEMPOTENCY_UNPROVEN` boundary. Same locator with changed excerpt is review. |
| Graph relation | Registered source/predicate/target/context and endpoint revisions | `ExplicitRelationIntentPlanner` and relation repository; active equivalent is no-op/reuse | Preserve. Retired and cardinality conflict remain explicit review/apply paths. |
| Article / Post | Canonical subject + editorial intent/scope/angle + overlap/continuation | Article research/preflight owns overlap; Capture and production `EditorialDraftGateway` now require the strict pre-create result before native draft writer | Fixed Capture and direct MCP draft bypasses. Only `CREATE_DIFFERENTIATED_ARTICLE` authorizes a new draft. |
| Media | Canonical Media stable key/subject scope; physical asset separately by storage/checksum | `MediaService` and Capture Media binding create-or-resolve | Preserve. No semantic merge from checksum alone. |
| MediaAsset | Media parent + asset kind/storage key/checksum/physical dimensions | `MediaService::addAsset`/ingest reconciles physical asset | Preserve separate Asset identity; one Media can own many Assets. |
| MediaUsage | Media + endpoint + role + placement key | `MediaService` deterministic usage reconciliation | Preserve. New placement is allowed without a new Media. |
| Video | Registered platform + external video ID | `VideoIntakeService` and repository reuse | Preserve. URL/title are lookup inputs, not canonical identity. |
| Capture | No semantic identity; one durable submission/orchestration record | `EditorialCaptureCoordinator` routes to owner resolvers and binds decisions | Preserve owner boundaries. Capture cannot decide semantic sameness. |

## State visibility and race policy

Duplicate searches include active, draft/pending/review, candidate and retired
state where the owner contract exposes it. Retired identity is historical
evidence and cannot be silently replaced by a new object. WPDB Knowledge,
Source and Evidence hydration now preserves retired rows for internal identity
lookup, while public readers continue to request active state.

Resolution packets carry candidate revisions and a deterministic fingerprint.
The repository remains the structural race defense. If a create race is
observed, the service retries owner resolution and reuses the canonical result
when it is now visible; otherwise the original failure remains visible.
Semantic ambiguity is not swallowed as a duplicate error.

## Create-path audit

| Path | Owner decision before create | Bypass status |
|---|---|---|
| Canonical Capture | Content intent, subject resolution, owner-specific semantic write-back; Article overlap now runs before draft | Capture is orchestration only. |
| Direct MCP/Admin semantic writers | Internal compatibility boundary plus Governance capability | Production `nhk.article.draft.create` receives the same Article resolver as Capture; review/unavailable state blocks native draft creation. Other owner writers retain their existing capability/governance boundaries. |
| Candidate/review apply | Owner proposal and controlled apply | Review/apply is not a second identity owner. |
| Repository primitive | UUID/stable-key/structural constraints and optimistic revision | Repository is not a semantic resolver; application boundary must run first. |
| Import/backfill/runtime compatibility | Existing bounded owner services only | No new import/backfill or data repair authorized by this task. |
| Native WordPress draft | Article owner pre-create decision, then Capture/direct MCP draft writer | Production missing/unavailable resolver returns review-required; only `CREATE_DIFFERENTIATED_ARTICLE` can invoke native create. |

Within the bounded repository composition audit, the production `Plugin`
composition root is the only durable Capture/Draft composition and injects the
Article research/overlap resolver (`articlePreCreateRequired=true`). Direct
MCP draft creation receives that same resolver through
`EditorialDraftGateway`. The no-resolver compatibility behavior is reachable
only from legacy unit/support constructors whose post writer is a fake; it is
not injected into a production composition and is classified `TEST_ONLY_SAFE`.

## Read-only audit status

No automatic duplicate repair, merge, retirement or deletion is part of this
checkpoint. The Dictionary duplicate audit remains the implemented bounded,
read-only audit surface. Knowledge/Source/Evidence audit output is represented
by resolver candidates and diagnostics in this slice; a corpus-scale audit
command is intentionally deferred until its owner-specific pagination and
review contract is separately approved.

## Explicit non-goals

- No global fuzzy or embedding identity.
- No LLM decision as canonical proof.
- No global uniqueness on human labels or claim text.
- No automatic merge of the existing `Côn hoa thị` pair.
- No migration, staging/production mutation or public projection change.
