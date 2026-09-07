# NHK V3 Content Operations Control Plane

> **NON-NORMATIVE CURRENT CONTROL-PLANE GUIDE.** The Constitution, current
> domain contracts, executable MCP catalog/registries and fresh runtime read-back
> control. MCP/Admin are adapters, never semantic owners.

## Shared boundary

```text
user/editor intent
→ content kind + canonical owner
→ current canonical research / reconcile
→ application service
→ governed operation where semantic
→ canonical owner read-back
→ idempotency verification
→ projection/publication gate
→ MCP/Admin response
```

Native WordPress editorial publication remains independent. A V3 knowledge
Article is complete only after its coordinated Article contract, including any
required semantic owner read-back.

## Reconcile-before-create control-plane rule

No guided form or MCP orchestration should jump from user text directly to a
semantic create. First resolve canonical UUID → stable key → exact name/alias and
classify the intent as exact-existing, merge-candidate, related-but-distinct,
genuinely-new or uncertain. Fuzzy/keyword matching is discovery only.

Knowledge additionally requires its intended canonical subject/context before
create. If a missing Authority node is genuinely required, create/apply/read it
back first. If resolution remains uncertain, produce a deferred/research packet;
do not create an orphan claim/node.

## Canonical owner map

| Content kind | Owner | Current write/read boundary |
|---|---|---|
| Post/Article | WordPress `wp_posts` | typed editorial gateways/read-back; semantic child changes stay separate |
| Category | WordPress taxonomy | typed Category gateway + native read-back |
| Authority | Authority registry/services | governed revision/lifecycle; resolver/entity read-back |
| Knowledge | Knowledge owner | governed ingest/update + canonical read-back |
| Source | Source owner | governed ingest + locator/provenance read-back |
| Evidence | Evidence owner | existing Claim+Source binding + canonical read-back |
| Graph relation | Graph | registered predicate/endpoints, Governance, Graph read-back |
| Media/MediaAsset/MediaUsage | Media contexts + binary/WP projection | shared governed Media application boundary; attachment is not semantic owner |
| Video | Video | governed external-reference intake; guided relation provenance orchestration + Video/Graph read-back |
| Specimen | Authority `specimen` | physical identity owner |
| Product | Authority `product` | listing/offer owner; no implicit Specimen ownership |
| Projection/frontend | application/read model | read-only; never semantic mutation |
| Editorial/research Note | no separate semantic owner currently registered | workspace/editorial context unless explicitly promoted through canonical semantic owners |

## Governance lifecycle and automation

Semantic lifecycle is:

`proposal create/ingest → submit → review → approval with content/dependency
binding fingerprints → eligibility → Controlled Apply → canonical owner
read-back → idempotency verification`.

Proposal/approved/eligible/apply-response state is not canonical completion.
`COMPLETED` requires owner read-back; frontend/publication success is an
additional projection gate.

Governance Automation Policy modes remain `REVIEW_REQUIRED`, `AUTO_APPROVE`,
`AUTO_PUBLISH`; missing policy defaults to `REVIEW_REQUIRED`. Automation never
removes review/binding/eligibility/owner-readback semantics. `AUTO_APPROVE` stops
before Apply. `AUTO_PUBLISH` must prove applicable canonical and
projection/frontend results before reporting publication success.

## Current semantic capability status

The current executable catalog exposes semantic writers for the registered
Knowledge, Source, Evidence, Media, Video, Proposal/Governance and relation
operations, plus current Authority operations. It also exposes read-only
canonical/Graph inventory, semantic resolver, relation dry-run and bounded
`nhk.entity.neighborhood`, and an authenticated deterministic relation batch
apply surface.

Historical statements that WRITE semantic is unavailable, Source/Evidence/
Knowledge writers are missing, Graph has no read seam, or governed relation
apply is generally unavailable are superseded. Target-environment availability
still requires fresh runtime discovery.

## Relation identity and registry

`relation_create` keeps real typed canonical endpoints:
`source_type/source_uuid`, registered `predicate`,
`target_type/target_uuid`, plus canonical Evidence refs when required.

The old relation-proposal hydration defect that could use an entity type instead
of the real source UUID is **RESOLVED**. It is not the same as a create Proposal
for a new Authority node, which has no canonical UUID before creation.

Current executable predicates:
`about`, `depicts`, `model_of`, `variant_of`, `uses_movement`,
`supports_music`, `configured_with_music`, `observed_playing_music`.

`classified_as` and a dedicated Product–Specimen relation remain registry gaps.
Never use broad `about` to fake a missing relation meaning.

## Authority create evidence

Classification create probe proposal
`01a07c4e-14b2-734e-8264-3f04b37e5fe4` passed create, submit, review, approval
and eligibility (`ready=true`, no reasons) and was deliberately not Applied to
avoid a junk node. Current status is therefore **verified through eligibility**,
not yet actual new-node Apply/generated UUID/entity-readback/relation reuse.

## Article sequence

`nhk.article.preflight` performs read-only canonical research/reconciliation,
including subject resolution, existing claim/evidence/Graph inventory, overlap,
Media/Video/SEO/compliance planning. It must identify reuse/merge/related/new/
uncertain outcomes before any semantic child create.

WordPress draft/create/update remains editorial. Required semantic children use
the full Governance lifecycle and owner read-back. Publication uses native
WordPress state/read-rendered verification and applicable claim/media/SEO gates.
Article body/Note/prose is never automatically Knowledge or Evidence.

## Media / Image sequence

Current canonical binary transport is multipart `nhk.media.ingest`. It validates
actual bytes, reconciles/reuses canonical Media, creates one Media when genuinely
new, retains source-original PRIVATE/protected, creates eligible derivatives
under that Media, reconciles MediaUsage, maps WordPress attachment projection and
reads canonical Media/asset/usage back.

Media, MediaAsset, MediaUsage and attachment remain separate. Detail/view
concepts such as front/back/movement/dial/hands/pendulum/gong/hammer/plate/
marking/logo/case detail are controlled inputs only where the executable Media
registry has a matching value. They are not Knowledge or Graph truth.

MediaUsage/placement/OCR/recognition does not create `depicts`, `about`,
Knowledge, Evidence or Authority automatically.

## Video sequence

Generic Video-derived Knowledge extraction remains planning-first. The current
guided Video relation workflow, however, can after canonical Video + target
resolution deterministically resolve/reuse or create and read back the private
YouTube Source, provenance Claim and Evidence needed by the explicit `about`
relation, then create the governed relation proposal.

Normal guided Admin forms do not ask for Video proposal UUID or Evidence UUID;
those dependencies are resolved by orchestration. Replay of the same YouTube ID
reuses the canonical Video and matching provenance/relation intent.

## Cuckoo current status

Classification `01a07614-832d-7f27-959c-74eb0cd63f3e` /
`nhk:classification:clock-type.cuckoo-clock` (`Đồng hồ chim cúc cu`) now has the
10 core Knowledge `about` relations completed through full Governance and
canonical Graph read-back. Older current-status wording that this specific
Knowledge→Classification group is a `RELATION_GAP` is resolved.

`Model/Variant → classified_as → Classification` is a different semantic
relationship and remains a registry gap.

## Admin Workbench

Standard workspaces remain Tổng quan, Nội dung, Media, Tri thức, Duyệt, Hệ
thống, Nâng cao. Normal flows are guided; raw proposal UUID, Evidence UUID,
fingerprint, expected revision and raw JSON remain Kỹ thuật/Nâng cao.

Admin is a control plane over existing owners/Governance. For Video, “Xem trên
web” is the eligible first-party `/video/{slug}/` action; “Mở nguồn gốc” is the
external source action.

## Frontend/retrieval

Frontend projects canonical owners/Graph. Related content distinguishes direct,
incoming/outgoing and derived bounded neighborhood. Keyword search, taxonomy or
postmeta never substitutes for a missing semantic relation.

Graph bounded neighborhood exists in the current application/MCP boundary. A
missing consumer/path in a specific frontend is `PARTIAL_FRONTEND_GAP`, not
Graph unavailability.

## Deferred/retry packet

Use explicit intermediate states such as `PENDING_RESEARCH`, `EVIDENCE_GAP`,
`REGISTRY_GAP`, `RELATION_GAP`, `LEXICAL_GAP`, `FRONTEND_GAP`,
`RUNTIME_BLOCKED`, `NEEDS_REVIEW`, and final outcomes `COMPLETED`,
`DEFERRED_WITH_REASON`, `BLOCKED_WITH_OWNER_ACTION`.

Preserve source/provenance, proposed subject/type/relation, evidence, blocker,
canonical IDs, existing proposal ID and deterministic rerun instructions. If
intent is unchanged after rate-limit/runtime interruption, reuse the existing
proposal/idempotency binding rather than creating a duplicate.

## Historical merge incident

The pinned-dial/Odo merge incident that persisted a type-like `subject_id` is
historical, identity-specific merge evidence. It is not current proof that
ordinary `relation_create` source binding is broken. Merge/rekey remains a
separately high-impact governed identity operation and must be verified on its
own source/target revisions/read-back.
