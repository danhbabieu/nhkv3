# MCP V3 CONTENT OPERATIONS

> **NON-NORMATIVE.** This is a runtime contract audit. If it conflicts with
> `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution controls.

Status: runtime audit and contract-safe implementation checkpoint, 2026-09-03.

New NHK-managed image bytes follow one scoped ingest law before durable
persistence: validate → auto-orient → resize → contextual SEO-safe filename →
WebP encode → normalized persistence → only required WebP derivatives →
read-back verification → temporary/source cleanup. Missing trustworthy naming
context and unavailable WebP conversion fail closed; original JPEG/PNG is not
stored silently. Existing legacy files are not rewritten or deleted.

This shared guide describes the MCP V3 runtime actually present for ChatGPT and
Codex. It does not authorize new entity types, predicates, relation types,
fields, operations, taxonomy or data population.

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

## 2. Tool catalog thực tế

`McpToolCatalog::tools()` exposes the exact current registered tool list. In
this 2026-09-03 workspace it contains 36 tools. `kind=mutation` implies
`governed=true`. The coordinated Article tools occupy positions 3–4; the
catalog's final position is `nhk.proposal.apply`. The clean HEAD
catalog and the wire smoke both use this
same ordered list; the local HTTP wire smoke remains an environment check and
must not be replaced by a static catalog assertion.

| TOOL | DOMAIN | READ/WRITE | GOVERNED | REVISION | GRAPH | STATUS |
|---|---|---|---|---|---|---|
| `nhk.search` | native Post + public semantic search | READ | No | N/A | No | READY, bounded/public |
| `nhk.semantic.resolve` | Authority context | READ | No | N/A | No | READY; ambiguity fails closed |
| `nhk.article.preflight` | Existing WP Post + semantic bundle | READ | No | N/A | Registry/Graph read only | READY; reconcile preflight |
| `nhk.article.ingest` | Article operation receipt + governed semantic delta | WRITE | Yes | Receipt + semantic revisions | Controlled Apply only | READY for reconcile; create/update fail closed |
| `nhk.entity.get` | Authority | READ | No | N/A | No raw edge | READY for registered type + UUID |
| `nhk.media.get` | Media + public assets/usages | READ | No | N/A | No raw edge | READY for active ready Media/public assets |
| `nhk.media.ingest` | Media/MediaAsset/MediaUsage or governed WordPress image attachment | WRITE | Yes | Both paths enter the governed Media service; file path creates/resolves one Media and projects an attachment | Usage is placement; attachment is storage/projection only | READY for metadata and multipart image adoption; runtime byte proof required |
| `nhk.media.attachment.get` | WordPress image attachment | READ | No | N/A | No semantic inference | READY for read-back |
| `nhk.video.ingest` | Video external reference + semantic intake preview | WRITE | Yes | Apply creates revision | Approved attachment candidates apply through Graph | READY for validated YouTube URL; source/review gates explicit |
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

The historical assertions expecting 18/19/21/22 are obsolete; the current
catalog also includes the typed Category and native Article draft/publication
operations present in `McpToolCatalog`. No prior tool was removed. Article ingest is capability-gated by
`nhk_ingest_articles`, while
Article preflight is read-gated. The exact current wire order is the table
order above.

## 3. Use-case capability matrix

| USE CASE | CURRENT CAPABILITY | STATUS |
|---|---|---|
| Find canonical entity | Authority resolver; `nhk.search` for bounded public discovery; UUID-only reads for other domains | PARTIAL |
| Read canonical entity | Entity/domain `get` tools | READY for exposed boundaries |
| Create/update entity | Ingest or generic governed proposal; no typed update tool | PARTIAL |
| Read Source/Evidence | `nhk.source.get`, `nhk.evidence.get` | READY |
| Create Knowledge claim | `nhk.knowledge.ingest` + lifecycle | READY |
| Read/create relation | Governed `relation_create`; raw Graph read is admin REST only; related semantic read has no MCP tool yet | PARTIAL / IMPLEMENTATION_GAP |
| Create/update/publish Post | No MCP Post application command | BLOCKED |
| Upload/find Media | Governed metadata ingest plus direct multipart image attachment and attachment read-back | READY for current image contract |
| Attach MediaUsage | Nested in Media ingest only | PARTIAL |
| Product / Specimen | Registered Authority types via generic paths | PARTIAL |
| Album | No V3 contract | SEMANTIC_GAP |
| Video | Governed ingest, UUID read, YouTube identity, optional thumbnail UUID | READY for current contract |
| Publish | Semantic Apply only; no editorial Post publish operation | PARTIAL/BLOCKED |
| Read-back | Domain reads plus native WP/Graph REST checks | PARTIAL |
| Frontend verification | Existing route smoke/browser QA, not an MCP tool | PARTIAL |

## 4. Post workflow

The existing read-only `nhk.article.preflight` surface also accepts optional
`research_topic` and `research_subject` fields. When present, it delegates to
the shared Article Semantic/SEO Research Preflight and returns a planning
packet; it performs no Post, taxonomy, semantic, Graph, Media, Video or
Governance write. The research path uses the shared bounded two-hop Graph
reader, Post semantic-reference projection, bounded Knowledge → Evidence →
Source inventory and public route/eligibility boundary. Without
`research_topic`, the reconciliation contract below is
unchanged.

For Phase 1, `nhk.article.preflight` and `nhk.article.ingest` support only
reconciliation of an existing WordPress Post: read and fingerprint the target,
preflight the explicit semantic bundle, create deterministic child proposals,
wait for Governance approval, apply eligible children, and read back semantic
and editorial state. Generic WordPress create/update/publish remains an
independent editorial workflow and cannot be reported as completed Article
Ingest by itself. Article create and editorial update return explicit
`UNSUPPORTED_OPERATION` outcomes and do not write WordPress.

The typed `nhk.article.draft.create` and `nhk.article.draft.update` tools now
cover native WordPress draft creation/update only. Creation is idempotent via
the existing Article operation receipt repository, never stores body in the
receipt, and returns a native state token plus `DRAFT_INCOMPLETE_FOR_PUBLICATION`.
Update requires a matching native state token and only updates an eligible
draft. The typed `nhk.article.publish` tool remains the only V3 publication
writer: it requires the current draft token and verified evidence, calls
`ArticlePublicationGate` before the native status transition, and reads the
published Post back. `nhk.article.publish.review` returns exactly `PASS`,
`OWNER_REVIEW_REQUIRED` or `SYSTEM_BLOCKED`; eligible failures create a
dedicated durable owner-decision record. `nhk.article.publish.approve` requires
the authenticated owner principal, exact decision/token/policy/fingerprint
binding and an affirmative instruction, then publishes through the same
writer and records read-back. System-blocked results never expose approval.
`nhk.article.trash` and `nhk.article.restore` use the same CAS/receipt
boundary and never permanently delete a Post. The typed `nhk.category.*` tools
similarly delegate to the shared native Category
gateway; category membership is taxonomy truth and never a Graph edge.

The publication boundary is enforced by `ArticlePublicationGate`; rendered
public verification and exact integration runtime evidence remain separate
completion gates.

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

## 7. Graph workflow and runtime matrix

Graph is the only relation persistence. Relation create, retire and reactivate
are governed operations through `GraphService`. There is no MCP Graph read
tool; raw Graph REST is administrator-only.

Full boot registers 15 endpoint types: `wp_post`; Authority `brand`, `model`,
`variant`, `movement`, `music`, `component`, `classification`, `specimen`,
`product`; and `media`, `video`, `knowledge`, `source`, `evidence`.

| SOURCE | PREDICATE | TARGET | CARDINALITY | DIRECT/DERIVED | EVIDENCE | GOVERNED OPERATION | MCP READ TOOL | MCP WRITE TOOL |
|---|---|---|---|---|---|---|---|---|
| all 15 endpoint types | `about` | all 15 endpoint types | outbound MANY / inbound MANY | DIRECT | none enforced in edge; provenance separate | `relation_create`, `relation_retire`, `relation_reactivate` | none; admin REST only | `nhk.proposal.create` + lifecycle |
| `media` | `depicts` | all 15 endpoint types | outbound MANY / inbound MANY | DIRECT | none enforced in edge; provenance separate | `relation_create`, `relation_retire`, `relation_reactivate` | none; admin REST only | `nhk.proposal.create` + lifecycle |
| `model` | `model_of` | `brand` | outbound ONE / inbound MANY | DIRECT | canonical endpoints; provenance where the relation operation requires it | `relation_create`, `relation_retire`, `relation_reactivate` | none; admin REST only | `nhk.proposal.create` + lifecycle |
| `variant` | `variant_of` | `model` | outbound ONE / inbound MANY | DIRECT | canonical endpoints; provenance where the relation operation requires it | `relation_create`, `relation_retire`, `relation_reactivate` | none; admin REST only | `nhk.proposal.create` + lifecycle |
| `variant` | `uses_movement` | `movement` | outbound MANY / inbound MANY | DIRECT | canonical endpoints and documented/configured-use evidence | `relation_create`, `relation_retire`, `relation_reactivate` | none; admin REST only | `nhk.proposal.create` + lifecycle |
| `movement` | `supports_music` | `music` | outbound MANY / inbound MANY | DIRECT | canonical endpoints and capability evidence | `relation_create`, `relation_retire`, `relation_reactivate` | none; admin REST only | `nhk.proposal.create` + lifecycle |
| `variant` | `configured_with_music` | `music` | outbound MANY / inbound MANY | DIRECT | canonical endpoints and configuration evidence | `relation_create`, `relation_retire`, `relation_reactivate` | none; admin REST only | `nhk.proposal.create` + lifecycle |
| `specimen` | `observed_playing_music` | `music` | outbound MANY / inbound MANY | DIRECT | concrete-object observation provenance/evidence | `relation_create`, `relation_retire`, `relation_reactivate` | none; admin REST only | `nhk.proposal.create` + lifecycle |

At clean HEAD the registry had two predicates (`about`, `depicts`). The current
working tree already contains the six exact approved Brand relationship
definitions above in `PredicateRegistry`; they are pre-existing uncommitted
work and were not added by this MCP task. No further predicate, derived
relation, predicate-specific evidence rule or Album relation may be invented.

### 7.1 Related semantic navigation read gap

The approved application contract for related navigation is
`docs/architecture/RELATED_SEMANTIC_PROJECTION_CONTRACT.md`. It requires a
bounded, registry-driven read over registered endpoints, direct/derived
classification, a maximum of two hops, direction-aware traversal, path
explainability, deduplication and public eligibility/readiness before
serialization.

The current MCP surface has no related/Graph read tool. Raw Graph REST remains
administrator-only, and the eight WordPress read Abilities mirror the existing
catalog rather than adding related navigation. This is an
`IMPLEMENTATION_GAP`/`P1` query-exposure gap, not permission to expose raw edges
or to add a new MCP tool in this documentation task. A future MCP read review
must delegate to the shared application query contract, return reader-safe
paths and preserve the existing capability, identity and fail-closed rules.
No taxonomy, post meta, hard-coded ID or generic WordPress read may substitute
for the governed Graph query.

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

The same `nhk.media.ingest` tool also accepts one direct multipart `file`
parameter. The MCP envelope carries JSON-RPC arguments separately from the
multipart file part; the file is never represented as base64 or a data URL.
`filename`, `max_width`, `max_height` and `quality` control the binary adapter.
Before `wp_upload_bits`, the adapter copies the upload to a temporary workfile,
validates the image MIME, applies EXIF orientation, resizes without cropping to
the maximum dimensions, sets the requested encoder quality and sanitizes the
passed filename. Only that processed file is inserted into the WordPress Media
Library as a public derivative. The source-original bytes are retained as a
private MediaAsset under the same canonical Media identity; workfiles are not
retained after the request. WordPress-generated image sizes are returned as
`derivatives`.

The direct file path is a binary/storage adapter inside the governed Media
flow. It creates or resolves the NHK semantic Media identity but does not
create Knowledge, Source, Evidence or Graph edges from image content.
`nhk.media.attachment.get` reads back the attachment ID,
canonical URL, sanitized filename, MIME, dimensions, filesize and derivatives.

Article Ingest reconciliation uses the same `ArticleMediaCoordinator` as the
WordPress post-created adapter. It returns a media state, mandatory-slot
diagnostics and Blueprint information without copying or reordering Post body
content. `nhk.article.preflight` previews media state read-only. A missing real
image binds a distinct system placeholder and remains incomplete.

Media detail types, SEO keyword groups, state values and diagnostic reason codes
are controlled registries owned by NHK Core. This MCP document does not define
their semantics; the sole source of law is
`docs/constitution/NHK_V3_CONSTITUTION.md` and the runtime registries.

The direct file boundary does not search by checksum or add a usage
independently. Checksum detects a duplicate candidate on the governed metadata
path; it never merges canonical identities.

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
implicit Graph edge. Video is a Graph endpoint and may use only the two
predicates through governed proposals. No local MP4 is downloaded.

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
schema now rejects any operation outside the nine existing executor operations
before proposal persistence; this is an input boundary, not a new operation
registry.

## 13. Error codes and fail-closed behavior

`-32600` invalid JSON-RPC request; `-32601` unknown method/tool; `-32602`
invalid/missing argument, including an unsupported proposal operation; `-32003` origin or capability denied; `-32020`
Streamable HTTP/header mismatch; `-32022` unsupported protocol version.
Typed domain/governance failures return an MCP `isError=true` result. Null
reads, ambiguity, unavailable readiness and revision/idempotency conflicts are
not success and must not be retried with altered content under the same key.

## 14. Read-back verification

After apply, use `result_entity_uuid`: Authority → `nhk.entity.get`; governed
Media metadata → `nhk.media.get`; Video → `nhk.video.get`;
Knowledge/Source/Evidence → matching read tool. Direct file attachment ingest
must use `nhk.media.attachment.get` with the returned `attachment_id` for
WordPress read-back. Graph requires administrator-only Graph REST. Post
requires native WordPress read/browser verification. Verify canonical identity,
active state, visibility, revision result, relation direction and public
projection; apply success alone does not prove public availability.

## 15. End-to-end example

For “Biên soạn và đưa bài lên web, xây chặt các quan hệ liên quan”:

```text
1. nhk.semantic.resolve
   Resolve Brand/Model context; stop on missing, conflict or ambiguity.
2. nhk.entity.get, nhk.knowledge.get, nhk.source.get, nhk.evidence.get
   Read canonical facts and public evidence; do not copy article body.
3. Native WordPress editorial API/UI
   Create/update the draft Post; do not publish yet.
4. knowledge/source/evidence.ingest
   Submit -> approve with fingerprints -> eligibility -> apply.
5. nhk.proposal.create with operation=relation_create
   Use only `about` or `depicts`, registered endpoints and valid keys; run the
   same governed lifecycle.
6. nhk.media.ingest / nhk.video.ingest
   Use only current asset/usage and external-reference contracts.
7. Read back domain records, Post and Graph; verify identity, revisions,
   visibility, relation direction and public projection.
8. Native WordPress editorial API/UI
   Publish only after Article Ingest's required semantic and verification stages
   satisfy the approved contract.
```

MCP-native Post CRUD/publish, Graph read, standalone MediaUsage, Album, and
Product–Specimen canonical-fact workflows remain blocked or gated. The
approved direct image attachment path is limited to processed WordPress binary
intake and read-back; it does not expand any of those semantic workflows.

## 16. WordPress Abilities MCP bridge

On WordPress 6.9+, the plugin registers eight existing read tools and the
minimum governed Video workflow as public Abilities under category
`nhk-v3-content-operations`. This is a discoverability adapter, not a second persistence or
transport path, and it is feature-detected on older WordPress versions.

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

Each reuses the existing input schema, `read` capability callback and metadata
`public=true`, `show_in_rest=true`, `readonly=true`, `destructive=false`,
`idempotent=true`.

The governed bridge additionally exposes `nhk-v3/video-ingest`,
`nhk-v3/proposal-create`, `nhk-v3/proposal-submit`, `nhk-v3/proposal-approve`,
`nhk-v3/proposal-reject`, `nhk-v3/proposal-eligibility` and
`nhk-v3/proposal-apply`. These callbacks delegate to the registered custom MCP
transport, preserving its capability mapping and lifecycle. Media, Knowledge,
Source and Evidence writers remain unexposed through Abilities; no generic
WordPress writer is registered.

## 17. Article Ingest implementation status

The Phase 1 coordinator, durable receipt, deterministic child proposal planner,
read-only editorial token, verification reader and diagnostic reader are
implemented under the approved operation-level contract. The receipt table is
`nhk_article_operations` with a unique idempotency key and optimistic receipt
revision. Same-key/different-fingerprint requests return
`IDEMPOTENCY_CONFLICT` without changing the original receipt. Partial semantic
apply is recorded and retries skip already-applied children; no compensation is
attempted.

The implementation is reconcile-only. WordPress create, editorial update,
draft and publish are deliberately unsupported pending the separate
`WORDPRESS_EDITORIAL_WRITE_IDEMPOTENCY_AND_CAS` review. No Article entity,
endpoint, status or generic Governance operation was added.

`V2MigrationService.php` can import legacy `post_content` through a separate
migration path; Article Ingest must never call it. Any reachable Article path
that does so is `CONSTITUTION_CONFLICT`. Likewise, if
`PostKnowledgeLinkService` mutates Graph directly outside
Governance/Controlled Apply, record `CONSTITUTION_CONFLICT` and route future
implementation through the governed boundary.

Until the contract is implemented and tested, a generic WordPress Post write or
the existing semantic tools alone cannot be reported as a complete V3 knowledge
Article workflow. Required-stage failure must remain an explicit non-success,
retryable, unavailable, conflict or equivalent contract-defined outcome.
