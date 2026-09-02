# NHK V3 Public Hub Matrix

> **NON-NORMATIVE.** This is a public-route evidence matrix. If it conflicts
> with `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution controls.

Status: approved public information architecture; read-only design
checkpoint, 2026-09-02. This matrix defines presentation routes and query
boundaries. It does not create Authority rows, assign slugs, write redirects,
repair Graph edges, or migrate article bodies.

| Domain | Menu label | Hub URL | Detail URL | Global discovery? | Primary menu? | Query service | Public eligibility | SEO indexable | Sitemap | Breadcrumb root | Empty-state policy | Legacy redirect |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Brand | Thương hiệu | `/thuong-hieu/` | `/{brand-slug}/` | Yes | Yes | `PublicEntityCollectionQuery` + `PublicRouteResolver` | Active, valid public identity, unique slug, and routeable; no parent required | Yes | Hub and eligible details | Thương hiệu | Honest no-results state; never seed or recreate rows | `/brand/` → `/thuong-hieu/` in one 301 |
| Model | Mẫu | `/mau/` | `/{brand-slug}/{model-slug}/` | Yes | Yes | `PublicEntityCollectionQuery` + structural parent context | Active, valid identity, exactly one safe Brand parent under the transition rule, unique nested slug | Yes | Hub and eligible details | Mẫu | Explain unavailable parent/empty result without exposing internal reason codes | `/model/` → `/mau/` in one 301 |
| Variant | Biến thể | No global hub by default | `/{brand-slug}/{model-slug}/{variant-slug}/` | No | No; linked from Model/Brand pages | `PublicEntityCollectionQuery` + structural parent context | Active, valid identity, exactly one safe Model parent and a complete Brand chain under the transition rule, unique nested slug | Yes for eligible details | Eligible details only | Mẫu | No global enumeration; missing structural context is an honest unavailable state | `/variant/` is retired as a canonical destination; any legacy exposure gets one approved 301 or 404 according to route ledger |
| Movement | Bộ máy | `/bo-may/` | `/bo-may/{movement-slug}/` | Yes | Yes | `PublicEntityCollectionQuery` + `PublicRouteResolver` | Active, valid identity, unique slug; Brand is not required | Yes | Hub and eligible details | Bộ máy | Honest empty state; shared/reusable Movement remains visible without fake Brand ownership | `/movement/` → `/bo-may/` in one 301 |
| Music | Bản nhạc | `/ban-nhac/` | `/ban-nhac/{music-slug}/` | Yes | Yes | `PublicEntityCollectionQuery` + `PublicRouteResolver` | Active, valid identity, unique slug; Brand is not required | Yes | Hub and eligible details | Bản nhạc | Honest empty state; absence of Brand relation must not hide Music | `/music/` → `/ban-nhac/` in one 301; `/am-nhac/` remains a compatibility alias to the canonical hub |
| Component | Linh kiện | `/linh-kien/` | `/linh-kien/{component-slug}/` | Yes | Yes | `PublicEntityCollectionQuery` + `PublicRouteResolver` | Active, valid identity, unique slug; Brand is not required | Yes | Hub and eligible details | Linh kiện | Show only reusable semantic components supported by the registry; no adjective-only fixtures | `/component/` → `/linh-kien/` in one 301 |
| Classification | Phân loại | `/phan-loai/` | `/phan-loai/{classification-slug}/` | Yes | No by default | `PublicEntityCollectionQuery` + `PublicRouteResolver` | Active, valid identity, unique slug; Brand is not required | Yes | Hub and eligible details | Phân loại | Honest empty state; classification is a reusable descriptive category, not physical identity | `/classification/` → `/phan-loai/` in one 301 |
| Knowledge | Tri thức | `/tri-thuc/` | Existing Knowledge public contract | Yes | Yes | Existing `KnowledgePageQuery` under the public claim/source/evidence gate | Active, public and verified claim/source/evidence boundary; no raw provenance endpoint as an SEO page | Yes for approved Knowledge projections | Existing Knowledge sitemap policy | Tri thức | Honest empty state; no atomic-claim fixture pages | Technical `/knowledge/` exposure is a compatibility route only and must resolve in one hop to the existing Knowledge contract |
| Specimen | Hiện vật | `/hien-vat/` | `/hien-vat/{specimen-slug}/` | Yes | Yes | `PublicEntityCollectionQuery` + `PublicRouteResolver` | Active concrete physical object with valid identity and unique slug; Product is never substituted for it | Yes | Hub and eligible details | Hiện vật | Honest empty state; absence of specimens is data evidence, not permission to fabricate them | `/specimen/` → `/hien-vat/` in one 301 |
| Product | Sản phẩm | `/san-pham/` | `/san-pham/{product-slug}/` | Yes | No until a real public commerce section exists | `PublicEntityCollectionQuery` + `PublicRouteResolver` | Active listing/offer with valid identity and unique slug; Product is not a Specimen identity | Yes only when the public product section is intentionally enabled | Only when the public product section is intentionally enabled | Sản phẩm | Hide the module when commerce is not enabled; never expose an Authority type merely because it is registered | `/product/` → `/san-pham/` in one 301 when the public section exists; otherwise preserve an honest unavailable response |
| Video | Video | `/video/` | Existing Video public contract | Yes | Yes | Existing `MediaVideoPageQuery` under the validated external-reference predicate | Active canonical external reference with valid supported platform/ID and public presentation fields | Yes for eligible Video details | Existing Video sitemap policy | Video | Honest empty state when no active valid reference exists; no synthetic Video row or broken embed | Technical route exposure is a compatibility route only and must resolve in one hop to the existing Video contract |
| Comparison | So sánh | `/so-sanh/` | Query-driven; no persisted comparison identity | Yes, as a surface | Yes | `ComparisonPageQuery` over two active public Authority references | Both references resolve to active, routeable public entities; no comparison is persisted | Hub/surface may be indexable; query variants are canonicalized or noindexed per SEO policy | Hub only; no synthetic comparison records | So sánh | Explain missing, ambiguous or ineligible references; never invent a comparison | `/comparison/` → `/so-sanh/` in one 301 |

## Route laws

1. A canonical discovery link must use the matrix URL, never an internal type
   namespace. Legacy technical hubs are redirect inputs, not menu targets,
   archive canonicals or breadcrumb roots.
2. Every redirect is one hop to the final canonical URL. Redirect resolution
   must fail closed when identity, parent context or eligibility is ambiguous.
3. Hub, card, REST and search consumers use the same public identity and
   eligibility result. Templates only render the resulting read model.
4. A missing row, unavailable storage, or blocked structural context produces
   an honest empty/unavailable state. The application must not seed, import,
   recreate or infer semantic data to fill a hub.
