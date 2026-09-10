# Editorial Capture addendum design — 2026-09-10

## Decision

An addendum is a governed continuation of an existing Capture, not a new
submission operation. The normal entry point remains `nhk.capture.ingest`.
The continuation request supplies the existing `capture_id`, its own
`idempotency_key`, and new text/subject hints/observations; it contains no
files. The original Capture request fingerprint and raw request remain
immutable.

## Boundary and sequence

```text
addendum request
→ resolve existing Capture and Article
→ bind addendum fingerprint/idempotency
→ append Capture audit/revision
→ bounded subject/Graph/Claim reconciliation
→ existing Governance review packet for semantic delta
→ reconcile the existing WordPress Article draft
→ reconcile MediaUsage without upload/re-adoption
→ publication gate
→ final read-back of the same Capture and Article
```

The addendum reuses the existing `EditorialCaptureCoordinator` and all injected
canonical owners. It never calls a generic/direct writer, creates a second
Capture/Post, changes the original request fingerprint, or silently applies a
semantic mutation. Candidate Claims are searched/reused by canonical identity;
new user knowledge remains `EXPLICIT_USER_KNOWLEDGE` and follows the existing
Governance proposal lifecycle.

## Idempotency and failure

`nhk_editorial_capture_addenda` stores the addendum identity, target Capture,
request fingerprint, lifecycle status, sanitized audit payload and resulting
Capture revision. The completed audit event in the Capture context points to
that same resulting revision, after the audit append is saved. Same addendum
key plus the same payload returns the recorded result; the same key plus a
changed payload returns `IDEMPOTENCY_CONFLICT`. A Capture target that is
missing, published, or unavailable fails closed. Rejected addenda retain only
the bounded `text`, `subject_hints`, `observations` and `metadata` payload for
audit; file metadata and paths are never persisted. Addenda with files are
rejected so physical assets cannot be re-uploaded or re-adopted by the
continuation path.

## Data and governance invariants

Only the existing Capture context/audit and additive addendum ledger are
changed by the continuation boundary. WordPress Post 342 remains the editorial
owner and remains draft. Media is reused only from the existing Capture asset
set; a text-only addendum cannot adopt unrelated Media. Semantic deltas return
the existing review packet and are eligible for the existing
Proposal → Submit/Review → Approve → Eligibility → Controlled Apply lifecycle.
No new entity type, predicate, relation type, or semantic owner is introduced.
