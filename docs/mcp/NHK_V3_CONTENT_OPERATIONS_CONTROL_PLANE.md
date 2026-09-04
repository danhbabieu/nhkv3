# NHK V3 Content Operations Control Plane

> **NON-NORMATIVE.** The Constitution and registered runtime contracts remain
> authoritative. This document maps adapters to existing application owners;
> it does not authorize generic writes or new semantic vocabulary.

## Shared boundary

```text
user intent → content kind → registered owner/endpoint
→ application service → governed operation (when semantic)
→ relation/media/SEO policy → read-back → publication gate
→ MCP and Admin adapters
```

MCP and WordPress Admin must consume the same application services and
capability source. Native WordPress editorial publishing remains independent;
an MCP-managed V3 Article is complete only after the Article Ingest contract.

| Content kind | Owner | Current boundary | Mutation policy |
|---|---|---|---|
| Post/Article | WordPress `wp_posts` | Article Ingest + editorial boundary | Post writes are editorial; semantic changes use Governance |
| Category/hub | WordPress taxonomy | typed `CategoryGateway` + native WordPress adapter | deterministic resolve/create, parent validation, fingerprint CAS, guarded delete and read-back |
| Authority | Authority registry | entity application services | governed revision/lifecycle |
| Knowledge/Source/Evidence | bounded Knowledge contexts | ingest/read services | Proposal → Approval → Eligibility → Apply |
| Graph relation | Graph | GraphService | governed relation lifecycle only |

| Media/MediaUsage | Media contexts + WordPress binary | governed Media service/coordinator plus attachment projection | multipart/file input creates-or-resolves one Media; source-original is PRIVATE/protected, eligible derivatives are PUBLIC under that Media, representative/evidence/detail roles are distinct, and attachment mapping is idempotent |
| Video | Video | Video intake/sync services | governed canonical external reference; optional Living Knowledge output is planning-only |
| Product/Specimen | Authority | existing type contracts | no Product–Specimen shortcut until approved |
| Projection module | application/frontend | configuration/query boundary | source-code/runtime contract, never semantic content |

## Capability manifest

The machine-readable manifest is a projection of the actual registered MCP
catalog. It reports supported reads/writes, governance, idempotency,
revision, relation/media/SEO support, read-back and an explicit unsupported
reason. It must not advertise an operation merely because a future contract
mentions it. Admin and MCP must use this one source.

The canonical binary transport for a new image is the existing direct
multipart `nhk.media.ingest` adapter. It validates, orients, resizes and names
from supplied editorial context, then enters the governed Media V3 boundary.
The source-original is retained as a MediaAsset; WebP/responsive outputs are
derivatives under the same Media identity. It does not use base64/data URLs or
infer semantic relations from image content. No `nhk-v3/media-ingest` Ability
is authorized or required.
Actual image bytes must validate before persistence. Corrupt/fake/unreadable
payloads fail closed and partial attachment, mapping or semantic artifacts must
be cleaned up. WordPress attachment is never semantic authority. Entity
projection exposes representative and evidence separately; evidence and
`technical_detail` never replace a representative, whose precedence is
deterministic.

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
| SEO Blueprint contract | contract added | CODE_GAP for full planner/projection |
| Shared capability source | partial catalog | CODE_GAP for manifest consumers |
| WordPress editorial gateway | draft create/update boundary | runtime-unverified pending exact integration DB | draft-only, receipt idempotency, native state-token CAS and explicit publication blockers |
| Taxonomy gateway | typed category facade exposed in MCP | runtime-unverified pending exact integration DB | no fuzzy-create, no Graph/semantic mutation, guarded delete |
| Related semantic query | existing bounded query, policy gaps remain | CODE_GAP/REGISTRY_GAP where traversal policy is absent |
| Video → Living Knowledge | planning seam implemented; target-handoff smoke verified | apply remains separate Governance boundary; guarded integration still ENVIRONMENT_BLOCKED |
| Media → Living Knowledge | not implemented | CODE_GAP; MediaUsage/depicts/OCR must not be promoted implicitly |
| Article → Living Knowledge automatic body update | not implemented by design | suggestion-only until separately governed |
| Product–Specimen persistence | unavailable | REGISTRY_GAP/CONTRACT_EXTENSION_REQUIRED |
| Live data application | prohibited in this slice | HUMAN GATE |

## Current semantic merge blocker

`rekey` and `merge` are present in the governed operation schema. They are not
currently safe for the pinned-dial apply because the live proposal binding
maps the supplied source UUID to `subject_id="component"`.
Classification: `PINNED_DIAL_MERGE=BLOCKED`,
`LIVE_MERGE_SUBJECT_BINDING_INVALID`. Diagnostic proposals were rejected; no
semantic data was mutated. This replaces stale `MERGE_OPERATION_NOT_EXPOSED`
wording while preserving the historical record.
