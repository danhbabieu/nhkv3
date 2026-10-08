# Westminster Universal Music Dossier V2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harden the read-only Music dossier into one safe, source-aware,
responsive renderer for Westminster, Sonodo, Ave Maria, and every future Music
entity without inventing canonical facts or audio ownership.

**Architecture:** Keep Authority, Knowledge, Source/Evidence, Graph, Media,
Video, Dictionary, and WordPress as the existing owners. Tighten the pure Music
reference contract and public allowlists, make the projection section-driven,
route Music before Dictionary detail, and let audio controls appear only for an
already public Media-backed delivery packet. Add a non-canonical Westminster
source map for research readiness; do not seed runtime data.

**Tech Stack:** PHP 8.5, PHPUnit 11, WordPress theme PHP/JavaScript/CSS,
existing NHK public projection and Media delivery helpers.

**Spec:** `docs/superpowers/specs/2026-10-08-westminster-universal-music-dossier-v2-design.md`

## Global Constraints

- The Music dossier is a read-only projection; it must not mutate Authority, Graph, Knowledge, Source, Evidence, Media, Video, Dictionary, WordPress posts, or public identities.
- WordPress native posts remain the sole source of editorial title, body, dates, categories, archives, homepage, search, RSS, sitemap and editorial URLs.
- No new Authority field, Graph predicate, endpoint type, audio binary owner, schema migration, staging seed, deployment, or push is part of this plan.
- Source-backed Westminster research is a non-canonical research map until it enters the governed Source/Evidence/Knowledge lifecycle.
- Public projection uses strict allowlists and omits UUIDs, stable keys, revisions, lifecycle/state, private metadata, private provenance, database IDs and diagnostics.
- Product, Specimen, Variant, Model, Movement and Brand remain distinct public types and relation contexts.
- A score is public only when its provenance and exact verification state satisfy the contract; pitch classes alone never authorize historical playback facts.
- Audio controls require an existing public Media/MediaAsset delivery packet; no raw external URL, browser beep, synthetic fallback, or unlicensed recording is emitted.
- Empty, unavailable, private, invalid and infrastructure-blocked states remain distinguishable and fail closed.
- Existing unrelated working-tree files, especially `docs/superpowers/plans/2026-10-08-fix-unsafe-knowledge-materialization.md`, remain untouched.

## Review Focus

- Music entities with `dictionary_detail` must still use the Music renderer — cover in Task 3 routing tests.
- A pre-normalized `status=AVAILABLE` packet must not bypass validation or expose a URL — cover in Task 1 and Task 2 projection tests.
- Product and Specimen relation items must remain distinguishable — cover in Task 2 projection/template tests.
- A library containing Media, Video and Article items must render every eligible owner-backed item — cover in Task 4 template contract tests.
- A valid-looking Westminster score/audio reference without governed provenance or public Media delivery must remain non-playable — cover in Task 1 and Task 4 tests.

---

### Task 1: Harden score and audio reference validation

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/MusicReferenceContract.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/MusicReferenceContractTest.php`

**Interfaces:**
- Consumes raw optional reference input through `MusicReferenceContract::normalize(array $packet): array`.
- Produces `status`, `score`, `audio`, and `errors`, containing only validated public-safe metadata.
- Never accepts a caller-provided status as proof of validation and never emits a raw URL as a playable source.

- [ ] **Step 1: Write failing tests for exact public verification rules.**

  Add tests asserting:

  - score `QUALIFIED`, `DRAFT`, `UNVERIFIED`, and blank verification states do not receive the verified public outcome;
  - `NaN`, positive infinity, negative infinity, negative/zero tempo, invalid octave range, overlapping events, out-of-order events, and out-of-range segments fail closed;
  - optional fields and arbitrary metadata are discarded;
  - audio accepts only the registered semantic modes, positive finite timing, non-empty provenance/rights, exact `VERIFIED` state, and a separate public-delivery packet when one is supplied;
  - audio input containing a raw `url` without a Media delivery proof produces metadata-only/non-playable output;
  - Piano, Bell Simulation and Historical Recording remain distinct.

- [ ] **Step 2: Run the focused tests and verify RED.**

  Run:

  ```bash
  vendor/bin/phpunit -c phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/MusicReferenceContractTest.php
  ```

  Expected: the new assertions fail against the current permissive score state,
  caller-status bypass, and raw URL behavior.

- [ ] **Step 3: Implement the minimal contract hardening.**

  Keep the method pure. Validate finite numbers with explicit finite checks,
  validate the selected event/segment ordering model, require the approved
  public score verification state, and copy only the named fields. Represent a
  playable source only as a safe owner-provided delivery object; otherwise
  retain metadata without a playable source.

- [ ] **Step 4: Run the focused tests and verify GREEN.**

  Expected: all `MusicReferenceContractTest` cases pass with no raw URL or
  caller-provided status bypass.

- [ ] **Step 5: Commit the contract task.**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Entity/MusicReferenceContract.php public/wp-content/plugins/nhk-core/tests/Unit/MusicReferenceContractTest.php
  git commit -m "fix(nhk-v3): harden music reference validation"
  ```

### Task 2: Make the Music projection source-safe and type-aware

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/MusicDossierProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/MusicDossierProjectionTest.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityProfileRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EntityProfileRegistryTest.php`

**Interfaces:**
- Consumes the existing public dossier packet and raw reference packet.
- Produces `music_dossier` with the V2 section order, strict public field
  allowlists, direct/derived relation origin, original object type, and
  validated score/audio metadata.
- `MusicDossierProjection::forEntity(AuthorityEntity $entity, array $dossier, ?array $referencePacket = null): array` remains read-only and backward-compatible at the call boundary.

- [ ] **Step 1: Write failing projection/profile tests.**

  Add assertions for:

  - the 13-section V2 recipe and the same recipe for Westminster, Sonodo, Ave
    Maria, and an empty Music fixture;
  - strict allowlisting of claims, evidence, dictionary, relation, Media,
    Video and Article items;
  - removal of nested internal IDs, lifecycle, state, revisions, private
    metadata, diagnostics and private provenance;
  - Product and Specimen retaining distinct `type` and reader labels;
  - a raw packet containing `status=AVAILABLE` still being normalized and
    invalidated when its score/audio is not valid;
  - `music_dossier` replacing stale prior projection data rather than being
    skipped by PHP array-union behavior;
  - `videos` and `articles` remaining in the library packet.

- [ ] **Step 2: Run the focused projection/profile tests and verify RED.**

  Run the two existing test files. Expected: failures expose the old 12-slot
  recipe, remove-only redaction, product/specimen collapse, status bypass and
  stale array-union behavior.

- [ ] **Step 3: Implement strict projection helpers.**

  Replace the remove-only `safeValue()` path with field-specific public-safe
  readers for claims, evidence, dictionary terms, relations, media, video and
  article cards. Use `array_replace()` or an equivalent explicit overwrite for
  `music_dossier`. Always normalize raw reference input inside the projection.
  Keep Source/Evidence policy ownership in the existing upstream boundary; do
  not copy private provenance into the new packet.

- [ ] **Step 4: Update the Music profile recipe and projection section mapping.**

  Use the exact V2 keys: `identity`, `introduction`, `history`, `structure`,
  `variants`, `clock_application`, `related_entities`, `score`, `audio`,
  `library`, `research`, `sources`, `related_melodies`. Keep registered Graph
  groups and relation origin; do not add predicates or semantic shortcuts.

- [ ] **Step 5: Run the focused projection/profile tests and verify GREEN.**

  Expected: all projection and profile tests pass, including redaction and
  object-type assertions.

- [ ] **Step 6: Commit the projection task.**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Entity/MusicDossierProjection.php public/wp-content/plugins/nhk-core/tests/Unit/MusicDossierProjectionTest.php public/wp-content/plugins/nhk-core/src/Application/Entity/EntityProfileRegistry.php public/wp-content/plugins/nhk-core/tests/Unit/EntityProfileRegistryTest.php
  git commit -m "fix(nhk-v3): make music dossier projection type-safe"
  ```

### Task 3: Route Music before Dictionary detail

**Files:**
- Modify: `public/wp-content/themes/nhk-v3/entity.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendPresentationContractTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php` only if the existing public-boundary assertions need a new route invariant.

**Interfaces:**
- Consumes the existing `nhk_v3_entity_detail_projection` result, including
  `entity.type`, `dossier.status`, `dossier.music_dossier.status`, and optional
  `dictionary_detail`.
- Produces one route decision: available Music dossier first; Dictionary detail
  fallback for non-Music or unavailable Music data.

- [ ] **Step 1: Write failing source-contract tests.**

  Assert that `entity.php` establishes the entity/type decision before the
  Dictionary branch and that an available Music dossier invokes
  `template-parts/presentation/music-dossier`. Add static tests for Westminster,
  Sonodo and Ave Maria-shaped fixtures using the same type-based path, with no
  name/slug/stable-key branch.

- [ ] **Step 2: Run the focused frontend tests and verify RED.**

  Expected: the current Dictionary-first branch violates the new routing
  invariant.

- [ ] **Step 3: Refactor the detail branch minimally.**

  Resolve the entity/type and Music availability before rendering. Select the
  Music partial when `type === music` and the sanitized dossier is available;
  otherwise preserve the existing Dictionary and generic detail behavior.
  Avoid changing canonical routes, SEO projection, or WordPress resolution.

- [ ] **Step 4: Run the focused frontend tests and verify GREEN.**

  Expected: route precedence, genericity and existing public-boundary tests pass.

- [ ] **Step 5: Commit the routing task.**

  ```bash
  git add public/wp-content/themes/nhk-v3/entity.php public/wp-content/plugins/nhk-core/tests/Unit/FrontendPresentationContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php
  git commit -m "fix(nhk-v3): route music entities through the universal dossier"
  ```

### Task 4: Make the frontend partial data-driven and accessible

**Files:**
- Modify: `public/wp-content/themes/nhk-v3/template-parts/presentation/music-dossier.php`
- Modify: `public/wp-content/themes/nhk-v3/music-dossier.js`
- Modify: `public/wp-content/themes/nhk-v3/entity.css`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendPresentationContractTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php`

**Interfaces:**
- Consumes the V2 `music_dossier` packet from Task 2.
- Produces semantic HTML with ordered sections, honest availability, public URL
  helpers, native audio fallback, keyboard controls, and responsive layout.

- [ ] **Step 1: Write failing template-contract tests.**

  Assert that the template iterates `section_order`, does not duplicate section
  order in a second hard-coded list, renders library Media/Video/Article data,
  distinguishes Product/Specimen labels, emits no playable control without a
  validated public delivery source, and contains no Westminster/Sonodo/Ave
  Maria name-specific branch.

- [ ] **Step 2: Run the focused template tests and verify RED.**

  Expected: the current hard-coded labels/order, Media-only library rendering,
  and URL-based audio control fail these assertions.

- [ ] **Step 3: Implement the generic section renderer.**

  Iterate the packet order and dispatch only by registered section key. Use
  generic reader labels and type labels. Render every owner-backed library group
  through existing public URL/media helpers. Keep source/evidence, relation
  origin, score, audio metadata and empty-state behavior honest.

- [ ] **Step 4: Implement safe playback enhancement.**

  Keep native `<audio>` as the base control. Bind the JavaScript controller only
  when a validated public delivery source is present. Preserve play/pause/stop,
  rate, repeat, segment selection, progress and keyboard state; do not create a
  source in JavaScript.

- [ ] **Step 5: Add responsive/accessibility CSS and contract assertions.**

  Ensure score events, source cards, relation cards and audio controls remain
  readable without horizontal overflow at mobile/tablet/desktop widths, with
  visible focus and semantic labels.

- [ ] **Step 6: Run focused frontend tests and verify GREEN.**

  Expected: template, accessibility, library and genericity assertions pass.

- [ ] **Step 7: Commit the frontend task.**

  ```bash
  git add public/wp-content/themes/nhk-v3/template-parts/presentation/music-dossier.php public/wp-content/themes/nhk-v3/music-dossier.js public/wp-content/themes/nhk-v3/entity.css public/wp-content/plugins/nhk-core/tests/Unit/FrontendPresentationContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php
  git commit -m "fix(nhk-v3): render music dossier sections generically"
  ```

### Task 5: Record non-canonical Westminster research readiness

**Files:**
- Create: `docs/research/westminster/universal-music-dossier-source-map.md`
- Test: no production test; review with `git diff --check` and secret scan.

**Interfaces:**
- Consumes publicly accessible source references and the approved V2 research
  model.
- Produces a human research map only; it is not Authority, Knowledge, Source,
  Evidence, Graph, Media, or runtime authorization data.

- [ ] **Step 1: Write the source map with explicit status labels.**

  Record source URL, source role, supported claim family, limitations, rights
  status, and next governed action for Great St Mary’s, UK Parliament,
  historical notation literature, Parliament audio licensing, and any vendor
  sample considered. Separate verified source statements from unresolved
  research questions. Do not insert UUIDs, canonical IDs, or seed payloads.

- [ ] **Step 2: Review the source map for unsupported assertions.**

  Every factual statement must link to a source; unresolved score pitch,
  octave, tempo, tuning, exact brand/model use, and recording rights must remain
  explicitly unresolved.

- [ ] **Step 3: Run diff and secret checks.**

  Expected: no credentials, tokens, private URLs, or canonical data mutation.

- [ ] **Step 4: Commit the research map.**

  ```bash
  git add docs/research/westminster/universal-music-dossier-source-map.md
  git commit -m "docs(nhk-v3): map Westminster music research sources"
  ```

### Task 6: Full verification, runtime QA, and execution-state checkpoint

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md` with one dated checkpoint after verification.
- No runtime data files, environment files, credentials, migrations, or deployment artifacts.

**Interfaces:**
- Consumes all completed contract, projection, routing, frontend and research
  tasks.
- Produces verification evidence with separate IMPLEMENTED, TESTED, VERIFIED,
  DEPLOYED and BLOCKED states.

- [ ] **Step 1: Run the focused Music and frontend suites.**

  Run the changed Music/profile/frontend Unit files and record exact counts.
  Expected: zero failures in the changed scope.

- [ ] **Step 2: Run PHP lint and JavaScript syntax checks.**

  Lint all changed PHP files and run `node --check public/wp-content/themes/nhk-v3/music-dossier.js`.
  Expected: no syntax errors.

- [ ] **Step 3: Run full Unit and Contract suites.**

  Run the repository’s full `NHK Unit` and `NHK Contract` suites. Compare
  failures against the pre-change baseline; do not classify any failure as
  unrelated without exact test-name overlap evidence.

- [ ] **Step 4: Attempt guarded Integration read-only/safely.**

  Inspect the current runtime identity before running. Run Integration only when
  the exact authorized identity is present; otherwise record the environmental
  blocker. Never run DOWN, DROP, TRUNCATE, reset, seed, or production mutation.

- [ ] **Step 5: Run repository hygiene checks.**

  Run `git diff --check`, changed-scope secret review, and `git status`. Confirm
  no environment file, credential, audio binary, dump, or unrelated plan was
  modified.

- [ ] **Step 6: Perform read-only browser QA against the actual current build.**

  Verify Westminster, Sonodo and Ave Maria routes; generic Music renderer;
  Dictionary precedence; score empty/populated behavior; audio no-source/source
  behavior; Media/Video/Article library; relation type/origin labels; canonical
  URL, title/H1, JSON-LD, landmarks, focus, alt text and responsive widths.
  If the current build is not deployed, mark the new behavior UNVERIFIED rather
  than claiming success.

- [ ] **Step 7: Update execution state and run final verification.**

  Record implementation/test/runtime/deployment/blocker states, source-map
  status, lack of canonical Westminster seed data, and audio readiness. Run the
  final diff/status check before any claim of completion.

- [ ] **Step 8: Commit the execution-state checkpoint.**

  ```bash
  git add docs/architecture/V3_EXECUTION_STATE.md
  git commit -m "docs(nhk-v3): record universal music dossier v2 verification"
  ```

## Execution Notes

- Execute tasks in order because Task 2 consumes the exact output of Task 1, Task 3 consumes the Music dossier state from Task 2, and Task 4 consumes the V2 section packet.
- Before implementation, create/use an isolated git worktree as required by `superpowers:using-git-worktrees`; do not implement on `main`.
- Use TDD for every production change: write the failing test, run it and inspect the failure, implement the smallest fix, run focused and broader tests, then commit.
- Any requirement for canonical Westminster ingestion, a new audio owner, a new Graph relation, migration, staging semantic mutation, deployment, or push is a plan conflict and requires a new approval gate.
- Final completion requires a fresh whole-branch review before integration; no deployment or push is part of this plan.

