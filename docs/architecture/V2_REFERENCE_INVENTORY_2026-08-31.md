# V2 Read-only Reference Inventory — 2026-08-31

This is a behavioral and route inventory, not a migration or parity claim.
The source was opened read-only in the in-app Browser at
[`demo.1945.vn`](https://demo.1945.vn/). No V2 data, forms or settings were
mutated.

## Observed public surfaces

| Route | Visible behavior | Observed signal | V3 implication |
|---|---|---|---|
| `/` | Discovery homepage | Hero search, latest feed, Tri thức feed, brand/model discovery, sidebar/footer navigation | Homepage must be a content gateway, not a static landing page |
| `/tri-thuc/` | Knowledge archive | H1 `Trung tâm tri thức`, visible article feed, pagination/search and archive sections | Native WP Post archive with filters/pagination and semantic enrichment |
| `/goc-chia-se/` | Sharing archive | H1 `Góc chia sẻ`, explicit public empty state | Keep category route and empty state; do not invent fixture content |
| `/thuong-hieu/` | Brand/model archive | H1 `Thương hiệu & Model`, separate brand and model sections with visible cards | Domain-specific Authority archive, not one generic archive; V3 keeps `/brand/` and `/model/` canonical with a compatibility alias |
| `/video/` | Video archive | H1 `Thư viện video`, explicit public empty state | First-class external Video archive with honest empty state |
| `/thu-vien/` | Media library | H1 `Video & Ảnh đồng hồ cổ`, media type filters, explicit empty states | Media archive/gallery with type filters and pagination |
| `/hien-vat/` | Specimen archive | H1 `Hồ sơ hiện vật`, explicit public empty state | Separate physical specimen route from Product |
| `/odo/` | Brand detail | H1 `Odo`, identity page with breadcrumb and related navigation | V3 canonical `/brand/{stable-key}/`; V3 redirects only when exactly one active Brand has the public slug and no native WP content owns the path |
| `/odo/odo-39/` | Model detail | H1 `Odo 39`, breadcrumb back to Odo and identity content | V3 canonical `/model/{stable-key}/`; resolver requires one active Brand slug and one active Model slug, with no name-only identity merge or native WP override |
| `/tri-thuc/{slug}/` | Editorial article detail | H1, overview/body, breadcrumb and previous/next article navigation | Native WordPress Post single with Graph-derived related content and no body projection |
| `/tim-kiem/?q=Odo` | Unified search | Grouped canonical Odo/Model results with search pagination | V3 uses native `/?s=` plus grouped semantic results through the application boundary |

## Navigation and information architecture

The visible shell uses the visitor-facing concepts `Thương hiệu & Model`,
`Nhận diện & Bộ máy`, `Âm nhạc đồng hồ cổ`, `Tri thức`, `Góc chia sẻ`,
`Chuyện đồng hồ` and `Video & Ảnh`, with a prominent global search. Footer
groups discovery, media and research links. V3 may simplify or reorganize
these labels under the approved NHK design contract, but must preserve the
underlying discovery paths and avoid exposing internal Governance/Authority
terminology to visitors.

## Inventory limits and next evidence

The route and interaction observations above were made in the in-app Browser
against V2 in read-only mode. They are page behavior evidence, not complete
database counts. A direct V2 REST endpoint and live credentials remain absent;
the separate restored-backup inventory records 800 posts, 1,301 entities,
relations, sources, evidence, media assets and URL candidates with explicit
migration reason codes. No page sample is used to infer migration counts, and
no V2 mutation was performed.
