# Universal Music Dossier Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add one read-only, reusable Music dossier projection and accessible frontend template, with fail-closed score/audio validation seams and no Westminster-specific semantic mutation.

**Architecture:** Extend the existing detail-only `SemanticDossierQuery` output through a `MusicDossierProjection` registered at the established entity-detail filter. Keep Authority, Knowledge, Source/Evidence, Graph, Media, Video, Dictionary, WordPress, SEO, and Public Identity as the existing owners; add only pure presentation metadata and validation. Render Music entities through a shared theme partial that omits empty sections and never branches on a Music name or stable key.

**Tech Stack:** PHP 8+ domain/application classes, PHPUnit, WordPress theme PHP/CSS, existing NHK public projection helpers and PHPUnit bootstrap.

**Spec:** `docs/superpowers/specs/2026-10-08-universal-music-dossier-design.md`

## Global Constraints

- The Music dossier is a read-only projection; it must not mutate Authority, Graph, Knowledge, Source, Evidence, Media, Video, Dictionary, WordPress posts, or public identities.
- WordPress native posts remain the sole source of editorial title, body, dates, archives, search, RSS, sitemap, and editorial URLs.
- Only registered Graph predicates and approved relation paths may be projected; reachability alone never authorizes semantic truth.
- Direct Music claims remain Music-scoped; Variant, Model, Specimen, Movement, and Brand claims must not be promoted to Music or Brand scope.
- Public output must omit UUIDs, stable keys, revisions, lifecycle/state fields, private provenance/metadata, and internal diagnostics.
- Empty or unavailable sections are omitted; invalid/private/ineligible records fail closed without placeholders or fallback writes.
- Score/audio data must never be generated from the Westminster research agenda or pitch classes alone.
- Piano, bell simulation, and historical recording are separate verification claims; a synthesized bell must be labeled as a simulation.
- Do not add an Authority payload field, schema migration, new Graph predicate, audio binary owner, or deployment in this slice.
- Public copy is Vietnamese-first, semantic HTML is accessible, and browser/runtime claims require actual browser/runtime evidence.
- Preserve the existing untracked file `docs/superpowers/plans/2026-10-08-fix-unsafe-knowledge-materialization.md`.

## Review Focus

- A malformed score packet must not expose partial note data or playback controls — covered by `MusicReferenceContractTest::test_score_packet_fails_closed_when_event_timing_or_pitch_is_invalid` in Task 1.
- A valid score with an unverified or rights-missing audio reference must expose no playable audio — covered by `MusicReferenceContractTest::test_audio_requires_public_rights_and_verification` in Task 1.
- A derived Variant/Movement path must not become a direct Music claim or Brand-wide capability — covered by `MusicDossierProjectionTest::test_projection_preserves_scope_and_relation_origin` in Task 2.
- A Music entity with no score, audio, dictionary, or related-melody data must still render the other available sections without placeholders — covered by `MusicDossierProjectionTest::test_projection_omits_empty_sections_without_hiding_existing_relations` and the template contract in Task 3.
- A second Music entity such as Sonodo must use the same profile/partial without a Westminster string or stable-key conditional — covered by `EntityProfileRegistryTest::test_music_profile_declares_the_universal_section_recipe` and `FrontendPresentationContractTest::test_music_template_is_generic_and_wired_without_westminster_branch` in Tasks 2–3.

---

### Task 1: Versioned score and audio reference contract

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Entity/MusicReferenceContract.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MusicReferenceContractTest.php`

**Interfaces:**
- Produces `MusicReferenceContract::normalize(array $packet): array` returning `['status' => 'AVAILABLE'|'EMPTY'|'INVALID', 'score' => ?array, 'audio' => list<array<string,mixed>>, 'errors' => list<string>]`.
- `score` requires non-empty `version`, non-empty `source`, non-empty `verification_status`, non-empty `tuning`, positive `tempo_bpm`, a non-empty `events` list, and non-empty `segments` when present.
- Each score event requires `pitch_class` matching `^[A-G](?:#|b)?$`, integer `octave`, integer `start_ms >= 0`, integer `duration_ms > 0`, and non-empty `phrase`.
- Each optional segment requires non-empty `key`/`label`, integer `start_ms >= 0`, and integer `end_ms > start_ms`.
- Each audio item requires `mode` in `PIANO|BELL_SIMULATION|HISTORICAL_RECORDING`, a non-empty `score_version`, `instrument`, `render_method`, `tuning`, `pitch_reference`, `tempo_bpm > 0`, positive `duration_ms`, `source`, `rights`, and `verification_status`; only `verification_status=VERIFIED` and non-empty `rights` are public-eligible.

- [ ] **Step 1: Write the failing tests**

  Add tests for:

  - a complete score plus Piano and Bell simulation packet normalizing to `AVAILABLE` while preserving ordered events and segment boundaries;
  - invalid pitch class, negative start, zero duration, missing version, and inverted segment bounds returning `INVALID` with no public score;
  - an empty packet returning `EMPTY` without inventing a default score or audio item;
  - audio references with `verification_status=UNVERIFIED` or blank `rights` being omitted from the playable `audio` result;
  - `HISTORICAL_RECORDING` remaining distinct from `BELL_SIMULATION`.

- [ ] **Step 2: Run the focused test to verify it fails**

  Run: `vendor/bin/phpunit -c phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/MusicReferenceContractTest.php`

  Expected: FAIL because `MusicReferenceContract` does not yet exist.

- [ ] **Step 3: Implement `MusicReferenceContract::normalize(array $packet): array`**

  Keep validation deterministic and pure. Copy only validated public-safe fields into the result; never pass through arbitrary metadata, URLs, internal IDs, or private provenance. Return `INVALID` for malformed score structure and `AVAILABLE` with only eligible audio entries when the score/audio packet is otherwise usable.

- [ ] **Step 4: Run the focused test to verify it passes**

  Run: `vendor/bin/phpunit -c phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/MusicReferenceContractTest.php`

  Expected: PASS with all reference-contract tests green.

- [ ] **Step 5: Commit**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Entity/MusicReferenceContract.php public/wp-content/plugins/nhk-core/tests/Unit/MusicReferenceContractTest.php
  git commit -m "feat(nhk-v3): validate music score and audio references"
  ```

### Task 2: Music profile and read-only dossier projection

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Entity/MusicDossierProjection.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/MusicDossierProjectionTest.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityProfileRegistry.php` in the Music definition
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EntityProfileRegistryTest.php`

**Interfaces:**
- Consumes `MusicReferenceContract::normalize()` from Task 1 and the existing dossier packet shape produced by `SemanticDossierQuery`.
- Produces `MusicDossierProjection::forEntity(AuthorityEntity $entity, array $dossier, ?array $referencePacket = null): array`.
- The returned packet keeps the base dossier unchanged and adds `music_dossier` with `status`, `profile_key`, `section_order`, `sections`, `score`, `audio`, and `warnings`.
- `sections` uses the twelve stable keys `identity`, `audio`, `score`, `introduction`, `history`, `structure`, `clock_application`, `verified_clocks`, `library`, `research`, `sources`, and `related_melodies`; absent content is omitted from the emitted sections.
- Music profile metadata declares `music-universal-dossier-v1`, the twelve-section order, existing Music relation targets, and only read capabilities.

- [ ] **Step 1: Write the failing registry and projection tests**

  Add tests that:

  - assert the Music profile recipe key and exact twelve-section order;
  - build two active Music entities with different names (one Westminster-shaped, one Sonodo-shaped) and assert the same `music_dossier.profile_key` and section contract;
  - assert identity/description, direct public Knowledge, existing relation sections, media, video, and article context are retained;
  - assert `configured_with_music`, `supports_music`, and `observed_playing_music` relation origin is preserved and no derived child claim becomes a direct Music claim;
  - assert empty score/audio/dictionary/related-melody inputs omit only those sections while keeping available sections;
  - assert UUID/stable-key/revision/lifecycle/private metadata do not appear in `music_dossier`.

- [ ] **Step 2: Run the focused tests to verify they fail**

  Run: `vendor/bin/phpunit -c phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/EntityProfileRegistryTest.php public/wp-content/plugins/nhk-core/tests/Unit/MusicDossierProjectionTest.php`

  Expected: FAIL because the Music profile recipe is not yet specialized and `MusicDossierProjection` does not yet exist.

- [ ] **Step 3: Implement the Music profile declaration**

  Replace the generic Music profile entry with a dedicated `EntityProfileDefinition` using only current registered relation target groups and read capabilities. Keep the canonical archive path `/ban-nhac/`; do not change Authority type definitions or payload schema.

- [ ] **Step 4: Implement `MusicDossierProjection::forEntity()`**

  Start from an `AVAILABLE` base dossier only. Build section payloads from existing public-safe dossier fields and bounded relation origin metadata. Treat an injected reference packet as optional test/extension input; when absent or invalid, expose no score/audio section. Do not query or mutate repositories from this class.

- [ ] **Step 5: Run the focused tests to verify they pass**

  Run: `vendor/bin/phpunit -c phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/EntityProfileRegistryTest.php public/wp-content/plugins/nhk-core/tests/Unit/MusicDossierProjectionTest.php`

  Expected: PASS with registry and projection tests green.

- [ ] **Step 6: Commit**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Entity/MusicDossierProjection.php public/wp-content/plugins/nhk-core/src/Application/Entity/EntityProfileRegistry.php public/wp-content/plugins/nhk-core/tests/Unit/MusicDossierProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/EntityProfileRegistryTest.php
  git commit -m "feat(nhk-v3): add universal music dossier projection"
  ```

### Task 3: Runtime wiring and generic frontend template

**Files:**
- Create: `public/wp-content/themes/nhk-v3/template-parts/presentation/music-dossier.php`
- Create: `public/wp-content/themes/nhk-v3/music-dossier.js`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Frontend/EntityDossierBootstrap.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Presentation/PublicTemplateFamilyAssetManifest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/PublicTemplateFamilyAssetManifestTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php`
- Modify: `public/wp-content/themes/nhk-v3/functions.php`
- Modify: `public/wp-content/themes/nhk-v3/entity.php`
- Modify: `public/wp-content/themes/nhk-v3/entity.css`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendPresentationContractTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/PluginBootWiringTest.php` if wiring assertions require it

**Interfaces:**
- Consumes `MusicDossierProjection::forEntity()` from Task 2 through the existing `nhk_v3_entity_detail_projection` filter.
- Produces `dossier.music_dossier` for Music detail reads and renders it through `music-dossier.php` using the existing public URL/media/SEO helpers.

- [ ] **Step 1: Write the failing wiring and frontend contract tests**

  Assert that:

  - `EntityDossierBootstrap` constructs and invokes `MusicDossierProjection` without adding a second dossier query or writer;
  - `entity.php` includes the Music partial only by canonical entity type/profile data, not by Westminster name, stable key, UUID, or hardcoded content;
  - the partial contains Vietnamese-first semantic headings, accessible audio control labels, and empty-section guards;
  - the entity asset manifest includes `nhk-v3-music-dossier` only for the entity family and `functions.php` maps that handle to `music-dossier.js`;
  - CSS contains responsive Music dossier layout rules without horizontal-overflow suppression hacks;
  - score/audio controls are guarded by validated packet state.

- [ ] **Step 2: Run the focused frontend tests to verify they fail**

  Run: `vendor/bin/phpunit -c phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/FrontendPresentationContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/PluginBootWiringTest.php`

  Expected: FAIL because the Music projection is not wired and the partial/styles do not yet exist.

- [ ] **Step 3: Wire the projection in `EntityDossierBootstrap`**

  Instantiate one `MusicDossierProjection` and apply it after the existing base/Brand/Clock-Type dossier enrichment at the established detail filter. Only run it for `entityType === 'music'`; preserve all other entity behavior and do not add repository writes.

- [ ] **Step 4: Add the generic Music partial and scoped styles**

  Render the declared section order from `music_dossier`. Use the identity/overview, Knowledge, relation, Media, Video, Article, Dictionary, source/evidence, score, and audio packets supplied by the projection. Omit sections whose packet is empty/unavailable. If a validated score packet is present in this slice, expose its ordered event/segment table with accessible labels and timing data; do not call it engraved notation or invent MusicXML/SVG output. Label `BELL_SIMULATION` as a simulation and do not render a playable control for invalid/unverified audio.

- [ ] **Step 5: Integrate the partial without duplicate generic sections**

  In `entity.php`, route Music detail content through the partial while retaining the shared shell, canonical identity header, sidebar, public navigation, and existing archive branch. Guard the old generic duplicate blocks only for the active Music dossier; do not alter Brand fallback behavior or archive query behavior.

- [ ] **Step 6: Add progressive audio controls and register the asset**

  Add `music-dossier.js` as a progressive enhancement over native `<audio>`: wire play/pause/stop, segment selection through `data-start-ms`/`data-end-ms`, visible progress, repeat, playback-rate selection, original-tempo reset, and synchronized `aria-current` event highlighting when the validated packet supplies event timing. The script must remain inert when no validated audio element exists and must not synthesize or replace a source. Add the Music script handle to `PublicTemplateFamilyAssetManifest` and map it in `functions.php`; preserve existing asset families and dependency ordering.

- [ ] **Step 7: Run focused frontend tests**

  Run: `vendor/bin/phpunit -c phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/FrontendPresentationContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/PluginBootWiringTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicTemplateFamilyAssetManifestTest.php public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php`

  Expected: PASS with generic-template and wiring assertions green.

- [ ] **Step 8: Commit**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Infrastructure/Frontend/EntityDossierBootstrap.php public/wp-content/plugins/nhk-core/src/Application/Presentation/PublicTemplateFamilyAssetManifest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicTemplateFamilyAssetManifestTest.php public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/FrontendPresentationContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/PluginBootWiringTest.php public/wp-content/themes/nhk-v3/functions.php public/wp-content/themes/nhk-v3/music-dossier.js public/wp-content/themes/nhk-v3/template-parts/presentation/music-dossier.php public/wp-content/themes/nhk-v3/entity.php public/wp-content/themes/nhk-v3/entity.css
  git commit -m "feat(nhk-v3): render reusable music dossier template"
  ```

### Task 4: Verification, runtime evidence, and checkpoint documentation

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Test/verification: repository test and lint commands below

**Interfaces:**
- Consumes the completed projection, validator, wiring, and template from Tasks 1–3.
- Produces a dated execution-state checkpoint that distinguishes implemented, locally tested, runtime-read, deployed, and blocked states.

- [ ] **Step 1: Run the focused Music suite**

  Run: `vendor/bin/phpunit -c phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/MusicReferenceContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/MusicDossierProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/EntityProfileRegistryTest.php public/wp-content/plugins/nhk-core/tests/Unit/FrontendPresentationContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/PluginBootWiringTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicTemplateFamilyAssetManifestTest.php public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php`

  Expected: PASS with zero failures.

- [ ] **Step 2: Run changed-file PHP lint**

  Run: `find public/wp-content/plugins/nhk-core/src/Application/Entity public/wp-content/plugins/nhk-core/src/Infrastructure/Frontend public/wp-content/plugins/nhk-core/tests/Unit -type f \( -name '*.php' \) -print0 | xargs -0 -n1 php -l`

  Expected: every file reports `No syntax errors detected`.

- [ ] **Step 3: Run the full Unit and Contract suites**

  Run: `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' && vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Contract'`

  Expected: both suites exit 0; existing warnings/deprecations remain non-failing and are recorded if present.

- [ ] **Step 4: Run repository hygiene checks**

  Run: `git diff --check` and a credential-like secret scan over changed files using the repository’s established review pattern.

  Expected: no whitespace errors, credentials, private keys, or tokens; the pre-existing untracked plan remains unmodified.

- [ ] **Step 5: Perform read-only local runtime/browser QA if available**

  Verify the current Music route resolves its canonical identity and route; verify a populated Music dossier contains no internal identifiers; verify a Music entity without score/audio omits those sections; verify no page links or media URLs are broken at desktop/mobile widths. If the runtime or browser is unavailable, record `UNVERIFIED` rather than inferring success.

- [ ] **Step 6: Update execution state**

  Add a dated checkpoint to `docs/architecture/V3_EXECUTION_STATE.md` summarizing code changes, focused/full test counts, lint/diff/secret results, route/browser evidence, no-mutation status, and explicit blockers: no verified Westminster score/audio asset, no audio owner/ingestion contract, and no deployment.

- [ ] **Step 7: Run final verification and commit the checkpoint**

  Run: `git diff --check && git status --short --branch`

  Expected: only intended implementation/checkpoint changes are present plus the preserved pre-existing untracked plan; no deployment or semantic data mutation has occurred.

  ```bash
  git add docs/architecture/V3_EXECUTION_STATE.md
  git commit -m "docs(nhk-v3): record universal music dossier checkpoint"
  ```

## Execution Notes

- Execute tasks in order because Task 2 consumes the exact validator output from Task 1 and Task 3 consumes the exact dossier packet from Task 2.
- The implementation method must be selected after plan review. Native inline execution is recommended because the tasks share one projection packet and one frontend route, while the final whole-branch review still remains required.
- Any need to add a persistent score/audio owner, migrate Authority payloads, add a Graph predicate, mutate Westminster, or deploy is a plan conflict/stop condition requiring a new approved design.
- Before the first implementation task, load `superpowers:test-driven-development`; during execution, use `superpowers:executing-plans` for native execution or `superpowers:subagent-driven-development` for delegated execution.
