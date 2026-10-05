# NHK V3 Current Runtime State

Status: `SEMANTIC_PENDING_ACCEPTANCE_GO / SEMANTIC_LIFECYCLE_PASS /
SYNTHETIC_CLEANUP_COMPLETE / LEXICAL_CONNECTOR_SCHEMA_REFRESHED`

Verified at: `2026-10-05`

Deployed TEST source revision:
`06a3cecd3f7bec11e9118ef77a795a669057c71a`

Environment: `staging`

TEST site: `https://demo.1945.vn`

Semantic write policy: `PROJECT_BUILD`

## Semantic acceptance

Capture-bound Source, Knowledge and Evidence proposals were live-verified on
TEST. Minimal proposals carried `payload.capture_id`; Capture fingerprint and
revision were persisted/server-derived authorization metadata. Each returned
`ready=true`, with no remaining `STAGING_SCOPE_REQUIRED`,
`STAGING_CAPTURE_REQUIRED`, `STAGING_DEPENDENCY_SCOPE_MISMATCH` or
`STAGING_SCOPE_NOT_ADMITTED` on the accepted path.

The governed semantic relation lifecycle passed:

`ADD → READ → idempotent replay → RETIRE → READ → REACTIVATE → READ`

The GraphRelationContext lifecycle and canonical read-back passed. The valid
test provenance was `EXPLICIT_USER_KNOWLEDGE`; `SYNTHETIC_TEST_ONLY` is not a
valid provenance enum. Exact replay returned `idempotent_replay=true`.

All acceptance-created synthetic Source, Knowledge, Evidence, edge and context
records were cleanup-retired. No audit/proposal history was deleted. The
pre-existing synthetic Capture and Movement
`01a10a73-942f-7218-8655-73dc22b4fafb` were not deleted, retired or otherwise
modified beyond the allowed TEST relation-target use.

## MCP and lexical status

The live TEST MCP path verified proposal discovery, eligibility, controlled
apply, semantic relation preview/apply/read, idempotent replay and cleanup
controlled retirement.

The connector schema refresh is visible for:

- `nhk.dictionary.lexical_relation.read`: `relation_uuid`, `idempotency_key`
- `nhk.dictionary.lexical_relation.preview`: `relation_uuid`,
  `expected_revision`

Lexical lifecycle acceptance was not rerun. The known lexical server-code
blocker is closed; this status is schema/readiness evidence only.

## Existing Dictionary state

Dictionary staging read-back remains:

- Entries: `30`
- Duplicate Entries: `0`
- Forms: `80`
- Lexical READY remaining: `0`
- Semantic references AVAILABLE: `3`
- Semantic references INVALID/STALE: `0`
- `NO_OWNER`: `26` — retained lexical-only
- `AMBIGUOUS`: `1` (`côn`) — retained for review; no owner selected
- Final 45 lexical Forms: applied successfully
- `400 ngày`: owner, Forms and public projection complete
- Public/search behavior: working

Boundaries preserved: no materialization rerun, no owner-subsystem mutation,
no production operation, and no additional data mutation for this closeout.

Known residual:

`KNOWN_P2_DATA_CLEANUP: Selection cam locale vi-VN → en`

This is a P2 data-cleanup item only. It does not affect current
storage/display acceptance and is deferred without code, migration, redesign,
owner review, `NO_OWNER` processing or `côn` ambiguity resolution.

## Next state

`READY_FOR_CON_HOA_THI_CANARY_PLAN`

This means canary planning may begin. It does not mean `Côn hoa thị` canary
completion, production acceptance, production rollout authorization or any
production mutation. Production and `Côn hoa thị` were not touched.
