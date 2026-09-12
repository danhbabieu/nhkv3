# NHK V3 Current Documentation Status Index

> **NON-NORMATIVE ROUTER / STATUS INDEX — 2026-09-09.**
> This file is not a second Constitution and does not create semantic vocabulary,
> operations, predicates, storage, routes or data. Its purpose is to tell
> downstream systems which sources are current law/contract, which sources are
> executable/runtime truth, and which documents are historical evidence.
>
> If anything here conflicts with `docs/constitution/NHK_V3_CONSTITUTION.md`,
> the Constitution controls.

## 0. MCP documentation bootstrap — 2026-09-09

Canonical documentation remains file-based under `docs/` (and the explicit
`AGENTS.md` bootstrap document). The normal read-only MCP surface exposes
`nhk.documentation.bootstrap`, `nhk.documentation.get` and
`nhk.documentation.list`. They project the exact deployed files or the
immutable `resources/canonical-docs/` snapshot through a deterministic,
hash-verified manifest; they do not read GitHub, a database documentation
store or arbitrary filesystem paths. Deprecated `nhk.docs.*` names, if
enabled, are aliases to the same reader.

Bootstrap returns runtime/documentation versions, manifest hash, the three
mandatory bootstrap documents and the ACTIVE manifest list. Get accepts only a
manifest path with bounded line ranges. A mismatch is
`DOC_RUNTIME_MISMATCH`; a Capture started with a changed checkpoint fails
closed as `DOCUMENTATION_CHECKPOINT_STALE`. Code-side discovery is covered;
target-runtime connector discovery/read-back remains an environment gate until
freshly verified.

## 0.1 Governed Conversational Authority — 2026-09-11

The current Capture boundary now has typed purposes `EDITORIAL`, `AUTHORITY`
and `MIXED`. Legacy packets remain Editorial; Authority-only packets store a
Capture-owned planning state and do not create a WordPress Post. Mixed packets
own at most one native Post and continue Authority-dependent semantic
reconciliation on the same Capture after canonical read-back.

`AuthorityIntentPlanner`, the server-owned stable-key policy and the registered
Authority/Graph services provide planning-only, reuse-first decomposition.
Resolution order is UUID → scoped stable key → exact canonical name → alias →
bounded lexical review. `subtype_of` owns true same-family Classification
hierarchy; `classified_as` is limited to Model/Variant/Specimen/Product sources.
Origin and geography are facet filters, and glass-dome observations remain
review-scoped. No combined classification, Model inference, taxonomy or
generic writer fallback is permitted.

Owner approval is structured and binds exact candidate IDs plus a closure
fingerprint containing Capture/revisions, registries, documentation/build,
policy and effective Governance state. Changed dependencies require
`PLAN_REAPPROVAL_REQUIRED` before Proposal creation. The existing Governance
queue and Controlled Apply remain the only semantic mutation owners.

## 0.3 Isolated V3 snapshot recovery — 2026-09-12

`V3_SNAPSHOT_RECOVERY_RUNTIME.md` defines the governed logical snapshot
boundary, deterministic manifest/hash contract, recovery-only import guard and
mandatory post-import read-back. Demo staging remains read-only. The code
boundary exists, but a data-bearing recovery runtime and source/writer adapters
are infrastructure prerequisites; no golden validation or Video recovery wave
is authorized until they pass.

## 1. Authority and read order

Clock Type follows the ACTIVE `ENTITY_PROFILE_CLOCK_TYPE_CONTRACT.md`: it is a
profile over `entity_type=classification` with exact `family=clock_type`, not a
new Authority type. The contract is read with the Authority, Graph, dossier,
Media, Video, Knowledge, Public Identity and Capture contracts whenever this
profile is in scope.

Use this precedence when deciding current behavior:

1. `docs/constitution/NHK_V3_CONSTITUTION.md` — sole supreme normative authority.
2. Current approved contracts referenced by `docs/constitution/READ_FIRST.md`.
3. Executable registries/catalogs and application boundaries for the vocabulary
   and capabilities actually present in the checked-out runtime.
4. Fresh runtime discovery/read-back when the question is whether a tool,
   Ability, route, record or integration is actually available in that
   environment.
5. `docs/architecture/V3_EXECUTION_STATE.md` — dated execution ledger. Newer
   checkpoints may supersede older entries; fixed historical counts are not
   timeless contracts.
6. Numbered P-phase documents, parity matrices, audits and dated implementation
   checkpoints — implementation/historical evidence unless a current contract
   explicitly incorporates them.
7. Plans/specs under `docs/superpowers/` and legacy/V2 material — plan/reference
   or migration evidence only.

## 1.1 Universal MCP ingest reconciliation — current canonical route

The sole normative rule is Constitution §20.1. Every MCP ingest of Media,
Video, Knowledge, Source, Evidence or Authority entity must follow the bounded
sequence:

`ingest → read-back → canonical search → neighborhood/Graph inspection →
duplicate/reuse analysis → relation candidate discovery → evidence/provenance
validation → apply every justified useful registered relation → final
read-back`.

The operational details are distributed only through the existing owning
contracts: `MCP_V3_CONTENT_OPERATIONS.md` and
`NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md` for orchestration/completion,
`04_MEDIA_MODEL.md`, `22_P6_MEDIA_VIDEO_FOUNDATION.md` and
`ADMIN_MEDIA_INPUT_GUIDANCE.md` for Media enrichment and representative
selection, `06_KNOWLEDGE_SOURCE_MODEL.md` and
`GOVERNED_LIVING_KNOWLEDGE_DESIGN.md` for claims/provenance, and
`VIDEO_SEMANTIC_INGEST_CONTRACT.md` plus `VIDEO_RELATIONSHIP_CONTRACT.md` for
Video. No separate reconciliation policy or vocabulary is created here.

`COMPLETE` requires canonical read-back, duplicate check, semantic research,
relation reconciliation, representative-media reconciliation when applicable
and final verification. “Maximize relations” means maximize justified useful
relations, not relation count. The controlled provenance classes are
`OBSERVED_FROM_MEDIA`, `EXPLICIT_USER_KNOWLEDGE`, `CATALOG_SUPPORTED`,
`EXTERNAL_RESEARCH` and `SYSTEM_INFERENCE`.

## 1.3 Universal Capture entry point — current canonical route

All new content submissions use one entry point: `nhk.capture.ingest` and its
`EditorialCaptureCoordinator`. Text-only, knowledge-only text, text plus one or
more images, and the registered Video adapter all create one durable Capture
and one native WordPress draft by default, then share the sequence
`physical ingest when applicable → resolve → Graph discovery → Claim retrieval
→ governed semantic write-back/apply/read-back → Article composition → publication gate
→ final read-back`.

Direct Media/Video/Knowledge/Source/Evidence/Article/relation/publication
mutation tools remain only for internal/admin compatibility or lifecycle work.
They are marked `internal_admin_only`, require
`nhk_internal_content_operations`, and fail closed with
`DIRECT_WRITE_BLOCKED`/`USE_CANONICAL_CAPTURE_FLOW` otherwise. Existing data is
read-compatible; no duplicate owner or fallback writer is created.

A newer timestamp alone never overrides the Constitution or an approved
contract. Conversely, an old checkpoint must not override a later executable
registry/catalog merely because its wording is present tense.

## 1.2 Editorial Capture operating interpretation — 2026-09-09

The accepted Editorial Capture boundary is a cross-owner orchestrator, not a
new semantic owner. For text-only and multipart image submissions, one new user
submission maps by default to one durable Capture and one native WordPress
draft. Replays with the same idempotency key and unchanged payload resume the
same Capture; changed payloads conflict rather than creating a second Article or
re-uploading completed physical assets.

The reusable semantic core is `interpret → resolve canonical subjects → bounded
Graph neighborhood → retrieve candidate Claims → evaluate scope/provenance/
evidence/relevance → semantic write-back review/Governance → compose Article`.
Graph reachability only discovers a Claim. A relation path is not permission to
use the Claim in prose. The Claim must retain canonical ID/revision, original
semantic subject and relation path, and it is directly usable only when scope,
provenance, evidence and relevance pass. Specimen-only scope never generalizes
upward; insufficient evidence or missing provenance remains review-only.

Article composition is editorial synthesis over selected Claims, not a copy of
Knowledge. The composer retains a body-free `claim_trace` and research snapshot
of Claim IDs/revisions so later reconciliation can explain what was reused
without storing a second factual body. Generated Article copy is never Evidence.

The currently accepted Capture adapter supports text-only and multipart images.
Video remains a distinct canonical external-reference intake and is not yet a
physical branch inside `nhk.capture.ingest`. Until a registered shared adapter
exists, a submission containing Video must preserve the Video boundary and must
not be reported as one unified Capture-complete operation by creating duplicate
Posts, Videos or convenience relations.

Executable runtime capability and connector/client exposure are separate facts.
A current connector may expose only a subset of a registered runtime catalog.
That condition is a `CLIENT_EXPOSURE_GAP`, not evidence that the registered
runtime capability does not exist. For the tested Easy MCP 1.7.16–1.7.17
boundary, NHK may use its version-gated native-file compatibility adapter after
Easy MCP authentication; unknown versions fail closed. However, the current
client must fail closed when a required operation is not callable; it must not
substitute a generic WordPress writer, a historical Ability assumption or a
superseded workflow.
Fresh target discovery/read-back controls callability claims.

### 1.3 Existing-Capture continuation — 2026-09-10

The canonical `nhk.capture.ingest` boundary now accepts an optional
`capture_id` for a text addendum to one existing Capture and its existing
WordPress draft. The original request/fingerprint is immutable; each addendum
has an independent idempotency ledger, audit/revision append and bounded
semantic rerun. Same-key/same-payload retries are idempotent and changed
payloads conflict. Addenda reject files and do not create a second Post or
re-adopt/unscoped-fallback Media; an existing Media is reusable only after
persisted subject-scope matching. Continuation semantic deltas use a bounded
Capture-owned Governance orchestrator: proposal → submit → explicit
review/approval policy → eligibility → controlled apply → canonical
read-back. A pending decision resumes on the same addendum/idempotency key;
it never creates a duplicate Capture, Article or proposal. Publication remains
a separate governed owner-publication lifecycle.

The 2026-09-10 orchestration convergence makes the Capture's single typed
canonical subject-resolution packet the handoff context for Claim retrieval,
Video enrichment, Media reconciliation, composition and Governance. Historical
Media is gated by persisted subject scope after resolution; missing scoped
Media remains incomplete/placeholder rather than retryable infrastructure
failure. This is an implementation checkpoint, not live DEMO verification.

## 2. Current boundary snapshot

### Semantic Claim Projection — 2026-09-08

The Claim Projection Layer is a derived read model implemented under
`Application/Projection`. `ClaimScopeResolver`, `GraphProjectionPolicy`,
`ClaimClassifier`, `ClaimRanker`, `ClaimClusterer` and
`LiveLedgerProjectionBuilder` expose public-safe direct/related claims without
becoming semantic owners. The default graph bound is two governed incoming
hops; `about`, `depicts` and unregistered inverses do not authorize
propagation. `canonical_subject_uuid` and explainable source context are
retained, while private Source/Evidence details are excluded.

`ClaimProjectionService` separates a near-real-time Ledger from a revisioned
SEO candidate. Candidate publication is explicit and atomic; a claim update
does not change URL, H1, canonical identity or published prose. Projection
revisions/dependencies use additive migration 016 and separate tables; the
legacy migration-009 projection context remains unrelated and body-free.
The canonical operator migration-up entrypoint delegates to the shared
`Plugin::runPendingMigrations()` sequence and now includes migrations 016 and
017; migration 017 stores Capture checkpoints only and does not migrate legacy
article bodies or populate semantic records. Ordinary frontend requests remain
migration-free.
Entity frontend detail consumes the shared service and renders a bounded
Vietnamese Ledger with honest unavailable state. Event subscribers listen only
to canonical application events, and the projection admin REST surface is
capability/nonce protected. Initial backfill is dry-run/resumable and never
publishes candidates automatically. On 2026-09-08, guarded local
`nhk_v3_test` migration 016, WPDB repository roundtrip, published-Ledger
read-back and projection REST auth/rebuild smoke were verified. Governed
canonical E2E, event/invalidation lifecycle, full frontend/search/SEO/backfill
acceptance and deploy readiness remain open until separately evidenced.

### Current cross-surface law — 2026-09-07

The current canonical read path is `Authority → Graph → canonical
projection/read model → frontend`. WordPress is the editorial
presentation/runtime layer; it is not semantic authority for Media, Video,
Knowledge, Source, Evidence or Graph. Semantic mutation always uses
`Proposal → Submit → Review/Approve → Eligibility → Controlled Apply →
canonical read-back`.

Public-capable canonical resources require persisted Public Identity before a
canonical frontend URL. Video uses `/video/{slug}/`; external URLs are only
source/provenance/embed/external references. Admin “Xem trên web” and “Mở nguồn
gốc” are separate actions.

`PRIVATE` Source/Evidence blocks raw public serialization, not an already
validated public-safe knowledge projection. The public projection allowlist is
`text`, `type`, `facet`, `scope` using the exact registered field names. It must
not expose private excerpts, metadata, IDs or reconstruct private payloads.
Graph/public relation eligibility remains an independent gate.

Normal Admin forms are guided and do not ask for proposal/Evidence UUIDs,
fingerprints, expected revisions or raw JSON; those remain Kỹ thuật/Nâng cao.
Frontend status is distinct from Apply: `Canonical Applied`, `Projection
Available`, `Frontend Available`, `Frontend Blocked`.

| Area | Current boundary | Current status / reuse rule |
|---|---|---|
| Article | WordPress `wp_posts` owns editorial title/body/excerpt/order/public editorial URL | semantic truth remains separate; Article completion is cross-boundary and runtime-gated; no body copy into Knowledge/Graph/receipts; `nhk.capture.ingest` is the only normal one-Capture/one-draft submission boundary for text/image/Video/knowledge-only input |
| Dictionary / lexical curation | dedicated Concept/Label/Candidate/Mention lexical stores under `DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md` | lexical lookup/curation only; search first, reuse existing owner, unknown terms become private candidates; no Authority/Knowledge/Evidence/Graph truth; research preview is read-only and stored Article body is never rewritten by auto-link projection |
| Authority | nine registered canonical types | canonical UUID/stable key/revision; no prose/URL/checksum-derived identity |
| Entity Profile / Clock Type | `ENTITY_PROFILE_CLOCK_TYPE_CONTRACT.md` | Brand and Clock Type are independent profiles; Clock Type is `classification + family=clock_type`; PR1 is contract/read-only regression only. Current legacy `clock-type` compatibility spelling, profile registry and root-route consumer parity remain explicit gaps. |
| Graph | only semantic relation persistence | current executable predicate vocabulary includes `about`, `depicts`, `model_of`, `variant_of`, `uses_movement`, `supports_music`, `configured_with_music`, `observed_playing_music`, `subtype_of` and `classified_as`; hierarchy is ACTIVE/same-family/cycle-free and membership is scope-bound; physical row completeness/family audit remains a separate runtime/data question |
| Public Entity Dossier | `docs/architecture/PUBLIC_ENTITY_DOSSIER_PROJECTION_CONTRACT.md`; detail-only read model over existing canonical owners | shared dossier seam is wired through `nhk_v3_entity_detail_projection`; Brand is the first complete typed path-recipe projection; direct subject Knowledge remains subject-scoped, deep Brand context keeps origin path, archives stay outside the heavy dossier path, and no display shortcut relation is persisted |
| Product–Specimen | no approved canonical persistence relation | payload fields, taxonomy, post meta or broad `about` are not ownership substitutes; contract/registry extension required before canonical linkage |
| Public Identity | persisted identity/history implementation plus shared public-slug policy exist in code | `PublicIdentityService`, `CanonicalPublicSlugPolicy`, repository/WPDB boundary, migration 014 and exact one-hop history resolver are implemented; compatibility routes now reuse the shared normalizer/collision candidates, while guarded migration/data allocation/current-route durable consumer parity and live re-projection remain runtime-unverified |
| Knowledge / Source / Evidence | atomic canonical claim + provenance/support contexts; Collector Profile maintenance is defined by `docs/architecture/COLLECTOR_PROFILE_CONTRACT.md` and executable `CollectorFacetRegistry` | governed writes only; reuse canonical IDs/revisions; Collector facet maintenance is metadata-only and facet-allowlisted; Article prose, Video transcript, OCR, captions and generated copy are not automatic Evidence; Article reuse retains Claim revision/path trace rather than duplicating claim text into semantic storage |
| Living Knowledge | read/plan/resolve then governed mutation | no silent semantic rewrite; downstream reuse must preserve scope and provenance; Dictionary labels may assist lexical matching but never mint claims/evidence |
| Video | canonical external reference | registered Video adapter now enters `nhk.capture.ingest` for new submissions; Video identity remains distinct and proposal/apply stays governed; standalone `nhk.video.ingest` is internal/admin-only lifecycle compatibility |
| Media | `docs/architecture/04_MEDIA_MODEL.md`, `docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md`, `docs/architecture/ADMIN_MEDIA_INPUT_GUIDANCE.md`, `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md` | new operator submissions carry files through Capture; `nhk.media.upload-batch`/`nhk.media.ingest` remain internal/admin physical and semantic compatibility boundaries with the same governed Media owner; post-ingest semantic enrichment, relation reconciliation and representative reconciliation are mandatory |
| Media → Living Knowledge | no approved automatic adapter yet | MediaUsage/`depicts`/OCR/recognition do not become Knowledge/Evidence implicitly |
| Article → Living Knowledge body update | suggestion/governed boundary only | Knowledge changes never auto-rewrite a published WordPress Article body |
| MCP | `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md` and `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`; executable catalog/transport are runtime truth | documentation is a read-only manifest projection; `nhk.documentation.*` is the canonical docs surface; `nhk.capture.ingest` is the sole normal new-submission boundary; direct mutation tools remain registered only as internal/admin compatibility and require `nhk_internal_content_operations`; catalog descriptions and Ability metadata expose the distinction |
| WordPress Abilities | discoverability/adapter projection of supported MCP/application operations | historical limited allowlists are not current truth; inspect current registration + fresh discovery; a missing client-exposed Ability is a callability gap for that client, never permission to fall back to a generic writer; binary multipart batch remains on the approved custom MCP boundary while metadata Media ingest remains the Ability bridge |
| SEO/Public Projection | `docs/seo/NHK_V3_SEO_CORE_CONTRACT.md`, `PUBLIC_URL_SLUG_CONTRACT.md`, `ENTITY_SEO_PROJECTION_CONTRACT.md`, `MEDIA_IMAGE_SEO_PROJECTION_CONTRACT.md`, `SITEMAP_INDEXABILITY_CONTRACT.md` plus existing Article/Video/Living Knowledge/Dictionary contracts | read/projection-only layer; one title/name-derived public-slug policy is reused by NHK-managed semantic generators; canonical/OpenGraph/schema/sitemap/internal-link surfaces consume the resolved canonical path rather than independently slugifying |
| Admin Workbench | `NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md` plus current Admin Workbench design/implementation evidence | implemented shared workspaces; normal flows are guided and Governance-backed; technical identifiers remain Advanced-only |
| Video frontend | `VIDEO_SEMANTIC_INGEST_CONTRACT.md`, `VIDEO_RELATIONSHIP_CONTRACT.md`, `VIDEO_YOUTUBE_SOURCE_CONTRACT.md`, `VIDEO_SEO_PROJECTION_CONTRACT.md` | first-party `/video/{slug}/` route and separate source action; external URL is never the canonical frontend destination |

### 2.1 Canonical Media/MCP document map — 2026-09-09

| Classification | Documents | Use |
|---|---|---|
| Canonical operational | `04_MEDIA_MODEL.md`, `22_P6_MEDIA_VIDEO_FOUNDATION.md`, `ADMIN_MEDIA_INPUT_GUIDANCE.md`, `MCP_V3_CONTENT_OPERATIONS.md`, `NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md` | Current Media ownership, upload paths, attachment lifecycle, MCP boundary, semantic ingest and read-back rules. |
| Bootstrap/router | `READ_FIRST.md`, this index | Direct new agents to the canonical set and distinguish code/runtime truth from dated evidence. |
| Executable truth | `EditorialCaptureCoordinator`, `WpdbCaptureRepository`, migration 017, `TextInputInterpreter`, `SubjectResolutionService`, `ClaimRetrievalEngine`, `ArticleComposer`, `SemanticContextPackage`, `MediaBatchUploadService`, `McpTransport`, `McpToolCatalog`, `McpAbilityRegistration`, attachment bridge/ingestor, `MediaIngestGateway`, idempotency repository | Actual Capture/Media vocabulary, dispatch, schemas, limits, Claim selection/trace, checkpoints and implementation status; docs must not invent capabilities. |
| Design/plan history | `docs/superpowers/specs/2026-09-08-multipart-batch-media-upload-design.md`, `docs/superpowers/plans/2026-09-08-multipart-batch-media-upload.md`, and this reconciliation plan | Rationale and implementation history; not canonical law or the only source of current workflow. |
| Historical/superseded | `docs/mcp/MCP_V3_ABILITY_EXPOSURE.md`, older dated P-phase/checkpoint sections and legacy/V2 audits | Preserve evidence where useful, but do not use them for current capability or upload-path decisions. |

The current canonical Media flow is `multipart batch → WordPress attachment →
canonical read-back / media-attachment-get → media-ingest → MediaAsset → Media
→ MediaUsage`. Upload-only does not infer or apply Knowledge, Source, Evidence
or Graph truth during the transport phase; after canonical Media ingest
read-back, universal post-ingest reconciliation is mandatory.
For editorial submissions, `nhk.capture.ingest` wraps this Media flow with one
durable Capture, one native Article draft, bounded semantic enrichment,
MediaUsage reconciliation, publication gating and final read-back.
Product/Specimen future sequencing is allowed as a workflow shape only;
the entities remain distinct and Product–Specimen remains `REGISTRY_GAP` until
an approved relation is registered.

### 2.3 Current Article/Media convergence checkpoint — 2026-09-09

The local code slice now covers existing-Media usage deltas, same-usage retries,
same-Media featured/inline reuse, stale-inline replacement under an explicit
current plan, deterministic representative add/promote/demote planning, Media-
aware observation input and publication-token refresh. Focused unit evidence is
local only; exact attachment `299` / Article `300` / target integration runtime
acceptance remains a separate gate. No demo, production, V2 or staging data is
changed by this checkpoint.

## 3. Current storage and writer rule

Every domain has one canonical owner and authorized writer boundary:

- Article editorial state → WordPress editorial gateways/read-back.
- Dictionary lexical state → dedicated Concept/Label/Candidate/Mention repository;
  automatic content detection may persist only lexical observations/candidates
  after the owning content write, while curation uses its dedicated authorized
  boundary. Dictionary persistence is not a semantic writer.
- Authority → Authority service/repository through Governance where semantic.
- Public Identity → dedicated Public Identity service/repository/history boundary;
  compatibility route derivation is not a second durable identity writer.
- Knowledge/Source/Evidence → their canonical services/repositories through
  Governance/Controlled Apply.
- Graph relations → `GraphService` through governed relation lifecycle.
- Media/MediaAsset/MediaUsage → governed Media application boundary; WordPress
  attachment is storage/public projection, not semantic owner.
- Video → governed Video intake/apply boundary.
- Public Entity Dossier → read-only composition only; never a canonical writer.
- MCP/Admin/WordPress adapters → orchestration/input adapters only; never a
  second semantic store or writer.

Downstream systems should resolve/reuse canonical UUID/stable key/revision and
read back from the owning store instead of cloning semantic data into a new
context. Dictionary owner delegation must be revalidated at read time; a stale
stored destination is not permission to publish/link an invalid canonical URL.

## 4. Historical-document interpretation

The following kinds of statements are snapshots unless explicitly reaffirmed by
current contracts/runtime:

- exact MCP tool or Ability counts;
- fixed test/assertion counts;
- environment-specific connector exposure counts;
- migration/runtime probe outcomes tied to a date;
- statements such as “not implemented”, “no Article Ability”, “registry gap”,
  “READY” or “BLOCKED” inside an older checkpoint;
- route/data counts captured before later implementation slices.

Preserve such text as historical evidence when useful, but label it historical
or route current downstream readers through this index/current contracts.
Do not rewrite history merely to make old checkpoints look current.

## 5. Known current gaps that remain intentional

### Collector-centric clock-type projection discovery — 2026-09-09

The collector pack is implementation guidance for a read-only projection over
the existing canonical owners; it does not add a semantic store, Authority
vocabulary or relation. The current runtime map is:

| Concern | Current executable boundary | Initial finding |
|---|---|---|
| Classification/public detail | `PublicEntityCollectionQuery`, `EntityPageQuery`, `PublicRouteResolver`, `SemanticDossierQuery` | Classification detail is already a public dossier entry point, but has no collector-facet projection. |
| Branch Knowledge | `EntityKnowledgeProjection::forSubject()` over `KnowledgeRepository::list()` | Subject filtering is metadata-scoped and read-only; retrieval has no dedicated page contract and must not inherit the 50-row Graph neighborhood cap. |
| Graph traversal | `GraphService`, `RelatedSemanticQuery`, `SemanticNeighborhoodQuery` | Traversal is bounded and explainable, but per-edge reads are capped at 50; a collector projection needs branch-scoped pagination/deduplication and honest truncation diagnostics. |
| Media | `EntityMediaProjection`, `PublicMediaGalleryQuery`, `MediaUsageRepository::listByEndpoint()` | Media is endpoint-scoped and excludes placeholders/unready assets; global Media must not be used as a readiness fallback. |
| Video/articles | `VideoRepository`, `RelatedSemanticQuery`, WordPress post projector | Related resources are available through canonical Graph/read boundaries; branch relevance must be preserved in Article preflight and collector output. |
| Article preflight | `ArticleResearchPreflight::research()` and `ArticleIngestPreflight::check()` | Research accepts an injected inventory reader, but does not itself enforce subject-scoped candidate sets; the integration boundary must provide isolated inventory and deterministic knowledge-category selection. |
| Frontend | `FrontendSemanticBootstrap`, `EntityDossierBootstrap`, theme `entity.php` | Existing template renders generic knowledge/relation sections; collector ordering and facet labels are not yet first-class. |
| Admin/API | `AdminWorkbenchReadApi`, `EntityApi`, Graph/inventory APIs | Existing read-only/admin surfaces are reusable; no collector coverage endpoint/workspace exists. |
| Public identity | `PublicIdentityService`, `PublicIdentityRepository`, `PublicRouteResolver` | Persisted identity boundary exists in code, but branch allocation and live read-back remain runtime-gated; a planned URL is not canonical ownership. |

The collector implementation therefore must reuse these owners, preserve
canonical UUID/revision/provenance/scope, keep Brand secondary in presentation,
and fail closed when a registered relation, identity owner, or runtime
dependency cannot be resolved. No data seed, backfill, public URL allocation,
or editorial publication is part of this discovery checkpoint.

- Public Identity runtime activation/data coverage/current-route durable consumer
  parity and target-runtime re-projection are not proven. The current canary
  projection is intentionally read-only; no bulk persisted-identity rewrite or
  governed re-projection executor is claimed by code presence alone;
- Brand is the first complete explicit deep-path Public Entity Dossier recipe;
  equivalent type-specific completeness recipes for Model, Movement, Variant
  and the remaining Entity types must be added deliberately rather than by
  increasing the generic graph traversal bound;
- Dictionary migration 015/runtime activation, initial curated data, dry-run
  legacy backfill and target-environment public-route/read-back are not proven
  until executed in the target WordPress runtime; code presence alone is not
  live acceptance;
- dedicated Dictionary MCP tools are not current capability truth unless they
  are added to the executable catalog and confirmed by fresh runtime discovery;
- dedicated Product–Specimen canonical relation;
- approved Classification membership predicate (`classified_as`) and governed Graph relation apply; read-only Graph inventory/relation dry-run capability is implemented;
- Collector facet registry and `collector_facet_update` governed maintenance path are implemented code-side; the CLI dry-run is read-only and target-runtime data/apply/read-back remain separately environment-gated;
- full physical Graph completeness/backfill where not runtime-proven;
- Media → Living Knowledge automatic claim-writing adapter (Media semantic
  enrichment/relation reconciliation remains mandatory);
- full target-runtime proof for the registered Video adapter inside the shared
  Editorial Capture path; code-side Capture now accepts Video and carries a
  governed Video proposal packet without duplicating Video identity or Article
  state;
- environment-specific connector exposure parity; a missing client surface is a
  `CLIENT_EXPOSURE_GAP`, not permission to infer a smaller runtime catalog or
  use a generic fallback writer;
- universal per-domain MCP post-ingest reconciliation orchestration and final
  `COMPLETE` read-back evidence across all target runtimes;
- automatic Article body rewrite from Knowledge (prohibited by design; only
  suggestion/governed editorial flow is allowed);
- exact integration/runtime gates wherever current execution evidence reports
  `ENVIRONMENT_BLOCKED` or unavailable infrastructure;
- any capability whose availability has not been confirmed by current runtime
  discovery/read-back in the target environment.

### Collector Profile implementation checkpoint — 2026-09-09

`Application/Collector/CollectorProfileQuery` is now the read-only branch
projection seam for a canonical Classification. It resolves one active
Classification, filters active/public Knowledge by exact
`provenance.metadata.subject_id`, deduplicates canonical UUIDs, paginates the
branch after retrieval, preserves evidence counts, and reports page versus
render-cap truncation separately. Optional related-resource input is accepted
only when the reader declares `scope=subject` and `branch_scoped=true`; global
inventory is an explicit unavailable result, never a fallback.

The first focused tests pass for >50 branch claims, duplicate canonical
records, unrelated-branch exclusion, truthful render caps and global-scope
rejection. Facet grouping, REST/Ability exposure, Article preflight wiring,
frontend/Admin integration and live runtime read-back remain open. No
semantic data, Graph edge, public identity or editorial record was mutated.

A gap is not permission to invent a shortcut.

## 6. Downstream operating rule

Before implementing or mutating data:

1. follow `READ_FIRST.md`;
2. resolve the responsible bounded context and canonical owner;
3. inspect current executable registry/catalog when vocabulary/capability is in
   question;
4. distinguish historical evidence from current contract;
5. fail closed on ambiguity or a missing approved writer/relation;
6. use Governance for semantic mutation;
7. run the bounded post-ingest reconciliation required by Constitution §20.1;
8. read back from the canonical owner after reconciliation before claiming
   completion.

For public Entity display work, also resolve the applicable dossier recipe. A
reachable graph node is not automatically approved public inherited truth; use
only current registered/contracted paths and preserve direct-versus-derived
scope in the emitted dossier.

For Dictionary work specifically, detection is not semantic identity: resolve
approved labels/current canonical owners first, create a private candidate only
when unresolved, and keep ambiguous terms unlinked until human curation.

This index should remain compact. Detailed law belongs in the Constitution or
approved domain contracts, not duplicated here.

### Collector-centric execution closure checkpoint — 2026-09-09

The collector branch now has a read-only profile query, admin-only REST
adapter, classification frontend projection and collector-first Vietnamese
sections. The projection keeps the existing Authority/Knowledge/
Source/Evidence/Graph/Media/Video owners, paginates the full branch claim set,
deduplicates canonical claims, reports truncation and evidence status, and
rejects global related-resource scope. Article research inventory now filters
Knowledge, Media and Video candidates by resolved subject scope; native
WordPress remains the editorial owner and no article is auto-created or
published by this work.

The dossier coverage page now includes a Classification branch matrix for
form, case style, movement, duration, sound/music, automata, night shutoff,
material/craft/scale, condition/originality and provenance/rarity/origin. The
approved candidate batch is resolved read-only against the Classification
registry: exact matches are marked REUSE, near matches require review, and a
NO_MATCH row remains `NO_MATCH_CREATE_REQUIRES_EVIDENCE` rather than becoming
an empty Authority node. This preserves the Constitution's no-invention and
Governance boundaries.

Focused unit coverage is green for Collector Profile, API, frontend,
branch-scoped Article preflight, inventory filter enforcement, coverage audit,
candidate reconciliation, public identity and Governance suites. Target
WordPress read-back is still environment-gated; no semantic seed, Graph edge,
public URL allocation, article, publication or external push was performed.

### Collector runtime recheck — 2026-09-09

The exact Integration command was rerun with `NHK_WP_TEST_DB=nhk_v3_test` and
`NHK_WP_TEST_PATH=public`. The initial sandbox database error was classified at
the connection boundary: MySQL is alive on TCP `127.0.0.1:3306` and
`/tmp/mysql.sock`, and read-only TCP/socket handshakes both select
`nhk_v3_test` when executed with the required local-runtime permission. The
repository `wp-config.php` and `public/wp-load.php` path are therefore not a
code/bootstrap defect.

The remaining Collector acceptance gate is `CANONICAL_FIXTURE_BLOCKED`: the
requested Classification UUID
`01a07614-832d-7f27-959c-74eb0cd63f3e` and stable key
`nhk:classification:clock-type.cuckoo-clock` are absent from both
`nhk_v3_test` and `nhk_v3`; the handoff ZIP contains documentation only and no
approved fixture or snapshot. The live Collector read path correctly returns
`CLASSIFICATION_NOT_AVAILABLE`, and the coverage audit does not convert that
absence into zero or complete data. No Authority seed, Knowledge, Evidence,
Graph edge, Media/Video relation, Article or publication was created.

The full Integration suite now reaches WordPress and passes 120 tests / 1,016
assertions with 4 canonical skips, 1 warning and 1 deprecation. This closes
the prior Governance rollback, corrupt-field, migration-target and MCP
fixture/authentication blocker set; it does not claim Collector runtime PASS.
The target branch read-back remains unverifiable until an approved canonical
fixture is present. The exact rerun command is:

`NHK_WP_TEST_DB=nhk_v3_test NHK_WP_TEST_PATH=public vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite 'NHK Integration'`

### Collector canonical fixture acceptance — 2026-09-09

The exact Collector fixture gap is closed locally without copying the DEMO
dataset or creating a production/domain seed. The guarded Integration fixture
creates the requested Classification with the canonical UUID, stable key,
Vietnamese name, ACTIVE state and revision 1 in `nhk_v3_test` only. It creates
55 public branch Knowledge claims (>50), representative Collector facets,
one public supporting Evidence chain, one unrelated-branch claim and no
Article, Media, Video or Brand fixture. Teardown removes the fixture through
the exact test database guard.

DEMO read-only MCP verification found the real Classification
`01a07614-832d-7f27-959c-74eb0cd63f3e` with stable key
`nhk:classification:clock-type.cuckoo-clock`, `ACTIVE`, revision `1`.
Graph inventory for the root returned `86` active direct `knowledge → about →
classification` edges; bounded neighborhood returned `50` items at its
contractual cap. A representative Knowledge read returned public supporting
Evidence. The Knowledge inventory subject filter returned zero because these
claims are relation-owned in Graph; this is not treated as branch emptiness.
No DEMO record was created, changed, reconciled or retired.

The guarded Collector fixture test passes `1` test / `25` assertions. The full
Integration suite passes `120` tests / `1,011` assertions with 4 canonical
skips, 1 warning and 1 deprecation. MCP test isolation resets the current
WordPress user before route registration so test order cannot leak
authentication state. Collector runtime acceptance is now GREEN for the
semantic-equivalent local slice: exact root, >50 pagination, facet grouping,
deduplication, evidence status, unrelated-branch isolation, empty Media/Video
readiness and Article preflight scope/category/title planning.

Article creation/publication and Media/Video creation remain intentionally
out of scope. The local fixture is not DEMO data, and no candidate Authority
node or Graph edge was invented because the real root and its canonical branch
already exist.

### Collector Profile API contract acceptance — 2026-09-09

The previously empty `tests/Contract` suite now contains the read-only
Collector Profile boundary contract. It covers canonical subject identity, the
complete facet-key shape including empty groups, coverage and pagination
diagnostics, the `verified`/`partial`/`unresolved` status vocabulary,
branch-scoped Article/Media/Video/maker IDs, fail-closed unsupported filters
and missing Classification behavior, and the permission boundary. The
contract suite passes 4 tests / 31 assertions. The implementation change only
materializes the declared facet groups and makes the permission callback
explicitly fail closed when WordPress capability context is unavailable.

This is a code/API contract PASS. It does not turn the absent canonical Cuckoo
Classification in local `nhk_v3` / `nhk_v3_test` into a live data PASS. The
guarded semantic-equivalent fixture and the live runtime blocker remain
separate evidence classes.

### Collector DEMO read-only recheck — 2026-09-09

The allowlisted read-only DEMO maintenance path confirmed the canonical
Classification exists and is `ACTIVE`, revision `1`, under the requested UUID
and stable key. The deployed Collector Profile read returned the subject but
reported `knowledge_count=0`, while the same runtime's canonical Graph
inventory reports direct `knowledge → about → classification` edges for the
branch. The deployed implementation therefore still reads branch Knowledge
from `provenance.metadata.subject_id` and misses relation-owned Knowledge.

The local fix keeps the existing Graph boundary: the Collector Profile now
accepts a branch claim reader that paginates incoming `about` edges filtered to
`knowledge`, hydrates canonical Knowledge UUIDs, deduplicates them and fails
closed on Graph/cursor errors. Metadata-only fixtures remain supported for the
guarded test seam; no global fallback is introduced. This fix is committed
locally but not deployed or pushed, so DEMO live profile read-back remains
runtime-deployment gated.

### Editorial Capture multipart connector gap — 2026-09-09

The Capture multipart connector gap is isolated at descriptor export. The
canonical `nhk.capture.ingest` catalog and `nhk-v3/capture-ingest` Ability now
declare `_meta["openai/fileParams"] = ["files"]` alongside the existing
top-level native multipart `files[]` schema. Text-only input remains valid;
multiple files remain bounded by `maxItems=20`; no base64, data or client path
field is introduced. The custom MCP tools/list export includes the same
descriptor metadata, and the Ability callback continues to preserve native
`$_FILES` parts before delegating to the one `/nhk/v1/mcp` transport.

This does not claim Easy MCP interoperability: the inspected stock Easy MCP
1.7.17 dynamic Ability serializer drops arbitrary Ability metadata and its
transport rejects non-JSON requests. Fresh connector schema refresh and live
image acceptance therefore remain blocked until that external adapter carries
the descriptor metadata and multipart request through.

### Editorial Capture runtime acceptance — 2026-09-09

Migration 017 was applied and rerun on the exact `nhk_v3_test` runtime. The
ledger is `17` after both runs and `wp_nhk_editorial_captures` has the expected
Capture checkpoint columns. No production or staging database was used.

Authenticated live discovery returned both `nhk.capture.ingest` and
`nhk-v3/capture-ingest`, with matching `files[]` binary schema, text-only
contract, idempotency metadata and capability enforcement. Authenticated
Ability discovery returned `200`; anonymous discovery returned `401`.

Fixtures completed through native draft, attachment read-back, canonical Media
adoption, subject resolution, bounded Claim retrieval, composition,
MediaUsage, publication gate and final WordPress read-back:

- A text-only: Capture `01a0848c-1ba5-708f-8821-4a08dbdab531`, Article `2760`,
  subject `01a08489-b9b2-7840-ba91-f554562b11a4` (`Vertical Brand`), no
  attachments, two placeholder MediaUsage slots, final state `PARTIAL` because
  owner publication review remains required.
- B text + one image: Capture `01a0848d-bb1e-7deb-a5bb-6a8b26826e6f`, Article
  `2766`, attachment `2765`, Media
  `01a0848d-bb59-72d2-805b-aab3d313bce2`, one canonical Media row and one
  MediaUsage row; retry reused the same Capture/Article/attachment/Media.
- C text + two images: Capture `01a0848e-918e-7d97-b0e4-8f17148fd020`, Article
  `2773`, attachments `2771,2772`, Media
  `01a0848e-91c9-7d8c-b475-c20086c1a108` and
  `01a0848e-91e3-7833-8dcd-fb7a0bf0019b`; retry kept one Capture/Article and
  two canonical Media/MediaUsage rows.

Semantic write-back was exercised through the existing Governance boundary for
all three fixtures. A used proposal
`01a08495-1f73-75aa-9103-b835a7d1e266` and Claim
`01a08495-1f8c-7016-86e4-68460a178640`; B used proposal
`01a08495-c6e2-7ad0-a391-1a40c3d74bb3` and Claim
`01a08495-c6f3-77aa-b252-995b97de8997`; C used proposal
`01a08497-ae2b-713e-b4ee-ded253f55717` and Claim
`01a08497-ae41-7705-97b4-509df16e8575`. Each passed submit, review, approve,
eligibility and apply with canonical read-back; same-key proposal replay
returned the existing applied proposal and the test database has one active
Claim per fixture. No relation hint was produced, so Graph read-back correctly
returned zero rather than inventing an edge.

The runtime fix is limited to allowing the text-only physical phase and
normalizing PHP multipart array shape before Capture fingerprinting; the latter
prevents `Array to string conversion` warnings and binds retries to real file
bytes. Focused acceptance tests pass 28 tests / 259 assertions; full Unit
passes 847 / 4,020; `composer lint` and `git diff --check` pass. The prior
Integration blockers were reconciled in the test/fixture contract and the
guarded suite now passes 120 tests / 1,016 assertions on two consecutive
runs, with 4 canonical skips, 1 warning and 1 deprecation. Editorial Capture
runtime and repository acceptance are both verified; Collector remains
separately gated by its missing approved canonical fixture.
## Active cross-domain law — Visual Support Requirement — 2026-09-11

`docs/architecture/VISUAL_SUPPORT_REQUIREMENT_CONTRACT.md` is ACTIVE and is
required reading for Media, Knowledge, Article, Video, Media annotation and
public projection work involving a visually explainable feature. It defines
the application-level persistent ledger, exact subject/scope/facet/feature
matching, `MISSING → RESOLVED/REVIEW_REQUIRED`, canonical Media reuse through
MediaUsage, bounded reverse reconciliation after Capture Media read-back,
projection invalidation and public fail-closed behavior. It explicitly keeps
feature technical/contextual illustration separate from node representative
image coverage and from Claim/Source/Evidence/Graph truth.

The code-side vertical slice adds migration 019, domain registries, ledger
repository, exact suitability, reverse reconciliation and the canonical Media
read-back event. Local tests cover the required missing/later-Media/reuse/
replay/private/scope/evidence cases. Target deployment and live MCP
bootstrap/get remain externally gated until the authorized deployment
procedure runs and reads back the new documentation identity.
