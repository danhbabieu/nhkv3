# NHK V3 Current Documentation Status Index

> **NON-NORMATIVE ROUTER / STATUS INDEX — 2026-09-09.**
> This file is not a second Constitution and does not create semantic vocabulary,
> operations, predicates, storage, routes or data. Its purpose is to tell
> downstream systems which sources are current law/contract, which sources are
> executable/runtime truth, and which documents are historical evidence.
>
> If anything here conflicts with `docs/constitution/NHK_V3_CONSTITUTION.md`,
> the Constitution controls.

## 0. MCP documentation bootstrap — 2026-09-08

The normal read-only MCP surface now exposes `nhk.docs.bootstrap` and
`nhk.docs.get`. They resolve only registry allowlisted canonical documents by
key, with bounded UTF-8 reads and a content-hash documentation revision;
callers do not need a GitHub connector. The bootstrap distinguishes
`canonical_contract` from `runtime_status` and uses the executable MCP catalog
and `McpCapabilityManifest` for runtime summaries. Code-side discovery is
covered; target-runtime connector discovery/read-back remains an environment
gate until freshly verified.

## 1. Authority and read order

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

A newer timestamp alone never overrides the Constitution or an approved
contract. Conversely, an old checkpoint must not override a later executable
registry/catalog merely because its wording is present tense.

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
`Plugin::runPendingMigrations()` sequence and now includes migration 016;
ordinary frontend requests remain migration-free.
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
| Article | WordPress `wp_posts` owns editorial title/body/excerpt/order/public editorial URL | semantic truth remains separate; Article completion is cross-boundary and runtime-gated; no body copy into Knowledge/Graph/receipts |
| Dictionary / lexical curation | dedicated Concept/Label/Candidate/Mention lexical stores under `DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md` | lexical lookup/curation only; search first, reuse existing owner, unknown terms become private candidates; no Authority/Knowledge/Evidence/Graph truth; research preview is read-only and stored Article body is never rewritten by auto-link projection |
| Authority | nine registered canonical types | canonical UUID/stable key/revision; no prose/URL/checksum-derived identity |
| Graph | only semantic relation persistence | current executable predicate vocabulary includes `about`, `depicts`, `model_of`, `variant_of`, `uses_movement`, `supports_music`, `configured_with_music`, `observed_playing_music`; governed relation commands now preserve explicit endpoint UUIDs, bounded direct/inverse reads and a read-only semantic-neighborhood MCP seam exist; `classified_as` remains a documented `REGISTRY_GAP` pending approved Authority vocabulary, and physical row completeness/backfill is a separate runtime/data question |
| Public Entity Dossier | `docs/architecture/PUBLIC_ENTITY_DOSSIER_PROJECTION_CONTRACT.md`; detail-only read model over existing canonical owners | shared dossier seam is wired through `nhk_v3_entity_detail_projection`; Brand is the first complete typed path-recipe projection; direct subject Knowledge remains subject-scoped, deep Brand context keeps origin path, archives stay outside the heavy dossier path, and no display shortcut relation is persisted |
| Product–Specimen | no approved canonical persistence relation | payload fields, taxonomy, post meta or broad `about` are not ownership substitutes; contract/registry extension required before canonical linkage |
| Public Identity | persisted identity/history implementation plus shared public-slug policy exist in code | `PublicIdentityService`, `CanonicalPublicSlugPolicy`, repository/WPDB boundary, migration 014 and exact one-hop history resolver are implemented; compatibility routes now reuse the shared normalizer/collision candidates, while guarded migration/data allocation/current-route durable consumer parity and live re-projection remain runtime-unverified |
| Knowledge / Source / Evidence | atomic canonical claim + provenance/support contexts | governed writes only; reuse canonical IDs/revisions; Article prose, Video transcript, OCR, captions and generated copy are not automatic Evidence |
| Living Knowledge | read/plan/resolve then governed mutation | no silent semantic rewrite; downstream reuse must preserve scope and provenance; Dictionary labels may assist lexical matching but never mint claims/evidence |
| Video | canonical external reference | Video → Living Knowledge planning seam implemented; explicit validated `about` target is preserved as enrichment subject; Dictionary observation after canonical write is lexical/non-blocking and does not broaden the target |
| Media | `docs/architecture/04_MEDIA_MODEL.md`, `docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md`, `docs/architecture/ADMIN_MEDIA_INPUT_GUIDANCE.md`, `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md` | `nhk.media.upload-batch` is PRIMARY multipart transport; native WordPress attachment lifecycle and canonical read-back precede separate governed `nhk-v3/media-ingest`; URL import is SECONDARY/IMPORT; base64 is FALLBACK/COMPATIBILITY; post-ingest semantic enrichment, relation reconciliation and representative reconciliation are mandatory; target acceptance remains runtime-gated |
| Media → Living Knowledge | no approved automatic adapter yet | MediaUsage/`depicts`/OCR/recognition do not become Knowledge/Evidence implicitly |
| Article → Living Knowledge body update | suggestion/governed boundary only | Knowledge changes never auto-rewrite a published WordPress Article body |
| MCP | `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md` and `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`; executable catalog/transport are runtime truth | `nhk.media.upload-batch` is the canonical multipart/file transport on `/nhk/v1/mcp` and is exported as `nhk-v3/media-upload-batch` with top-level `files[]`; the Ability bridge preserves connector multipart parts while delegating to the same transport; `nhk-v3/media-ingest` remains the governed semantic metadata/attachment-binding Ability; discovery existence is not live callability, and authenticated `upload_files` read-back is still required |
| WordPress Abilities | discoverability/adapter projection of supported MCP/application operations | historical limited allowlists are not current truth; inspect current registration + fresh discovery; binary multipart batch remains on the approved custom MCP boundary while metadata Media ingest remains the Ability bridge |
| SEO/Public Projection | `docs/seo/NHK_V3_SEO_CORE_CONTRACT.md`, `PUBLIC_URL_SLUG_CONTRACT.md`, `ENTITY_SEO_PROJECTION_CONTRACT.md`, `MEDIA_IMAGE_SEO_PROJECTION_CONTRACT.md`, `SITEMAP_INDEXABILITY_CONTRACT.md` plus existing Article/Video/Living Knowledge/Dictionary contracts | read/projection-only layer; one title/name-derived public-slug policy is reused by NHK-managed semantic generators; canonical/OpenGraph/schema/sitemap/internal-link surfaces consume the resolved canonical path rather than independently slugifying |
| Admin Workbench | `NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md` plus current Admin Workbench design/implementation evidence | implemented shared workspaces; normal flows are guided and Governance-backed; technical identifiers remain Advanced-only |
| Video frontend | `VIDEO_SEMANTIC_INGEST_CONTRACT.md`, `VIDEO_RELATIONSHIP_CONTRACT.md`, `VIDEO_YOUTUBE_SOURCE_CONTRACT.md`, `VIDEO_SEO_PROJECTION_CONTRACT.md` | first-party `/video/{slug}/` route and separate source action; external URL is never the canonical frontend destination |

### 2.1 Canonical Media/MCP document map — 2026-09-09

| Classification | Documents | Use |
|---|---|---|
| Canonical operational | `04_MEDIA_MODEL.md`, `22_P6_MEDIA_VIDEO_FOUNDATION.md`, `ADMIN_MEDIA_INPUT_GUIDANCE.md`, `MCP_V3_CONTENT_OPERATIONS.md`, `NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md` | Current Media ownership, upload paths, attachment lifecycle, MCP boundary, semantic ingest and read-back rules. |
| Bootstrap/router | `READ_FIRST.md`, this index | Direct new agents to the canonical set and distinguish code/runtime truth from dated evidence. |
| Executable truth | `MediaBatchUploadService`, `McpTransport`, `McpToolCatalog`, `McpAbilityRegistration`, attachment bridge/ingestor, `MediaIngestGateway`, idempotency repository | Actual registered vocabulary, dispatch, schemas, limits and implementation status; docs must not invent capabilities. |
| Design/plan history | `docs/superpowers/specs/2026-09-08-multipart-batch-media-upload-design.md`, `docs/superpowers/plans/2026-09-08-multipart-batch-media-upload.md`, and this reconciliation plan | Rationale and implementation history; not canonical law or the only source of current workflow. |
| Historical/superseded | `docs/mcp/MCP_V3_ABILITY_EXPOSURE.md`, older dated P-phase/checkpoint sections and legacy/V2 audits | Preserve evidence where useful, but do not use them for current capability or upload-path decisions. |

The current canonical Media flow is `multipart batch → WordPress attachment →
canonical read-back / media-attachment-get → media-ingest → MediaAsset → Media
→ MediaUsage`. Upload-only does not infer or apply Knowledge, Source, Evidence
or Graph truth during the transport phase; after canonical Media ingest
read-back, universal post-ingest reconciliation is mandatory.
Product/Specimen future sequencing is allowed as a workflow shape only;
the entities remain distinct and Product–Specimen remains `REGISTRY_GAP` until
an approved relation is registered.

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
- full physical Graph completeness/backfill where not runtime-proven;
- Media → Living Knowledge automatic claim-writing adapter (Media semantic
  enrichment/relation reconciliation remains mandatory);
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
