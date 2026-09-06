# NHK V3 Admin Hybrid Workbench Design

**Status:** Approved implementation design, 2026-09-06  
**Scope:** WordPress Admin information architecture, presentation, navigation, capability-aware surfaces and operator diagnostics.  
**Authority:** Subordinate to `docs/constitution/NHK_V3_CONSTITUTION.md` and the current contracts routed by `docs/constitution/READ_FIRST.md`.

## 1. Goal

Build an NHK V3 Admin experience that is easy to scan, easy to manage and easy to repair without creating a second semantic writer.

The Admin experience must present work in the language of operator tasks while retaining visible canonical ownership, Governance state and fail-closed diagnostics.

The governing UX rule is:

> **TASK-ORIENTED SURFACE → DOMAIN-AWARE INTEGRITY → EXISTING APPLICATION WRITER**

Admin remains an adapter. It never becomes canonical storage and never invents an operation because a UI control would be convenient.

## 2. Source law and constraints

This design follows these current boundaries:

- WordPress `wp_posts` remains the sole owner of Article editorial title/body/excerpt/order/public editorial URL.
- Authority owns registered canonical entities.
- Knowledge owns atomic claims.
- Source/Evidence owns provenance/support.
- Graph is the only semantic relation persistence.
- Governance owns durable semantic mutation.
- Media, MediaAsset, MediaUsage and WordPress attachment remain distinct boundaries.
- Video remains a governed canonical external reference.
- Dictionary Concept/Label/Candidate/Mention remains lexical curation state, not Authority/Knowledge/Evidence/Graph truth.
- Admin and MCP consume the same application boundaries and capability source.
- Missing capability, storage, runtime dependency, identity or revision must remain an explicit unavailable/blocked state; the UI must not manufacture a fallback writer.
- No migration, legacy repair, semantic backfill, production/staging mutation or public identity allocation is authorized by this UI work.

## 3. Chosen architecture

### 3.1 Hybrid Workbench

The primary navigation is task-first:

1. Tổng quan
2. Nội dung
3. Media
4. Video
5. Tri thức
6. Duyệt thay đổi
7. Từ điển
8. Hệ thống

The integrity layer is domain-aware. Every workbench card records:

- owner;
- purpose;
- current capability requirement;
- availability/status;
- a safe destination;
- an explicit note when the surface is read-only, external/native WordPress or advanced.

### 3.2 Progressive disclosure

The default dashboard shows operator workflows, not raw JSON or database-oriented controls.

Existing low-level proposal composer, Graph lookup, raw semantic lookup, migration ledger and other technical tools remain available under an **Nâng cao** workbench. They are not deleted or silently changed. This preserves operational power while reducing accidental use by editors.

### 3.3 No new frontend framework

The implementation uses server-rendered PHP plus a small dedicated stylesheet and JavaScript file.

Reasons:

- no new build pipeline or Node dependency;
- consistent with the current plugin architecture;
- easy to inspect and repair on a WordPress host;
- WordPress-native controls remain available;
- presentation can be separated from domain/application code.

## 4. Maintainability model

The Admin code is split by responsibility:

- `AdminWorkbenchRegistry` — single registry for sections, labels, slugs, owners, capabilities and destinations;
- `AdminWorkbenchState` — pure presentation model for availability, state stack and blocker counts;
- `AdminWorkbenchPage` — top-level dashboard rendering only;
- `AdminAssets` — enqueue admin CSS/JS only on NHK V3 Admin pages;
- existing `AdminPage` — retained as advanced/technical operations surface;
- existing Dictionary and dossier pages — wired into the same parent menu without duplicating their domain logic.

No new page class may open a direct semantic database write path. Existing read-only repositories may continue to power diagnostics where already contract-approved.

## 5. Information architecture

### 5.1 Global shell

Every new workbench page uses:

- page title;
- short human description;
- environment/runtime warning region when applicable;
- section navigation;
- task cards;
- explicit ownership/status metadata;
- honest empty/unavailable states.

The default dashboard must not display invented counts. Counts are shown only when derived from a current runtime reader that is already available. Otherwise the card shows availability and the next safe action.

### 5.2 Workbench definitions

#### Tổng quan

Purpose: orient the operator and surface the next safe place to work.

Cards:

- Nội dung — opens native WordPress Posts because WordPress owns editorial content.
- Media — opens native Media Library for attachment/editorial storage and clearly explains that canonical Media identity is governed separately.
- Video — opens the NHK V3 advanced operations surface filtered/documented for governed Video intake when the current writer is exposed; never creates a local Post or MP4 shortcut.
- Tri thức — opens semantic inspection/read tools.
- Duyệt thay đổi — opens Governance proposal lifecycle tools.
- Từ điển — opens Dictionary curation only when the user has the curation capability.
- Dossier coverage — opens read-only semantic coverage diagnostics when wired.
- Hệ thống — opens health/migration/read-only technical diagnostics.

#### Nội dung

The dashboard points to the native WordPress Posts surface rather than recreating an editor. Article semantic completion remains governed by Article preflight/publication contracts.

The UI copy must distinguish:

- WordPress editorial draft/published state;
- semantic readiness;
- Governance state;
- public verification.

A WordPress published status is not represented as proof that the V3 knowledge workflow completed.

#### Media

The dashboard points to the native WordPress Media Library for attachment management and to advanced NHK inspection for canonical Media state.

Copy must preserve:

`Media identity ≠ MediaAsset ≠ MediaUsage ≠ WordPress attachment`.

OCR, EXIF, filename, caption and recognition are not represented as semantic identity or Evidence.

#### Video

The workbench surfaces existing governed Video intake/inspection only. A preview or enrichment packet is labelled as planning/review, not applied Knowledge.

#### Tri thức

The workbench surfaces canonical entity and semantic reads. It never converts prose into a semantic write automatically.

#### Duyệt thay đổi

The Governance surface must visually preserve the distinct stages:

`Proposal → Submitted → Approved → Eligibility → Controlled Apply → Read-back`.

Approval is not displayed as completion. Controlled Apply is not displayed as verified until read-back evidence exists.

#### Từ điển

Existing candidate/draft review remains capability-gated and uses the Dictionary curation boundary. The UI must retain search/reuse-first semantics and explicit human decisions.

#### Hệ thống

Health, migration status and technical lookup remain available, but are grouped away from daily editorial tasks.

## 6. State stack

A single generic status badge is forbidden where it collapses independent states.

The presentation model supports separate state rows such as:

- Editorial
- Canonical lifecycle
- Visibility
- Governance
- Eligibility
- Readiness
- Public route
- Verification

The dashboard uses only states that are actually known. Unknown/unavailable values are rendered explicitly rather than assumed.

State tones are presentation-only and never create domain enums:

- `ready` — verified/available;
- `attention` — incomplete/review required;
- `blocked` — fail-closed blocker;
- `neutral` — informational/unknown/not applicable.

Color is never the sole signal; each state includes text.

## 7. Capability-driven visibility

Navigation items and action links declare a required capability where the current contract already defines one.

Examples:

- `manage_options` — system/admin diagnostics;
- `nhk_view_governance` — Governance read surfaces;
- `nhk_create_proposals` — proposal composition;
- `nhk_approve_proposals` — approval actions;
- `nhk_apply_proposals` — Controlled Apply;
- `nhk_ingest_articles` — Article managed operations;
- `nhk_curate_dictionary` — Dictionary curation.

The registry does not invent new WordPress capabilities in this slice.

A user without a capability does not receive a fake enabled action. The workbench may hide the action or show a read-only explanatory card depending on the section.

## 8. Accessibility and interaction

Minimum requirements:

- semantic headings in order;
- `<nav>` with accessible label for workbench navigation;
- visible focus state;
- buttons/links with clear action text;
- no color-only status;
- error/blocked states expressed in text;
- `aria-live` only for genuinely dynamic operation feedback;
- responsive grid that collapses to one column on narrow screens;
- minimum practical target sizing for interactive controls;
- reduced-motion preference respected;
- keyboard-accessible cards and navigation;
- raw JSON output retained only in advanced technical surfaces.

## 9. Visual language

The design should feel native to WordPress while making NHK V3 boundaries easier to understand.

Use:

- WordPress system font stack;
- neutral panels/cards;
- restrained borders and radius;
- one accent inherited from WordPress/Admin context where possible;
- spacing tokens defined once in the Admin stylesheet;
- no branded illustration or decorative dependency;
- no inline styles in new Admin classes.

## 10. Safe destinations

The registry may generate only destinations that already exist:

- native `edit.php` for Posts;
- native `upload.php` for Media Library;
- NHK V3 Admin routes registered in this implementation;
- existing read/technical Admin callbacks.

It must not advertise a dedicated writer page for an operation that is not actually wired.

## 11. Runtime and empty-state behavior

The dashboard itself must remain renderable even if semantic storage is unavailable.

A failed optional subsystem appears as:

- section name;
- `Không khả dụng` or equivalent clear text;
- reason when safely known;
- next safe action, usually System diagnostics;
- no invented count and no fallback mutation.

Dictionary is a concrete example: if its storage is unavailable, the curation page must continue to state that explicitly instead of showing an empty-success view.

## 12. Testing strategy

### 12.1 Pure unit tests

`AdminWorkbenchRegistryTest` verifies:

- stable unique section IDs/slugs;
- task ordering;
- canonical owner labels;
- required capabilities;
- no invented writer route;
- native WordPress destinations for editorial and attachment work.

`AdminWorkbenchStateTest` verifies:

- independent state stack rows;
- blocked/attention/ready/neutral tones;
- unavailable remains distinct from empty;
- blocker totals are deterministic.

### 12.2 Source/architecture tests

`AdminWorkbenchArchitectureTest` verifies:

- new Admin presentation classes contain no direct semantic SQL mutation statements;
- new Admin presentation classes do not introduce semantic repository writes;
- CSS/JS are external assets rather than new inline presentation blobs;
- plugin boot wires the Workbench entrypoint instead of exposing the old monolith as the primary menu.

### 12.3 CI gate

A dedicated Admin workflow runs:

1. Composer install;
2. `git diff --check`;
3. PHP lint;
4. NHK Unit suite;
5. Admin-specific tests.

The workflow is useful after this branch and therefore targets pull requests that modify Admin/plugin/test/spec paths, not only one temporary feature branch.

## 13. Implementation boundaries

This slice explicitly does **not**:

- change any semantic entity type;
- add a Graph predicate;
- add a migration;
- mutate production/staging/V2 data;
- backfill data;
- repair identity;
- allocate public URLs;
- change Article editorial ownership;
- create a second Media or Video writer;
- auto-apply Dictionary candidates;
- implement the missing Media-to-Living-Knowledge adapter;
- create a Product-to-Specimen shortcut;
- claim live runtime acceptance merely because code/CI passes.

## 14. Acceptance criteria

The implementation is accepted only when all of the following are true:

1. NHK V3 opens on a task-oriented dashboard rather than the old technical monolith.
2. Editorial content and Media attachment destinations point to native WordPress owners.
3. Advanced technical tools remain reachable but are clearly separated.
4. Menu metadata exists in one registry, not duplicated across page classes.
5. Capability requirements are declared centrally and respected during rendering.
6. State presentation preserves independent layers and honest unavailable states.
7. Dictionary curation is wired when its runtime is available and remains fail-closed when unavailable.
8. Dossier coverage is wired only as read-only diagnostics.
9. New Admin classes contain no direct semantic writer.
10. New styling/script behavior is isolated in dedicated assets.
11. The interface is responsive and keyboard-accessible at the structural level.
12. Unit tests and lint pass in a fresh verification run.
13. Diff/secret review finds no credentials, private keys or environment secrets.
14. `V3_EXECUTION_STATE.md` records the implementation checkpoint and clearly separates code readiness from target-runtime acceptance.
15. Merge to `main` occurs only after the branch verification evidence is green and the final diff is reviewed.
