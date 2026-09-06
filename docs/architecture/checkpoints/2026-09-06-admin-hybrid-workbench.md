# NHK V3 Admin Hybrid Workbench checkpoint — 2026-09-06

> **NON-NORMATIVE EXECUTION EVIDENCE.** The Constitution and current registered contracts remain authoritative.

## Scope

Implemented a task-first WordPress Admin shell for NHK V3 without changing canonical ownership, semantic vocabulary, Governance lifecycle, migrations, production data or runtime semantic writers.

## Implemented

- NHK V3 top-level Admin now opens a task-oriented Workbench dashboard.
- One central registry defines section order, labels, owners, required capabilities and safe destinations.
- WordPress Posts remains the editorial destination; WordPress Media Library remains the attachment destination.
- Existing technical `AdminPage` remains available under `Nâng cao`.
- Existing Dictionary curation remains on its current capability-gated runtime boundary.
- Existing semantic dossier coverage audit is surfaced as read-only `Hồ sơ dữ liệu`.
- Presentation state stack keeps independent ready/attention/blocked/neutral rows and does not create domain enums.
- CSS and progressive-enhancement JavaScript live in dedicated Admin assets; the new Workbench makes no mutation request.
- Legacy top-level Admin callback is explicitly detached before the `nhk-v3` slug is reused, preventing the old technical page and new dashboard from rendering together.

## Writer and data safety

New Workbench presentation classes contain no direct semantic SQL mutation path and no generic repository writer shortcut. Admin remains an adapter. Existing semantic mutations still use the registered Governance/application boundaries and canonical read-back.

No production/staging/V2 data, migration, Graph edge, Knowledge/Evidence record, Media identity, Video identity, public route or WordPress content was created or changed by this implementation work.

## Verification evidence

GitHub Actions run `34009672366` on commit `0dd5beae249ad79d7e415a8eac23847273abf56e` completed successfully:

- Composer install: PASS
- Composer validation: PASS with the repository's existing missing-license warning
- `git diff --check origin/main...HEAD`: PASS
- PHP lint: PASS
- NHK Unit: **587 tests / 2,822 assertions / 0 failures**, with 2 warnings and 5 PHPUnit deprecations

A prior run exposed trailing-whitespace failure in the design document; it was corrected before the green verification run.

## Runtime acceptance boundary

`CODE_READY` is supported by the CI evidence above. This checkpoint does **not** claim target WordPress browser/runtime acceptance, deployment acceptance or production data mutation. Those remain separate runtime/deployment concerns.
