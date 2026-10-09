# NHK V3 — Executable Music Intake Parity Results

**Status:** local implementation evidence; runtime acceptance blocked.

## Local parity

| Check | Result |
|---|---|
| 26 Music categories remain A–Z | PASS |
| Existing Music field keys/status vocabulary preserved | PASS |
| Field metadata is explicitly intake-only | PASS |
| Music/Dictionary/Score-Audio worksheets use transient boundaries | PASS |
| Source locator, evidence excerpt and instruction separation | PASS through existing structured interpreter boundary |
| Dictionary lexical observation remains distinct from Knowledge/Graph | PASS by contract and worksheet assertions |
| Score/audio rights and governed-delivery distinction | PASS by reference/readiness contract and worksheet |
| Public dossier reader-safe projection | PASS by existing `MusicDossierProjection` allowlist tests |
| Westminster/Sonodo/Ave Maria examples are non-authorizing | PASS by worksheet and standard boundaries |

## Commands

Verified locally on 2026-10-09:

- Combined Music/Dictionary/Score-Audio/structured-intake/public
  projection/documentation-registry selection: 78 tests / 2,505 assertions,
  pass; 3 PHPUnit deprecations and 1 PHPUnit deprecation.
- `composer generate:mcp-docs`: 64 canonical documentation files generated;
  `source_revision=5eaf21c5a2f4ce63a54fecb7ab07dcc49a5663cd`; the emitted
  manifest hash is recorded by the generated snapshot.

The closeout check set is:

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/MusicCoverageAssessmentTest.php public/wp-content/plugins/nhk-core/tests/Unit/MusicDossierProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/McpDocumentationRegistryTest.php
php -l public/wp-content/plugins/nhk-core/src/Application/Entity/MusicDataCollectionStandard.php
git diff --check
composer generate:mcp-docs
```

PHP lint and `git diff --check` remain required before the task commit. The
repository-wide Unit baseline is not used to convert local documentation
evidence into runtime acceptance; any unrelated baseline identities must remain
separately classified.

The generated snapshot is immutable release output and must never be hand-edited.

## Blockers and next acceptance step

The authorized TEST runtime identity, deployed build identity, signed exact
Capture packet and canonical owner/evidence/revision read-back are not proven in
this local workspace. No live public Music acceptance is claimed. The next step
is a fresh read-only runtime bootstrap followed, only when explicitly bounded
and authorized, by governed acceptance and canonical read-back.

No staging/production mutation, deployment or push occurred for this evidence.
