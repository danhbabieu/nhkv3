# Knowledge, Source và Evidence

> **NON-NORMATIVE CURRENT MODEL / RUNTIME GUIDE.** Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

Knowledge, Source và Evidence là ba canonical domain boundary riêng, không phải
WordPress body và không phải Authority type.

- **Knowledge** là atomic claim/fact/research statement có canonical identity,
  lifecycle và revision.
- **Source** là canonical nguồn có source type, durable locator và provenance/
  metadata theo contract.
- **Evidence** là canonical link giữa một existing Claim và một existing Source,
  với relation `supports`, `contradicts` hoặc `qualifies`, excerpt/locator và
  metadata/visibility theo policy.

Một Source tồn tại không chứng minh mọi claim của subject. Provenance nhét trong
Knowledge payload không thay thế Source/Evidence khi fact/relation contract yêu
cầu chain riêng.

## Current writer and identity boundary

Governed Knowledge, Source and Evidence ingest/write surfaces are exposed in the
current runtime/MCP catalog. An ingest response may represent only a Proposal;
`proposal_id` is never a canonical Claim/Source/Evidence ID and must not be
copied into `claim_id`, `source_id` or `evidence_id`.

Current lifecycle is:

`create/ingest proposal → submit → review → approval with binding fingerprints →
eligibility → Controlled Apply → canonical owner read-back → idempotency
verification`.

Create/ingest does not become canonical success until the owning repository/query
returns the expected record. Evidence creation validates that Claim and Source
already exist canonically and are active; their revisions/dependencies remain
part of the eligibility/apply boundary.

Visibility is separate from canonical validity. Active PRIVATE/HIDDEN
Source/Evidence may be used by governed internal verification under the current
policy without being exposed by public reads. Public serializers remain
reader-safe and policy-gated.

## Reconcile before Knowledge create

Before creating any Knowledge claim:

1. resolve the intended canonical semantic subject/context;
2. inventory current Knowledge at the same subject/facet/scope;
3. reconcile the proposed observation against existing claims and evidence;
4. classify it as exact existing, merge/update candidate, related-but-distinct,
   genuinely new, or uncertain;
5. only a genuinely new atomic claim may enter create/ingest.

Exact normalized text can be one deterministic signal inside the same canonical
subject/facet/scope, but lexical/fuzzy/AI similarity is never broad identity
proof. A new Post, Video, Media item or differently worded sentence does not
justify a duplicate claim if canonical truth already exists.

`same_claim` means reuse/enrich the current claim. `add_evidence` requires an
existing Claim and Source. Qualification/contradiction remains a distinct
structured decision; it is not silent overwrite of the earlier claim.

## No orphan semantic data

A Knowledge claim must not be deliberately created detached from the semantic
subject it is intended to describe. The canonical target must be resolved first.
If the correct target does not exist and a new Authority node is genuinely
needed, the node is created through its Authority/Governance lifecycle and read
back before the Knowledge claim is created.

If the target cannot be resolved, safely created or related with current
registry vocabulary, the candidate is deferred with provenance/reason/owner
action. “Create claim now, attach node later” is prohibited and cannot produce a
`COMPLETED` result.

## Required factual semantic ingest lifecycle

For new researched truth, use:

`Source/Evidence research → canonical subject/target resolution → reconcile
existing canonical data/claims → create/update/merge Authority node if genuinely
needed → canonical Authority read-back → Knowledge ingest → Knowledge canonical
read-back → governed Graph attachment → Source/Evidence attachment → Graph +
Knowledge + Evidence canonical read-back → idempotency check`.

Second-run acceptance requires no duplicate Authority node, Knowledge claim,
Source, Evidence, active relation, dangling relation or orphan claim.

## Article / WordPress boundary

WordPress Post is editorial truth. An Article may reference many Knowledge
claims and one Knowledge claim may be reused by many Posts. Article body text,
title, excerpt, research notes and generated copy are not canonical Knowledge
merely because WordPress stores or renders them.

Article Ingest may create planning candidates, but new semantic truth must leave
the editorial workspace and enter the canonical Source/Evidence/Knowledge and
Governance flow before being called canonical. Knowledge updates may create an
Article update suggestion; they never rewrite a published WordPress body
automatically.

A direct Post→Knowledge Graph write outside Governance/Controlled Apply is a
`CONSTITUTION_CONFLICT`; relation state remains Graph-owned.

## Video boundary — current reconciliation

Generic Video factual extraction remains planning-first: `USER_HINT`, authorized
transcript observations and source metadata are candidate inputs, not Evidence or
canonical Knowledge by themselves. Generated Video editorial copy is never
Evidence.

The older statement that Video workflows cannot resolve/create any canonical
Source is **historical for the generic enrichment-preview seam** and must not be
used to describe the current guided Video relation workflow. The current
`VideoRelationAdminService` can, after canonical Video + canonical target
resolution, deterministically resolve/reuse or create and read back:

`private YouTube Source → provenance-scoped Claim → private Evidence`.

That provenance chain is then referenced by the governed Video `about` relation
proposal. Normal operators are not asked to manually supply Video Proposal UUID
or Evidence UUID. Existing matching Source/Claim/Evidence is reused only when
its canonical provenance binding still matches; a mismatch fails closed.

This does not turn Video metadata into Knowledge automatically and does not make
Source/Evidence public. It is one bounded orchestration path using the existing
canonical owners; arbitrary transcript facts still require the normal
reconcile/Governance flow.

## Media / Image boundary

MediaUsage, `depicts`, filename, alt, caption, OCR, EXIF and visual recognition
are not Evidence or Knowledge by themselves. They may feed read-only research or
lexical candidate planning. A future Media→Living Knowledge write adapter must
still resolve canonical subject, reconcile current claims, and use the same
Source/Evidence/Knowledge/Governance boundaries.

## Public-safe knowledge projection

Raw Source/Evidence privacy is a boundary on raw payload serialization, not a
blanket ban on public Knowledge. When the canonical pipeline has produced an
eligible public-safe projection, frontend queries may render only the registered
safe fields such as `text`, `type`, `facet`, `scope` (using exact runtime names).

The projection must not expose raw Source, private Evidence excerpt/metadata,
canonical private IDs, or reconstruct private payloads. A public-safe Knowledge
projection also does not make a Graph relation public; relation eligibility is
independent.

## Deferred and retry boundary

Unresolved factual candidates use explicit states such as `PENDING_RESEARCH`,
`EVIDENCE_GAP`, `REGISTRY_GAP`, `RELATION_GAP`, `LEXICAL_GAP`,
`RUNTIME_BLOCKED` or `NEEDS_REVIEW`, and end as `COMPLETED`,
`DEFERRED_WITH_REASON` or `BLOCKED_WITH_OWNER_ACTION`.

A deferred record should retain source/provenance, proposed subject/type/relation,
evidence, resolved canonical IDs, registry/runtime blocker, existing proposal ID
and rerun instruction. When a rate limit/runtime interruption occurs after a
proposal already exists, reuse that proposal/idempotency binding if the intent is
unchanged; do not mint a duplicate proposal just to retry.
