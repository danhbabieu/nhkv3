# NHK V3 Admin Control Plane Design

Status: approved design, 2026-09-06

## Goal

Build a Vietnamese-first, Constitution-compliant Admin control plane for
Governance, editorial publication, semantic curation, Media/Video intake and
operations without creating a second canonical writer or exposing internal
operational data to public readers.

## Scope and delivery slices

The Admin is delivered as five independently testable slices:

1. Admin Shell + Governance Workspace.
2. Editorial Article + Publication Workspace.
3. Semantic Entity, Knowledge, Source/Evidence and Graph Workspace.
4. Media + Video Workspace.
5. Operations, Audit, Privacy and Accessibility QA.

The first implementation slice is the shell and Governance Workspace. Later
slices consume the same navigation, capability, diagnostic, confirmation,
loading, empty and unavailable-state primitives.

## Non-negotiable boundaries

- WordPress `wp_posts` remains the only owner of editorial title, body, author,
  dates, categories, archives, search, RSS, sitemap and editorial URLs.
- Authority owns canonical entity identity and lifecycle; Graph owns typed
  relations; Knowledge owns claims; Source/Evidence owns provenance; Media,
  MediaAsset, MediaUsage and Video retain separate ownership.
- Admin is an orchestration and diagnostic adapter. It must call existing
  application services and the same capability source as MCP.
- Semantic mutations use Proposal → Human Approval → Eligibility → Controlled
  Apply → repository → audit, with revision, idempotency and read-back.
- Unknown registry values, malformed UUIDs, ambiguous resolution, unavailable
  dependencies and infrastructure uncertainty fail closed with typed
  diagnostics.
- No legacy article-body import, semantic backfill, Graph repair, identity
  merge, Product–Specimen shortcut, production/staging mutation or public
  identity activation is included.

## Information architecture

The WordPress Admin menu contains one NHK V3 entry with these workspaces:

- **Tổng quan**: runtime health, capability availability, pending review,
  recent failures and explicit environment state. No invented metrics.
- **Governance**: proposal inbox, filters by lifecycle/outcome, proposal
  detail, dependency graph, fingerprints, diagnostics, audit and actions.
- **Biên tập**: native Article draft/preflight/publication gate; publication
  decision states are `PASS`, `OWNER_REVIEW_REQUIRED` and `SYSTEM_BLOCKED`.
- **Semantic**: canonical entity lookup, Knowledge claims, Source/Evidence and
  read-only Graph traversal. Resolution order is UUID → stable key → exact
  canonical name/alias; ambiguity displays candidates and blocks action.
- **Media & Video**: governed Media intake and external Video intake, with
  source-original, derivative, usage role and readiness distinctions.
- **Vận hành**: migration ledger, audit/read-back evidence, runtime contract
  gaps and privacy/accessibility checks.

Navigation items are capability-aware. A missing capability hides mutation
controls but leaves a truthful read-only explanation; it never silently
substitutes a weaker operation.

## Governance Workspace UX

The default screen is a review inbox rather than a raw JSON console.

Each proposal row shows a human label, domain/operation, subject display name,
state, revision, dependency status, eligibility outcome, last apply status and
updated time. Internal UUID/stable-key values are available in a collapsible
“Chi tiết kỹ thuật” region with copy buttons and accessible labels.

The proposal detail layout is:

1. Header: subject, operation, lifecycle badge, revision and capability state.
2. Decision banner: exact `PASS`, `OWNER_REVIEW_REQUIRED` or `SYSTEM_BLOCKED`
   result, with owner-facing explanation.
3. Binding card: expected revision, proposal revision, content fingerprint,
   dependency fingerprint, idempotency key and approval expiry.
4. Dependency/readiness panel: direct dependency IDs, status and typed reason
   codes; no inferred “ready” state.
5. Proposed change: allow-listed fields rendered as labels and values; raw
   payload is secondary and escaped.
6. Audit timeline: submit, review, approval, eligibility, apply attempt,
   read-back and final result, including authenticated principal and timestamps.
7. Action bar: only actions allowed by capability, lifecycle and current
   revision; every mutation has pending, success, failure and uncertain
   transition states.

Action semantics:

- `Submit` and `Approve` preserve the returned fingerprints and revision.
- `Eligibility` is read-only and refreshes the exact diagnostic registry
  result.
- `Controlled Apply` is available only when approval and eligibility permit it.
- `Reject` records a governed decision and never deletes the proposal.
- Owner publication approval is distinct from semantic approval and cannot
  override authentication, authorization, identity, CAS, security or
  infrastructure blockers.

## Editorial, semantic and asset UX rules

The Editorial workspace must keep title/body editing native to WordPress while
showing V3 preflight and publication diagnostics beside it. Claim compliance
is meaning- and evidence-based, not a keyword-only blacklist. Unsupported
absolute/leadership claims are narrowed or blocked for review; the UI does not
pretend that generated text is Evidence.

The Semantic workspace distinguishes canonical identity, display name, stable
key and public slug. Graph screens show only registered endpoint/predicate
options, direction, cardinality, direct/derived classification and bounded
path explanation. No relation is created from a label, filename, OCR,
caption, visual similarity or taxonomy.

The Media/Video workspace makes source-original, derivative, Media identity,
MediaUsage role, attachment projection, representative/evidence/technical
role, external Video identity and readiness visibly different. Upload is
governed and idempotent; corrupted or unreadable bytes show a blocking error.

## Legal and compliance requirements translated to UX

- Public promotional copy review must surface the legal-policy version and
  evidence scope before publication. The UI must not imply legal clearance
  merely because a form is complete.
- Personal data fields are minimized, purpose-labelled, access-controlled and
  redacted from general operational views. Audit shows principal and event
  metadata needed for accountability, not unnecessary personal payloads.
- Approval and electronic operational actions retain timestamp, principal,
  input binding, integrity fingerprints and durable read-back status. UI must
  distinguish completed, failed and uncertain transitions.
- Vietnamese is the default interface language. Technical codes remain
  available as secondary diagnostics for operators.
- All controls have labels, keyboard focus, semantic headings, non-color-only
  status, sufficient contrast, touch-safe targets and reduced-motion behavior.

These requirements are implementation interpretations, not legal advice. The
legal-policy version used at publication time is a runtime/configuration input
and must not be fabricated by the frontend.

## State model and error handling

Every workspace supports the same explicit states:

- loading: action-specific progress and disabled duplicate submission;
- empty: no matching records, with scope and reset action;
- unavailable: dependency/runtime capability unavailable, with reason and safe
  next step;
- blocked: exact typed diagnostic and remediation, no unsafe action;
- conflict: stale revision/fingerprint with refresh and re-review path;
- uncertain: external/native transition cannot be proven, no blind retry;
- success: read-back evidence and durable result, never optimistic-only copy.

Errors are rendered from diagnostic definitions where available. Unknown or
malformed diagnostics are shown as system-blocked and logged for operators.

## Architecture and file boundaries

The first slice should extract the current `AdminPage` responsibilities into
focused view/controller classes while retaining WordPress Admin hooks:

- `Infrastructure/Admin/AdminPage.php`: menu registration and shell routing.
- `Infrastructure/Admin/AdminCapabilityView.php`: capability manifest projection.
- `Infrastructure/Admin/GovernanceWorkspacePage.php`: inbox/detail/read-back
  composition.
- `Infrastructure/Admin/AdminDiagnosticPresenter.php`: typed diagnostic to
  Vietnamese operator copy, severity and remediation.
- `Infrastructure/Admin/assets/admin.css` and `admin.js`: progressive,
  accessible presentation and fetch interactions; no domain decisions.

Existing repositories and application services remain the only data access
path. New endpoints are not introduced unless an existing read/action boundary
is genuinely missing and separately reviewed against the registry/contracts.

## Verification acceptance

- Focused tests prove capability-gated visibility, lifecycle action matrix,
  stale revision handling, typed diagnostic mapping and truthful unavailable /
  uncertain states.
- Integration tests prove proposal lifecycle/read-back parity and no duplicate
  idempotent action.
- PHP lint, focused PHPUnit, full relevant test suite, `git diff --check`,
  secret review and responsive accessibility QA are required at checkpoints.
- No parity claim is made without reading current execution state and parity
  matrix and recording fresh evidence.
