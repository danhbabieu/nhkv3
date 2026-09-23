# NHK V3 Capture Pipeline Convergence Design

**Status:** Draft for human review  
**Date:** 2026-09-23  
**Scope:** Local NHK V3 runtime only; no V2, production or staging data mutation.

## 1. Intent and success criteria

This work makes the shared lifecycle deterministic, idempotent and read-back
verifiable across:

`Capture → Intent → Assets → Subject Resolution → Authority/Knowledge → MediaUsage → Article Composition → Publication Gate → Public URL → Projection → Frontend Read-back`.

The implementation must preserve the Constitution's ownership boundaries:
WordPress `wp_posts` owns editorial fields and URLs; Authority owns canonical
entities; Knowledge owns claims; Source/Evidence owns provenance; Graph owns
typed relations; Governance owns durable semantic mutations; Media, MediaAsset,
MediaUsage and Video remain distinct owners.

Definition of success:

- each registered Capture intent executes only its own dependency graph;
- exact canonical identity remains authoritative through retry;
- existing Media is reused without duplicate identity or usage rows;
- Article fields, MediaUsage, native WordPress projection and public output
  agree after canonical read-back;
- state tokens and revisions are refreshed after every mutable owner update;
- completion contains concrete required-owner IDs and cannot be promoted by a
  stale snapshot;
- retries converge to one canonical operation and do not replay completed
  physical or governed work.

## 2. Evidence and confirmed defect

The checked-out repository is clean on `main`. The focused Capture, subject,
publication and Media-related suite passes 128 tests / 539 assertions.

The first confirmed shared defect is in
`src/Application/Capture/EditorialCaptureCoordinator.php`: the common Article
branch invokes `videoPublicationVerifier` even when the resolved intent is not
`VIDEO`. This permits Video-specific readiness/blocker logic to contaminate
`TEXT_ARTICLE`, `IMAGE_ARTICLE` and semantic-only flows. The fix belongs at the
intent boundary, not in a data-specific workaround.

Other observed boundaries already partly implement the desired model and must
be preserved rather than replaced: `SubjectResolutionPacket`, staged Capture
receipts, `ArticleComposer` explicit-title precedence, `ArticleMediaCoordinator`
canonical usage planning, `ArticlePublicationGate`, and the server-issued
staging acceptance verifier.

## 3. Proposed architecture

### 3.1 Intent-owned dependency graph

The coordinator resolves one persisted `ContentIntent` and dispatches only the
owners required by that intent:

- `TEXT_ARTICLE`: subject/semantic branches → native draft → composition →
  Article publication/read-back; Media is optional unless the contract says
  otherwise.
- `IMAGE_ARTICLE`: the same Article path plus submitted/reused image adoption
  and required Article MediaUsage reconciliation.
- `VIDEO`: Video-specific enrichment, governed semantic dependencies and Video
  public-readiness verification; no Article is fabricated.
- `MEDIA_ENRICHMENT`: Media/MediaUsage only; no Article, claim retrieval or
  Video verification.
- `KNOWLEDGE_DELTA`: governed Knowledge delta and canonical read-back; no
  Article or image dependency.
- `KNOWLEDGE_REPAIR`: target-bound repair path; no inference, Article or
  unrelated Video reconciliation.

Video verification, Video thumbnail fallback and Video-only resume hints must
  be guarded by the resolved `VIDEO` intent and by an actual Video owner. Empty
  Video evidence is not a valid reason to invoke a Video gate.

### 3.2 Immutable subject authority handoff

Subject resolution uses the contract precedence:

`canonical UUID → stable key → explicit subject hint → title/body inference`.

Once an exact active canonical subject is resolved, the coordinator persists a
`SubjectResolutionPacket` containing identity, type, revision and source. All
downstream owners consume this packet. Retry rehydrates it from the canonical
Capture record and revalidates the current owner revision; it does not rebuild
identity from weaker text hints. A changed or retired canonical owner produces a
bounded review/blocker rather than silent reassignment.

The continuation service may emit
`CAPTURE_SUBJECT_RECONCILIATION_VIDEO_REQUIRED` only for a Capture whose
persisted intent is `VIDEO`.

### 3.3 Canonical media and placement convergence

The physical asset path is:

`MediaAsset → Media → MediaUsage → exact target/role/placement → WordPress projection`.

The same canonical Media identity may be reused across placements. A native
WordPress attachment or `_thumbnail_id` is storage/projection evidence only; it
does not become canonical `MediaUsage` without reconciliation. Conversely, an
applied `featured_primary` or inline usage must project to the corresponding
native WordPress placement and be verified by read-back.

Article placement identity is deterministic and durable across compose, retry,
revision and publish. Desired usage reconciliation is revision-aware and
idempotent for add, replace, remove and representative binding. The staging
packet is server-issued from the exact Capture fingerprint and propagates
through proposal, approval, Controlled Apply and final read-back.

### 3.4 Article composition and field contract

The canonical field precedence is:

`explicit request → approved/generated editorial value → body inference`.

This applies to title, excerpt, slug, H1, meta description, alt text and
caption. Native WordPress field aliases are normalized once at the Article
boundary. Unknown fields fail clearly; successful mutation requires canonical
read-back equality for every requested field. The Composer remains a
projection/composition service and never becomes an editorial storage owner.

### 3.5 State token, gate and URL lifecycle

After every Article or MediaUsage mutation, the coordinator replaces its
cached Article state with the returned canonical read-back token. A retry after
an external Article edit rehydrates the WordPress owner before composing or
publishing.

Draft route reservation and public route activation are separate states:

`native draft route reservation → publication eligibility → publish → canonical
route activation/read-back → rendered public verification`.

The publication gate consumes current owner evidence only. Each blocker carries
an owner, machine-readable cause and remediation signal. Optional enrichment is
reported as warning/deferred repair; only contract-required dependencies block.

### 3.6 Completion and receipts

Phase receipts remain append-only. The effective current outcome is selected by
owner identity and current receipt marker, not by an old failure snapshot.
`required_owners` is built only after owner creation/read-back, so an Article
owner cannot be represented by an empty ID. Completion is true only when every
required owner has canonical read-back, dependency/usage state, public state
and frontend state required by that intent.

## 4. Implementation slices

1. Add intent-isolation regression coverage and fix the common coordinator
   Video invocation boundary.
2. Add exact-subject retry and canonical state-token regression coverage;
   repair any stale rehydration path found by the tests.
3. Add Article MediaUsage/placement convergence coverage for new, reused and
   multi-image Media, including native featured/inline read-back.
4. Add Article field normalization/read-back coverage and publication/route/
   completion convergence coverage.
5. Run the complete local quality gate and update the execution ledger with
   evidence. Only then assess whether an environment-backed guarded integration
   or deployment verification is possible.

Each slice is implemented as a small shared-boundary change with a failing
regression test first. No identity-specific fixture branch, generic writer,
direct database mutation or Governance bypass is permitted.

## 5. Verification plan

Required local checks:

- focused failing-then-passing Unit/Contract tests per slice;
- full Unit and Contract suites;
- guarded Integration suite only when exact `nhk_v3_test` configuration is
  available;
- PHP lint for changed files;
- migration checks only if a schema change is proven necessary;
- `git diff --check` and secret review;
- frontend/public route smoke only for changed route behavior.

The generalized regression must cover: existing Media reuse, exact subject,
explicit title, featured and inline placement identity, Article edit before
retry, publication review, publish retry, public URL read-back and final
rendered read-back. It must also prove TEXT-only, MEDIA_ENRICHMENT, VIDEO,
KNOWLEDGE_DELTA and KNOWLEDGE_REPAIR remain isolated.

## 6. Non-goals and safety gates

- No Constitution or normative contract changes.
- No legacy article-body import, V2 mutation, production mutation or broad
  staging acceptance.
- No hard-coded Post ID, Media ID, Capture ID, subject name or fixture-specific
  branch.
- No final production cutover claim. Deployment/runtime identity and external
  MCP acceptance remain `UNVERIFIED` until independently available and
  read-back verified.

## 7. Open verification risks

The repository's execution ledger records recent local readiness but also
environment-gated WordPress bootstrap/database and deployment limitations.
Those are verification constraints, not authorization to weaken fail-closed
behavior. If a required integration/runtime dependency remains unavailable,
the final report will distinguish local proof from `UNVERIFIED` external proof.
