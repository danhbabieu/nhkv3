# Dictionary Entry/Sense implementation plan

Date: 2026-10-03

## Objective and guardrails

Deliver a small, testable Entry/Sense runtime slice while preserving
Migration015 as the compatibility source. `DictionaryConcept` remains the
durable Sense identity; no parallel Sense owner, Graph endpoint, Evidence
store, URL identity, data backfill, staging mutation, deployment, or V2 data
change is allowed.

The implementation is intentionally split into structure and read behavior.
The additive schema creates future storage boundaries but does not populate
semantic mappings. A missing mapping is represented by a deterministic,
read-only one-Concept compatibility Entry; this is not an implicit migration.

## Files and responsibilities

### Slice A — additive schema

- Add `Infrastructure/Migration/DictionaryEntrySenseMigration024.php`.
  Create `nhk_dictionary_entries`, `nhk_dictionary_forms`, and
  `nhk_dictionary_entry_senses` with UUID/revision/state/index constraints.
  `entry_senses.concept_uuid` points to existing Concept identity by value;
  no foreign-key rewrite or data population is performed.
- Register migration 024 in `Plugin`’s target, pending runner and activation
  path. Keep Migration015 unchanged and make `schemaReady()` idempotent.
- Add migration unit coverage. Guarded integration read-back remains a
  verification gate and is not invoked without `NHK_WP_TEST_PATH` plus the
  exact `nhk_v3_test` database; no new integration fixture performs cleanup or
  mutation in an unavailable environment.

Acceptance: repeated `up()` is safe; all four Migration015 tables are still
untouched; no insert/delete/backfill is issued by 024; UUIDs remain binary
values at the boundary.

### Slice B — Entry/Form read model and repository

- Add immutable `LexicalEntry` and `LexicalEntryForm` domain values. Entry owns
  preferred wording; forms are lookup surfaces. Concepts are exposed as Sense
  references, not copied into a new owner.
- Add `DictionaryEntryRepository` and `WpdbDictionaryEntryRepository`.
  Reads use durable 024 rows when present. `findForConcept()` and
  `findByForm()` fall back to a virtual one-entry/one-sense compatibility
  projection from Migration015 only when no mapping exists.
- Keep writes out of this slice so the old Concept preferred label cannot
  diverge from a new Entry writer. Any future write must use one explicit
  owner and CAS/read-back.

Acceptance: Migration015-only data resolves without creating rows; equal
labels do not group Concepts; malformed/stale mappings return an unavailable
or ambiguous result rather than guessing.

### Slice C — Entry/Sense resolver

- Add an Entry/Sense resolver service that normalizes a raw term, finds Entry
  forms, evaluates mapped Concepts in context, and revalidates the existing
  semantic destination through the supplied owner callback.
- Return `RESOLVED` only for one viable Sense, `AMBIGUOUS` for multiple viable
  Senses or insufficient context, and `UNKNOWN`/`UNAVAILABLE` otherwise.
  Preserve the existing Concept-only resolver and MCP behavior unchanged.
- Add unit tests for one-to-one compatibility, multi-sense ambiguity,
  context filtering, destination failure, URL-not-identity, and deterministic
  repeated results.

Acceptance: no auto-link on ambiguity; no Concept merge; owner route is
derived/revalidated at read time; Knowledge references remain references and
their claim text is not copied.

### Slice D — documentation/runtime status

- Update the Dictionary architecture/contract, documentation status index,
  execution state, and MCP operations ledger with exact code-side evidence.
- Mark only schema/domain/read-resolver behavior as
  `IMPLEMENTED_CODE_SIDE`/`TESTED_LOCAL` when tests prove it. Keep public
  Entry routes, Entry/Sense MCP mutations, full Knowledge destination write
  lifecycle, governed Dictionary Media binding, and Video association as
  explicit implementation gaps unless independently verified.

## Verification gates

Run focused Dictionary unit tests, migration tests, resolver/repository tests,
PHP lint on changed PHP files, `git diff --check`, and the full unit suite when
resources permit. Run guarded integration tests only when the configured test
WordPress/MySQL environment exists. Report exact counts and pre-existing or
environment-gated failures separately. Review the final diff for Graph,
Evidence, URL-identity, duplicate-owner, auto-merge, and data-mutation
violations.

## Explicitly deferred

No public `/tu-dien/{entry-slug}/` rendering cutover, legacy redirect rewrite,
new MCP mutation operations, MediaUsage target expansion, Dictionary Video
relation, or Knowledge claim write is included in this slice. These require
their own contract-compliant vertical slices after the read model is proven.
