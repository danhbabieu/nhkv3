# MCP V3 Video Workflow

> **NON-NORMATIVE CURRENT WORKFLOW.** The Constitution, current Video contracts,
> executable MCP catalog and fresh runtime read-back control.

## Canonical intake

`nhk.video.ingest` is the governed YouTube intake adapter. It accepts a
validated URL plus bounded user/editorial context and returns/creates the
appropriate governed Video intent. Same YouTube external identity reuses the
same canonical Video; watch/Shorts/embed/`youtu.be`/tracking variants do not mint
new identities.

Source metadata may come from the official platform adapter when configured.
Missing API configuration/remote availability is explicit. No fabricated
transcript, scraped fallback fact or silent editorial overwrite is allowed.
`USER_HINT` remains context, not Evidence.

Video is separate from Media/thumbnail, Knowledge, Source, Evidence and Article.
The canonical public route is `/video/{slug}/`; external URL is source/
provenance/embed only.

## Resolve/reconcile before relation or Knowledge create

Before creating semantic Video-related data:

1. resolve canonical Video/external identity;
2. resolve canonical target/subject;
3. inventory existing Graph relations and Knowledge/Source/Evidence;
4. reuse exact existing canonical records;
5. create only genuinely missing canonical records through their owner;
6. defer ambiguity instead of producing orphan/duplicate data.

A validated explicit `about` target remains authoritative for the relation and
its semantic context. Text/user-hint matching must not broaden it.

## Generic Living Knowledge planning seam

Authorized transcript observations/source context may produce a bounded
`knowledge_enrichment` planning packet. That preview does not itself mean a
Knowledge/Evidence mutation occurred. Whole transcript text and generated
editorial prose are not Evidence.

Candidates still require canonical subject resolution, existing-claim
reconciliation and the normal governed Knowledge/Source/Evidence lifecycle.

## Current guided Video relation workflow

The current guided Admin/application relation workflow is newer than the old
“no Source can be created” planning-only checkpoint. With a canonical Video and
canonical target it can resolve/reuse or deterministically create and read back:

`private canonical YouTube Source → provenance-scoped Claim → private Evidence`.

It then builds the Video `about` relation proposal with canonical
`evidence_refs`, content/dependency fingerprints and stable idempotency.
Existing Source/Claim/Evidence is reused only when its provenance still matches;
a mismatch fails closed.

The normal operator selects Video + target. The UI/orchestration does not require
the operator to paste a Video proposal UUID or Evidence UUID manually.

This provenance workflow is bounded to the explicit relation. It does not turn
arbitrary transcript/source/editorial text into semantic truth automatically.

## Governance lifecycle

Current lifecycle is:

`proposal create/ingest → submit → review → approval with binding fingerprints →
eligibility → Controlled Apply → canonical Video/Graph owner read-back →
idempotency verification`.

A proposal ID remains distinct from canonical Video/Source/Claim/Evidence/Graph
identity. Approved/eligible is not canonical success. Relation completion
requires Graph read-back with the real Video source UUID, registered `about`
predicate and canonical target UUID.

Replay must not duplicate Video, Source, Claim, Evidence, active relation or
proposal for the same durable intent.

## Relation identity and registry

Video relation packet preserves:

`source_type=video`, real `source_uuid`, `predicate=about`, real
`target_type/target_uuid`, canonical Evidence references.

The historical relation `subject_id` hydration defect is resolved in the current
proposal repository and is not a current global Graph blocker.

Do not use broad `about` to fake `classified_as`, structural membership,
configuration, movement-use or Product–Specimen ownership. Missing predicate is
a registry/relation gap and remains deferred/fail-closed.

## Frontend and retrieval

After canonical Video read-back and Public Identity eligibility, the frontend
uses `/video/{slug}/`. Persisted source state such as `metadata.source` is
normalized in the application/query layer; no second source identity is minted.

“Xem trên web” is the first-party page; “Mở nguồn gốc” is the external source.
Frontend reads canonical Graph/read models and public-safe Knowledge. It must
not infer relations from keyword search.

Bounded Graph neighborhood infrastructure is present via the current Graph
application/MCP boundary. If a specific Video frontend does not consume every
eligible path/profile, record `PARTIAL_FRONTEND_GAP`, not Graph unavailability.

## Retry/deferred behavior

Target ambiguity, evidence gap, registry gap, runtime interruption or other
blocked dependency must preserve resolved canonical IDs and any existing
proposal ID. Retry the same proposal/idempotency binding when intent is unchanged;
do not create a duplicate proposal merely because the previous run was
interrupted.
