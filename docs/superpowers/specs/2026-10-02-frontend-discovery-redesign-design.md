# NHK V3 Frontend Discovery Redesign — 2026-10-02

## Status

Implementation design for the public NHK V3 frontend. This is a presentation-only redesign. It does not authorize semantic mutation, Graph writes, Public Identity changes, route reallocation, data backfill, Dictionary approval, Media ingest, Video ingest, Article publication, or Product/Specimen identity changes.

## Source baseline

- Runtime reviewed through @v55 documentation bootstrap: source revision `885d6cd50e2aba9d810f65c309b9c174aab444d5`.
- Repository implementation branch starts from `main@6dd5913abe399cafbb2204dea079bd3c57bb7004`, whose only intervening change is outside this frontend scope.
- Governing sources: `AGENTS.md`, Constitution, frontend route inventory, Media model, Dictionary contract, Clock-Type ecosystem, Public Entity dossier, Shared Feed Ordering and existing Presentation Navigation contracts.

## Product intent

NHK is not primarily a chronological WordPress blog. The public experience is a Vietnamese-first discovery and lookup surface over editorial Posts plus Authority, Knowledge, Graph, Media, Video, Dictionary, Specimen and Product projections.

The redesign must make four reader goals obvious within the first viewport and repeat them consistently across the site:

1. Sản phẩm
2. Thương hiệu
3. Loại đồng hồ
4. Từ điển / Tra cứu

Search is the universal fifth entry point. Editorial content remains important but is not the only organizing axis.

## Navigation architecture

### Desktop header

Visible primary navigation, in this exact priority order:

- Sản phẩm → `/san-pham/`
- Thương hiệu → `/thuong-hieu/`
- Loại đồng hồ → `/loai-dong-ho/`
- Từ điển → `/tu-dien/`
- Tri thức → `/tri-thuc/`

A single secondary disclosure named **Khám phá** contains:

- Hình ảnh → `/thu-vien/`
- Video → `/video/`
- Mẫu → `/mau/`
- Bộ máy → `/bo-may/`
- Bản nhạc → `/ban-nhac/`
- Linh kiện → `/linh-kien/`
- Hiện vật → `/hien-vat/`
- So sánh → `/so-sanh/`
- Góc chia sẻ → `/goc-chia-se/`

The current dedicated LOẠI dropdown and separate mobile LOẠI dropdown are removed. Curated Clock-Type Presentation Navigation remains the source for `/loai-dong-ho/` and its own hub/detail navigation; the global header must not duplicate that tree.

### Mobile

Use the same primary/discovery source as desktop. No second LOẠI menu. The menu remains keyboard accessible, Escape-closeable and focus-contained through the existing navigation script.

### Footer

Three groups:

- **Tra cứu:** Sản phẩm, Thương hiệu, Loại đồng hồ, Từ điển
- **Nội dung:** Tri thức, Hình ảnh, Video, Góc chia sẻ
- **Khám phá:** Mẫu, Bộ máy, Bản nhạc, Linh kiện, Hiện vật, So sánh

No duplicate semantic tree is persisted for this redesign.

## Homepage information architecture

### 1. Compact hero

Hero remains compact, search-first and visually bounded. Copy should explain that readers can tra cứu sản phẩm, thương hiệu, loại đồng hồ và thuật ngữ.

Exactly one real hero image may use eager/high-priority loading. No slider.

### 2. Four primary gateways

Within the hero, show four large gateway controls using the same canonical navigation definition:

- Sản phẩm — xem các hồ sơ sản phẩm/listing công khai
- Thương hiệu — đi vào hồ sơ nhà sản xuất/thương hiệu
- Loại đồng hồ — duyệt nhóm đồng hồ đã được biên tập
- Từ điển — tra thuật ngữ/cách gọi của người chơi

These are navigation affordances only; they do not imply data availability or create content.

### 3. Unified latest feed

Keep the cross-domain latest feed immediately after the hero. It remains newest-first under the shared feed ordering contract and bounded to four visible items on the homepage.

The row visual must request a compact derivative when the source is a WordPress attachment. It must never download a full-size image merely to render a 104–120px visual.

### 4. Editorial selection

Keep the current sticky-first editorial selection after the latest feed.

### 5. Discovery sections

Keep semantic modules, but present them in reader priority:

1. Loại đồng hồ
2. Thương hiệu / Entity discovery
3. Hình ảnh
4. Video
5. Tri thức
6. Từ điển
7. Topic/editorial sections where data exists

Product must remain a first-class global entry point even when a dedicated bounded Product feed is not available. Do not fabricate Product cards or derive Specimen/Product relations that are not sanctioned by the current contracts.

## Image and video performance policy

### Rule

Full public assets and compact card assets are distinct presentation concerns.

- Canonical public image delivery may continue to use the approved high-resolution source-derived asset.
- Homepage/archive/feed cards request compact WordPress derivatives when an attachment ID is available.
- Compact Article cards use WordPress `medium`, not `medium_large`.
- Unified latest feed attachment-backed images use `medium`, with responsive `srcset`/`sizes`.
- Featured lead may keep `medium_large` because it renders materially larger.
- Support cards may keep `thumbnail`.
- Video lists use poster/thumbnail only; iframe/embed is deferred to Video detail.
- CSS resizing is not considered a performance optimization if the HTML still requests the large asset.

### Image integrity

Do not hard-crop antique objects for this redesign. Existing `object-fit: contain` and intrinsic/orientation-aware frames stay authoritative.

### Semantic Media

For canonical Media without an attachment derivative, do not invent a transformed URL. Reuse the current governed projection. Future derivative expansion belongs in the Media projection boundary, not template string rewriting.

## Dictionary behavior

`/tu-dien/` is promoted in navigation now.

Dictionary readiness and public content are separate facts. Current runtime has a ready hub but no approved public entries. The frontend must therefore:

- expose the Dictionary route;
- show only approved/public projection items;
- preserve honest empty states;
- never surface NEEDS_REVIEW, AMBIGUOUS, DRAFT or candidate objects as public definitions.

## Cross-page visual discovery

Existing dossier/article/video relation projections remain the only source for contextual media/video/article sections. The redesign should increase visibility through shared presentation components, not by keyword scraping or broad Graph traversal.

No new relation predicate, shortcut edge or inherited truth is permitted.

## Accessibility and responsive requirements

- Keep semantic landmarks and heading hierarchy.
- Header controls remain keyboard navigable.
- Mobile navigation must not duplicate primary items.
- Minimum interactive target should remain approximately 44px where practical.
- Maintain visible focus states.
- No horizontal overflow at 360, 390, 430, 768 and desktop widths.
- Respect reduced motion.

## Performance acceptance

The redesign is accepted only when:

- no homepage visual except the single hero is eager/high-priority;
- latest-feed attachment images request `medium`;
- normal Article cards request `medium`;
- Video cards remain poster-only;
- no template changes canonical Media URLs by string manipulation;
- archive/list paths do not invoke heavy entity dossier assembly.

## Files expected to change

Primary implementation seam:

- `src/Application/Presentation/PublicNavigationDefinition.php`
- `themes/nhk-v3/header.php`
- `themes/nhk-v3/front-page.php`
- `themes/nhk-v3/inc/class-nhk-home-page-query.php`
- `themes/nhk-v3/template-parts/article-card.php`
- `themes/nhk-v3/style.css`
- focused presentation/navigation tests

Footer consumes the shared navigation definition and should not need bespoke route data.

## Explicit non-goals

- No FSE/theme migration.
- No semantic schema change.
- No new CPT/taxonomy.
- No Product/Specimen relation workaround.
- No Dictionary approval/backfill.
- No Media binary regeneration or bulk thumbnail regeneration.
- No route rename/reprojection.
- No production deploy or cutover.
- No redesign of Admin workbenches.

## Acceptance matrix

- Four primary gateways visible in the compact hero.
- Product, Brand, Clock Type and Dictionary are top-level header items.
- Only one global discovery dropdown exists.
- No separate LOẠI desktop/mobile dropdown in global header.
- Footer repeats the four lookup destinations.
- Latest feed remains directly after hero and bounded to four items.
- Article card and latest feed attachment images request compact derivatives.
- Images remain uncropped.
- Hình ảnh and Video remain discoverable from header, homepage and footer.
- Dictionary route is visible even when public item count is zero.
- Existing semantic owners, routes, Graph scope and publication rules remain unchanged.
