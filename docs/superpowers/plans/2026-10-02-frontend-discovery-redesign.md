# NHK V3 Frontend Discovery Redesign Implementation Plan — 2026-10-02

Spec: `docs/superpowers/specs/2026-10-02-frontend-discovery-redesign-design.md`

## Task 1 — Lock navigation behavior with tests

Modify focused presentation tests first.

Expected assertions:
- primary nav exact order: Sản phẩm, Thương hiệu, Loại đồng hồ, Từ điển, Tri thức;
- discovery contains Hình ảnh and Video plus existing specialist hubs;
- header has one discovery control and no dedicated global Clock-Type menu;
- footer definition contains the four lookup destinations.

Run focused PHPUnit tests and confirm RED before implementation.

## Task 2 — Simplify global navigation

Update `PublicNavigationDefinition` and `header.php`.

Rules:
- one primary group;
- one discovery disclosure;
- no second LOẠI tree in global header;
- no route allocation or semantic writes;
- same data source for desktop/mobile.

Run focused tests to GREEN.

## Task 3 — Reframe homepage entry hierarchy

Update `front-page.php` and styles.

Implement:
- hero copy centered on lookup/discovery;
- four exact gateway controls from shared primary navigation;
- descriptive secondary line per gateway;
- four columns on wide screens, two columns on mobile;
- latest feed remains immediately after hero.

Do not add a new Product query merely for visual symmetry.

## Task 4 — Compact Article/feed image requests

Tests first:
- Article card uses `medium`;
- Home query resolves Article feed image/srcset/sizes using `medium`;
- latest feed template requests attachment image size `medium`;
- featured lead remains `medium_large`.

Implementation then updates:
- `template-parts/article-card.php`
- `inc/class-nhk-home-page-query.php`
- latest-feed image call in `front-page.php`.

Preserve object-fit contain and existing fallbacks.

## Task 5 — Responsive visual polish

Update `style.css` only as needed:
- primary header remains readable at laptop widths;
- hero gateways are 4-column desktop / 2-column mobile;
- gateway copy is compact;
- existing mobile menu/focus behavior remains valid;
- no full-viewport hero or horizontal overflow.

Do not introduce animation dependencies.

## Task 6 — Verification

Run:
- focused navigation/presentation unit tests;
- full NHK Unit suite;
- Contract suite;
- `composer lint`;
- `git diff --check`;
- secret scan if repository helper exists.

If local/runtime browser is available, run frontend route smoke and check `/`, `/san-pham/`, `/thuong-hieu/`, `/loai-dong-ho/`, `/tu-dien/`, `/thu-vien/`, `/video/` at mobile/tablet/desktop. Environment absence is reported as an evidence gap, not converted into PASS.

## Review focus

Review must explicitly look for:
- accidental semantic writes or route changes;
- duplicated mobile navigation;
- regression of Clock-Type Presentation Navigation;
- full-size image downloads on compact cards;
- Dictionary candidate leakage;
- Product/Specimen conflation;
- accessibility regressions;
- homepage query expansion or dossier-on-archive performance regressions.
