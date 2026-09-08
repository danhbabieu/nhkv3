# NHK V3 Semantic Claim Projection Layer

Status: implementation design, subordinate to the Constitution and current
Knowledge, Graph, Public Entity Dossier and SEO contracts.

## Purpose and ownership

The Claim Projection Layer is a derived read model between canonical Node,
Knowledge, Source/Evidence and Graph truth and public readers. Authority owns
canonical node identity, Knowledge owns atomic claims, Source/Evidence owns
support and provenance, Graph owns typed relations and Governance owns
semantic mutation. Projection owns neither semantic truth nor public identity.

Deleting projection rows and rebuilding from those owners must produce the
same result for the same canonical revisions, graph revisions, policy revision
and template revision. Projection never copies claims into `wp_posts`, entity
payloads, post meta or canonical frontend JSON.

The frontend consumes `ClaimProjectionService`; it does not query Graph or
Knowledge repositories and does not infer relation relevance.

## Two read products

### Live Claim Ledger

The ledger is a near-real-time, public-safe materialized view. Direct claims
are claims whose canonical subject is the requested node. Related claims are
included only through an explicit policy, with an explainable path and a
bounded distance. APPROVED claims do not need a second UI approval.

The ledger may expose APPROVED claims as primary, DISPUTED claims in a
dispute section, and UNCERTAIN claims in a needs-verification section only
when the policy permits it. SUPERSEDED, DEPRECATED and PRIVATE claims are
excluded from new public ledger results. Public evidence counts include only
active public Evidence whose active Source is public. Private excerpts,
locators, metadata and internal IDs are never serialized to public output.

### SEO/editorial candidate

SEO prose is revisioned separately. A canonical mutation creates or updates a
candidate and marks only dependent sections dirty. The published revision
remains public until an explicit validation and atomic publish transition.
Candidate states are `candidate`, `validating`, `ready`, `published`,
`superseded` and `failed`. URL, slug, UUID, public identity and stable H1 are
stable-core fields and are not changed by claim projection.

## Vocabulary and profiles

The controlled category vocabulary is:

`identity`, `history`, `classification`, `mechanism`, `configuration`,
`component`, `dial_and_hands`, `case_and_decoration`, `music_and_strike`,
`sound`, `operation`, `dimension`, `material`, `provenance`,
`user_experience`, `identification_rule`, `comparison`, `exception`,
`dispute`, `other`.

Classification is deterministic and falls back to `other`. It may use already
stored Knowledge metadata, registered predicates, subject type, existing claim
type and conservative text signals. It never mints a category or canonical
fact. Node profiles are allowlists; Specimen is physical-object scoped and
Product remains listing/commercial scope. Product attributes never become
Brand or Model facts.

## Scope and subject preservation

`ClaimScopeResolver` returns direct and related eligible claims, a bounded
Graph path and a `ProjectionContext`. Distance is 0 for direct, 1 for a
neighbor and 2 for a second neighbor. The default maximum is 2; no recursive
traversal is allowed. A claim's `canonical_subject_uuid` always remains the
original subject in the result.

Contextual framing may mention the related node, for example “Ở biến thể
Odo36/10, ...”, but it may never rewrite “Odo36/10 ...” as “Odo36 ...”.
Every hop must be active, registered, endpoint-resolvable and allowed by
`GraphProjectionPolicy`, including source type, target type, predicate,
direction, category, claim state, evidence/public eligibility and distance.
Unsupported predicates, invalid identities, cycles and ambiguous endpoints
fail closed. Duplicate claim/node/context candidates are reduced to one
presentation item while retaining the best explainable path.

The initial explicit structural policy allows child-to-parent context for
`variant_of` and `model_of`, including the two-hop Variant → Model → Brand
path, with category allowlists. It does not treat `about`, `depicts` or an
unregistered inverse as a propagation shortcut. The policy is centralized and
is not repeated in templates.

## Ranking and clustering

`ClaimRanker` computes a deterministic presentation score from directness,
semantic relevance, evidence strength, confidence, editorial priority,
freshness, distance, dispute and redundancy. Ranking never decides truth.

`ClaimClusterer` creates derived clusters with a deterministic semantic key,
representative claim, supporting claim IDs and contradictory claim IDs. It
never merges, retires or updates canonical Knowledge. Contradictory claims
remain available for appropriate dispute presentation.

## Storage, hashes and invalidation

Projection storage is separate from canonical stores and is revisioned. The
logical records are node-level projection, section payloads, dependency rows
and candidate revisions. Each rebuild carries node UUID, eligible claim IDs
and revisions, relevant edge IDs and revisions, policy revision, template
revision, input hash, generated/published timestamps and status. Same input
hash is a no-op.

`ProjectionDependencyIndex` maps claim and relation dependencies to affected
node/section projections. `ProjectionInvalidationService` marks only impacted
ledger and SEO sections dirty, rebuilds the ledger through the application
service and creates/updates a candidate. It never purges the whole site and
never hooks raw database writes. Canonical events are the integration seam:
Knowledge approved/revised/superseded/deprecated, approved Graph edge changes
and relevant Source/Evidence visibility changes.

Public reads are bounded materialized reads. Evidence detail is lazy. Cache,
when available, is keyed by node UUID, projection revision and visibility
context and is invalidated through the dependency index; no Redis dependency
is introduced.

## SEO prose and publication gate

`SeoProjectionBuilder` is deterministic/template-first. It groups ranked
claims by section, preserves subjective and epistemic language, frames
related claims with their source node, and describes disputes without
rendering an absolute fact. AI is not used to create facts or publish copy;
any future synthesis must sit behind a validated `SectionSynthesisService`.

Candidate validation requires an existing canonical node, no private or
superseded/deprecated claim, subject/path preservation, no unresolved
absolute contradiction, non-empty critical sections only when required,
sanitized bounded HTML, stable H1 and stable canonical URL. Publishing is
atomic and allows at most one active published revision per node.

## Frontend, admin and backfill

Entity pages use the shared renderer. Published semantic prose appears above
“Tri thức & chứng cứ”. The ledger exposes category counts, direct/related
scope, dispute/needs-verification states, evidence summary and bounded
pagination or lazy loading. Claim cards are escaped, keyboard accessible and
do not render hundreds of evidence nodes initially. Missing or failed
projection is an honest non-fatal state with the existing entity summary.

Admin is a capability/nonce-protected control plane over the same services:
published/candidate revision, dirty sections, counts, last rebuild,
validation, rebuild, validate, publish and discard. It never bypasses
Governance or changes canonical data.

The initial backfill is a dry-runnable, resumable, batched projection rebuild
only. It never publishes all candidates automatically and never creates,
repairs, merges, seeds or rewrites semantic records, URLs or legacy article
bodies.

## Failure and security rules

Empty, unavailable, blocked and conflicted outcomes are distinct. Invalid
UUIDs, deleted nodes, dangling relations, repository failures, private
Knowledge, private Source/Evidence locators, XSS input and unauthorized
admin actions fail closed. Structured logs contain counts, revisions, hashes,
duration, dirty sections and a reason, but not private content.

## Acceptance boundary

The implementation is accepted only when unit tests cover contracts,
classification, policy, scope, ranking, clustering, visibility, hashing,
revisioning and invalidation; integration tests cover canonical Knowledge →
Graph → projection → store → read service; frontend tests cover zero, one,
related, disputed, private and many-claim pagination; and security,
performance, migration and regression checks distinguish code failures from
unavailable runtime infrastructure. Existing canonical routing and
WordPress editorial ownership remain unchanged.
