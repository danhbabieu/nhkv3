# V2 Read-only Reference Inventory — 2026-08-31

This is a behavioral and route inventory, not a migration or parity claim.
The source was opened read-only in the in-app Browser at
[`demo.1945.vn`](https://demo.1945.vn/). No V2 data, forms or settings were
mutated.

## Observed public surfaces

| Route | Visible behavior | Observed signal | V3 implication |
|---|---|---|---|
| `/` | Discovery homepage | Hero search, latest feed, Tri thức feed, brand/model discovery, sidebar/footer navigation | Homepage must be a content gateway, not a static landing page |
| `/tri-thuc/` | Knowledge archive | H1 `Trung tâm tri thức`, 12 visible article cards, pagination, search and archive sections | Native WP Post archive with filters/pagination and semantic enrichment |
| `/goc-chia-se/` | Sharing archive | H1 `Góc chia sẻ`, explicit public empty state | Keep category route and empty state; do not invent fixture content |
| `/thuong-hieu/` | Brand/model archive | H1 `Thương hiệu & Model`, 15 visible cards, separate brand and model sections | Domain-specific Authority archive, not one generic archive |
| `/video/` | Video archive | H1 `Thư viện video`, explicit public empty state | First-class external Video archive with honest empty state |
| `/thu-vien/` | Media library | H1 `Video & Ảnh đồng hồ cổ`, media type filters, explicit empty states | Media archive/gallery with type filters and pagination |
| `/hien-vat/` | Specimen archive | H1 `Hồ sơ hiện vật`, explicit public empty state | Separate physical specimen route from Product |

## Navigation and information architecture

The visible shell uses the visitor-facing concepts `Thương hiệu & Model`,
`Nhận diện & Bộ máy`, `Âm nhạc đồng hồ cổ`, `Tri thức`, `Góc chia sẻ`,
`Chuyện đồng hồ` and `Video & Ảnh`, with a prominent global search. Footer
groups discovery, media and research links. V3 may simplify or reorganize
these labels under the approved NHK design contract, but must preserve the
underlying discovery paths and avoid exposing internal Governance/Authority
terminology to visitors.

## Inventory limits and next evidence

The visible card counts above are page observations only and are not complete
V2 database counts. A direct V2 WordPress REST endpoint request was blocked by
the browser client, and no V2 database credential/export is present in this
workspace. Therefore Posts, categories, attachments, canonical entities,
Knowledge, Sources, Relations, Videos and URLs remain count-pending. P10 must
obtain a read-only DB/export/API inventory before identity mapping or any real
V3 migration. No count is inferred from the visible page sample.
