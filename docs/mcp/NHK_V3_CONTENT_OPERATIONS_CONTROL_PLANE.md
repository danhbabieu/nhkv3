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
| Media/MediaUsage | Media contexts + WordPress binary | Media service/coordinator | reuse and contextual usage; placeholders incomplete; new managed image bytes normalize to WebP before persistence |
| Video | Video | Video intake/sync services | governed canonical external reference |
| Product/Specimen | Authority | existing type contracts | no Product–Specimen shortcut until approved |
| Projection module | application/frontend | configuration/query boundary | source-code/runtime contract, never semantic content |

## Capability manifest

The machine-readable manifest is a projection of the actual registered MCP
catalog. It reports supported reads/writes, governance, idempotency,
revision, relation/media/SEO support, read-back and an explicit unsupported
reason. It must not advertise an operation merely because a future contract
mentions it. Admin and MCP must use this one source.

The canonical binary transport for a new image is the existing direct
multipart `nhk.media.ingest` adapter. It validates, orients, resizes, names
from supplied editorial context, encodes WebP, persists the normalized output,
reads it back and cleans temporary/source workfiles. It does not use
base64/data URLs, retain the source filename/binary, invoke global WordPress
intermediate-size generation, or create semantic inference. No
`nhk-v3/media-ingest` Ability is authorized or required.

## Required Article sequence

`nhk.article.preflight` must complete semantic inventory, overlap analysis,
relation plan, internal-link plan, SEO blueprint, media/video plan and claim
compliance before an Article draft or publication orchestration proceeds.
`nhk.article.ingest` remains the governed coordinator and must preserve
idempotency, revision binding, read-back and fail-closed outcomes.

## Current gap classification

| Area | Status | Classification |
|---|---|---|
| Existing Article reconcile preflight | partial | CODE_GAP for full research packet |
| SEO Blueprint contract | contract added | CODE_GAP for full planner/projection |
| Shared capability source | partial catalog | CODE_GAP for manifest consumers |
| WordPress editorial gateway | draft create/update boundary | runtime-unverified pending exact integration DB | draft-only, receipt idempotency, native state-token CAS and explicit publication blockers |
| Taxonomy gateway | typed category facade exposed in MCP | runtime-unverified pending exact integration DB | no fuzzy-create, no Graph/semantic mutation, guarded delete |
| Related semantic query | existing bounded query, policy gaps remain | CODE_GAP/REGISTRY_GAP where traversal policy is absent |
| Product–Specimen persistence | unavailable | REGISTRY_GAP/CONTRACT_EXTENSION_REQUIRED |
| Live data application | prohibited in this slice | HUMAN GATE |
