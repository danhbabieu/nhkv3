# NHK V3 Current Runtime State

Status: `DICTIONARY_STORAGE_AND_DISPLAY_COMPLETE_ON_STAGING`
Verified at: `2026-10-04` (fresh staging state)
Deployed commit: `5294b44aa998614d37802b11c5c9dcb4b69f0893`

Dictionary staging read-back:

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

Boundaries preserved:

- No materialization rerun.
- No owner-subsystem mutation.
- No production operation.
- No additional data mutation for this closure.

Known residual:

`KNOWN_P2_DATA_CLEANUP: Selection cam locale vi-VN → en`

This is a P2 data-cleanup item only. It does not affect current
storage/display acceptance and is deferred without code, migration, redesign,
owner review, `NO_OWNER` processing or `côn` ambiguity resolution.
