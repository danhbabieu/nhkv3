# MCP V3 CONTENT OPERATIONS

> **NON-NORMATIVE.** This is a runtime contract audit. If it conflicts with
> `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution controls.

Status: runtime audit and contract-safe implementation checkpoint, 2026-09-09.

Governed Source/Knowledge/Evidence/Video ingest responses use an explicit
identity envelope: `proposal_id`, `proposal_state`, `target_uuid` and
`canonical_id`. Proposal UUIDs are never returned as canonical entity IDs, and
`canonical_id` is null before Controlled Apply plus canonical owner read-back.
Evidence ingest validates canonical active Claim/Source dependencies at request
acceptance and again at apply. Visibility is independent of lifecycle; internal
verification may read active PRIVATE/HIDDEN Evidence without changing it, while
public evidence reads remain fail-closed.


New NHK-managed image bytes follow one governed Media V3 ingest law before
durable persistence/public projection: validate the actual payload → auto-orient
→ apply the 1200px maximum-long-edge rule → contextual SEO-safe naming →
WebP/eligible derivative encoding → retain source-original `PRIVATE`/protected
→ persist eligible optimized derivatives `PUBLIC` under the same Media identity
→ read-back verification → temporary-workfile cleanup. Long edge `<= 1200px`
keeps original dimensions; larger images use proportional rounded downscale.
There is never an upscale, crop, stretch or forced square canvas.

Corrupt, fake or unreadable payloads, invalid storage paths and unavailable
required conversion fail closed. Partial attachment, mapping, asset or usage
artifacts must be cleaned up. Existing legacy files are not rewritten or
deleted.

This shared guide describes the MCP V3 runtime actually present for ChatGPT and
Codex. It does not authorize new entity types, predicates, relation types,
fields, operations, taxonomy or data population.

## Single canonical submission entry point — 2026-09-09

`nhk.capture.ingest` is the only normal MCP entry point for a new submission.
It accepts text-only, knowledge-only text, text with one or more multipart
images, and the registered Video adapter. Each submission creates one Capture
and one native Article draft by default, then follows
`physical ingest when applicable → resolve → Graph discovery → Claim retrieval
→ governed semantic write-back/apply/read-back → Article composition →
publication gate → final read-back`.

The standalone mutation tools for Media, Video, Knowledge, Source, Evidence,
Article draft/update/publish, relation and proposal creation are retained only
for internal/admin compatibility or lifecycle operations. They are marked
`internal_admin_only` in the executable catalog/Ability metadata, require
`nhk_internal_content_operations`, and return `DIRECT_WRITE_BLOCKED` with
`USE_CANONICAL_CAPTURE_FLOW` when called without that boundary. A client must
not fall back to one of these writers when Capture is unavailable.

## 1. MCP architecture

The endpoint is `/wp-json/nhk/v1/mcp`, using JSON-RPC 2.0 and Streamable HTTP.
`McpTransport` validates protocol and arguments, `McpReadHandler` performs
reader-safe orchestration, and governed writes use `McpGovernanceHandler` plus
Governance's submit → approve → eligibility → controlled apply lifecycle.

MCP is transport/orchestration only. Application and Domain validate canonical
identity, registry membership, revisions, idempotency, provenance, readiness
and Graph rules. WordPress `wp_posts` remains title/body/author/date/category,
URL and publish truth.

Modern requests use protocol `2026-07-28`; `Accept` must include both
`application/json` and `text/event-stream`. Malformed arguments fail before
dispatch. Governed tools require their capability. Initialized notifications
return HTTP 202 with no body.

### Universal post-ingest reconciliation and completion

Every MCP ingest operation, including Media, Video, Knowledge, Source, Evidence
and Authority entity ingest, must continue through the Constitution's bounded
post-ingest reconciliation. The canonical order is:

```text
ingest
→ read-back
→ canonical search
→ neighborhood/Graph inspection
→ duplicate/reuse analysis
→ relation candidate discovery
→ evidence/provenance validation
→ apply every justified useful registered relation
→ final read-back
```

The reconciliation is bounded by the active registries, endpoint/predicate
allow-lists, traversal/result budgets, dependency closure and Governance. It
maximizes justified useful relations, not relation count; weak, speculative,
duplicate or convenience-only candidates remain unapplied with diagnostics.
Canonical search must happen before minting a new identity, claim, Source,
Evidence, Video or Media; it means owner-bound canonical search/reuse, not only
the public discovery result from `nhk.search`. A transport-only attachment upload is an intermediate
storage result; it must hand off to governed Media semantic ingest before the
overall operation can be complete.

`COMPLETE` is reserved for a result with canonical read-back, duplicate check,
semantic research, relation reconciliation, representative-media
reconciliation where applicable and final verification. Ingest success,
proposal creation, preview, partial success, pending review or attachment
creation alone is never `COMPLETE`.

### Editorial Capture and Semantic Enrichment

`nhk.capture.ingest` is the shared editorial boundary for one user submission.
It persists one Capture identity and idempotency key, stores the raw editorial
intent and subject hints, accepts text-only, multipart images or the registered
Video adapter, creates one native WordPress draft, adopts each verified image
attachment into canonical Media, preserves Video as a distinct governed owner,
interprets text into scoped candidates, resolves Authority subjects, retrieves
bounded Claims through the Graph neighborhood, composes the draft, reconciles
`MediaUsage`, runs the publication gate and performs final native read-back.
Replays resume the same Capture and must not create a second Post or re-upload
an already completed physical phase.

The Capture tool is capability-gated by `nhk_ingest_articles`; its optional
`files[]` are native multipart parts and never base64, paths or JSON bytes.
The text-only path is valid and still runs interpretation, subject resolution,
Claim retrieval and publication checks. User statements and image observations
remain scoped input/candidate provenance; they do not become universal Claims,
Evidence or Graph edges implicitly. Existing-Capture continuation semantic
write-back is executed only by the bounded Capture-owned Governance
orchestrator, which requires proposal lifecycle, approval policy, eligibility,
Controlled Apply and canonical read-back. Pending review resumes the same
addendum/idempotency key; it cannot create duplicate semantic records. New
submissions still return review-only semantic candidates until their governed
workflow is explicitly continued.

The same tool also accepts an optional `capture_id` to continue one existing
Capture with a text addendum. The original request/fingerprint is immutable;
the addendum has its own idempotency key, appends an auditable Capture revision,
and records the resulting Capture revision in both the audit event and addendum
ledger. Rejected addenda retain only sanitized text/subject hints/observations/
metadata; file metadata and paths are never persisted. The addendum reuses the
same native Post and reruns bounded semantic resolution,
Governance/review and Article reconciliation. Addenda reject files, never
create a second Post, and never re-adopt or use unscoped/global Media when no
new asset is supplied; an existing Media is reusable only when its persisted
subject scope matches the resolved canonical subject. Same-key/same-payload
retries are idempotent; same-key payload changes return a conflict.

For a Capture with images, the orchestration unit is
`Capture → attachments → attachment read-back → canonical Media adoption →
Media interpretation → subject resolution → bounded Graph/Claim retrieval →
semantic write-back → MediaUsage/representative reconciliation → Article
composition → publication review → optional publish → final read-back`. One
Capture normally owns one Article; each asset retains its own contextual
caption, alt, description, observations and relation candidates. Existing
Media is resolved by canonical UUID/stable key and receives usage deltas rather
than a duplicate identity. Publication review must inspect current canonical
state/token and refresh once after a concurrent native write; it must not replay
stale inline or representative planning.

Composition exposes an editorial claim trace rather than a raw claim dump. Each
selected claim records its canonical ID/revision, subject, bounded relation path,
editorial role and evidence/provenance status. `OBSERVED_FROM_MEDIA`,
`EXPLICIT_USER_KNOWLEDGE` and `CANONICAL_CLAIM` remain distinct input classes;
only a governed semantic operation may turn a candidate into durable Claim,
Evidence or Graph state.

Text-only example:

```json
{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"nhk.capture.ingest","arguments":{"idempotency_key":"capture-2026-09-09-001","text":"Một ghi chú về chiếc đồng hồ.","subject_hints":["Ô Đô 36/10"]}}}
```

For images, send the same tool call as multipart with top-level `files[]` and
keep binary parts out of `arguments`; optional per-file metadata belongs in
`items[]`. A replay uses the same idempotency key and unchanged payload.

### Documentation bootstrap surface

The normal read-only MCP catalog exposes `nhk.documentation.bootstrap`,
`nhk.documentation.get` and `nhk.documentation.list`. Bootstrap returns the
runtime version, documentation version, deterministic manifest hash, generated
time, entry-point paths, and bounded content for READ_FIRST, the Documentation
Status Index and Execution State. List and Get resolve only manifest-allowlisted
repository-relative paths; Get supports bounded line ranges and returns the
file hash plus manifest identity. They never browse arbitrary filesystem paths
or read source/config/secrets.

The old `nhk.docs.bootstrap` and `nhk.docs.get` names remain read-only
deprecated compatibility aliases only. They do not define a second rule set.

Documentation truth and runtime truth remain separate: the bootstrap's
`canonical_contract` describes what the Constitution/contracts require, while
`runtime_status` describes what the current MCP catalog registers. The
manifest's documentation version, runtime version and hash must be coherent;
otherwise the machine-readable `DOC_RUNTIME_MISMATCH` outcome is returned.
When a deployment artifact does not contain repo-level `/docs`, the release
build must generate the plugin documentation snapshot from canonical repo docs;
the snapshot is not a separately edited source of truth. Mutation requires a
current `documentation_checkpoint` containing the bootstrap's manifest hash and
documentation version; a redeploy makes it stale and fails closed with
`DOCUMENTATION_CHECKPOINT_STALE`.


### Storage & Reuse Map — 2026-09-04

| DATA | CANONICAL OWNER / STORAGE | DOWNSTREAM REUSE RULE | MUST NOT HAPPEN |
|---|---|---|---|
| Article title/body/excerpt/editorial order | WordPress `wp_posts` | reuse Post identity/state token and native read-back | copy body into Knowledge, receipt or Graph storage |
| Media identity | `Media` | reuse canonical UUID/stable key/revision | merge/mint identity from checksum, filename or URL alone |
| Uploaded source bytes | source-original `MediaAsset` | retain privately/protected under the same Media | discard because a WebP/public derivative exists |
| WebP/thumbnail/responsive image | derivative asset / WordPress attachment projection | reuse under the same Media | create another semantic Media identity |
| Image role/alt/caption/order | `MediaUsage` + WordPress editorial placement | reuse same Media with contextual usage | treat usage as Knowledge/Evidence/Graph truth |
| Video | canonical Video external reference | reuse platform + external ID and governed attachments | download MP4 or create a WordPress Post implicitly |
| Video-derived fact candidate | Living Knowledge planning packet | preserve explicit validated `about` target; otherwise narrowest fail-closed subject | auto-write Knowledge/Evidence/Graph from hint/transcript |
| Knowledge claim | `Knowledge` | reuse canonical UUID/stable key/revision | duplicate claim because new prose repeats it |
| Provenance/support | `Source` + `Evidence` | reuse canonical source/evidence chain | treat generated prose, OCR, caption or transcript as Evidence by itself |
| Typed semantic relation | Graph | reuse registered endpoint IDs + predicate | infer relation from placement/upload/prose alone |

Every write must be read back from its owning store. MCP never becomes a second
canonical store, and Admin/WordPress adapters never become parallel semantic
writers.

### How a fresh agent chooses a writer

1. Resolve the requested resource through the runtime registry using canonical
   UUID, stable key, then exact canonical name/alias. Ambiguity or an unknown
   type fails closed.
2. Use the bounded owner: native WordPress Article/category gateway for
   editorial data; Authority APIs for registered Authority types;
   `MediaIngestGateway` for Media/MediaAsset/MediaUsage; `nhk.video.ingest` plus
   Video Governance for Video; Claim/Source/Evidence ingest for Knowledge; and
   GraphService only for registered relations.
3. For semantic writes, use Proposal → submit → approve → eligibility → apply
   and read back the result. Never use generic Post, CPT, taxonomy or postmeta
   writers as semantic fallback.
4. If no registered owner or operation exists, return the applicable
   `REGISTRY_GAP`, `CODE_GAP` or `SEMANTIC_GAP`; never invent a writer.

After the owner write, every ingest adapter invokes the universal reconciliation
sequence above. The adapter must carry forward its canonical read-back,
candidate set, duplicate/reuse findings, provenance/evidence checks, relation
apply results and final read-back status. It must not report `COMPLETE` while a
required stage is pending, unavailable, ambiguous or only proposed.

## 2. Tool catalog thực tế

`McpToolCatalog::tools()` exposes the exact current registered tool list.
`kind=mutation` implies `governed=true`; the current list includes the
capability-gated `nhk.capture.ingest` boundary. The executable catalog and
fresh wire discovery, not a dated tool count, are the authority for current
availability; local HTTP wire smoke remains an environment check.

| TOOL | DOMAIN | READ/WRITE | GOVERNED | REVISION | GRAPH | STATUS |
|---|---|---|---|---|---|---|
| `nhk.search` | native Post + public semantic search | READ | No | N/A | No | READY, bounded/public |
| `nhk.semantic.resolve` | Authority context | READ | No | N/A | No raw edge | READY; ambiguity fails closed |
| `nhk.article.preflight` | Existing WP Post + semantic bundle | READ | No | N/A | Registry/Graph read only | READY; reconcile preflight |
| `nhk.article.ingest` | Article operation receipt + governed semantic delta | WRITE | Yes | Receipt + semantic revisions | Controlled Apply only | READY for reconcile; create/update fail closed |
| `nhk.capture.ingest` | Editorial Capture + bounded semantic enrichment | WRITE | Yes | Capture revision + native draft token | Bounded neighborhood read; relation writes remain governed | LIVE RUNTIME ACCEPTANCE PASS (2026-09-09); guarded Integration PASS (120 tests / 1,016 assertions, 4 canonical skips) |
| `nhk.entity.get` | Authority | READ | No | N/A | No raw edge | READY for registered type + UUID |
| `nhk.media.get` | Media + public assets/usages | READ | No | N/A | No raw edge | READY for active ready Media/public assets |
| `nhk.media.ingest` | Media/MediaAsset/MediaUsage or governed WordPress image attachment | WRITE / INTERNAL | Yes | Both paths enter the governed Media service; file path creates/resolves one Media, retains PRIVATE source-original and projects PUBLIC derivatives/attachment | Usage is placement; attachment is storage/projection only | Internal/admin compatibility boundary; new submissions use Capture |
| `nhk.media.attachment.get` | WordPress image attachment | READ | No | N/A | No semantic inference | READY for read-back |
| `nhk.video.ingest` | Video external reference + semantic intake preview | WRITE / INTERNAL | Yes | Apply creates revision | Approved attachment candidates apply through Graph | Internal/admin compatibility boundary; new submissions use Capture; optional Knowledge output is planning-only |
| `nhk.video.get` | Video | READ | No | N/A | No raw edge | READY for active valid public reference |
| `nhk.knowledge.get` | Knowledge + public evidence | READ | No | N/A | No raw edge | READY for active/public chain |
| `nhk.source.get` | Source + public evidence | READ | No | N/A | No raw edge | READY for active/public chain |
| `nhk.evidence.get` | Evidence + public endpoints | READ | No | N/A | No raw edge | READY for active/public chain |
| `nhk.knowledge.ingest` | Knowledge claim | WRITE | Yes | Apply/revision governed | No edge by ingest | READY |
| `nhk.source.ingest` | Source | WRITE | Yes | Apply/revision governed | No edge by ingest | READY |
| `nhk.evidence.ingest` | Evidence | WRITE | Yes | Apply/revision governed | Claim/Source boundary | READY |
| `nhk.proposal.create` | Governance envelope | WRITE | Yes | `expected_revision` | `relation_create` allowed | PARTIAL; final validation at apply |
| `nhk.proposal.submit` | Governance | WRITE | Yes | Proposal revision | N/A | READY |
| `nhk.proposal.review` | Governance | READ | capability-gated | N/A | N/A | READY; returns approval bindings |
| `nhk.proposal.approve` | Governance | WRITE | Yes | Fingerprints bind approval | N/A | READY |
| `nhk.proposal.reject` | Governance | WRITE | Yes | Proposal revision | N/A | READY |
| `nhk.proposal.eligibility` | Governance check | READ | capability-gated | Revision/dependencies | N/A | READY |
| `nhk.proposal.apply` | Governance + target | WRITE | Yes | Controlled Apply | GraphService | READY for implemented branches |

Historical assertions expecting smaller tool counts are obsolete. The current
catalog also includes the typed Category and native Article draft/publication
operations present in `McpToolCatalog`; the exact live catalog must still be
confirmed through a fresh tool discovery/wire smoke before relying on a count.
Article ingest is capability-gated by `nhk_ingest_articles`, while Article
preflight is read-gated.

## 3. Use-case capability matrix

| USE CASE | CURRENT CAPABILITY | STATUS |
|---|---|---|
| Find canonical entity | Authority resolver; `nhk.search` for bounded public discovery; UUID-only reads for other domains | PARTIAL |
| Read canonical entity | Entity/domain `get` tools | READY for exposed boundaries |
| Create/update entity | Ingest or generic governed proposal; no typed update tool | PARTIAL |
| Read Source/Evidence | `nhk.source.get`, `nhk.evidence.get` | READY |
| Create Knowledge claim | `nhk.knowledge.ingest` + lifecycle | READY |
| Read/create relation | Governed `relation_create`; raw Graph inventory and relation dry-run are read-only MCP tools; relation creation remains governed | PARTIAL / IMPLEMENTATION_GAP |
| Create/update/publish Post | typed Article draft create/update plus gated publish/trash/restore boundary; exact live catalog/runtime still requires discovery/read-back | PARTIAL / RUNTIME-GATED |
| Capture editorial text/images into one draft | `nhk.capture.ingest` | LIVE RUNTIME ACCEPTANCE PASS (2026-09-09); publication remains owner-policy gated |
| Upload/find Media | governed metadata ingest plus direct multipart image attachment and attachment read-back | READY for current image contract |
| Attach MediaUsage | nested in Media ingest only | PARTIAL |
| Product / Specimen | registered Authority types via generic paths | PARTIAL |
| Album | no V3 contract | SEMANTIC_GAP |
| Video | governed ingest, UUID read, YouTube identity, optional thumbnail UUID, optional read-only Knowledge planning | READY for current contract |
| Publish | semantic Apply is separate; Article publication uses its typed publication gate | PARTIAL / RUNTIME-GATED |
| Read-back | domain reads plus native WP/Graph REST checks | PARTIAL |
| Frontend verification | existing route smoke/browser QA, not an MCP tool | PARTIAL |

## 4. Post workflow

The existing read-only `nhk.article.preflight` surface also accepts optional
`research_topic` and `research_subject` fields. When present, it delegates to
the shared Article Semantic/SEO Research Preflight and returns a planning
packet; it performs no Post, taxonomy, semantic, Graph, Media, Video or
Governance write. The research path uses the shared bounded two-hop Graph
reader, Post semantic-reference projection, bounded Knowledge → Evidence →
Source inventory and public route/eligibility boundary. Without
`research_topic`, the reconciliation contract below is unchanged.

For Phase 1, `nhk.article.preflight` and `nhk.article.ingest` support
reconciliation of an existing WordPress Post: read and fingerprint the target,
preflight the explicit semantic bundle, create deterministic child proposals,
wait for Governance approval, apply eligible children, and read back semantic
and editorial state. A generic WordPress write by itself cannot be reported as
a completed V3 knowledge Article workflow.

The typed Article draft create/update boundary covers native WordPress draft
creation/update only. Creation is idempotent via the existing Article operation
receipt repository, never stores body in the receipt, and returns a native state
token plus `DRAFT_INCOMPLETE_FOR_PUBLICATION`. Update requires a matching native
state token and only updates an eligible draft. The typed Article publication
boundary is the only V3 publication writer: it requires the current draft token
and verified evidence, calls `ArticlePublicationGate` before the native status
transition, and reads the published Post back. Owner-review approval remains
separate from system-blocked failures. Trash/restore uses the same CAS/receipt
boundary and never permanently deletes a Post. Typed Category operations remain
native taxonomy truth and never Graph truth.

The publication boundary is enforced by `ArticlePublicationGate`; rendered
public verification and exact integration runtime evidence remain separate
completion gates. Article body/excerpt stays only in WordPress editorial
storage; receipts, Knowledge and Graph never become a second Article-body store.

The minimum Article/Media runtime acceptance is the real-file chain:
`file → governed ingest/adoption → attachment → one Media identity →
MediaAsset/MediaUsage read-back → representative/evidence projection → Article
preflight`. A static catalog or focused unit pass is not evidence that this
WordPress/runtime chain has passed.

## 5. Authority workflow

Resolve by canonical UUID, then stable key, then exact canonical name/alias.
Ambiguous matches return candidates and are never auto-resolved. Reads use
`nhk.entity.get`; only registry-allowed fields are returned. Writes use an
existing Governance operation and require target revision for updates/lifecycle.

| TYPE | GRAPH | ALLOWED PAYLOAD FIELDS |
|---|---:|---|
| `brand` | yes | `aliases`, `description`, `country`, `founded_year` |
| `model` | yes | `brand_uuid`, `aliases`, `description`, `launch_year` |
| `variant` | yes | `model_uuid`, `aliases`, `description`, `reference` |
| `movement` | yes | `manufacturer`, `caliber`, `description`, `frequency_hz`, `jewels` |
| `music` | yes | `artist`, `album`, `description`, `release_year` |
| `component` | yes | `kind`, `manufacturer`, `description` |
| `classification` | yes | `family`, `description` |
| `specimen` | yes | `model_uuid`, `serial_number`, `acquired_at`, `notes`, `physical_provenance`, `technical_observations`, `condition_observations` |
| `product` | yes | `vendor`, `url`, `price`, `currency`, `availability`, `listing_title`, `listing_copy`, `offer_state`, `inventory_state`, `listing_start_at`, `listing_end_at`, `commercial_lifecycle`, `condition_copy` |

## 6. Knowledge / Source / Evidence workflow

The three ingest tools create proposals. Submit, approve with returned
fingerprints, check eligibility and apply. Evidence requires existing Claim and
Source UUIDs. Closed runtime profiles are: claim types `fact`, `specification`,
`history`, `technical`, `provenance`, `other`; source types `publication`,
`website`, `archive`, `catalog`, `interview`, `other`; evidence relations
`supports`, `contradicts`, `qualifies`; visibility `PUBLIC`, `PRIVATE`, `HIDDEN`.
Public reads require active records and a public evidence chain.

Repeated Article prose, Video hints/transcripts and Media annotations must first
resolve against canonical Knowledge. `same_claim` does not require a duplicate
claim. `add_evidence` requires an existing canonical Claim and Source plus their
revision closure. Generated text, OCR, caption, alt and transcript text are
never Evidence merely because they are available to MCP.

After a Knowledge, Source or Evidence ingest read-back, the adapter must run
canonical search, neighborhood/Graph inspection, duplicate/reuse analysis and
relation candidate discovery against the registered Authority, Media,
Source/Evidence and related Knowledge context. Each candidate keeps its
provenance class (`OBSERVED_FROM_MEDIA`, `EXPLICIT_USER_KNOWLEDGE`,
`CATALOG_SUPPORTED`, `EXTERNAL_RESEARCH` or `SYSTEM_INFERENCE`) and must pass
subject/scope evidence validation before a governed relation apply. User input
and Media observation remain scoped evidence/input; they are not universal
facts without supporting Source/Evidence.

## 7. Graph workflow and runtime matrix

Graph is the only relation persistence. Relation create, retire and reactivate
are governed operations through `GraphService`. The read-only
`nhk.graph.inventory` tool enumerates stored edges with typed endpoints,
direction, lifecycle and diagnostics; `nhk.relation.backfill.dry_run` scans the
canonical/Graph snapshot without mutation. Raw Graph REST remains
administrator-only.

Full boot registers 15 endpoint types: `wp_post`; Authority `brand`, `model`,
`variant`, `movement`, `music`, `component`, `classification`, `specimen`,
`product`; and `media`, `video`, `knowledge`, `source`, `evidence`.

| SOURCE | PREDICATE | TARGET | CARDINALITY | DIRECT/DERIVED | EVIDENCE | GOVERNED OPERATION | MCP READ TOOL | MCP WRITE TOOL |
|---|---|---|---|---|---|---|---|---|
| all 15 endpoint types | `about` | all 15 endpoint types | outbound MANY / inbound MANY | DIRECT | none enforced in edge; provenance separate | `relation_create`, `relation_retire`, `relation_reactivate` | `nhk.graph.inventory`, `nhk.relation.backfill.dry_run` | `nhk.proposal.create` + lifecycle |
| `media` | `depicts` | all 15 endpoint types | outbound MANY / inbound MANY | DIRECT | none enforced in edge; provenance separate | `relation_create`, `relation_retire`, `relation_reactivate` | none; admin REST only | `nhk.proposal.create` + lifecycle |
| `model` | `model_of` | `brand` | outbound ONE / inbound MANY | DIRECT | canonical endpoints; provenance where the relation operation requires it | `relation_create`, `relation_retire`, `relation_reactivate` | none; admin REST only | `nhk.proposal.create` + lifecycle |
| `variant` | `variant_of` | `model` | outbound ONE / inbound MANY | DIRECT | canonical endpoints; provenance where the relation operation requires it | `relation_create`, `relation_retire`, `relation_reactivate` | none; admin REST only | `nhk.proposal.create` + lifecycle |
| `variant` | `uses_movement` | `movement` | outbound MANY / inbound MANY | DIRECT | canonical endpoints and documented/configured-use evidence | `relation_create`, `relation_retire`, `relation_reactivate` | none; admin REST only | `nhk.proposal.create` + lifecycle |
| `movement` | `supports_music` | `music` | outbound MANY / inbound MANY | DIRECT | canonical endpoints and capability evidence | `relation_create`, `relation_retire`, `relation_reactivate` | none; admin REST only | `nhk.proposal.create` + lifecycle |
| `variant` | `configured_with_music` | `music` | outbound MANY / inbound MANY | DIRECT | canonical endpoints and configuration evidence | `relation_create`, `relation_retire`, `relation_reactivate` | none; admin REST only | `nhk.proposal.create` + lifecycle |
| `specimen` | `observed_playing_music` | `music` | outbound MANY / inbound MANY | DIRECT | concrete-object observation provenance/evidence | `relation_create`, `relation_retire`, `relation_reactivate` | none; admin REST only | `nhk.proposal.create` + lifecycle |

Only predicates currently registered by runtime may be used. Documentation or
historical fixture text never authorizes an additional relation. No derived
relation, Album relation or predicate-specific evidence rule may be invented.
Post-ingest reconciliation applies every registered relation candidate that is
useful and justified, and deliberately rejects weak/speculative edges.

### 7.1 Related semantic navigation read gap

The approved application contract for related navigation is
`docs/architecture/RELATED_SEMANTIC_PROJECTION_CONTRACT.md`. It requires a
bounded, registry-driven read over registered endpoints, direct/derived
classification, a maximum of two hops, direction-aware traversal, path
explainability, deduplication and public eligibility/readiness before
serialization.

`nhk.entity.neighborhood` now exposes the shared bounded query for registered
profiles. Raw Graph REST remains administrator-only, and the Capture boundary
delegates to the same application query contract rather than exposing raw
edges. The query returns reader-safe paths, deduplicated targets and a maximum
of two hops; unsupported profiles/bounds fail closed. No taxonomy, post meta,
hard-coded ID or generic WordPress read may substitute for the governed Graph
query.

## 8. Media workflow

Media identity, MediaAsset binary metadata and MediaUsage placement are
separate. The existing metadata path of `nhk.media.ingest` accepts current
stable key/name/readiness, asset packet and usage packet; Controlled Apply
delegates through the shared `MediaIngestGateway` to `MediaService::ingest`.
Asset metadata includes storage key, optional original filename, checksum, MIME,
size, dimensions and visibility. Usage includes endpoint type/key, controlled
role, order and contextual SEO fields. Article roles are `featured_primary`,
`inline_primary` and `inline_supporting`; the five existing generic roles
remain in the same registry. `nhk.media.get` returns active ready Media, public
deliverable assets and reader-safe usage.

`nhk.media.upload-batch` is the primary multipart transport for one or more
images. It accepts `files[]`, an idempotency key, optional batch metadata and
per-file hints, then returns an ordered manifest with attachment/Media IDs,
checksum, dimensions, MIME, byte size, read-back status and typed per-item
errors. One file uses the same implementation with batch size one. Partial
failure is retained per item and replay uses the same idempotency binding;
different payload under the same key is a deterministic conflict. The custom
`/nhk/v1/mcp` endpoint carries the binary parts. The same contract is exported
as the `nhk-v3/media-upload-batch` WordPress Ability with a top-level `files[]`
binary parameter so connector discovery can expose it. The canonical Capture
descriptor additionally exports `_meta["openai/fileParams"] = ["files"]`;
this identifies the native multipart argument without changing the text-only
schema or putting bytes, base64 or client filesystem paths into Ability JSON.

The thin NHK Ability callback preserves native `$_FILES` parts when the Ability
adapter receives them and delegates to `/nhk/v1/mcp`. An Easy MCP adapter must
preserve both this `_meta` descriptor and the multipart request. Easy MCP
1.7.17's stock dynamic Ability serializer currently emits only `inputSchema`
and annotations and its MCP transport accepts JSON only; until that external
adapter is upgraded or patched, its `wp_ability_nhk_v3_capture_ingest` surface
remains a `CLIENT_EXPOSURE_GAP`. It is not permission to use a direct Media or
WordPress writer.

The canonical lifecycle is multipart batch → native WordPress attachment
creation with the 1200px public sizing policy →
`wp_generate_attachment_metadata()` and derivatives → canonical
attachment read-back / `nhk.media.attachment.get` → governed
`nhk-v3/media-ingest` attachment adoption/binding → MediaAsset → Media →
MediaUsage. The upload transport phase does not infer or apply Knowledge,
Source, Evidence, Graph relations or Model/Variant truth; after canonical Media
ingest read-back, the universal post-ingest reconciliation is mandatory. The
source-original remains a private/protected MediaAsset and WordPress
derivatives remain derivatives under the same Media identity.

The path classification is fixed: `nhk.media.upload-batch` is
PRIMARY/RECOMMENDED multipart transport; `wp_upload_media_from_url` is
SECONDARY/IMPORT for already-public HTTPS files; `wp_upload_media` base64 is
FALLBACK/COMPATIBILITY only and is not the production-primary path for many
images. Any legacy direct multipart branch on `nhk.media.ingest` is a bounded
compatibility adapter; it does not replace the batch transport or merge the
transport and semantic boundaries.

Same idempotency key plus the same binary payload reuses the canonical result;
same key with a different payload returns `IDEMPOTENCY_CONFLICT`. Filename
equality is not identity and checksum is not global semantic dedup. A batch is
not all-or-nothing: successful items remain, failed items carry their own
errors, `partial_success` is explicit and failed items may be retried. If
runtime acceptance is not freshly proven, status is
`IMPLEMENTED_CODE_SIDE / LIVE_ACCEPTANCE_PENDING`.

`nhk.media.attachment.get` reads back attachment projection state including the
attachment ID, canonical URL, sanitized filename, MIME, dimensions, filesize and
derivatives. Checksum is a duplicate candidate only; it never merges canonical
identities. A suitable existing Media should be reused before creating another
semantic identity.

Article Ingest reconciliation uses the same `ArticleMediaCoordinator` as the
WordPress post-created adapter. It returns media state, mandatory-slot
diagnostics and Blueprint information without copying or reordering Post body
content. `nhk.article.preflight` previews media state read-only. A missing real
image binds a distinct system placeholder and remains incomplete.

Media detail types, SEO keyword groups, state values and diagnostic reason codes
are controlled registries owned by NHK Core. This MCP document does not define
their semantics; the sole source of law is the Constitution and runtime
registries.

### 8.1 Media semantic enrichment and representative reconciliation

After every governed Media ingest/read-back, inspect the canonical semantic
neighborhood and run bounded enrichment. A single Media may be reused through
multiple contextual usages and justified relations to multiple canonical nodes;
each usage/relation must have its own supported subject/context and must not be
created merely to increase Graph count.

Inspect every directly related node that lacks an image. The best currently
available image may become a temporary representative only when its
representative relevance is sufficient. Selection is deterministic in this
order: exact subject specificity → visual coverage → technical relevance →
image quality/resolution → provenance confidence → current representative
quality. A Variant-level image must not fill a broader Brand/Model slot when
representative relevance is insufficient.

When a more suitable candidate appears, compare suitability, promote the new
representative and demote the old one to gallery, `technical_detail` or
evidence when still suitable. Never delete the old Media, MediaAsset or
provenance. Representative status is `BEST CURRENTLY AVAILABLE`, a mutable
presentation choice rather than an immutable relation.

## 9. Product / Specimen

The approved Constitution amendment separates the two registered Authority
types. Specimen is the canonical identity of one physical object and owns
serial/physical evidence, provenance, observations, condition and
evidence-supported identification. Product is the canonical identity of one
commercial listing/offer/context and owns listing copy, offer state, price,
availability, inventory/listing state and commercial lifecycle.

Cardinality is Specimen `0..N` Products over time and Product `0..1` Specimen.
A Product that claims one specific physical object is semantically complete
only with exactly one Specimen; generic/pre-specimen Product may remain
unlinked when the current Product contract permits it. Product copy is not
Knowledge or physical truth, and commerce edits do not mutate Specimen.

The current runtime has no dedicated approved Product–Specimen persistence
relation. The former `specimen_uuid` Product field is no longer registered, and
broad `about` remains insufficient as an ownership contract. This is recorded
as `REGISTRY_GAP`/`CODE_GAP`; no relation, payload repair, inferred Specimen or
backfill is performed. A future relation requires explicit semantics,
endpoints, cardinality, provenance and Governance review.

## 10. Album

Album has no canonical V3 entity type, Authority registry entry, Graph endpoint,
predicate, repository, service, MCP tool or public contract. `music.album` is a
field, not an Album entity; generic gallery/collection language does not
establish one.

This is `SEMANTIC_GAP`. Do not create an Album entity, taxonomy, relation or
projection. A future contract must first choose its owner and identity boundary.

## 11. Video

Video identity is the validated external reference. The domain supports YouTube
watch, short, embed and `youtu.be` forms and stores one canonical watch URL plus
platform/external ID. Optional thumbnail Media is a typed field, not an
implicit Graph edge. No local MP4 is downloaded.

`nhk.video.ingest` may additionally return a bounded `knowledge_enrichment`
planning packet. When an already-validated explicit `about` target is supplied,
that canonical target is authoritative for both the relation candidate and the
enrichment subject; text research must not silently broaden Variant → Model or
Brand. `USER_HINT` is context rather than Evidence. Transcript text must be
atomically extracted through an approved read-only extractor and is never one
large canonical claim. Generated editorial prose is never Evidence.

At the current Video boundary no NHK Source is created implicitly. A repeated
observation may resolve `same_claim`; `add_evidence` is proposal-ready only with
canonical `source_id` + `source_revision`. The planning packet never submits,
approves or applies Knowledge/Evidence and never creates Graph predicates.

After a governed Video ingest has produced a canonical owner read-back, the
universal post-ingest reconciliation still runs: canonical search,
neighborhood/Graph inspection, duplicate/reuse analysis, relation candidate
discovery, evidence/provenance validation, governed application of every useful
registered relation and final Video/Graph read-back. The optional Knowledge
enrichment packet remains planning-only unless its own governed operation is
applied; a preview or source snapshot is never `COMPLETE`.

The runtime smoke for `SaLpWgitdSE` / Odo 36/10 verifies the target handoff:
explicit `about → variant 95873bfe-d978-4eda-a5a2-ce9ba79625df` remains the
enrichment subject and candidate scope. That smoke performed no Knowledge,
Evidence or Graph mutation.

## 12. Governance

There is no standalone `OperationRegistry`; the effective allowlist is in the
executor/domain services:

| DOMAIN | EXISTING OPERATIONS |
|---|---|
| Authority | `create`, `ingest`, `rekey`, `rename`, `update`, `retire`, `reactivate` |
| Media | `ingest` |
| Video | `ingest`, `update`, `retire`, `reactivate` |
| Knowledge/Source/Evidence | `create`, `ingest`, `update`, `retire`, `reactivate` |
| Graph | `relation_create`, `relation_retire`, `relation_reactivate` |
| MCP proposal lifecycle | `create`, `submit`, `approve`, `reject`, `eligibility`, `apply` |

Generic proposal strings are not authorization; final validation occurs at
apply. Every semantic write retains capability checks, expected revision,
fingerprints, idempotency, audit and controlled transaction. The MCP proposal
schema rejects unsupported operations before proposal persistence; this is an
input boundary, not a new operation registry.

For `relation_create`, the governed proposal binder resolves the current
revision of both typed endpoints and stores `source_revision` and
`target_revision` in the binding payload. Missing revision data fails closed;
`expected_revision=1` is never used as an endpoint fallback. Eligibility
continues to compare both bound revisions and blocks with
`TARGET_REVISION_CHANGED` when either endpoint changes before apply. Authority
`nhk.entity.get` and semantic search expose the canonical revision needed by
this contract.

## 13. Error codes and fail-closed behavior

`-32600` invalid JSON-RPC request; `-32601` unknown method/tool; `-32602`
invalid/missing argument, including an unsupported proposal operation; `-32003`
origin or capability denied; `-32020` Streamable HTTP/header mismatch; `-32022`
unsupported protocol version. Typed domain/governance failures return an MCP
`isError=true` result. Null reads, ambiguity, unavailable readiness and
revision/idempotency conflicts are not success and must not be retried with
altered content under the same key.

## 14. Read-back verification

After apply, use `result_entity_uuid`: Authority → `nhk.entity.get`; governed
Media metadata → `nhk.media.get`; Video → `nhk.video.get`;
Knowledge/Source/Evidence → matching read tool. Direct file attachment ingest
must use `nhk.media.attachment.get` with the returned `attachment_id` for
WordPress projection read-back. Graph requires administrator-only Graph REST.
Post requires native WordPress read/browser verification. Verify canonical
identity, active state, visibility, revision result, relation direction and
public projection; then perform the final read-back after all justified relation
and representative changes. Apply or ingest success alone does not prove
public availability or permit `COMPLETE`.

## 15. End-to-end example

For “Biên soạn và đưa bài lên web, xây chặt các quan hệ liên quan”:

```text
1. nhk.semantic.resolve
   Resolve canonical subject; stop on missing, conflict or ambiguity.
2. nhk.entity.get, nhk.knowledge.get, nhk.source.get, nhk.evidence.get
   Reuse canonical facts/evidence; do not copy the Article body into semantic storage.
3. Typed Article draft create/update boundary
   Create/update native WordPress draft with idempotency/state-token CAS.
4. knowledge/source/evidence.ingest when new semantic truth is actually required
   Submit -> approve with fingerprints -> eligibility -> apply.
5. nhk.proposal.create with operation=relation_create
   Use only registered predicates/endpoints; run the same governed lifecycle.
6. nhk.media.ingest / nhk.video.ingest
   Reuse current Media/Video identities and their storage/planning contracts.
7. Read back each owning domain, WordPress Post and Graph; verify identity,
   revisions, visibility, relation direction and public projection.
8. Typed Article publication gate
   Publish only after semantic, media, compliance and rendered verification gates pass.
```

Standalone MediaUsage writes, raw Graph reads, Album and Product–Specimen
canonical-fact shortcuts remain blocked or gated. Direct image attachment intake
is only an adapter into the governed Media identity/storage boundary and does
not expand semantic write authority.

## 16. WordPress Abilities MCP bridge

On WordPress 6.9+, the plugin registers existing read tools and the minimum
governed Video workflow as public Abilities under category
`nhk-v3-content-operations`. This is a discoverability adapter, not a second
persistence or transport path, and it is feature-detected on older WordPress
versions.

| ABILITY | MCP SOURCE |
|---|---|
| `nhk-v3/search` | `nhk.search` |
| `nhk-v3/semantic-resolve` | `nhk.semantic.resolve` |
| `nhk-v3/entity-get` | `nhk.entity.get` |
| `nhk-v3/media-get` | `nhk.media.get` |
| `nhk-v3/video-get` | `nhk.video.get` |
| `nhk-v3/knowledge-get` | `nhk.knowledge.get` |
| `nhk-v3/source-get` | `nhk.source.get` |
| `nhk-v3/evidence-get` | `nhk.evidence.get` |

Each reuses the existing input schema, capability callback and reader-safe
metadata. The governed bridge additionally exposes the registered Video and
Proposal lifecycle Abilities. Those callbacks delegate to the custom MCP
transport, preserving capability mapping and lifecycle. No Ability name in this
document authorizes a writer unless it is visible in fresh runtime discovery.

### Article publication continuation Ability surface

For an existing Capture-owned Article that has reached
`READY_FOR_PUBLICATION`, the bridge exposes exactly these lifecycle continuation
Abilities for authenticated client discovery:

- `nhk-v3/article-publish-review` → `nhk.article.publish.review`
- `nhk-v3/article-publish-approve` → `nhk.article.publish.approve`
- `nhk-v3/article-publish` → `nhk.article.publish`

They remain `nhk_internal_content_operations`-guarded and are not included in
the Easy MCP operator allowlist for new submissions. Their callbacks delegate to
the canonical MCP transport, then `EditorialDraftGateway` and
`OwnerPublicationApplicationService`; the `ArticlePublicationGate`, owner
decision/audit, state-token CAS, idempotency and native/public read-backs remain
mandatory. This surface does not expose a low-level WordPress writer and does
not create a Capture or Article.

The supported local/runtime continuation CLI is
`public/wp-content/plugins/nhk-core/bin/nhk-core-publication.php`. It accepts
only an existing Capture ID, its bound Article ID, an idempotency key and a
canonical publication-evidence JSON file. It reads the current Capture and
native Article state, bootstraps the runtime documentation checkpoint, and
delegates `run`, `review`, `approve` or `publish` to the same three MCP Ability
names above. It cannot accept Article content, allocate an Article slug, create
semantic records, or call a WordPress writer directly. `run` requires explicit
`--affirmation="Đăng"` for an owner-review result and reports PASS only after
the canonical service returns a durable publication receipt and both native
and rendered-public read-back pass.

## 17. Article Ingest implementation status

The Article coordinator, durable receipt, deterministic child proposal planner,
read-only editorial token, verification reader and diagnostic reader are
implemented under the approved operation-level contract. The receipt table is
`nhk_article_operations` with a unique idempotency key and optimistic receipt
revision. Same-key/different-fingerprint requests return `IDEMPOTENCY_CONFLICT`
without changing the original receipt. Partial semantic apply is recorded and
retries skip already-applied children; no compensation is attempted.

The receipt never stores the full Article body. Native WordPress draft and
publication transitions remain bounded by the typed editorial gateway and
publication gate; semantic changes remain separate governed operations.
`V2MigrationService.php` is a separate migration path and Article Ingest must
never call it. Any reachable Article path that copies legacy `post_content` into
semantic storage or mutates Graph outside Governance is
`CONSTITUTION_CONFLICT`.


A generic WordPress write, Media upload or Video preview alone cannot be
reported as a complete V3 knowledge Article workflow. Required-stage failure
must remain an explicit non-success, retryable, unavailable, conflict or
contract-defined outcome.

## Ô Đô governance binding incident — 2026-09-04

The live capability schema exposes `merge`; the old statement that merge is
unavailable is historical only. The current live diagnostic is blocked because
proposal-create with pinned-dial source UUID
`32f43d4b-d6c8-4223-a89b-cc47f30cda77` persisted `subject_id="component"`.

The diagnostic was rejected and no merge/apply occurred. The required fix is
to bind the canonical source UUID through both the local and remote governance
transport paths and re-verify before any owner-approved merge.

## Current public projection and frontend completion law — 2026-09-07

MCP/Admin read orchestration follows `Authority → Graph → canonical
projection/read model → frontend`. A successful semantic Apply is not a
frontend success. Consumers distinguish `Canonical Applied`, `Projection
Available`, `Frontend Available` and `Frontend Blocked`; the last requires
canonical route resolution and read-back.

For Video the route is `/video/{slug}/`. External platform URLs remain source,
provenance and embed references only. Public-safe knowledge may be returned
when its canonical projection passes policy and contains only the registered
safe fields; raw PRIVATE Source/Evidence and private metadata/IDs remain
excluded. Relations still require Graph/public eligibility independently.
