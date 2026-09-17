# NHK V3 — Intent-Scoped Publication Gates and Contextual Media Reuse Design

**Status:** design-only specification, 2026-09-17
**Scope:** intent-scoped publication/completion orchestration, canonical Media
reuse, contextual image SEO, Dictionary illustration projection and bounded
reconciliation
**Implementation:** none in this checkpoint
**Deployment/SSH/direct DB write:** none

This specification is subordinate to
`docs/constitution/NHK_V3_CONSTITUTION.md`. It unifies the previously committed
publication-gate design with the existing Media/Image SEO, Visual Support and
Dictionary contracts. It refines orchestration and projection selection; it
does not create an Article entity, semantic Post owner, new Graph
endpoint/predicate, new Media identity, new binary store or Governance bypass.

## 1. Decision summary

Publication must evaluate the requirements of the resolved Content Intent and
the explicit operation, not the union of every subsystem's completion flags.

The gate will use four design-level dimensions for each requirement:

| Dimension | Values | Meaning |
|---|---|---|
| Applicability | `REQUIRED`, `CONDITIONAL`, `OPTIONAL`, `NOT_APPLICABLE` | Whether this intent/operation owns the requirement |
| Resolution policy | `VERIFY`, `AUTO_RECONCILE`, `HUMAN_REVIEW`, `HARD_BLOCK` | What the orchestrator may do before a publication decision |
| Observed state | `VERIFIED`, `PENDING`, `RECONCILE`, `REVIEW_REQUIRED`, `BLOCKED` | Current evidence from the owning boundary |
| Evidence | owner read-back, receipt, or diagnostic | Why the state is true |

These are classification fields for orchestration and diagnostics. They are not
permission to invent a new closed runtime enum. Existing publication outcomes
remain `PASS`, `OWNER_REVIEW_REQUIRED` and `SYSTEM_BLOCKED`; existing owner
outcomes such as `PARTIAL`, retryable and unavailable remain distinguishable.

The central rule is:

> `PASS` requires every `REQUIRED` requirement to be verified. `NOT_APPLICABLE`
> requirements are skipped. `AUTO_RECONCILE` requirements are repaired once
> within their owning boundary before a decision. Only a concrete truth,
> identity, integrity, stale-write or unsafe-public-state risk is a system
> block.

The same requirement packet is used by Capture completion and Article
publication. Media projection readiness is reported by its owning Media/Asset/
Usage boundary; Dictionary and Visual Support are applicable only when their
approved projection or requirement is requested. A successful physical upload,
Media commit or lexical observation is never silently promoted to a complete
public result.

## 2. Problem statement and evidence

The current Article flow combines editorial ownership, semantic planning,
Knowledge/Graph mutation, representative Media selection, Article MediaUsage,
public identity, SEO and rendered verification into one broad completion
shape. That causes unrelated or repairable branches to become publication
blockers.

### 2.1 Case 573 evidence supplied for this design

The verified case is:

| Item | Value |
|---|---|
| Runtime source revision | `598d50e356e11be043ef120718642766cef9f9e` |
| Post | `573` |
| Capture | `01a0ad6c-45db-7f37-b678-5a4b8d734b71` |
| Media | `01a0ad65-2b73-707e-9f0f-0a6999690b3d` |
| Attachment | `572` |
| Subject | Vedette 37 |
| Model | `4cbe5aa1-4222-46bd-a140-6ab66d2da199` |
| Stable key | `nhk:model:vedette.37` |
| Classification | Cần gạt ngắt chuông đêm |
| Classification ID | `645ce96b-cd53-47b2-a2d7-f2439930a81a` |
| Planned slug | `vedette-37-ngat-chuong-dem` |

Canonical Media read-back already proves two representative usages for image
572: one for Vedette 37 and one for the night-silence classification. Their
representative SEO metadata is also read back.

The Article review nevertheless reports:

```text
CANONICAL_PUBLIC_IDENTITY_INVALID
RESEARCH_PREFLIGHT_BLOCKED
SUBJECT_UNRESOLVED
DUPLICATE_INTENT_UNRESOLVED
CATEGORY_UNRESOLVED
SEMANTIC_PLAN_INCOMPLETE
SEMANTIC_READBACK_UNVERIFIED
MEDIAUSAGE_INCOMPLETE
PUBLIC_CLAIM_COMPLIANCE_BLOCKED
SEO_PROJECTION_INVALID
STRUCTURED_DATA_INVALID
PUBLIC_ROUTE_NOT_READY
RENDERED_PUBLIC_VERIFICATION_UNAVAILABLE
```

The Article preflight independently resolves Vedette 37, recommends category
`Tri thức đồng hồ`, and produces the valid SEO intent
`vedette-37-ngat-chuong-dem`. It still reports
`ARTICLE_MEDIA_FEATURED_MISSING` and `ARTICLE_MEDIA_INLINE_MISSING` because it
observes the Article slot before the Article usage reconciliation has consumed
the committed Media ID.

The Capture also reports:

```text
CAPTURE_GOVERNANCE_FAILED
Relation endpoint revision is unavailable: wp_post:1:573
```

This is an orchestration/contract defect in the current path, not evidence that
Vedette 37 or Media 572 is unsafe.

### 2.2 Exact current defect: Article creates an unnecessary Post relation

The current path is:

1. `EditorialCaptureCoordinator::run()` resolves an Article intent and creates
   a native draft when required.
2. Its semantic write-back callback invokes
   `GovernedCaptureContinuationService::execute()`.
3. `GovernedCaptureContinuationService::plans()` unconditionally appends a
   `relation_create` plan for every `TEXT_ARTICLE` or `IMAGE_ARTICLE` with a
   resolved subject:

   ```text
   source_type = wp_post
   source_uuid = <blog_id>:<post_id>
   predicate   = about
   target      = resolved canonical subject
   origin      = CAPTURE_ARTICLE_SUBJECT_BINDING
   ```

4. `runGovernedPlan()` creates/reviews that relation proposal.
5. The Graph `RelationRevisionBinder` binds both endpoint revisions. It calls
   the registered `wp_post` resolver and throws when the current Post revision
   cannot be read:

   ```text
   Relation endpoint revision is unavailable: wp_post:1:573
   ```

6. The child failure is classified by the Capture coordinator as governance
   failure and contaminates Article completion.

The registered `wp_post` Graph endpoint is a valid editorial endpoint for
explicit, contract-approved relations and read operations. It is not an
Authority entity and must not be used as a fake semantic owner merely because
an Article discusses Vedette 37. For an ordinary editorial Article with no
semantic delta, the relation plan must not be created.

The existing `PostKnowledgeLinkService` correctly rejects direct mutation and
requires a governed relation proposal; this design does not weaken that rule.
It narrows when an Article is allowed to request such a semantic mutation.
The same semantic-delta guard must cover the adjacent current planner branch:
`GovernedCaptureContinuationService::plans()` also enters Knowledge/relation
planning when `intent === KNOWLEDGE_DELTA || count($variants) === 1`. A single
resolved Variant or a body claim candidate is not itself an explicit delta.
For an ordinary Article, that branch is `NOT_REQUIRED`; for an explicit delta,
it retains the existing governed proposal/dependency/read-back path.

### 2.3 Exact current defect: representative usage is mistaken for Article usage

The current Article media boundary is implemented by
`ArticleMediaCoordinator`. Its `ensureForPost()` reconciles
`FEATURED_PRIMARY` and `INLINE_PRIMARY` against endpoint `wp_post:<blog>:<id>`.
Representative usages are intentionally stored against their semantic target,
for example `model:<uuid>` and `classification:<uuid>`; they are not Article
slot usages.

The current sequence can therefore be:

```text
Media 572 read-back
  ├─ representative → model Vedette 37       VERIFIED
  └─ representative → classification ...     VERIFIED
Post 573 created
  └─ ArticleMediaCoordinator has not yet reconciled wp_post:1:573 slots
ArticleResearchPreflight reads current Article slots
  └─ featured/inline appear placeholder/missing
```

That result is a state-ordering mismatch. It does not justify creating a second
Media, re-uploading attachment 572, or treating representative usage as an
Article slot.

### 2.4 Current live Media re-adoption evidence

The same canonical Media case exposes a separate physical/projection boundary:

| Item | Observed state |
|---|---|
| WordPress attachment | `572` was edited through the WordPress Image Editor |
| Current physical file | `dong-ho-vedette-37-loai-ngat-chuong-dem-e1789617314273.webp` |
| Dimensions / size | `900x1200`, `177286` bytes |
| Before bounded adoption | Attachment and canonical Media existed; representative usages existed; `Media.assets=[]` |
| Bounded enrichment | `MEDIA_ENRICHMENT` reused attachment `572` and Media `01a0ad65-2b73-707e-9f0f-0a6999690b3d`, restoring one public derivative without a new binary upload or Media identity |
| Remaining inconsistency | `nhk.media.attachment.get(572)` returned `null` while `wp_get_media(572)`, Capture physical read-back and `nhk.media.get(Media UUID)` succeeded |

The executable boundary explains why this must be treated as a projection/read-
back defect rather than duplicate identity: `McpReadHandler::mediaAttachmentGet()`
delegates to `WordPressMediaAttachmentIngestor::read()`, which validates the
current WordPress attachment, filesystem path, metadata and derivative paths.
The canonical attachment-to-Media mapping is owned separately by
`WordPressMediaAttachmentBridge` and
`nhk_media_wordpress_attachments`. The read response currently does not merge
that mapping with the physical read result. The unified invariant is therefore:

```text
active attachment mapping
→ exact attachment read-back
→ same canonical Media UUID and selected asset mapping
```

If one layer cannot prove the chain, the result is a typed
`ATTACHMENT_READBACK_INCONSISTENT`/unavailable diagnostic. It is never an
honest `null` success and never permission to mint a second Media. A future
bounded repair may re-adopt the same attachment through the existing bridge,
preserve all unrelated usages, update the source-derived asset mapping and
perform final attachment, Media and public-derivative read-back.

## 3. Constitutional design principles

1. WordPress native `wp_posts` remains the sole owner of Article title, body,
   excerpt, editorial ordering, category and native permalink.
2. Article prose is editorial input by default. It is not Knowledge, Evidence
   or Graph truth.
3. Authority owns canonical subjects; Knowledge owns atomic claims;
   Source/Evidence owns provenance; Graph is the only relation store; Media,
   MediaAsset and MediaUsage remain separate.
4. A `wp_post` endpoint may be used only where an existing registered contract
   explicitly requires an editorial relation. It never becomes an Authority
   entity.
5. Representative Media usage and Article editorial usage are different
   contexts. One canonical Media may serve both when each binding is valid.
6. Governance remains mandatory for a real semantic mutation. `NOT_REQUIRED`
   is not a Governance bypass; it means no semantic write was requested.
7. Public identity is phase-aware. A planned draft route is not the same thing
   as a materialized public route, and a planned route is not automatically
   invalid.
8. Empty, unavailable, invalid, conflict, retryable and unsafe states remain
   distinct. Infrastructure failures are never converted into empty success.
9. Every automatic repair is bounded, idempotent, revision-aware and followed
   by canonical read-back.
10. This specification changes orchestration selection only. It does not
    authorize legacy-body import, data repair, staging mutation or deployment.

### 3.1 Existing executable ownership evidence

The design follows the current repository boundaries rather than introducing a
new coordinator-owned store:

| Concern | Existing owner evidence | Unified design consequence |
|---|---|---|
| Article editorial truth | native WordPress Post and `WordPressArticleMediaAdapter` | Title, body, excerpt, category, editorial ordering and permalink remain WordPress-owned. |
| Semantic identity | Authority resolver and immutable Capture subject packet | A resolved subject is reusable context; it is not automatically a mutation request. |
| Claims/provenance | Article research, Knowledge, Source/Evidence and Governance | Claim candidates remain planning input until an explicit semantic delta enters the governed lifecycle. |
| Graph relation | `GraphService`, endpoint/predicate registries and `RelationRevisionBinder` | Only an explicit registered relation creates a Graph proposal; MediaUsage and Dictionary projection do not. |
| Physical image | `WordPressMediaAttachmentIngestor`, `WordPressMediaAttachmentBridge`, `MediaService` | One attachment adoption resolves one canonical Media and its source/public assets. |
| Asset delivery | `MediaAsset`, `PublicMediaAssetSelector`, `PublicMediaAssetDelivery` | Public output selects a source-derived eligible derivative; private source-original remains private. |
| Usage/context | `MediaUsage`, `WpdbMediaUsageRepository`, `ArticleMediaCoordinator`, `MediaBindingService` | Endpoint, role, placement and contextual SEO are usage-scoped and revisioned. |
| Feature visual need | `VisualSupportRequirementService` and indexed requirement repository | Missing feature illustration is durable application state, not a Claim, Evidence, Graph edge or publication-wide semantic blocker. |
| Dictionary | Dictionary concept/label/candidate repositories and `DictionaryPublicQuery` | Lexical curation owns lexical copy and destination projection; illustration is reused through MediaUsage. |
| Public image projection | Article/entity/gallery/Dictionary projection queries | Renderers resolve contextual usage first, then eligible MediaAsset; no second SEO truth store is created. |

The owner table is also the dependency rule: a gate may ask an owner for
read-back, but it may not manufacture that owner's canonical record to satisfy
another branch.

## 4. Intent-scoped requirement matrix

The matrix is the default policy. A request may narrow it only through an
explicit registered operation and its owner contract. No body parser may widen
it merely because it found claim candidates.

| Requirement / owner | `IMAGE_ARTICLE` | `TEXT_ARTICLE` | `MEDIA_ENRICHMENT` | `KNOWLEDGE_DELTA` | `VIDEO` | `MIXED` / Authority-supported flow |
|---|---|---|---|---|---|---|
| Capture identity/read-back | Required | Required | Required | Required | Required | Required |
| Native WP Post exists | Required | Required | N/A | N/A unless explicit Article sub-intent | N/A unless explicit Article sub-intent | Required only for editorial sub-intent |
| Post state/revision/CAS | Required | Required | N/A | N/A | N/A | Required for editorial sub-intent |
| Canonical subject resolution | Required when subject is stated or inferred as a registered subject | Required when subject is stated or inferred | Conditional for exact target binding; N/A for unscoped physical commit | Required | Required | Required for each approved semantic candidate |
| Editorial prose persistence | Required | Required | N/A | N/A unless explicit Article sub-intent | N/A unless explicit Article sub-intent | Required for editorial sub-intent |
| Article `FEATURED_PRIMARY` | Required with a real eligible Media when image policy applies | Required usage record; real eligible Media is conditional on text-Article policy | N/A | N/A | N/A | Required for editorial image sub-intent |
| Article `INLINE_PRIMARY` | Required with a real eligible Media when image policy applies; may reuse same real Media under single-image exception | Required usage record; real eligible Media is conditional on text-Article policy | N/A | N/A | N/A | Same as resolved editorial image sub-intent |
| Article MediaUsage SEO blueprint | Required for each Article slot; placeholder state remains honest until a real Media is available | Required for each Article slot; real-image readiness may be `NOT_APPLICABLE` under text-Article policy | N/A | N/A | N/A | Required for editorial image sub-intent |
| Representative Media reconciliation | Conditional: only if explicitly requested or the Media branch is in scope | Conditional | Required for requested representative binding | N/A unless semantic plan requests it | Conditional for registered thumbnail/Media branch | Required only for the requested branch |
| Knowledge read/reuse | Conditional for claim selection in prose | Conditional for claim selection in prose | N/A | Required | Conditional for video enrichment | Required for approved semantic branch |
| Knowledge mutation | N/A by default; Required only for explicit delta | N/A by default; Required only for explicit delta | N/A | Required | Conditional and separately approved | Required for approved delta only |
| Source/Evidence mutation | N/A by default | N/A by default | N/A | Required when the delta requires support | Required for a governed factual/relation package | Required only for approved semantic work |
| Graph mutation | N/A by default | N/A by default | N/A for MediaUsage/representative binding | Required only for an approved registered relation delta | Required for governed semantic attachments | Required only for approved relations |
| Governance | N/A when no semantic mutation exists; Required for semantic delta | N/A by default; Required for semantic delta | Required for semantic Media mutation; not for Article | Required | Required | Required for every semantic mutation |
| Native category | Required when Article publication policy requires it | Required when Article publication policy requires it | N/A | N/A | N/A unless explicit Article | Required for editorial sub-intent |
| Planned public route | Required before publication decision | Required before publication decision | N/A | Conditional per public-capable owner | Conditional per Video owner | Required per public-capable owner |
| Materialized public route/read-back | Required before claiming public publication | Required before claiming public publication | N/A | Required only when publishing a public semantic resource | Required for public Video success | Required per public-capable owner |
| SEO projection | Required | Required | Conditional for Media projection | Conditional | Required for Video publication | Required for each public branch |
| Public claim compliance | Required over actual public Article copy and its projections | Required | Conditional over MediaUsage public copy | Required for public claim text | Required | Required per rendered surface |
| Rendered public verification | Required after publication / before public success | Required after publication / before public success | N/A | Required for public semantic route | Required | Required per published branch |
| Video | N/A | N/A | N/A | N/A | Required | Required only for explicit Video branch |

The Authority and Mixed cases are explicit refinements of the final matrix
column, not new intent values:

| Intent | Native Article | Authority/semantic owner | Dictionary | Media illustration | Publication consequence |
|---|---|---|---|---|---|
| `AUTHORITY` | `NOT_APPLICABLE` | Required for the approved Authority plan; Governance and canonical read-back remain required for mutation | Optional lexical planning only | Optional visual-support/representative branch only when requested | No Article route, Article MediaUsage or Article SEO gate is acquired. |
| `MIXED` | Required only for the explicit Article sub-intent | Required only for the explicit approved semantic delta/Authority branch | Conditional read/projection branch | Conditional per exact requested consumer | Article and semantic branches have separate receipts; completion requires every applicable required branch. |

`AUTHORITY` is the current typed Capture purpose/operation boundary, not a new
Authority entity type. It must continue to reuse registered Authority types,
Graph predicates and Governance operations. A Dictionary concept or a visual
support requirement never upgrades itself into an Authority branch.

### 4.1 Requirement decision owners

The owner of applicability is not the same as the owner of canonical data:

| Decision | Owner boundary | Rule |
|---|---|---|
| Content Intent | `ContentIntentRouter` | Resolve explicit intent first; heuristic routing may not overrule explicit intent. |
| Article owner existence | `EditorialCaptureCoordinator` | Only Article intents create a native Post. |
| Subject applicability | `SubjectResolutionService` plus the operation packet | A resolved subject is context, not a write request. |
| Semantic delta applicability | Capture semantic planner / `GovernedCaptureContinuationService` | Require an explicit delta signal or approved governed plan. |
| Article Media slots | `ArticleMediaCoordinator` and native WP read-back | Representative usages are never substituted. |
| Representative binding | `MediaBindingService` and its registered target recipe | Exact target/context and role are required. |
| Claim/evidence compliance | `ArticleResearchPreflight` plus shared claim policy | Evaluate actual public copy; generated prose is not evidence. |
| Planned/materialized route | native WP editorial reader plus public route/SEO services | Phase determines whether absence is a warning, reconcile state or block. |
| Final outcome | `ArticlePublicationGate` / owner publication service | Classify all diagnostics by safety and policy, not by subsystem count. |
| Capture completion | `CompletionCoordinator` | Aggregate only the owner branches required by the resolved intent. |

## 5. Editorial input versus semantic delta

### 5.1 Default rule

`TEXT_ARTICLE` and `IMAGE_ARTICLE` body, title, caption and descriptive wording
are editorial input. `claim_candidates`, observations, OCR, captions, filenames
and generated prose may be retained in a body-free research trace, but they do
not authorize a Knowledge or Graph write.

For an Article with no semantic delta:

```text
semantic_delta = NO
knowledge_write = NOT_REQUIRED
source_write    = NOT_REQUIRED
evidence_write  = NOT_REQUIRED
graph_write     = NOT_REQUIRED
governance      = NOT_REQUIRED
semantic_readback = NOT_REQUIRED
```

`NOT_REQUIRED` must be represented as an applicability decision with the
operation/intent fingerprint that produced it. It must not be represented as a
false `APPLIED` result or a fabricated canonical read-back.

### 5.2 Conditions that make semantic write-back required

Semantic mutation is required only when at least one is true:

1. The resolved intent is explicit `KNOWLEDGE_DELTA`.
2. The user explicitly asks to add or update canonical knowledge, evidence,
   source or relation state.
3. An approved Authority or `MIXED` plan contains an actual semantic delta,
   with exact target IDs and expected revisions.
4. An existing-owner update contract explicitly requires mutation and binds the
   operation to the canonical owner.

A parser finding a factual-looking sentence is not sufficient. A claim may be
selected for Article wording only if its existing canonical ID/revision,
subject, scope, provenance, evidence and relevance pass the research contract.
Selection/reuse is not mutation.

### 5.3 Compliance treatment of descriptive wording

Wording such as “phù hợp với không gian nhỏ” or “hạn chế làm phiền khi nghỉ
ngơi” is evaluated against the actual public copy. It is not automatically a
new canonical Knowledge claim. If it is descriptive and remains truthful in
scope, it may pass the claim policy without creating a Knowledge record.

An objective historical/technical statement such as a 21h–07h schedule must be
handled according to evidence scope: reuse a matching canonical claim/evidence,
narrow or attribute the wording, or route to human review. The system must not
create Knowledge solely to satisfy a gate.

## 6. `wp_post` and Graph boundary

### 6.1 Target boundary

`wp_post:<blog_id>:<post_id>` is the native editorial target. Its owner is
WordPress. It can participate in a registered Graph relation when a separate
operation explicitly requires one, but it is never an Authority entity and the
Article workflow never needs a Graph `article` endpoint.

For a normal Article that is “about” a canonical subject, the semantic
association is carried by one or more of:

- the immutable Capture subject-resolution packet;
- the Article operation receipt/research trace;
- an existing dedicated Article semantic projection, only if an already
  approved contract defines its owner and lifecycle;
- an explicit, separately requested and registered Graph relation.

The last option is not implied by the presence of Article prose.

### 6.2 Planned change to the current path

`GovernedCaptureContinuationService::plans()` must stop adding the unconditional
`CAPTURE_ARTICLE_SUBJECT_BINDING` relation for `TEXT_ARTICLE` and
`IMAGE_ARTICLE`. The plan builder must emit no relation plan when
`semantic_delta = NO`.

When a user explicitly requests a relation, the existing relation path remains:

```text
registered endpoint/predicate
→ exact source/target resolution
→ evidence/provenance policy
→ Proposal → approval → eligibility
→ Controlled Apply → Graph read-back
```

The `wp_post` revision error must then remain a genuine integrity/runtime
failure for that explicitly required relation. It must not be demoted merely to
make publication pass. For the ordinary Article case it disappears because the
relation is not applicable.

### 6.3 No new shortcut

This design does not add `article`, `post_about_subject`, a parallel relation
table, a postmeta semantic link, a frontend convenience edge or a generic
content node. Existing Graph predicates and endpoint registries remain the only
vocabulary.

## 7. Representative Media versus Article MediaUsage

### 7.1 Separate contexts

Representative usage answers: “which Media represents this canonical semantic
target/facet?” Article usage answers: “which Media occupies this Article's
editorial slot?” They have different endpoint keys, role policies, contextual
SEO and read-back owners.

```text
Media 572
  ├─ representative → model:<Vedette-37>        MediaBindingService
  ├─ representative → classification:<night>   MediaBindingService
  ├─ featured_primary → wp_post:1:573           ArticleMediaCoordinator
  └─ inline_primary → wp_post:1:573             ArticleMediaCoordinator
```

The four usages are not interchangeable, but all four may point to one
canonical Media identity when their individual contracts pass.

### 7.2 One-image Article rule

For `IMAGE_ARTICLE` with exactly one eligible real Media after deduplication:

- the same Media ID may satisfy `FEATURED_PRIMARY` and `INLINE_PRIMARY`;
- no second Media, binary, attachment or placeholder is created;
- the body is not artificially repeated to satisfy a slot;
- each Article usage gets its own contextual SEO blueprint/read-back;
- the single-image exception is recorded in the Article media evidence.

With two or more eligible real Media, normal placement rules apply. If no real
Media is available, placeholders remain honest incomplete state and may be
`OWNER_REVIEW_REQUIRED` or `SYSTEM_BLOCKED` only according to the active
publication policy; they never become evidence or preferred structured-data
images.

### 7.3 Required ordering

After draft creation has produced the Post ID:

1. Read the Capture's committed ordered Media IDs and attachment/Media
   read-back.
2. Resolve the Article subject packet; do not rediscover identity by filename,
   title similarity or Graph reachability.
3. Call `ArticleMediaCoordinator::ensureForPost()` with the Post ID and selected
   Media IDs.
4. Reconcile `FEATURED_PRIMARY`, `INLINE_PRIMARY` and supporting usages through
   the existing Media/Usage owner with stable placement keys and CAS.
5. Read back the Post editorial image state and Article MediaUsage state.
6. Only then build Article research/publication evidence.

`ArticleResearchPreflight::research()` may still report a planning diagnostic
before reconciliation, but that diagnostic must be classified as
`AUTO_RECONCILE`/pending rather than as a semantic failure. The final gate must
consume the post-reconciliation snapshot, not the earlier representative-only
snapshot.

### 7.4 Contextual usage fields and registered role mapping

`MediaUsage` is the contextual presentation record. Its current persisted
identity is the canonical Media plus endpoint type/key, registered role and
placement key; its contextual fields are `title`, `alt_text`, `caption`,
keyword groups, sort order, selection source/policy and optimistic revision.
`MediaUsageMetadataMigration021` and `WpdbMediaUsageRepository` already own
this revisioned boundary.

The design distinguishes these reader-facing intents without inventing a new
runtime enum:

| Design intent | Existing registered role/owner |
|---|---|
| Whole-node representative | `representative`, reconciled by `MediaBindingService` |
| Article featured image | `featured_primary`, reconciled by `ArticleMediaCoordinator` and WordPress featured state |
| Article inline image | `inline_primary` or `inline_supporting`, reconciled against native Post content |
| Technical/detail view | `technical_detail`, optionally backed by an exact `VisualSupportRequirement` |
| Evidence-like illustration | `evidence` only as presentation; it does not create Source/Evidence truth |
| Gallery/supporting visual | registered `gallery`/supporting compatibility role where the target contract permits |
| Contextual illustration | a target/placement context using one of the registered roles above; `contextual` is not a new role or Graph endpoint |

The implementation must validate the selected role through
`MediaUsageRoleRegistry`. If a desired context has no registered role or target
contract, the result is `REGISTRY_GAP`/review, not a free-form role. Role alone
never proves a semantic relation or factual feature.

### 7.5 One Media identity and many valid contexts

The reuse invariant is:

```text
same visual asset
→ one canonical Media
→ one source-original MediaAsset plus eligible derivatives
→ many independently revisioned MediaUsage records
```

A new Media identity is justified only when the physical visual is meaningfully
different or the existing identity cannot truthfully represent it. Valid new
visuals include a whole-object view, a marking close-up, a mounting detail, an
installed context or another evidence/technical view. A different alt/caption,
Article, Model, Classification or Dictionary context is not a reason to create
another binary, Attachment or Media.

Exact binary reuse is separate from visual-similarity discovery. Before a new
binary becomes a new Media, the bounded physical boundary may compare the
source checksum, exact existing Attachment mapping, source provenance and
storage ownership. An exact duplicate is a reuse candidate and should resolve
to the already-owned Media when the mapping and scope are unambiguous. A
near-duplicate photograph remains a separate candidate unless an approved
identity contract explicitly proves that it is the same physical asset;
similarity, filename, title, URL or checksum alone never merges semantic
identity across unrelated provenance.

### 7.6 MediaAsset lifecycle and WordPress compatibility

The source-original remains a PRIVATE/protected `MediaAsset`; the canonical
public derivative is source-derived WebP under the existing 1200px maximum
long-edge rule, with smaller derivatives serving listing/srcset purposes only.
`MediaAsset` owns checksum, MIME, dimensions, byte size, visibility, storage and
delivery metadata. WordPress Attachment is a storage/projection locator and
compatibility boundary, not the Media or SEO owner.

Re-adoption of an edited attachment must use the exact existing mapping
`wp-attachment:<blog>:<attachment_id>` and the current WordPress physical
read-back. It may update or restore the source/public asset under the same
Media UUID, preserve all existing MediaUsage records, update the attachment
mapping and perform final MediaAsset/attachment/delivery read-back. If the
physical edit materially changes the visual into a different semantic object,
the operation returns review rather than guessing or merging.

No re-adoption path may delete representative usage, create a Dictionary-owned
binary, mint a second Media, or change a published public URL silently. The
existing `WordPressMediaAttachmentBridge::adoptAttachment()` is the later
implementation boundary; `MediaService` remains the asset reconciliation owner.

## 8. Public Identity: planned and materialized lifecycle

Article has a deliberate native WordPress route exception: WordPress owns the
editorial `post_name`/permalink. The gate still needs an explicit two-phase
model so a draft is not falsely marked invalid.

### 8.1 Planned phase

The draft may be publication-ready for route planning when all are verified:

- the editorial owner is deterministic (`wp_post:<blog>:<post>`);
- title/slug intent is non-empty and normalized under the active public URL
  policy;
- the planned route is deterministic from the current Article title/intent;
- native route and collision checks have passed for the owner/scope;
- reserved/protected route rules pass;
- no conflicting persisted identity or stale owner state is present.

The absence of a materialized public URL on a draft is not by itself
`CANONICAL_PUBLIC_IDENTITY_INVALID`. It is a planned-state fact.

### 8.2 Materialized phase

Before returning a successful publication/public result, the system must verify:

- native WordPress publication transition completed under the expected state
  token;
- actual permalink/post name is persisted and read back;
- route resolves to Post 573's exact owner;
- canonical URL/SEO projection agree with the materialized route;
- rendered public output is available and field-level verification passes.

Hard-block conditions remain:

- identity conflict or non-deterministic owner;
- invalid slug;
- public/native route collision with another owner;
- stale or mismatched persisted identity;
- route cannot safely materialize;
- publication outcome cannot be determined.

`CANONICAL_PUBLIC_IDENTITY_INVALID` therefore needs phase-aware evidence. A
planned draft may still be `VERIFIED` for planned identity while materialized
URL remains `NOT_APPLICABLE` until publication. The existing
`PublicIdentityService` remains the owner for semantic public identities; it is
not used to create a second Article identity.

### 8.3 Contextual image SEO resolution

For every rendered image, the projection resolves metadata in this exact order:

1. exact active contextual `MediaUsage` for the current endpoint, role and
   placement;
2. subject-specific representative `MediaUsage` when the consumer explicitly
   permits representative fallback;
3. verified neutral Media metadata;
4. WordPress Attachment fallback metadata for compatibility only;
5. explicit `MISSING`.

The fallback must be field-level and deterministic: a missing caption does not
erase a safe alt text, and an Attachment title cannot override a more specific
canonical Usage. Render-time code may select stored projections, but it may not
invent a description, infer a feature, or convert a filename/OCR/recognition
signal into semantic truth. Contextual `title`, `alt_text` and `caption` may
differ across Article, Entity, Classification and Dictionary usages because the
reader context differs; each remains bounded by the Media identity and claim
compliance contract.

The current implementation evidence is `ArticleMediaSeoProjection`,
`EntityMediaProjection`, `PublicMediaGalleryQuery` and
`VisualSupportPublicProjection`. The unified implementation should align these
readers to the same precedence and public-asset selector rather than introduce
a second SEO truth store. Every rendered URL must use an eligible public
MediaAsset; private source-originals, placeholders, review-only assets and
unavailable derivatives are omitted. Image sitemap and structured data use the
same eligibility and preferred-image policy.

### 8.4 Safe reverse-enrichment propagation

Progressive enrichment is allowed only through an explicit scope matrix:

| Source context | Target context | Allowed propagation |
|---|---|---|
| Exact approved subject identity and scope | neutral Media name | Yes, when the name is genuinely identity-safe and the Media revision/CAS passes. |
| Article-specific alt/title/caption | global Media or Attachment SEO truth | No. Keep it on Article `MediaUsage`; an Attachment compatibility projection may mirror it only for that exact placement and under its owner contract. |
| Representative contextual SEO | another target Usage | No automatic copy; generate a separately scoped Usage blueprint. |
| Verified neutral Media metadata | eligible contextual Usage | Yes as deterministic fallback, never as stronger context than the source supports. |
| WordPress Attachment manually edited title/alt | canonical Media identity/name | No automatic semantic authority. It is compatibility input and may produce a review/readback diagnostic. |
| Dictionary definition or lexical explanation | Media description/identity | No. Dictionary remains lexical editorial ownership. |
| Exact approved Dictionary concept identity | Dictionary `MediaUsage` illustration | Yes, through the existing MediaUsage/projection boundary with target/revision read-back. |
| VisualSupportRequirement binding | affected consumer Usage/projection | Yes, only for the exact subject/scope/facet/feature/intent and after bounded suitability/read-back. |

Thus later context can enrich a neutral Media name or add a target Usage, but
editorial wording cannot leak backward and rewrite global Media truth. Every
allowed propagation is idempotent, revision-aware and auditable. A conflict or
scope mismatch is review/blocking at the owning boundary, never silent merge.

## 9. Blocker taxonomy

### 9.1 `HARD_BLOCK`

These map to `SYSTEM_BLOCKED` unless a more specific existing owner outcome is
required:

- ambiguous/conflicting canonical subject where the published truth would be
  wrong;
- stale Post/editorial state token or semantic revision that could overwrite
  newer state;
- corrupt, unreadable, private-ineligible or invalid Media required for the
  actual public surface;
- public URL collision, conflicting owner or non-deterministic route;
- unsupported objective/superiority claim that cannot be narrowed safely and
  cannot be human-approved under the active policy;
- Governance rejection when a semantic mutation is genuinely `REQUIRED`;
- canonical owner mismatch, broken identity or invalid endpoint/registry;
- unsafe publication state, uncertain native transition or unavailable final
  read-back;
- unknown/malformed diagnostic or policy mismatch.

### 9.2 `AUTO_RECONCILE`

These are repairable only within one bounded owner operation:

- Article `MediaUsage` absent while a valid canonical Media is already
  committed;
- SEO projection or Article blueprint missing but deterministically
  regeneratable;
- category absent but deterministically resolvable through the native category
  owner and policy;
- planned route not yet materialized on a draft;
- derived projection stale;
- a temporary owner readback gap where one refresh can establish current state;
- representative reconciliation explicitly requested and deterministically
  scoped;
- WordPress Image Editor changed the attachment while the stable attachment
  mapping remains unambiguous and the current physical image can be validated;
- attachment-to-Media readback is stale but the exact mapping, physical file,
  Media identity and public derivative can be reconciled without ambiguity;
- a missing contextual projection is deterministically rebuildable from an
  existing Usage/Asset/Dictionary owner.

### 9.3 `WARNING` / `HUMAN_REVIEW`

- subjective editorial wording;
- descriptive copy that needs editorial judgment but is not unsafe;
- optional visual support;
- an evidence gap outside the factual scope actually published;
- a lexical candidate or Dictionary illustration choice that is ambiguous but
  not required for the host Article/Entity to remain truthful;
- a contextual caption/alt choice requiring editorial selection;
- incomplete quality enhancements explicitly marked overridable by the active
  publication policy.

These map to `OWNER_REVIEW_REQUIRED` only when the policy declares them
publication-relevant. They never fabricate a pass.

### 9.4 `NOT_APPLICABLE`

- Video branch in a non-Video flow;
- Knowledge/Source/Evidence mutation when no semantic delta exists;
- Graph mutation when no registered relation write is requested;
- representative selection when no representative request or applicable Media
  branch exists;
- Dictionary curation/illustration when no approved Dictionary projection is
  requested;
- feature-level VisualSupportRequirement when the host contract marks the
  visual as optional;
- Article route/readback for Media-only or Knowledge-only intents without an
  explicit Article sub-intent;
- real-image publication readiness for `TEXT_ARTICLE` when the active policy
  does not require a real image; the constitutional Article slot Usage records
  and their blueprint/read-back still apply.

## 10. Bounded auto-reconciliation algorithm

The gate must not retry indefinitely or convert infrastructure failure into a
false pass.

```text
1. Resolve Content Intent and build the requirement plan.
2. Read current owner snapshots and classify each requirement.
3. If any required HARD_BLOCK exists, return SYSTEM_BLOCKED.
4. For AUTO_RECONCILE requirements:
   a. refresh the owner state once;
   b. invoke the exact scoped reconciler once;
   c. read back the owner once;
   d. recompute the requirement result from read-back.
5. If a CAS/revision conflict appears, return SYSTEM_BLOCKED/CONFLICT.
6. If the bounded repair succeeds, continue with current snapshots.
7. If it is still unavailable but safe to retry, return PARTIAL/retryable with
   exact owner and stage; do not publish.
8. If the remaining failures are overridable quality rules, return
   OWNER_REVIEW_REQUIRED with the exact fingerprint.
9. Verify every REQUIRED requirement.
10. Run native publication only when the gate returns PASS and the caller
    requested publish.
11. Read back Post, Article MediaUsage, Media, route, SEO and rendered output.
12. Return success only after the final read-back proves the requested state.
```

The one-refresh rule applies per owner boundary, not per entire Capture. A
successful Media reconciliation must never be replayed merely because rendered
verification is unavailable. Resume uses the Capture/operation idempotency key
and the last durable owner receipt.

For an Image Editor re-adoption, the bounded owner operation is:

```text
attachment read-back
→ resolve existing attachment mapping by exact attachment/blog identity
→ validate current physical bytes and metadata
→ preserve the Media UUID and unrelated Usage UUIDs
→ restore/update source-original and eligible public derivative
→ update the attachment/asset mapping once
→ read back attachment + Media + MediaAsset + public delivery
```

For an attachment readback inconsistency, the operation first compares the
mapping table, WordPress attachment metadata, Media provenance and asset
metadata. If all identify one owner, it may reconcile the projection once. If
they identify different owners, missing files, or conflicting checksums, it is
`HARD_BLOCK`/owner review. A `null` from one adapter is never converted into
“attachment absent” when another canonical boundary proves the attachment.

## 11. Idempotency and concurrency

- Capture replay with the same key and unchanged fingerprint resumes the same
  Capture; changed payloads remain an idempotency conflict.
- Draft creation remains one Post per Article intent.
- Media adoption reuses committed Media/Attachment IDs and never downloads or
  re-imports the same binary merely to fill an Article slot.
- Article usage identity is the existing Post/role/placement boundary. Existing
  Usage UUIDs and revisions are preserved; stale updates fail closed or refresh
  once according to the owner contract.
- The single-image exception may bind the same Media ID to two Article roles,
  but it may not create duplicate Media/Asset/Attachment records.
- Semantic proposals bind subject, operation, payload fingerprint, expected
  revision, dependency closure and idempotency key. A real semantic delta must
  still pass Proposal → Approval → Eligibility → Controlled Apply → read-back.
- Graph exact triples remain idempotent. No Article-specific shortcut edge is
  created to make a frontend or gate appear complete.
- Publication approval remains bound to Post, state token, policy version and
  blocker fingerprint and expires under the current Owner Publication law.

## 12. Failure semantics and diagnostics

Every requirement result must carry:

```text
requirement_key
applicability
resolution_policy
observed_state
owner_boundary
owner_revision/state_token when applicable
evidence_reference
diagnostic_codes
attempt_count (bounded)
```

The diagnostic must say whether the problem is:

- not requested (`NOT_APPLICABLE` / `NOT_REQUIRED`);
- pending a bounded owner repair;
- owner review quality work;
- unavailable infrastructure/runtime;
- identity/relationship conflict;
- unsafe or unverifiable publication state.

Examples:

| Current condition | Classification | Publication effect |
|---|---|---|
| Vedette 37 resolved; no semantic delta | NOT_APPLICABLE | No Knowledge/Graph/Governance blocker |
| Media 572 valid; Article slots absent before reconcile | AUTO_RECONCILE | Run Article Media reconcile once |
| `wp_post:1:573` revision unavailable for an unrequested relation | Orchestration defect | Remove relation from plan; retain diagnostic for code repair |
| `wp_post:1:573` revision unavailable for an explicit required relation | HARD_BLOCK | Stop; relation cannot be safely governed |
| Draft has deterministic slug intent but no permalink yet | Planned identity | Continue draft/review; do not report invalid identity |
| Route collision with another Post | HARD_BLOCK | No owner override |
| Optional feature visual missing | WARNING / N/A | Does not block Article unless contract marks it required |
| Unsupported “best/only” claim with no lawful evidence | HUMAN_REVIEW or HARD_BLOCK | Narrow meaning or stop according to policy |
| Rendered public route unavailable after publish attempt | HARD_BLOCK | Do not claim public success |

## 13. Case 573 expected flow under this design

1. `ContentIntentRouter` resolves `IMAGE_ARTICLE`.
2. The Article draft owner is Post 573; its state token is read back.
3. Subject resolution returns Vedette 37/model UUID. This satisfies the
   Article subject requirement; it does not request a semantic write.
4. The planner records:

   ```text
   SEMANTIC_DELTA = NO
   KNOWLEDGE_WRITE = NOT_REQUIRED
   SOURCE_WRITE    = NOT_REQUIRED
   EVIDENCE_WRITE  = NOT_REQUIRED
   GRAPH_WRITE     = NOT_REQUIRED
   GOVERNANCE      = NOT_REQUIRED
   ```

5. The Article media reconciler reuses Media 572 and binds:

   ```text
   Media 572 → wp_post:1:573 / FEATURED_PRIMARY
   Media 572 → wp_post:1:573 / INLINE_PRIMARY
   ```

   because this is one eligible real image. It does not duplicate the
   representative usages for the model or classification.

   If the current WordPress Image Editor version of attachment 572 is the
   physical input, the same bounded adoption boundary first proves the existing
   `wp-attachment:<blog>:572` mapping, preserves Media
   `01a0ad65-2b73-707e-9f0f-0a6999690b3d`, restores/reads the eligible public
   derivative and leaves both representative usages intact. It does not create
   a second Attachment, Media or binary.

6. Category planning resolves `Tri thức đồng hồ` through the native category
   owner.
7. Public route planning accepts slug intent
   `vedette-37-ngat-chuong-dem` in the planned draft phase after collision and
   owner checks. Lack of a materialized permalink is not a blocker at this
   phase.
8. SEO projection is built from the Article's actual title/body/category,
   canonical Post route policy and the Article MediaUsage snapshot.
9. Claim compliance evaluates the actual public Article copy. Descriptive
   wording may pass as descriptive; the exact 21h–07h assertion must reuse,
   narrow/attribute or request review based on available evidence.
10. Publication proceeds only if all required Article requirements, final
    rendered verification and native read-back pass. Otherwise the result is
    the exact remaining `OWNER_REVIEW_REQUIRED`, retryable or `SYSTEM_BLOCKED`
    state.

The expected correction is not “force Article 573 through.” It is that the case
does not fail merely because of `CAPTURE_GOVERNANCE_FAILED` from an unrequested
Post relation or `MEDIAUSAGE_INCOMPLETE` observed before Article usage
reconciliation. It may still stop on a genuine route, claim, CAS or rendered
verification risk.

## 14. Concrete intent examples

### 14.1 `IMAGE_ARTICLE`

Input contains Article prose and one valid image. Resolve subject and compose
the native Post. Reuse the canonical Media, reconcile both mandatory Article
slots, and run Article SEO/compliance/read-back. Knowledge and Graph mutation
are `NOT_APPLICABLE` unless the request includes an explicit semantic delta.

### 14.2 `TEXT_ARTICLE`

Input contains Article prose but no image. Native Post, category, planned route,
SEO, claim compliance and editorial/read-back requirements apply. The mandatory
Article MediaUsage slot records/blueprints still reconcile, while real-image
publication readiness is `NOT_APPLICABLE` when the active content policy does
not require a real image. A factual-looking sentence remains editorial input
unless the user explicitly requests Knowledge mutation.

### 14.3 `MEDIA_ENRICHMENT`

There is no Article owner. Resolve the exact existing Media and target, then use
`MediaBindingService` for the requested representative/detail usage. Media,
MediaAsset and MediaUsage read-back applies; Article composition, Article route,
Article SEO and Article publication are `NOT_APPLICABLE`. A Graph edge is not
created merely because the Media is representative.

### 14.4 `KNOWLEDGE_DELTA`

There is no Article unless explicitly requested. Resolve the canonical subject,
claim scope, source/evidence and registered relations. Govern every mutation
through the full Governance lifecycle, then read back Knowledge/Source/Evidence
and Graph. Article MediaUsage and native Post publication are `NOT_APPLICABLE`.

### 14.5 `VIDEO`

Keep Video as the canonical external-reference owner. Apply Video-specific
source, evidence, semantic attachment, public identity, SEO and rendered
verification rules. Do not create an Article or local Media as a side effect.
Any optional Article sub-intent creates a separate Article requirement plan;
Video requirements do not leak into it unless explicitly requested.

### 14.6 `MIXED` / Authority-supported flow

The Capture may own at most one native Article plus an explicitly approved
Authority/semantic plan. The Article branch and semantic branch have separate
requirement matrices and receipts. Authority/Graph/Knowledge mutations require
Governance; Article editorial publication does not become a semantic apply.
The combined completion receipt is complete only when every branch required by
the explicit plan is verified.

## 15. Unified canonical Media reuse and Dictionary illustration

This section extends the Article Media rules above to every approved consumer.
It does not create a new Album owner, generic Content node, Dictionary binary
store or convenience Graph edge.

### 15.1 Côn 111 reuse example

One canonical representative photo may be reused as follows:

```text
Media A = Bộ côn 111

Media A → Component/Concept “Côn 111” → representative
Media A → Article 54 → technical_detail / inline
Media A → Article 57 → technical_detail / inline
Media A → Article Westminster → contextual/technical illustration
Media A → Dictionary Concept “Côn 111” → preferred illustration
```

Each consumer has its own endpoint/context, placement and contextual
`title`/`alt_text`/`caption`. The shared Media identity and eligible public
derivative are reused; no duplicate binary, Attachment or Media is created.
The illustration does not prove the Dictionary definition, create `depicts`,
or create an Article-to-subject Graph edge.

Additional valid visuals remain separate Media because they add information:

```text
Media B = close-up of the “111” marking
Media C = mounting/attachment detail
Media D = Côn 111 installed on a machine
```

These are not duplicate SEO variants. They are distinct physical views with
different coverage and technical/contextual value. Selection remains governed
by exact subject/scope, visual coverage, technical relevance, quality,
provenance and current representative policy. Visual similarity alone cannot
merge them.

### 15.2 Dictionary preferred illustration

Dictionary remains a lexical/curation projection. An approved Dictionary
Concept may reuse one eligible canonical Media through the existing
MediaUsage/projection boundary as its current preferred illustration and may
expose supporting visuals through additional registered usages. It never copies
binary data into Dictionary persistence and never owns Authority, Knowledge,
Source/Evidence or Graph truth.

The preferred illustration is stable for reader continuity but replaceable:

- an owner-approved Usage is the current preferred illustration;
- an explicitly approved Media B may replace Media A for future Dictionary
  projection under Usage revision/CAS;
- Media A and its existing contextual usages remain valid unless their own
  owner contracts retire them;
- a missing, private, placeholder, review-only or unavailable asset yields an
  honest incomplete/unavailable Dictionary image projection, not a duplicate
  Media or Attachment.

The current executable read boundary is `DictionaryRuntime` →
`DictionaryPublicQuery` → `EntityMediaProjection` for the lexical concept
endpoint. The implementation slice must ensure that “preferred” is a stable
projection decision using existing registered MediaUsage selection metadata,
not a new Dictionary binary field or hidden semantic relation. Owner-delegated
Dictionary concepts link directly to their canonical Entity/Knowledge/Article
owner and do not create an indexable competing Dictionary detail page.
The lexical endpoint/Usage boundary must remain an existing allow-listed
application boundary; if the runtime cannot validate it, return
`REGISTRY_GAP` rather than inventing a Graph endpoint or free-form role.

### 15.3 Visual Support and technical reuse

`VisualSupportRequirement` is the exact requirement ledger for a named feature;
it is not a representative substitute. A resolved requirement may bind the
same Media A already used as representative, or Media B/C/D when the detail
view is more suitable. The binding is exact to subject, scope, facet, feature
key, visual intent and consumer context. The reverse reconciler performs the
indexed bounded lookup, creates the contextual Usage through the existing
owner, invalidates affected projections and preserves prior binding history.

Feature support remains `MISSING`, `RESOLVED` or `REVIEW_REQUIRED` separately
from public asset eligibility. Public projection omits private/review/
placeholder/unavailable assets and never treats a VisualSupport binding as
Evidence or as a Graph relation.

### 15.4 Explicit data placement

| Data | Canonical placement | Forbidden propagation |
|---|---|---|
| Identity-safe Media name/provenance | `Media` and its provenance | Article-specific wording, Dictionary definition or Attachment editor text may not become global identity automatically. |
| Source-original/public derivative/checksum/dimensions/visibility | `MediaAsset` | Derivative does not become a new Media. |
| Target, role, placement, sort, contextual title/alt/caption, selection and revision | `MediaUsage` | Usage does not become Knowledge, Evidence or Graph truth. |
| Article body/title/category/order | native WordPress Post | Do not copy into semantic stores or Dictionary persistence. |
| Dictionary label/definition/destination | Dictionary concept/label repositories | Do not copy lexical text into Media or claim truth. |
| Feature visual need/binding | VisualSupport requirement ledger + MediaUsage | Do not broaden scope or infer a factual claim. |
| Canonical image URL/alt/caption in public output | projection queries from the above owners | Do not invent fields at render time or expose private source-originals. |

## 16. Attachment readback and public render invariants

The public image path must prove all of the following before emitting an image:

1. the active contextual MediaUsage belongs to the exact endpoint/placement;
2. the Media is active, ready and not a placeholder;
3. the selected MediaAsset is a source-derived eligible PUBLIC asset under the
   current size/visibility/delivery policy;
4. contextual SEO fields are read from the precedence chain in §8.3;
5. the URL is the canonical delivery path for that asset and not a private
   source-original or a stale WordPress attachment path;
6. Article structured data/image sitemap and rendered `<img>` use the same
   eligible selection policy.

An empty WordPress Attachment alt/caption does not break a valid canonical
   Usage. Conversely, a valid Attachment row without a canonical MediaAsset
   read-back does not make a broken image public. Media existence alone never
   creates an indexable image-content page; `/anh/` delivery and sitemap
   eligibility remain separate public projection decisions.

The attachment read contract must return a typed result distinguishing:
`VERIFIED` (physical attachment + mapping + Media/Asset agree),
`UNAVAILABLE` (runtime/filesystem dependency unavailable),
`INCONSISTENT` (each boundary is readable but disagrees), and
`NOT_FOUND` (the exact attachment owner is absent). It must not collapse
`INCONSISTENT` into `null`, empty success or a new Media candidate.

## 17. Exact components and files likely affected

This design step changes no implementation file. The later implementation
slice should inspect and likely change only these existing boundaries:

| Existing component/file | Intended responsibility in the implementation slice |
|---|---|
| `Application/Capture/ContentIntentRouter.php` | Preserve the resolved intent and explicit-vs-heuristic source used by the requirement plan. |
| `Application/Capture/EditorialCaptureCoordinator.php` | Pass intent-scoped requirements, invoke Article Media reconciliation before final research/gate evidence, and aggregate only required owner branches. |
| `Application/Capture/GovernedCaptureContinuationService.php` | Stop generating the unconditional Article `wp_post --about--> subject` plan; retain the governed path for explicit semantic deltas/relations. |
| `Application/Capture/CaptureArticlePreflightHandoff.php` | Map `NOT_REQUIRED` semantic branches distinctly from applied/read-back branches and consume the post-reconcile Media snapshot. |
| `Application/Article/ArticleResearchPreflight.php` | Keep research read-only; distinguish editorial claim candidates, reusable canonical claims, optional visual support and Article MediaUsage state. |
| `Application/Article/ArticlePublicationGate.php` | Evaluate the intent-scoped requirement packet and classify only applicable diagnostics. |
| `Domain/Article/PublicationDiagnosticRegistry.php` | Extend existing diagnostic metadata only after the approved implementation contract chooses exact codes; unknown codes remain system-blocked. |
| `Application/Completion/CompletionCoordinator.php` | Aggregate required owner branches by intent and preserve `NOT_APPLICABLE` branches without fabricating canonical read-back. |
| `Application/Media/ArticleMediaCoordinator.php` | Reconcile Post slots from committed Capture Media IDs; preserve one-image reuse, contextual SEO and CAS. |
| `Application/Media/MediaBindingService.php` | Remain the owner for exact semantic-target representative binding; no Article slot shortcut. |
| `Application/PublicIdentity/PublicIdentityService.php` and native editorial reader | Keep semantic Public Identity separate from the WordPress Article planned/materialized route phases. |
| `Application/Seo/SeoRuntimeReadback.php` and Article SEO projection boundary | Verify actual Article route/projection after materialization; do not treat a planned slug as a materialized route. |
| `Application/Graph/RelationRevisionBinder.php` / `Infrastructure/Graph/WpPostEndpointResolver.php` | Preserve strict revision failure for explicitly required relations and add regression coverage for the unrequested relation not being planned. |
| `Application/Knowledge/PostKnowledgeLinkService.php` | Preserve governed-only behavior; no direct Post-to-Knowledge writer. |
| `Application/Mcp/McpArticleIngestHandler.php` | Ensure read-only preflight and final ingest use the same intent-scoped evidence. |
| `Application/Media/MediaService.php` / `MediaUsageReconciler.php` | Preserve one Media identity, reconcile exact asset/usage deltas and keep Usage UUID/revision semantics. |
| `Application/Media/ArticleMediaSeoProjection.php`, `EntityMediaProjection.php`, `PublicMediaGalleryQuery.php` | Consume the shared contextual SEO precedence and eligible public-asset selector. |
| `Application/Media/VisualSupportRequirementService.php`, `VisualSupportReverseReconciliationService.php`, `VisualSupportPublicProjection.php` | Keep exact feature requirements separate from representative coverage and invalidate affected projections after bounded reuse. |
| `Infrastructure/Media/WordPressMediaAttachmentBridge.php` | Re-adopt Image Editor changes through exact attachment mapping while preserving Media/Usage identity. |
| `Infrastructure/Media/WordPressMediaAttachmentIngestor.php` | Return typed physical attachment/readback state and retain the existing safe upload/derivative path. |
| `Application/Mcp/McpReadHandler.php` | Make `nhk.media.attachment.get` distinguish physical absence from mapping/projection inconsistency. |
| `Infrastructure/Media/WpdbMediaAssetRepository.php`, `WpdbMediaUsageRepository.php` | Preserve asset/usage persistence, revision/CAS and exact identity reads; no parallel writer. |
| `Application/Dictionary/DictionaryRuntime.php`, `DictionaryPublicQuery.php` | Reuse canonical Media through the existing Dictionary projection and model preferred illustration as replaceable Usage state. |
| `Infrastructure/Dictionary/WordPressDictionarySitemapProvider.php` | Keep delegated/draft/ambiguous Dictionary concepts out of indexable detail and sitemap projection. |

No new file path, entity type, endpoint type, predicate, relation type or
semantic store is required by this design.

## 18. Backward compatibility and migration

- No data migration, legacy Article-body parsing, semantic backfill, Graph
  repair, Media merge, attachment rewrite, Dictionary binary copy or public URL
  reallocation is part of this design.
- Existing published Articles remain native WordPress content. Existing
  representative usages remain representative usages; existing Article usages
  remain Article usages.
- Existing Capture records are resumed from their persisted intent when one is
  present. Legacy records without a persisted intent must use the current
  compatibility resolution path and report an explicit ambiguity rather than
  silently broaden requirements.
- Existing semantic relation proposals are not deleted or retired by this
  design. The future implementation only stops creating a new implicit Article
  relation for operations where no relation was requested.
- Existing governed `KNOWLEDGE_DELTA`, Authority and Video paths retain their
  Governance and evidence requirements.
- The existing `single_real_image_exception` behavior is preserved and becomes
  the explicit Article-level one-real-image rule rather than a reason to create
  duplicate Media.
- Existing WordPress Image Editor edits are handled as bounded re-adoption of
  the same attachment/Media mapping; they do not trigger a general repair,
  checksum merge or usage deletion.
- Existing Dictionary concepts, labels, delegated destinations and lexical
  UUIDs remain unchanged. Preferred illustration is a projection/Usage choice,
  not a new Dictionary asset store.
- Owner publication approval and durable read-back rules remain unchanged;
  this design only improves the diagnostic classification before approval.

## 19. Observability and diagnostics

Every Capture/Article receipt should expose a body-free requirement report with:

- resolved intent and intent source;
- Article owner ID/state token when applicable;
- requirement key and applicability decision;
- owning service and current revision/read-back fingerprint;
- semantic delta decision and its explicit basis;
- Article Media IDs by slot, representative usages separately, and whether the
  one-real-image exception was used;
- MediaAsset source/public derivative IDs, dimensions, checksum/readback state,
  attachment mapping state and whether re-adoption was attempted;
- contextual SEO source selected for each rendered image and explicit
  `MISSING`/fallback state;
- VisualSupport requirement state and exact subject/scope/facet/feature when a
  visual dependency is applicable;
- Dictionary concept destination, preferred/supporting illustration Usage and
  indexability state when Dictionary projection is applicable;
- planned route and materialized route status separately;
- bounded attempt count and resume hint;
- exact diagnostic code, classification and policy version;
- final Post, MediaUsage, Media, SEO, route and rendered-readback evidence.

Logs must never include Article body, private source-original paths, credentials,
or raw semantic payloads beyond the existing body-free trace policy.

The most important regression telemetry is:

```text
intent=IMAGE_ARTICLE
semantic_delta=NO
graph_write=NOT_REQUIRED
article_media_reconcile=VERIFIED
representative_media_reconcile=VERIFIED (separate target usages)
planned_route=VERIFIED
materialized_route=PHASE_DEPENDENT
media_asset=PUBLIC_DERIVATIVE_VERIFIED
contextual_seo_source=ARTICLE_MEDIAUSAGE
dictionary_illustration=NOT_APPLICABLE
```

## 20. Test strategy

### Unit tests

- Matrix coverage proves each intent marks unrelated branches
  `NOT_APPLICABLE`.
- Article prose with claim candidates produces no Knowledge/Graph proposal when
  no delta signal exists.
- Explicit `KNOWLEDGE_DELTA` still produces governed proposals and cannot pass
  without approval/eligibility/read-back.
- `TEXT_ARTICLE`/`IMAGE_ARTICLE` does not create the implicit
  `wp_post --about--> subject` plan.
- An explicitly requested relation still fails closed when `wp_post` revision
  cannot be read.
- Existing Media 572 can satisfy both Article mandatory slots with one
  canonical ID when it is the only eligible real image.
- Representative usages never satisfy Article slots by themselves.
- Missing Article usage triggers exactly one bounded reconcile/read-back path.
- Planned route without materialized permalink is not an identity hard block;
  actual collision and stale owner state remain hard blocks.
- Compliance distinguishes descriptive wording from unsupported superiority or
  objective claims and never creates Knowledge from the wording alone.
- Completion aggregation does not require non-applicable owner branches.
- Contextual alt/title/caption differ by Article, Entity and Dictionary Usage
  without changing global Media or Attachment identity.
- The fallback order is deterministic and field-level; missing safe metadata is
  explicit rather than invented at render time.
- A source checksum/attachment mapping exact duplicate reuses the canonical
  Media candidate, while a visually similar but separately captured view stays
  separate.
- Image Editor re-adoption preserves Media UUID, existing representative and
  Article/Dictionary/technical Usage UUIDs while restoring a missing derivative.
- Attachment readback distinguishes VERIFIED, UNAVAILABLE, INCONSISTENT and
  NOT_FOUND; the inconsistent case never creates a second Media.
- VisualSupport reverse reconciliation binds only exact subject/scope/facet/
  feature/intent and does not create Claim/Evidence/Graph truth.
- Dictionary preferred illustration is reusable, pinned/replaceable through
  existing Usage state, and owner-delegated concepts remain non-indexable as
  competing detail pages.

### Contract tests

- `EditorialCaptureCoordinator`, Article preflight, Article publication gate,
  `ArticleMediaCoordinator`, `MediaBindingService`, `CompletionCoordinator`
  and the MCP Article surface agree on the same applicability matrix.
- Diagnostic registry classification remains deterministic and unknown
  diagnostics fail closed.
- Governance lifecycle remains unchanged for real semantic mutations.
- Public SEO and rendered-readback fields use the materialized route only.
- Dictionary public query and sitemap exclude draft, ambiguous, delegated,
  incomplete and unavailable concepts according to the lexical contracts.

### Guarded integration tests

On exact `nhk_v3_test` only, prove one Article draft, one Media, two distinct
Article usages and two pre-existing representative usages with canonical
read-back. Prove same-key replay does not duplicate any owner. Prove a stale
Post token blocks the write and a changed route owner blocks publication.

No integration test in this design step writes staging/live data.

## 21. Recommended bounded implementation slices

The later implementation must preserve reviewable boundaries. Each slice can
be tested and reviewed independently; no slice authorizes staging/live data
mutation or changes the physical upload pipeline by assumption.

1. **Intent-scoped publication requirements and semantic-delta gate.** Add the
   requirement packet, `NOT_REQUIRED` semantic evidence, phase-aware route
   identity and explicit omission of the implicit Article relation. Regression
   coverage proves ordinary Article prose does not create Knowledge/Graph/
   Governance work, while real deltas retain the full lifecycle.
2. **Article MediaUsage ordering/reconciliation.** Move the committed Media-ID
   handoff and Article slot reconcile ahead of final preflight/gate evidence;
   preserve the one-real-image exception, Post CAS and existing usage identity.
3. **Contextual Media SEO projection.** Align Article, Entity, gallery,
   VisualSupport and Dictionary image readers on the exact Usage → subject
   representative → neutral Media → Attachment fallback order, with explicit
   `MISSING` and public-asset eligibility. No second metadata truth store.
4. **WordPress Image Editor re-adoption and attachment readback.** Harden the
   existing Bridge/Ingestor mapping lifecycle so edited attachment `572`-style
   changes preserve Media and Usage identities, restore assets when needed and
   return typed mapping/readback inconsistency rather than null ambiguity.
5. **Dictionary illustration reuse/preferred projection.** Use the existing
   Dictionary and MediaUsage boundaries for a stable pinned preferred
   illustration plus replaceable supporting visuals; preserve delegated owner
   routes and Dictionary indexability rules.
6. **Exact binary duplicate prevention.** If current transport/adoption does
   not fully prove it, add an owner-bound exact checksum/mapping/provenance
   reuse decision before Media creation. Keep visual similarity as discovery
   only and keep near-duplicate photographs separate.

Each slice must include unit/contract coverage, guarded integration coverage
where its owner requires a database, canonical read-back evidence and a
separate execution-state checkpoint. Slices 1–2 address publication and media
ordering; slices 3–6 address projection/reuse and can be reviewed without
loosening the publication safety law.

## 22. Live acceptance plan

This design checkpoint performs no live acceptance. The supplied Case 573 IDs
are not present in the currently approved `STAGING_ACCEPTANCE_SCOPE` in
`AGENTS.md`; a future live run therefore requires a newly approved bounded
package containing the exact Capture, Post, Media, Attachment, subject and
representative IDs and operation families.

When separately authorized, the run must be read-first and fail-closed:

1. Bootstrap and read the deployed canonical documentation; verify build/source
   identity and current runtime registries.
2. Perform a duplicate/read-only audit for Capture 573, Post 573, Media 572,
   Attachment 572 and both representative usages.
3. Read the current Post state token, current Article MediaUsage and current
   canonical Media/Asset/representative read-backs, including the exact
   attachment mapping/readback result for attachment 572.
4. Execute only the canonical Capture/Article Media reconciliation boundary;
   never use a generic WordPress writer, direct SQL or a Governance bypass.
5. Confirm `semantic_delta=NO` and verify that no Knowledge/Graph proposal is
   created for the Article association.
6. Reconcile Article featured/inline usages with the same Media ID and read
   back the Post, MediaUsage and Media identity.
7. Read category, planned/materialized route, SEO projection, claim compliance
   and rendered frontend verification.
8. If an actual hard blocker remains, stop and return its exact owner/stage;
   do not override identity, route, CAS, corruption or infrastructure failure.
9. If publication is explicitly authorized and all required read-backs pass,
   publish through the typed Article publication boundary and verify final
   rendered output.

## 23. Explicit non-goals

- No implementation, migration, deployment, SSH or direct DB write in this
  design checkpoint.
- No Article semantic owner, Article Graph endpoint, Album owner or generic
  content node.
- No weakening of Source/Evidence, claim-scope, Governance, revision,
  idempotency, public route or rendered-readback requirements.
- No automatic promotion of Article prose, OCR, caption, recognition or
  generated copy into Knowledge, Evidence or Graph.
- No duplicate Media, Asset, Attachment or public URL owner.
- No broad retry loop, silent overwrite, generic fallback writer or fake
  success state.
- No change to the physical image upload pipeline, private source-original,
  public WebP derivative policy, batch behavior, Video owner or existing
  published Article behavior. In particular, later slices must preserve
  provided-file safe transport, private source-original retention, the WebP
  derivative and 1200px max-long-edge/no-upscale/no-crop rules, fast Media
  commit, ordered last-batch context, partial-batch retry behavior and the
  no-reupload Article handoff.
- No claim that Case 573 is publishable without the final evidence chain;
  this design only removes unrelated orchestration blockers.

## 24. Acceptance criteria mapping

| Criterion | Design proof |
|---|---|
| A | Article with no semantic delta marks Knowledge/Graph/Governance `NOT_REQUIRED`. |
| B | Explicit semantic delta retains the full Governance lifecycle. |
| C | `wp_post` remains editorial owner/registered endpoint only; no fake Authority node or implicit relation. |
| D | Article Media reconciliation reuses committed canonical Media without binary upload. |
| E | One real image may fill featured and inline Article slots with the same Media ID. |
| F | Representative and Article usages have separate endpoints, roles and owners. |
| G | Planned route is valid before materialization; materialized route is verified after native publication. |
| H | Hard blocks are tied to identity, truth, integrity, stale state or unsafe public state. |
| I | Auto-reconcile is one refresh + one reconcile + one read-back per owner. |
| J | Claim/evidence policy remains meaning-, scope- and law-aware. |
| K | Media/Video physical and semantic owner boundaries are unchanged. |
| L | Case 573 proceeds past the implicit Post relation and pre-reconcile Article Media diagnostics, stopping only on a genuine remaining blocker. |
| M | The same canonical Media can be reused across Model, Classification, Article and Dictionary contexts. |
| N | Contextual title/alt/caption belongs to MediaUsage and may differ safely by exact target/placement. |
| O | Neutral Media metadata remains scope-safe; Article, Dictionary and Attachment editorial data do not leak backward automatically. |
| P | Exact binary/mapping reuse is separated from visual-similarity discovery; useful distinct views remain distinct Media. |
| Q | Image Editor re-adoption preserves the existing Media identity, source/public asset lifecycle and unrelated Usage records. |
| R | Attachment readback inconsistency is typed/reconciled or blocked without duplicate Media. |
| S | VisualSupport and Dictionary illustration reuse remain projection/application boundaries, not semantic truth or binary stores. |
| T | Dictionary preferred illustration is stable but replaceable, and delegated concepts do not create competing indexable pages. |
| U | The physical upload/WebP/private-source, Video, Graph registry, Governance and public claim laws remain unchanged. |
