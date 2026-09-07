# Video Semantic Ingest Contract

> **NON-NORMATIVE CURRENT IMPLEMENTATION CONTRACT** under the sole
> Constitution. The Constitution controls if any text conflicts.

## Identity and ownership

Video is a canonical external Video reference, not a MediaAsset, WordPress Post,
Knowledge claim or Source. Current YouTube identity uses platform + external
video ID; watch/Shorts/embed/`youtu.be` forms and tracking parameters normalize
to the same external identity. Replay must reuse the existing canonical Video.

Canonical public Video URL is `/video/{slug}/` through the Public Identity
boundary. External YouTube URL remains source/provenance/embed data. Thumbnail
Media is a separate typed Media reference and does not change Video identity.

## Intake input and source policy

`nhk.video.ingest` remains the governed YouTube intake adapter. It accepts the
validated URL plus bounded user/editorial context such as `user_hint`,
`editorial_instruction`, optional thumbnail Media and already-resolved intended
relations. It preserves normalized canonical source URL, external ID, source
provenance/state and NHK editorial intent.

The official platform adapter is the only external source lookup boundary.
Unavailable API configuration/remote metadata remains explicit; no HTML scraping,
fabricated transcript or guessed platform fact is permitted. `USER_HINT` is
valuable context but is not Evidence by itself.

Same external identity plus same durable intent is idempotent. Source changes
produce reconciliation/review state; they never silently overwrite NHK editorial
content, canonical Video identity or Graph truth.

## Resolve and reconcile before mutation

Before a new semantic attachment or Video-derived fact is created:

1. resolve the canonical Video/external identity;
2. resolve the canonical intended target/subject;
3. reconcile current canonical relations and Knowledge claims;
4. reuse exact existing records and provenance chains when valid;
5. create only a genuinely missing canonical record through its owning boundary;
6. defer ambiguity instead of minting a temporary identity/claim.

A validated explicit user `about` target is authoritative for that relation and
for its semantic context. Text/title/user-hint matching must not broaden an
explicit Variant target into Model/Brand. Multiple conflicting explicit targets
fail closed.

## Generic Video → Living Knowledge planning

Authorized transcript text and user hints may feed a bounded factual observation
planner after canonical target resolution. Whole transcript text is source
material, not one canonical claim. Generated editorial copy is never Evidence.

Generic enrichment output remains planning-first: `same_claim`, new-claim,
qualification/contradiction or add-Evidence candidates must still pass canonical
Knowledge/Source/Evidence reconciliation and the normal governed mutation
boundary before becoming truth.

The historical wording “Video cannot resolve/create any canonical Source” is
**not current for the guided relation workflow below**. It remains relevant only
to an isolated generic preview seam that does not itself mutate Knowledge.

## Current guided Video relation provenance workflow — 2026-09-07

After canonical Video and canonical Authority target resolution, the guided
Video relation application service can deterministically resolve/reuse or create
and read back the provenance chain needed by the relation:

`canonical YouTube Source → provenance-scoped Knowledge Claim → Evidence`.

The current implementation:

- derives a deterministic Source stable key from YouTube platform/external ID/
  canonical URL;
- reuses an existing Source only when active provenance/locator still matches;
- resolves/reuses a target-scoped provenance Claim for the Video relation;
- derives deterministic Evidence identity/fingerprint and reuses it only when
  Claim, Source, Video UUID, visibility, locator and reconciliation fingerprint
  still match;
- keeps Source/Evidence PRIVATE under current Video provenance policy;
- reads each canonical owner back before the relation proposal is created;
- fails closed on wrong-source/wrong-claim/wrong-evidence provenance.

Normal Admin operators therefore select canonical Video + target and do **not**
manually enter a Video proposal UUID or Evidence UUID. The orchestration resolves
those dependencies and creates the relation proposal with canonical
`evidence_refs` and stable idempotency.

This bounded provenance orchestration is not permission to turn arbitrary Video
metadata/transcript into Knowledge. It exists to support the explicit governed
relation and still uses the canonical Knowledge/Source/Evidence owners rather
than a Video-private provenance store.

## Relation and Governance lifecycle

Video relation candidates use registered Graph vocabulary only. The current
Video outbound relation is `about`; do not invent a Hub/thumbnail/category
predicate. Relation endpoint packet preserves:

`source_type=video`, real `source_uuid`, registered predicate,
real `target_type`, real `target_uuid`, canonical Evidence references.

The lifecycle is:

`Video/provenance reconciliation → relation proposal create → submit → review →
approval with binding fingerprints → eligibility → Controlled Apply → canonical
Video/Graph read-back → idempotency verification`.

A Video Proposal UUID is not a canonical Video UUID. Approved/eligible state is
not relation completion. Graph read-back must show the active typed edge. Replay
must reuse existing canonical Video/provenance/relation rather than duplicate.

## Canonical frontend handoff

After canonical owner read-back and Public Identity eligibility, Video projects
to `/video/{slug}/`. Query/application code normalizes current persisted source
shape such as `metadata.source` and supported compatibility shapes without
creating a second source owner.

Frontend reads canonical projection, eligible Graph relations, public-safe
Knowledge and reader-safe provenance. It must not infer facts/relations from
keywords or external title text. External URL is never a fallback canonical
frontend destination.

Admin “Xem trên web” and “Mở nguồn gốc” are separate actions.

## Deferred/retry behavior

Ambiguous target, missing evidence, missing predicate, runtime interruption or
unavailable dependency uses explicit deferred/blocker state. Preserve existing
proposal ID and canonical IDs already resolved. Re-run the same proposal/
idempotency binding when the intent is unchanged; do not duplicate Video,
Source, Evidence, Knowledge or relation merely because execution was
interrupted.
