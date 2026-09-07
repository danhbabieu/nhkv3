# NHK V3 MCP Content Operations

> **NON-NORMATIVE CURRENT MCP CONTRACT.** The Constitution, current domain
> contracts and executable `McpToolCatalog` control. Fresh runtime discovery is
> required for environment-specific availability. Historical fixed tool/Ability
> counts are not current capability truth.

## 1. MCP ownership boundary

MCP is transport/orchestration over existing owners. It is not a semantic store
or writer owner.

- WordPress native Post/Category operations remain editorial/taxonomy owners.
- Authority owns canonical entities.
- Knowledge owns atomic claims.
- Source/Evidence owns provenance/support.
- Graph owns typed semantic relations.
- Media/MediaAsset/MediaUsage own media identity/binary/context.
- Video owns canonical external Video reference.
- Governance controls durable semantic mutation.

No MCP packet, tool name or HTTP success may create a type/predicate/field that
is absent from the current registry.

## 2. Current read and write capability truth

The current executable catalog includes, among other current operations:

### Read/research

- `nhk.search`;
- `nhk.canonical.inventory`;
- `nhk.graph.inventory`;
- `nhk.relation.backfill.dry_run`;
- `nhk.semantic.resolve`;
- `nhk.entity.neighborhood` (bounded semantic neighborhood, max two hops under
  registered profiles);
- `nhk.entity.get`;
- `nhk.media.get`, `nhk.media.attachment.get`;
- `nhk.video.get`;
- `nhk.knowledge.get`, `nhk.source.get`, `nhk.evidence.get`;
- Article/category/public-url/proposal read operations currently registered by
  the executable catalog.

### Governed/controlled mutation

- current Authority proposal operations including registered create/ingest/
  update/lifecycle/rekey operations;
- `nhk.knowledge.ingest`;
- `nhk.source.ingest`;
- `nhk.evidence.ingest`;
- `nhk.media.ingest`;
- `nhk.video.ingest`;
- `nhk.proposal.create`, submit/review/approve/reject/eligibility/apply;
- authenticated `nhk.relation.backfill.apply` for explicitly confirmed,
  deterministic candidates;
- registered public-identity and Article/Category writes in the current catalog.

Therefore old wording that semantic writers are unexposed, Source/Evidence/
Knowledge write does not exist, Graph has no MCP/read seam, or relation apply is
generally unavailable is **historical/superseded**. Use fresh runtime discovery
to determine whether the current target environment exposes a particular tool.

## 3. Reconcile before create

MCP orchestration must research canonical state before generating a create
proposal. Resolve canonical UUID → stable key → exact canonical name/alias and
reconcile intent as:

`EXACT_EXISTING`, `MERGE_CANDIDATE`, `RELATED_BUT_DISTINCT`,
`NO_EXISTING_CANONICAL_RECORD`, or `UNCERTAIN`.

Only genuinely-new canonical identity proceeds to create. Fuzzy/keyword/title/
filename similarity is discovery evidence only. `UNCERTAIN` goes to deferred/
review state.

A Knowledge claim must have its intended canonical subject/context resolved
before create. If a new Authority target is genuinely needed, Authority create
is applied/read back first. Do not create an orphan claim to attach later.

## 4. Governance lifecycle and completion

Semantic create/ingest may return only a governed Proposal. Proposal ID is never
canonical entity/Claim/Source/Evidence/Video/Media/Graph identity.

Current lifecycle:

`proposal create/ingest → submit → review → approval with content/dependency
binding fingerprints → eligibility → Controlled Apply → canonical owner
read-back → idempotency verification`.

`DRAFT`, `SUBMITTED`, `APPROVED`, `ready=true`, HTTP 2xx or Apply response is not
`COMPLETED`. The owning read tool/repository/read model must return the intended
canonical mutation. Replay of unchanged durable intent must not duplicate the
record or side effect.

If rate limit/runtime interruption occurs after a proposal exists, reuse that
proposal ID/idempotency binding. Same key + changed intent is a conflict; do not
mint a new proposal just to retry unchanged work.

## 5. Canonical relation packets

Current Graph relation packet preserves:

- `source_type`;
- real existing `source_uuid`;
- registered `predicate`;
- `target_type`;
- real existing `target_uuid`;
- canonical Evidence references when the owning relation contract requires them.

The historical relation hydration bug that could expose `subject_id` as an
entity-type string instead of source UUID is **RESOLVED** in the current proposal
repository. It is not a global Graph blocker.

The executable predicate registry currently contains `about`, `depicts`,
`model_of`, `variant_of`, `uses_movement`, `supports_music`,
`configured_with_music`, `observed_playing_music`.

`classified_as` and a dedicated Product–Specimen relation remain
`REGISTRY_GAP`. Broad `about` cannot substitute for classification membership,
structural parentage, configuration, movement-use or Product–Specimen ownership.

## 6. Authority create status

Runtime probe proposal `01a07c4e-14b2-734e-8264-3f04b37e5fe4` for
`operation=create, entity_type=classification` passed create, submit, review,
approval and eligibility (`ready=true`, no reasons). It was intentionally not
Applied to avoid a junk node.

This proves the create pipeline through eligibility only. Actual new Authority
Controlled Apply → generated UUID → entity/resolver read-back → immediate
relation use remains to be proven when a real node is needed.

Pre-create `subject_id=classification` is not the old relation bug; the new node
has no UUID yet.

## 7. Knowledge / Source / Evidence workflow

Current semantic factual workflow is:

`research Source/Evidence → resolve canonical subject → reconcile existing
claims → create/update/merge Authority only if needed → Authority read-back →
Knowledge ingest/read-back → governed Graph attachment → Source/Evidence
attachment → Graph/Knowledge/Evidence read-back → idempotency check`.

Source is canonical provenance/locator. Evidence links existing Claim + existing
Source using `supports`, `contradicts` or `qualifies`. Do not replace the
Source/Evidence model with arbitrary Knowledge provenance blobs.

Public visibility is separate from canonical/internal verification. Active
PRIVATE/HIDDEN Source/Evidence may be used internally where the contract allows,
without public serialization.

## 8. Media / image workflow

`nhk.media.ingest` is the current governed Media metadata/direct multipart image
surface. `Media`, `MediaAsset`, `MediaUsage` and WordPress attachment remain
separate.

Direct file transport uses multipart binary, not base64/data URLs. It validates
actual image bytes, normalizes/orients/resizes/encodes as configured,
create-or-resolves one canonical Media, retains source-original privately/
protected, creates eligible derivatives under the same Media, creates/reconciles
contextual usages and reads canonical Media/attachment projection back.

Reconcile reusable Media before creating another identity. Checksum/filename/URL
is a duplicate signal, not semantic merge proof.

Operator view/detail concepts may include `front`, `back`, `movement`, `dial`,
`hands`, `pendulum`, `gong`, `hammer`, `plate`, `marking`, `logo`,
`case_detail`, but only current executable Media registries authorize actual
identifier values. MediaUsage/detail context is not Knowledge/Evidence/Graph
truth. Relation such as `depicts` requires separate canonical target resolution
and Graph/Governance.

`nhk.media.attachment.get` reads WordPress projection/storage result; WordPress
attachment is not semantic Media owner.

## 9. Video workflow

`nhk.video.ingest` is the governed canonical external Video intake. Same YouTube
external ID reuses the canonical Video. User hint, editorial instruction,
thumbnail Media and intended relation context remain separate data concerns.

Generic transcript/Knowledge extraction is planning-first and does not make
Knowledge automatically. The current guided Video relation workflow can, after
canonical Video + target resolution, resolve/reuse or deterministically create
and read back the private YouTube Source, provenance-scoped Claim and Evidence
required by the explicit `about` relation. It then creates the governed relation
proposal with canonical `evidence_refs` and stable fingerprints/idempotency.

Normal operators do not manually enter Video proposal UUID or Evidence UUID.
Wrong provenance fails closed. External YouTube URL is source/embed only; public
Video route is `/video/{slug}/` after Public Identity/projection eligibility.

## 10. Article / editorial workflow

Article title/body/URL remains native WordPress truth. `nhk.article.preflight`
is read-only research/reconciliation. Article semantic child work reuses
canonical owners; new facts must independently pass reconcile-before-create,
Source/Evidence/Knowledge and Governance.

Editorial Note/research annotation has no separate canonical semantic owner in
the current registry. A note is context until promoted through the normal
semantic owner chain.

Article body, generated copy, alt/caption, Video title/transcript and Product
listing copy are never Evidence merely because they are visible in WordPress.

## 11. Graph retrieval and frontend handoff

The current MCP/application read surface includes bounded
`nhk.entity.neighborhood` and operational Graph inventory. Raw technical Graph
reads can remain admin/internal while public/dossier queries expose reader-safe
canonical relations/path context.

Frontend must distinguish direct, incoming/outgoing and derived bounded paths.
It must not keyword-search a fake relation. Where a specific frontend does not
yet consume every eligible neighborhood/path, record `PARTIAL_FRONTEND_GAP`,
not “Graph unavailable”.

## 12. Cuckoo current runtime state

Classification Cuckoo:

- UUID `01a07614-832d-7f27-959c-74eb0cd63f3e`;
- stable key `nhk:classification:clock-type.cuckoo-clock`;
- name `Đồng hồ chim cúc cu`.

Ten core Knowledge claims have completed the governed
`Knowledge → about → Classification Cuckoo` relation lifecycle and canonical
Graph read-back. Older statements that this specific Knowledge group is a
`RELATION_GAP` are resolved.

This does not authorize Model/Variant classification membership;
`classified_as` remains unregistered.

## 13. Deferred / retry packet

Use explicit states such as `PENDING_RESEARCH`, `EVIDENCE_GAP`, `REGISTRY_GAP`,
`RELATION_GAP`, `LEXICAL_GAP`, `FRONTEND_GAP`, `RUNTIME_BLOCKED`,
`NEEDS_REVIEW`, with final outcomes `COMPLETED`, `DEFERRED_WITH_REASON`,
`BLOCKED_WITH_OWNER_ACTION`.

Preserve source/provenance, proposed subject/type/relation, evidence, blocker,
resolved canonical IDs, existing proposal ID and deterministic rerun instruction.

## 14. WordPress Abilities bridge

WordPress Abilities is a discoverability/adapter projection over registered
application/MCP operations, not a second writer. Do not use historical fixed
Ability counts/allowlists as current truth. Read current registration and fresh
runtime discovery. Multipart Media remains on its approved binary MCP transport
boundary even where JSON-only Ability transport cannot carry a file.

## 15. Read-back map

After semantic apply:

- Authority → `nhk.entity.get`/resolver;
- Knowledge → `nhk.knowledge.get` or canonical Knowledge inventory;
- Source → `nhk.source.get`/internal canonical owner according to visibility;
- Evidence → `nhk.evidence.get`/internal evidence-chain owner according to
  visibility;
- Media → `nhk.media.get`, plus `nhk.media.attachment.get` for WP projection;
- Video → `nhk.video.get`;
- Graph → canonical Graph/outbound/inbound/neighborhood/inventory read-back;
- Post → native WordPress/read-rendered verification.

Public/frontend success is a later projection gate and is never inferred solely
from semantic Apply.
