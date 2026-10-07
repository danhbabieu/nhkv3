# Final Template-Family Architecture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enforce the approved family-level public presentation architecture and fix its Dictionary, SEO, asset, and collection-query correctness boundaries without semantic data mutation.

**Architecture:** Canonical family query services produce normalized packets consumed by the existing family entry templates. The implementation adds only reusable family/contract support; it does not create record-specific templates or split family entry files mechanically.

**Tech Stack:** PHP 8+, PHPUnit 11, WordPress theme PHP/CSS/JS, Composer PSR-4.

**Spec:** `docs/superpowers/specs/2026-10-07-final-template-family-architecture-design.md`

## Global Constraints

- Base must remain `c98f0051e136831c0226ceba068ae8ec22e519f8` lineage.
- `NEW RECORD ≠ NEW SOURCE FILE`.
- Do not migrate, backfill, mutate staging/production, change Dictionary lifecycle, touch Côn hoa thị data, deploy, push, pull, or rebase.
- Keep `entity.php`, `dictionary.php`, `video.php`, `media.php`, `knowledge.php`, and `single.php` as family entry surfaces unless a tested compatibility cleanup retires unreachable code.
- Keep WordPress `wp_robots` as the only robots meta serializer.
- Never expose internal identifiers, generic internal scope fields, or unapproved semantic types in public presentation.
- If bounded repository querying needs new indexes, report `INDEX_REQUIRED` with exact evidence and do not add a migration.

## Review Focus

- A record-specific filename or include can bypass the family invariant; contract and behavioral tests cover the filesystem and route selection.
- A Dictionary sense can leak `scope` instead of reader-facing usage text; composer regression covers `movement_plate_thickness`.
- A delegated Dictionary page can emit a competing canonical or JSON-LD node; SEO parity tests cover owner canonical and no duplicate detail surface.
- A route can load an unrelated CSS chain or omit Video/Comparison styles; asset manifest tests cover exact family handles.
- Collection code can hide a full scan behind a new abstraction; query tests/instrumentation report bounded capability or `INDEX_REQUIRED`.

## File Map

- Create: `public/wp-content/plugins/nhk-core/src/Application/Presentation/PublicTemplateFamilyContract.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Seo/PublicSeoPacket.php` only if the existing projection cannot express the normalized packet without ambiguity.
- Modify: Dictionary composer/query tests and implementation.
- Modify: SEO projection/route/theme serialization only where tests demonstrate duplicate decision-making.
- Modify: `public/wp-content/themes/nhk-v3/functions.php` and family CSS dependency declarations.
- Modify: `entity.php`, `dictionary.php`, `video.php`, `media.php`, `knowledge.php`, and shared presentation partials only for tested reusable extraction or dead-branch retirement.
- Create/modify focused Unit/Contract tests; no data fixtures tied to Côn hoa thị.

## Execution Slices

### Slice A — Architecture guard and correctness bugs

1. Add failing tests for forbidden record-specific public templates/includes and neutral Article/Video/Dictionary/Entity family reuse.
2. Implement the guard as a read-only filesystem/source scanner with explicit allowlist categories for domain normalization and fixtures.
3. Add the Dictionary regression for `scope = movement_plate_thickness` and singular `usage_note`.
4. Change the composer to accept only explicit public usage fields, with legacy `notes` behind an explicit reader-facing contract.
5. Add route tests proving Media detail and Knowledge atomic detail remain fail-closed.
6. Add failing asset tests for VideoDetail and Comparison family declarations; implement the smallest family asset manifest/decision seam.
7. Run focused tests, PHP lint, diff-check, and scoped secret review; commit Slice A.

### Slice B — SEO convergence

1. Add failing tests for one normalized SEO packet, absolute canonical/OG/JSON-LD/sitemap URLs, and `wp_robots` ownership.
2. Normalize `PublicSeoProjection`/family SEO input without adding a manual robots tag.
3. Refactor the theme serializer to consume the valid packet rather than reconstructing family decisions.
4. Preserve Article, VideoObject, DefinedTerm, and BreadcrumbList nodes without duplicate output.
5. Run SEO focused tests and commit Slice B.

### Slice C — Presentation deduplication

1. Add contract coverage for shared reusable partials and one family entry per domain.
2. Extract only genuinely repeated relation/media/section presentation from Entity/Article/Video.
3. Converge editorial archives through one provider/renderer while allowing `tri-thuc.php` to remain a thin wrapper.
4. Keep type/profile configuration in packets/registries and preserve domain behavior.
5. Run theme/contract tests and commit Slice C.

### Slice D — CSS and asset architecture

1. Add failing tests that assert family handle declarations and absence of unrelated dependency chains.
2. Flatten stylesheet dependencies into base, shared presentation, family, and feature layers.
3. Preserve visual selectors while ensuring VideoDetail and Comparison receive required CSS.
4. Run asset, responsive, accessibility static checks and commit Slice D.

### Slice E — Query scalability and media I/O

1. Add instrumentation tests for bounded public query APIs where repository interfaces support them.
2. Replace list-all/sort/slice only where an existing bounded contract is available.
3. Keep explicit `INDEX_REQUIRED` reports for entity/media/video/knowledge/search paths that cannot be bounded without schema evidence.
4. Split Media card projection from binary delivery integrity checks; preserve fail-closed delivery checksum validation.
5. Add bounded filename lookup only if an existing read model/repository supports it; otherwise report exact blocker.
6. Run focused performance tests and commit Slice E only for verified changes.

## Final Verification

- `composer test` or the repository's configured PHPUnit suite, with changed-surface failures investigated.
- `composer lint`.
- `git diff --check`.
- Scoped secret scan for credentials/private keys.
- Static family/template scan.
- Route negative tests and SEO/asset contract tests.
- Read and update `docs/architecture/V3_EXECUTION_STATE.md` at each checkpoint.
- No claim of deployment or runtime acceptance without external evidence.
