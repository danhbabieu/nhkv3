# Capture → Video → Governance → Publication Repair Design

## Goal

Repair the existing canonical Capture continuation so a validated Capture
subject packet remains authoritative for its Video child, the governed Video
proposal can be resumed without dead-end or duplication, and public Article /
Video projections expose honest media and claim-level diagnostics.

The live acceptance operation reuses the existing Capture
`01a096c0-97cc-7192-acf2-4735f9bf6582`, Article `467`, external YouTube ID
`VwP1AH9E3HA`, intended Video candidate
`01a096c0-9c59-7cee-b1b6-7bdd445345ac`, and Variant
`7301f50c-ef0d-4e95-a581-39e5063d4648`.

## Governing boundaries

- Capture remains orchestration only and remains the sole normal entry point.
- Authority owns the Variant subject and its lifecycle.
- Video owns the canonical external-reference identity.
- Source/Evidence owns provenance support; Knowledge owns atomic claims.
- Graph owns the single `Video → about → Variant` relation.
- Governance remains the only semantic mutation path.
- WordPress native Post remains the Article owner.
- Media, MediaAsset and MediaUsage remain separate; a YouTube thumbnail is not
  silently adopted as Article Media.
- Public Identity and SEO are projections over verified canonical owners.

## Design

### 1. Authoritative Video subject handoff

The Capture packet is considered authoritative only after a canonical owner
read validates its UUID, registered target type, active state and usable
revision. A packet with `match=uuid_exact` is passed unchanged to:

- the Video `about` target;
- `knowledge_enrichment.subject`;
- the Video provenance planner's semantic scope.

The provenance planner may still record source title/description/channel data,
but those fields no longer gate a valid explicit packet. Independent source
resolution is diagnostic only. A strong canonical B conflicting with explicit
A produces a review/conflict diagnostic while preserving A. Invalid,
unsupported, inactive or stale packets fail closed. No Model/Brand fallback is
permitted for a valid Variant packet.

### 2. Governed proposal and continuation

The Video child is represented by one real Governance proposal. The proposal
identity is separate from the intended Video canonical UUID. Every pending
result exposes `proposal_id`, `proposal_state`, `target_uuid` and
`canonical_id`; `canonical_id` remains null until Controlled Apply and verified
canonical-owner read-back.

Proposal lookup is idempotent by Capture + platform + external video ID +
semantic intent. Existing proposal state is resumed in place. Approved or
Applied proposals are never rebuilt through an alternate writer; an Applied
replay returns the existing Controlled Apply/read-back result idempotently.

The governed lifecycle remains:

`Proposal → Submit → Review/Approve → Eligibility → Controlled Apply → Video
read-back → Graph about relation verification → Public Identity/readiness →
final Video read-back → Capture continuation → Article publication gate`.

Source, Knowledge and Evidence dependencies retain their own governed
idempotency keys and canonical read-backs. Video relation application uses the
existing registered relation planner and Graph service; it does not write a
relation into Video metadata as a substitute for Graph.

### 3. Article media and claim diagnostics

Article media diagnostics retain existing machine codes but add a structured
operator payload: featured/inline status, expected subject, preferred
view/aspect, whether a validated Video thumbnail is available as an optional
fallback, and whether a real upload is preferred or required. The public/admin
message is Vietnamese-first and conversational.

Compliance output remains fail-closed and uses the existing claim/compliance
vocabulary. It adds exact claim text, classification, canonical scope,
evidence status, reason, narrowing eligibility, a genuinely narrower rewrite
when possible, and the human-review requirement. It does not create a new
semantic vocabulary or treat generated wording as Evidence.

### 4. Quality-aware Video thumbnails

The existing source snapshot/Video metadata boundary retains the candidate URL
set and records a bounded selected-thumbnail result. A selector probes the
approved external-source boundary, validates successful image responses,
actual dimensions and placeholder/invalid rejection, then chooses the highest
quality usable candidate in this order:

`maxresdefault → sddefault → hqdefault → mqdefault → default`.

The selected URL, variant and dimensions feed Video SEO, `VideoObject`, sitemap,
cards, detail, related blocks and Article fallback diagnostics. No extra Media
identity is created. A future custom Video cover can take precedence through
the existing governed Media reference before the validated source thumbnail.

## Error handling

Unavailable source, invalid identity, stale revision, missing canonical
dependency, Governance authorization, eligibility, Graph read-back, Public
Identity, MediaUsage and compliance failures remain distinguishable and fail
closed. No catch converts infrastructure failure to empty data or success.

## Test strategy

Tests are added before implementation for: explicit Variant handoff with no
text match, weak text, strong A/B conflict, invalid/inactive packet; proposal
creation after the prior blocker, pending identity envelope, same-proposal
resume, approval/eligibility/apply/read-back, one Graph edge, Applied replay,
external-ID idempotency; structured media UX; claim diagnostics and narrowing;
thumbnail probe ordering/dimensions/rejection; and all affected public
projection consumers.

## Acceptance evidence

The final report may claim PASS only after fresh documentation bootstrap and
required ACTIVE contract reads, focused and guarded tests, lint/static/diff/
secret checks, and live read-back prove stable Capture/Post/Video/Variant
identity, one proposal, one Graph edge, public identity, selected thumbnail,
Article diagnostics and all legitimate publication gates.

