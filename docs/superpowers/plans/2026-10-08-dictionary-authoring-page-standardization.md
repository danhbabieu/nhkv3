# Dictionary Authoring and Public Page Standardization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Chuẩn hoá cách trình bày một trang Từ điển và bổ sung hợp đồng dữ liệu soạn từ điển, dùng `Côn hoa thị` làm ví dụ kiểm chứng fail-closed.

**Architecture:** Giữ Dictionary là lexical owner; Entry/Form/Sense chỉ giữ từ, nghĩa, ngữ cảnh và tham chiếu typed tới owner canonical. Trang `Côn hoa thị` không được hard-code hay tạo dữ liệu live: nếu delegated thì canonical về hồ sơ Component `/linh-kien/con-hoa-thi/`, còn các khẳng định chưa đủ nguồn phải hiện trạng thái chưa sẵn sàng/không index. Giao diện dùng cùng một partial Dictionary detail và thể hiện rõ dữ liệu rỗng khác dữ liệu tạm thời không khả dụng.

**Tech Stack:** PHP 8.x, PHPUnit, WordPress theme templates, Markdown architecture/runbook documentation.

**Spec:** `docs/constitution/NHK_V3_CONSTITUTION.md`, `docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md`, `docs/architecture/DICTIONARY_ENTRY_SENSE_ARCHITECTURE.md`, `docs/architecture/DICTIONARY_SEMANTIC_ENRICHMENT_PROJECTION_CONTRACT.md`, `docs/audits/2026-10-06-con-hoa-thi-public-dossier-audit.md`.

## Global Constraints

- Dictionary is lexical curation only; it is not Authority, Knowledge, Source/Evidence or Graph.
- `Côn hoa thị` is a reference dataset, never a code branch, schema exception or authorization scope.
- Never migrate, seed, import, mutate staging/production/V2 data, or create a generic WordPress writer.
- Public output is Vietnamese-first, reader-safe, accessible and honest about empty, blocked and unavailable states.
- A delegated owner must not receive a competing indexable Dictionary detail page; canonical URLs come from the owner projection.
- Preserve existing uncommitted Music changes and do not stage them in this work.

## Review Focus

- Delegated `Côn hoa thị` with unavailable Knowledge/media projections must link to the Component owner and distinguish unavailable from empty data.
- An unresolved or ambiguous term must never be promoted to a public definition or canonical owner link.
- Alternate, colloquial and hidden labels must remain reader-safe and deduplicated.
- A dedicated lexical entry must retain one self-canonical URL and indexability only when its own lexical payload is eligible.
- The authoring guide must not silently turn source locators, media observations or owner URLs into factual claims.

### Task 1: Dictionary authoring data contract and Côn hoa thị example

**Files:**
- Create: `docs/architecture/DICTIONARY_AUTHORING_DATA_CONTRACT.md`
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

**Interfaces:**
- Consumes: Entry/Sense, semantic-reference, source/attestation, public SEO and Côn hoa thị audit contracts.
- Produces: A reusable authoring checklist and documentation-only packet template with a fail-closed Côn hoa thị example.

- [ ] **Step 1: Write the authoring contract**

  Document required, conditional and forbidden fields; the Entry/Form/Sense split; owner/reference/provenance rules; resolution and review workflow; publication/SEO gates; media and Knowledge boundaries; the repeatable authoring worksheet; and a `Côn hoa thị` example explicitly marked `EXAMPLE_ONLY / NO_LIVE_WRITE`.

- [ ] **Step 2: Update the documentation status index and execution checkpoint**

  Register the new contract as current implementation guidance, preserve the distinction between implemented runtime and target design, and record no data mutation/deployment.

- [ ] **Step 3: Verify documentation invariants**

  Run Markdown/link/secret checks available in the repository, plus `git diff --check`; confirm the example contains no credentials, internal IDs or authorization language.

- [ ] **Step 4: Commit**

  Commit only the new/updated documentation files with `docs(dictionary): add authoring data contract`.

### Task 2: Normalize the public Dictionary detail page

**Files:**
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryNormalizedPageContractTest.php`
- Modify: `public/wp-content/themes/nhk-v3/template-parts/dictionary/dictionary-detail.php`
- Modify: `public/wp-content/themes/nhk-v3/dictionary.css`

**Interfaces:**
- Consumes: `DictionaryDetailPresentationComposer` packet fields `route`, `canonical_owner`, `senses`, `knowledge`, `media`, `videos`, `articles` and `availability`.
- Produces: A standard detail partial that shows the canonical owner notice once, keeps lexical meaning primary, and renders honest public availability notes without exposing internal statuses.

- [ ] **Step 1: Write failing contract tests**

  Add tests proving the detail partial has one delegated-owner notice path, explicit Vietnamese copy for empty/unavailable related data, accessible status semantics, and Côn hoa thị’s owner canonical `/linh-kien/con-hoa-thi/` remains the example path in the test fixture only. Assert the partial never renders operational IDs/status names.

- [ ] **Step 2: Run the focused test and observe the expected failure**

  Run the new PHPUnit test; expected failure is missing normalized owner/availability markers in the template.

- [ ] **Step 3: Implement the minimal template/CSS change**

  Add a single reader-facing canonical-owner callout for delegated packets, add a small status-summary block driven only by existing normalized bucket states, preserve current content sections and internal-field stripping, and style it responsively with existing design tokens.

- [ ] **Step 4: Run focused Dictionary/frontend tests**

  Run the new test plus `DictionaryDetailPresentationComposerTest`, `DictionaryUnifiedRendererContractTest`, `FrontendContractTest` and `FrontendPresentationContractTest`; expected result is pass with any pre-existing baseline warnings recorded.

- [ ] **Step 5: Lint, diff and secret review**

  Run PHP lint on changed PHP files, `git diff --check`, and a changed-scope secret scan. Confirm the four pre-existing Music files remain unstaged.

- [ ] **Step 6: Commit**

  Commit only the new test, partial and CSS with `feat(dictionary): standardize public detail page`.

### Task 3: Whole-change verification and review

**Files:**
- Modify: plan ledger under `.superpowers/sdd/` only (git-ignored)

- [ ] **Step 1: Run the focused Dictionary/frontend verification selection**
- [ ] **Step 2: Self-review the diff against Constitution and all Review Focus cases**
- [ ] **Step 3: Record baseline failures or deferred minors without claiming unavailable runtime acceptance**

