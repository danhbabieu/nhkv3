# NHK V3 Content Operations Control Plane

> **NON-NORMATIVE.** The Constitution and registered runtime contracts remain
> authoritative. This document maps adapters to existing application owners;
> it does not authorize generic writes or new semantic vocabulary.

## Shared boundary

```text
user intent → content kind → registered owner/endpoint
→ application service → governed operation (when semantic)
→ ingest → canonical read-back → canonical search
→ neighborhood/Graph inspection → duplicate/reuse analysis
→ relation candidate discovery → evidence/provenance validation
→ apply every justified useful registered relation → final read-back
→ publication gate when applicable
→ MCP and Admin adapters
```

MCP and WordPress Admin must consume the same application services and
capability source. Native WordPress editorial publishing remains independent;
an MCP-managed V3 Article is complete only after the Article Ingest contract.

Every MCP ingest domain uses this bounded deep reconciliation, including
Media, Video, Knowledge, Source, Evidence and Authority entities. “Maximize
relations” means maximize justified useful relations, not edge count; weak or
speculative candidates remain diagnostics/review items. `COMPLETE` requires the
canonical read-back, duplicate check, semantic research, relation
reconciliation, representative-media reconciliation where applicable and final
verification. Attachment-only transport, preview, partial, pending or
unavailable outcomes are intermediate and cannot be reported as complete.

### Editorial Capture boundary — 2026-09-09

`nhk.capture.ingest` is the single-submission coordinator for editorial text
and optional images. One idempotency key maps to one durable Capture and one
native WordPress draft. The resumable phases are:

```text
Capture received → physical attachments stored/read back → draft created
→ Media adopted → text interpreted → subjects resolved
→ bounded Claims retrieved → semantic write-back review packet
→ Article composed/updated → MediaUsage reconciled
→ publication gate → final native read-back
```

Text-only input is valid. Multipart files remain binary transport data and are
never put into JSON, Knowledge, Evidence or Graph storage. The Capture stores
editorial intent, observations, candidate provenance and phase receipts; the
WordPress Post remains the only owner of article title/body/excerpt. A replay
with the same payload resumes the Capture; a changed payload returns an
idempotency conflict. Ambiguous subject resolution, unavailable semantic
Governance, incomplete MediaUsage or missing publication evidence remain
machine-readable review/blocker states.

The current code-side semantic write-back phase creates a bounded review
packet and does not silently apply candidate Claims or relations. Canonical
semantic mutation still requires the existing Governance lifecycle. The
coordinator therefore reports `READY_FOR_PUBLICATION` or `REVIEW_REQUIRED`
until an eligible owner publication operation returns a verified native
published read-back.

#### Claim selection, trace and connector-exposure rule — 2026-09-09

Graph reachability is discovery only. A relation path may make a Claim a
candidate, but it is never sufficient permission to use that Claim in Article
prose. Capture Claim retrieval must keep the canonical Claim ID/revision,
semantic subject, relation path, scope, provenance, evidence state and
relevance decision. Direct use requires evidence that is supported within the
same scope, usable provenance and sufficient relevance to the Capture intent;
specimen-only Claims must not generalize upward. Missing/insufficient evidence
or provenance remains `review`, and incompatible scope remains `exclude`.

Article composition may synthesize only the selected Claim set and must retain a
body-free `claim_trace` plus a research snapshot of Claim IDs/revisions. It must
not dump raw Claim text as a semantic copy, turn generated prose into Evidence,
or create a second factual store inside the Article/Capture receipt.

The accepted Capture adapter currently covers text-only and multipart image
submissions. Video remains a distinct canonical external-reference intake and
is not yet a physical asset branch inside `nhk.capture.ingest`. A submission
that includes Video must therefore preserve the Video boundary and must not be
reported as one unified Capture-complete workflow until a registered shared
adapter exists. The implementation may reuse the semantic core, but it must not
create a duplicate Article, duplicate Video or convenience relation to imitate
missing orchestration.

Executable runtime capability and connector exposure are separate facts. A
registered runtime tool/Ability can be absent from one connector surface or
conversation. Such absence is a `CLIENT_EXPOSURE_GAP`, not proof that the
runtime catalog lacks the capability. Conversely, a client that cannot invoke a
required boundary must fail closed for that step; it must not substitute generic
WordPress Post/Media writers, historical Ability assumptions or an old
workflow. Fresh target discovery/read-back remains required before claiming
client callability.

The Governance Automation Policy is resolved in the shared application
orchestration boundary used by MCP and Admin. Its only modes are
`REVIEW_REQUIRED`, `AUTO_APPROVE` and `AUTO_PUBLISH`; absent configuration is
`REVIEW_REQUIRED`. Human review is configurable; Governance gates are not.
MCP responses distinguish submitted/manual review, approved/ready-to-apply,
published/frontend-available and blocked outcomes. Apply success alone is
never reported as frontend publication success.

| Content kind | Owner | Current boundary | Mutation policy |
|---|---|---|---|
| Post/Article | WordPress `wp_posts` | Article Ingest + editorial boundary | Post writes are editorial; semantic changes use Governance |
| Editorial Capture | Capture repository + WordPress draft + downstream owners | `nhk.capture.ingest` coordinator | one Capture/one draft by idempotency; no implicit semantic apply/publication |
| Category/hub | WordPress taxonomy | typed `CategoryGateway` + native WordPress adapter | deterministic resolve/create, parent validation, fingerprint CAS, guarded delete and read-back |
| Authority | Authority registry | entity application services | governed revision/lifecycle |
| Knowledge/Source/Evidence | bounded Knowledge contexts | ingest/read services | Proposal → Approval → Eligibility → Apply |
| Graph relation | Graph | GraphService | governed relation lifecycle only |

| Media/MediaUsage | Media contexts + WordPress binary | governed Media service/coordinator plus attachment projection | multipart/file input creates-or-resolves one Media; source-original is PRIVATE/protected, the normalized public derivative has max long edge 1200px with no upscale/crop and preserved aspect ratio, eligible derivatives are PUBLIC under that Media, representative/evidence/detail roles are distinct, and attachment mapping is idempotent |
| Video | Video | Video intake/sync services | governed canonical external reference; optional Living Knowledge output is planning-only |
| Product/Specimen | Authority | existing type contracts | no Product–Specimen shortcut until approved |
| Projection module | application/frontend | configuration/query boundary | source-code/runtime contract, never semantic content |

## Capability manifest

The machine-readable manifest is a projection of the actual registered MCP
catalog. It reports supported reads/writes, governance, idempotency,
revision, relation/media/SEO support, read-back and an explicit unsupported
reason. It must not advertise an operation merely because a future contract
mentions it. Admin and MCP must use this one source.

The canonical binary transport for a new image is `nhk.media.upload-batch` on
the custom `/nhk/v1/mcp` multipart boundary. It accepts `files[]` (one file is
a batch of one), validates bytes, creates/adopts native WordPress attachments,
generates metadata/derivatives and returns an ordered per-item manifest with
canonical read-back. The source-original is retained as a MediaAsset;
WebP/responsive outputs are derivatives under the same Media identity. It does
not use base64 as the default transport or infer semantic relations from image
content.

The transport classification is PRIMARY/RECOMMENDED for
`nhk.media.upload-batch`, SECONDARY/IMPORT for `wp_upload_media_from_url` when
the source is already a public HTTPS URL, and FALLBACK/COMPATIBILITY for
base64 `wp_upload_media`. Connector discovery also exposes the transport as
`nhk-v3/media-upload-batch` with a top-level `files[]` binary parameter. Its
thin Ability adapter preserves native multipart parts and delegates to the
custom boundary; bytes are not serialized into Ability JSON, base64 or paths.

After attachment read-back, `nhk-v3/media-ingest` remains the separate governed
semantic Media boundary for attachment adoption/binding through
`wordpress_attachment_id`; it is not the binary batch uploader.

The canonical flow is `multipart batch → WordPress attachment lifecycle →
attachment read-back / media-attachment-get → media-ingest → MediaAsset →
Media → MediaUsage`. Upload transport does not infer or apply Knowledge,
Source, Evidence, Graph relations or inferred Model/Variant truth during its
transport phase; the mandatory post-ingest reconciliation runs after canonical
Media read-back. Source/Evidence is reconciled only by a later governed
semantic workflow, and a durable
WordPress locator is preferred after canonical read-back without duplicating a
Source/Evidence record solely to change its locator.

When the input is an editorial submission, `nhk.capture.ingest` owns the
cross-boundary sequencing around this physical flow. It may call the batch
uploader as its physical phase, then performs explicit attachment adoption and
Article/MediaUsage reconciliation. The standalone upload tool remains useful
for a media-first workflow and never substitutes for Capture completion.

Batch results are per-item rather than all-or-nothing: successful attachments
remain when another item fails, the batch returns `partial_success`, and failed
items can be retried. SHA-256 is computed from real bytes; same key and same
payload reuses, while same key and different payload returns
`IDEMPOTENCY_CONFLICT`. Code-side implementation exists; live multipart
acceptance remains `IMPLEMENTED_CODE_SIDE / LIVE_ACCEPTANCE_PENDING` until
fresh target discovery/read-back proves it.
Actual image bytes must validate before persistence. Corrupt/fake/unreadable
payloads fail closed and partial attachment, mapping or semantic artifacts must
be cleaned up. WordPress attachment is never semantic authority. Entity
projection exposes representative and evidence separately; evidence and
`technical_detail` do not replace a representative solely by role, recency or
size. A fully compared more suitable candidate may be promoted, with the old
one demoted when still suitable, under the deterministic precedence.

## Storage and reuse map — 2026-09-04

| Data | Canonical owner/storage | Reuse rule | Never infer/duplicate |
|---|---|---|---|
| Article title/body/excerpt/editorial order | WordPress `wp_posts` | reuse native Post identity/state token | do not copy body into Knowledge, receipt or Graph storage |
| Media identity | `Media` | reuse canonical UUID/stable key/revision | checksum/filename/URL does not mint or merge identity |
| Uploaded source bytes | source-original `MediaAsset` | retain privately/protected under same Media | do not discard because WebP exists |
| WebP/thumbnail/responsive image | derivative asset / WordPress attachment projection | reuse under same Media identity | derivative is not a new Media |
| Image placement/alt/caption | `MediaUsage` + WordPress editorial placement | reuse same Media with contextual usage | usage is not Knowledge/Evidence/Graph truth |
| Video | canonical Video external reference | reuse platform + external ID and canonical target attachments | no local MP4 or Post identity implied |
| Video-derived fact candidate | Living Knowledge planning packet | resolve narrowest canonical subject; explicit valid `about` target wins | no automatic Knowledge/Evidence/Graph mutation |
| Knowledge claim | `Knowledge` | reuse UUID/stable key/revision | repeated prose does not create a duplicate claim |
| Provenance/support | `Source` + `Evidence` | reuse canonical source/evidence chain | generated text, transcript, OCR or caption is not Evidence by itself |
| Typed semantic relation | Graph | reuse registered endpoint IDs and predicate | no relation from placement, upload or prose alone |

All downstream adapters must read back from the owning store after a write. MCP
is orchestration/transport, not a canonical data store; Admin is an input
adapter, not a second writer.

The reconciliation preserves provenance classes
`OBSERVED_FROM_MEDIA`, `EXPLICIT_USER_KNOWLEDGE`, `CATALOG_SUPPORTED`,
`EXTERNAL_RESEARCH` and `SYSTEM_INFERENCE`. User statements and image
observations stay scoped and are not promoted to universal facts without
supporting evidence.

## Required Article sequence

`nhk.article.preflight` must complete semantic inventory, overlap analysis,
relation plan, internal-link plan, SEO blueprint, media/video plan and claim
compliance before an Article draft or publication orchestration proceeds.
Subject resolution is canonical UUID → stable key → exact canonical name/alias;
ambiguity fails closed and generic preflight never hard-codes a WordPress Post
ID. Runtime acceptance must prove real file → attachment → one Media identity
→ assets/usages → representative/evidence projection → Article preflight.
`nhk.article.ingest` remains the governed coordinator and must preserve
idempotency, revision binding, read-back and fail-closed outcomes. The
operation-level `ArticlePublicationGate` consumes those verified results and
requires the exact current draft state token, canonical public identity,
semantic read-back, MediaUsage completion, SEO/public-route verification and
claim-compliance acceptance. It returns explicit blocker codes and does not
publish or replace any bounded-context policy.

Video intake may expose `knowledge_enrichment`, but that packet is read-only
planning output. An explicitly validated Video `about` target is handed through
to enrichment as the canonical subject before broader text research. No MCP
preview packet is evidence that Knowledge/Evidence was applied; those records
require their own governed proposal and read-back.

## Current gap classification

| Area | Status | Classification |
|---|---|---|
| Existing Article reconcile preflight | partial | CODE_GAP for full research packet |
| Editorial Capture coordinator | persisted/resumable boundary with text-only and multipart image paths; runtime/repository acceptance verified on 2026-09-09 | semantic candidates still require Governance and owner publication remains policy-gated |
| Video inside shared Capture | not yet unified under the physical Capture adapter | CODE_GAP / ADAPTER_GAP; keep canonical Video intake separate and do not fake Capture completion |
| Connector exposure parity | environment/client-specific subset may differ from executable runtime catalog | `CLIENT_EXPOSURE_GAP`; fresh discovery/read-back required, no generic-writer fallback |
| SEO Blueprint contract | contract added | CODE_GAP for full planner/projection |
| Shared capability source | partial catalog | CODE_GAP for manifest consumers |
| WordPress editorial gateway | draft create/update boundary | Capture integration acceptance verified for the tested path; other exact gateway operations remain runtime-specific | draft-only, receipt idempotency, native state-token CAS and explicit publication blockers |
| Taxonomy gateway | typed category facade exposed in MCP | runtime-unverified pending exact integration DB | no fuzzy-create, no Graph/semantic mutation, guarded delete |
| Related semantic query | existing bounded query, policy gaps remain | CODE_GAP/REGISTRY_GAP where traversal policy is absent |
| Video → Living Knowledge | planning seam implemented; target-handoff smoke verified | apply remains separate Governance boundary; no implicit Claim/Evidence write from preview |
| Media → Living Knowledge | no automatic claim-writing adapter | CODE_GAP; MediaUsage/depicts/OCR must not be promoted implicitly, while post-ingest semantic reconciliation remains mandatory |
| Article → Living Knowledge automatic body update | not implemented by design | suggestion-only until separately governed |
| Product–Specimen persistence | unavailable | REGISTRY_GAP/CONTRACT_EXTENSION_REQUIRED |
| Live data application | governed per owning workflow; not implied by documentation | HUMAN/POLICY GATE where current policy requires it |

## Current semantic merge blocker

`rekey` and `merge` are present in the governed operation schema. They are not
currently safe for the pinned-dial apply because the live proposal binding
maps the supplied source UUID to `subject_id="component"`.
Classification: `PINNED_DIAL_MERGE=BLOCKED`,
`LIVE_MERGE_SUBJECT_BINDING_INVALID`. Diagnostic proposals were rejected; no
semantic data was mutated. This replaces stale `MERGE_OPERATION_NOT_EXPOSED`
wording while preserving the historical record.

## Current Admin Workbench law — 2026-09-07

The standard menu is Tổng quan, Nội dung, Media, Tri thức, Duyệt, Hệ thống and
Nâng cao. Media uses shared `Tất cả`, `Hình ảnh` and `Video` workspaces with
separate domain list/detail adapters. Normal guided flows select canonical
records and do not ask for proposal UUID, Evidence UUID, fingerprint, expected
revision or raw JSON; those belong only to Kỹ thuật/Nâng cao.

Admin remains a control plane over the same application services and Governance
boundary. “Xem trên web” opens the canonical first-party Video route only when
the projection is eligible; “Mở nguồn gốc” is the external source action.