# Public Dossier Form Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the public entity dossier into a coherent reader-first form that works across clock profiles and removes empty, duplicated, or misleading presentation.

**Architecture:** Keep the existing canonical read model and owner boundaries. Make the theme consume a small presentation guide assembled from existing summary, Knowledge facets, collector facets, hierarchy, and related projections; render sections conditionally and deduplicate only at the read/presentation boundary.

**Tech Stack:** PHP 8.5, WordPress theme templates, CSS, PHPUnit 11, existing frontend contract tests and route smoke tools.

**Spec:** `docs/superpowers/specs/2026-09-16-public-dossier-form-design.md`

## Global Constraints

- WordPress native `wp_posts` remains editorial truth; Authority, Knowledge, Source/Evidence, Graph, Media and Video ownership remains unchanged.
- Do not invent entity types, endpoint types, predicates, relation types, canonical fields, or semantic writes.
- Do not migrate, import, parse or populate legacy article bodies.
- Public copy is Vietnamese-first, reader-safe, accessible semantic HTML, with honest empty/unavailable states.
- Do not display internal identifiers, raw `family=clock_type`, zero-count filler, duplicate relations, or unsupported purchase/value claims.
- Preserve existing routes, public identity, SEO ownership, readiness semantics and existing user changes outside the touched files.

---

### Task 1: Add failing presentation contract tests

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendPresentationContractTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EntityPresentationViewModelTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php`

**Interfaces:**
- Consumes: `EntityPresentationViewModel::fromDossier()` and the theme's stable section markers.
- Produces: executable expectations for reader order, empty-state behavior, and duplicate-safe public output.

- [x] Write a test that asserts the presentation packet exposes a reader guide with the ordered keys `definition`, `context`, `collector_value`, `collector_focus`.
- [x] Write a test that maps a real-looking clock-type dossier with identity and knowledge claims into the definition/context guide without exposing `family`, UUIDs, or internal status text.
- [x] Write a test that an unavailable collector profile produces an explicit empty guide state rather than numeric zero content.
- [x] Run the focused tests and confirm they fail because the guide packet does not yet exist.

### Task 2: Implement the read-only reader guide

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Presentation/EntityPresentationViewModel.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EntityPresentationViewModelTest.php`

**Interfaces:**
- Consumes: existing `summary`, `description`, `knowledge.facets`, and optional `collector_profile.facets` passed through the dossier.
- Produces: `reader_guide` containing only reader-safe text/items and `has_content` booleans; no canonical identity or semantic mutation.

- [x] Add a private `readerGuide()` mapper that selects the first non-empty claim for definition/context and groups collector claims into value/focus without equating rarity and value.
- [x] Use an explicit empty item `{status: "EMPTY", items: []}` for missing groups; do not invent factual prose from a missing claim.
- [x] Strip internal keys from guide items with the same safety policy as existing public relation items.
- [x] Run the focused tests and confirm they pass.

### Task 3: Recompose the entity detail template around the reader journey

**Files:**
- Modify: `public/wp-content/themes/nhk-v3/entity.php`
- Modify: `public/wp-content/themes/nhk-v3/template-parts/presentation/entity-hero.php`
- Modify: `public/wp-content/themes/nhk-v3/template-parts/presentation/local-section-nav.php` only if the current conditional nav needs the guide anchors.

**Interfaces:**
- Consumes: `view.reader_guide`, existing dossier sections, hierarchy and relation projections.
- Produces: semantic public HTML in this order: hero, section nav, reader guide, identity/hierarchy, knowledge, evidence, media, relations.

- [x] Remove the always-present empty `hierarchy-rail` grid child; render hierarchy only when it has a parent or child, using the existing clock-type hierarchy section.
- [x] Add one reader-guide section with four compact blocks: `Đây là gì?`, `Vai trò & bối cảnh`, `Giá trị sưu tầm`, `Người sưu tầm thường xem gì?`; render only non-empty items plus honest update states.
- [x] Change the raw `FAMILY=clock_type` facts display to a visitor-safe profile explanation; retain other real identity payload values.
- [x] Keep claim evidence directly below its claim and avoid repeating the same source as a standalone knowledge card.
- [x] Hide the collector profile when it has no collector facets and no valid related media/video/article/maker item; never show a `0` stat row.
- [x] Deduplicate collector articles/media/video/makers by canonical identity or URL before rendering.
- [x] Make the right rail conditional and keep the in-page navigation limited to sections actually rendered.
- [x] Run the theme contract tests and PHP lint for the edited templates.

### Task 4: Establish the shared visual rhythm

**Files:**
- Modify: `public/wp-content/themes/nhk-v3/entity.css`
- Modify: `public/wp-content/themes/nhk-v3/presentation.css` only if shared styles are genuinely reused.
- Modify: `public/wp-content/themes/nhk-v3/functions.php` only if the asset version must be bumped.

**Interfaces:**
- Consumes: the stable semantic classes from Task 3.
- Produces: responsive editorial layout with one main content column and one optional context rail.

- [x] Add a two-column `.semantic-layout` only for `semantic-main + context-rail`; use a `.reader-guide` grid for the four orientation blocks.
- [x] Style definition as readable prose, context/value/focus as short evidence-aware cards, and status/empty copy as muted but visible.
- [x] Prevent empty sections from reserving space; keep section separators and type scale consistent with the existing NHK tokens.
- [x] Add mobile rules for one-column reader blocks, readable line length, and no horizontal overflow.
- [x] Bump only the theme asset cache version if needed, then run `git diff --check`.

### Task 5: Improve the archive/home entry points without duplicating semantics

**Files:**
- Modify: `public/wp-content/themes/nhk-v3/entity.php` archive branch.
- Modify: `public/wp-content/themes/nhk-v3/front-page.php` only if the home clock-group section needs the same reader-first framing.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicEntityCollectionQueryTest.php` if archive card fields need a regression.

**Interfaces:**
- Consumes: existing archive items, profile labels, descriptions and counts.
- Produces: concise archive intro explaining what a group page is for, with cards that lead to coherent dossiers.

- [x] Replace the generic archive sentence with profile-aware Vietnamese copy that does not claim unavailable counts or content.
- [x] Keep archive cards limited to title, safe description, representative image, and positive related counts.
- [x] Do not introduce a new semantic source for the copy; use existing profile label and query result only.
- [ ] Run archive collection tests and route smoke if local WordPress runtime is available. (Blocked: local WordPress/DB is unavailable.)

### Task 6: Verify against the real public route and close the change

**Files:**
- Update: `docs/architecture/V3_EXECUTION_STATE.md` only if the checkpoint evidence warrants a factual update.

**Interfaces:**
- Consumes: changed theme/presentation code and existing local runtime.
- Produces: fresh unit/contract/lint/diff evidence and a read-only visual check of `/dong-ho-cong-cong/`, `/loai-dong-ho/`, and `/`.

- [x] Run focused presentation/frontend contract tests, then the full Unit suite or classify environment failures exactly.
- [x] Run PHP lint over changed PHP files, `git diff --check`, and a secret/prohibited-data review.
- [ ] Run the documented frontend route smoke/read-only verification; do not mutate staging or production. (Blocked: local WordPress/DB is unavailable.)
- [ ] Inspect the rendered page for no PHP warning banner, no blank hierarchy column, no duplicate article, no raw internal family label, and an obvious reader journey. (Blocked: local runtime is unavailable; the remote demo was inspected read-only before implementation and remains undeployed.)
- [x] Review the diff for overlap with pre-existing user changes and report any runtime/deployment blocker without claiming external publication.
