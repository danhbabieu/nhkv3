# Côn hoa thị — public dossier and source-policy audit

Date: 2026-10-06
Scope: read-only public projection, dictionary routing, and international research boundary
Status: `NO_DATA_MUTATION`

## Executive result

The canonical Component contract is already the correct public owner:

- canonical UUID: `01a10c73-50dd-77bf-ac78-c0c4f66b2208`
- stable key: `nhk:component:con-hoa-thi`
- public route: `/linh-kien/con-hoa-thi/`

The existing public sentence “Côn hoa thị được ghi nhận trên các mẫu đồng hồ Junghans.” is not accepted as a public factual claim by this audit. It needs a governed Knowledge claim with international support and an explicitly documented scope. The Vietnamese source `Vang Vọng — Junghans mũi tên gông hoa thị` (`https://vangvong.com/shop/dong-ho-junghans-mui-ten-gong-hoa-thi-choi-chuong-cuc-hay/`) is retained only as historical/internal provenance and is excluded from public source display by `PUBLIC_RESEARCH_SOURCE_DISPLAY_POLICY`.

No live TEST MCP read-back could be completed from this checkout: the public demo route and candidate read endpoint were inaccessible to the available read-only web reader, and no repository snapshot contains the supplied canonical UUID or either supplied Dictionary Entry UUID. This is recorded as infrastructure/data unavailability, not as empty semantic data.

## Read-only capture matrix

| Surface | Requested read | Result | Classification |
|---|---|---|---|
| Authority | Component UUID, stable key, name, route | Contract and unit route proof exist; live object read unavailable | `UNAVAILABLE_LIVE_READER` |
| Dossier | Component public dossier | Projection seam exists; live payload unavailable | `UNAVAILABLE_LIVE_READER` |
| Knowledge | Direct subject-scoped claims | No live/read-only payload available | `UNAVAILABLE_LIVE_READER` |
| Source/Evidence | Public support and source cards | Policy implemented; live records unavailable | `UNAVAILABLE_LIVE_READER` |
| Graph | Component neighborhood | No live/read-only payload available | `UNAVAILABLE_LIVE_READER` |
| Media/Video/Articles | Related public material | No live/read-only payload available | `UNAVAILABLE_LIVE_READER` |
| Dictionary | Entry, Sense, forms, canonical owner | Projection tests cover delegated owner and ambiguity; supplied live Entry IDs absent from repo snapshots | `UNAVAILABLE_LIVE_READER` |

## Claim/source/evidence matrix

| Candidate claim | Scope | Source state | Public decision | Required continuation |
|---|---|---|---|---|
| “Côn hoa thị được ghi nhận trên các mẫu đồng hồ Junghans.” | entity/component; wording currently unclear as to literal term equivalence | Vietnamese Vang Vọng URL only in supplied brief | `QUALIFIED_OR_BLOCKED`; do not render as evidenced fact | Capture exact physical/configuration wording and bind an international source through Governance |
| Gong rods/gong-block/configuration relationship | physical/configuration; not a Vietnamese-term equivalence | International patent/catalogue/institutional material is available from research | `KEEP_PUBLIC` only when claim wording remains within source scope | Create canonical claim/evidence packet with locator and excerpt |
| Literal equivalence between “Côn hoa thị” and an international horology term | lexical/terminology | No exact equivalence established by research | `UNSUPPORTED` | Keep lexical note only; do not assert synonymy |

### International research boundary

The Junghans Archive provides historical catalogue material; the Polish National Museum of Technology record describes a Junghans wall clock and a spiral steel-wire gong; German patent DE1458431U documents gong-block/resonance-plate/rod relationships; specialist horology material describes rods mounted to a gong block. These sources support bounded physical/configuration statements, not an automatic translation or synonym for `Côn hoa thị`.

Research references:

- [Junghans Archive catalogues](https://junghansarchiv.de/en/catalogues)
- [Polish National Museum of Technology — Junghans wall clock](https://nmt.waw.pl/en/zbiory/zegar-scienny-szafkowy/)
- [German patent DE1458431U](https://patents.google.com/patent/DE1458431U/en)
- [Gustav Becker 1912 catalogue](https://de.scribd.com/document/527543824/Gustav-Becker-Hauptkatalog-Main-Catalog-1912-No-200)
- [Specialist explanation of gong rods and gong blocks](https://antiquevintageclock.com/2018/08/20/what-is-this-clock-thing-for-2-the-strike-rod-lock/)

## Dossier enrichment classification

This is a projection/enrichment inventory, not an authorization to write data.

| Area | Current classification | Reason / next bounded read |
|---|---|---|
| Authority identity and route | `EXISTING` | Canonical Component owner and route are defined by the registry/resolver |
| Direct Knowledge | `MISSING_OR_UNAVAILABLE` | No live subject-scoped read-back; no claim is fabricated |
| International Evidence | `MISSING_OR_UNAVAILABLE` | Research references exist, but no governed Evidence record was created |
| Relations | `MISSING_OR_UNAVAILABLE` | No live Graph neighborhood available |
| Media | `MISSING_OR_UNAVAILABLE` | No live Media/MediaUsage read-back available |
| Video | `MISSING_OR_UNAVAILABLE` | No live Video read-back available |
| Articles | `MISSING_OR_UNAVAILABLE` | WordPress editorial search/read-back unavailable in this audit |
| Lexical mapping | `EXISTING_PROJECTION_WITH_DUPLICATE_REVIEW` | Entry→Sense→owner seam exists; two duplicate Entry IDs need lineage review |
| Unsupported literal equivalence | `UNSUPPORTED` | No international source proves the Vietnamese term mapping |

The public dossier must omit empty sections and must not duplicate raw repository payloads. The existing `SemanticDossierQuery`/`EntityKnowledgeProjection` seam is the permitted read-only continuation path.

## Duplicate Dictionary Entry analysis

Supplied duplicate Entry records:

- `01a106b0-2b77-7caa-8f0b-b79cf62ad1a7`
- `01a10c9f-4890-712a-b9c1-a326c8767f0c`

No deterministic survivor can be selected from the available checkout. Creation/update timestamps, persisted public paths, forms, Sense mappings, canonical-owner references, Capture lineage, tool receipts, and commit lineage for these exact records were not available through the read-only surface. Therefore both remain preserved; neither was merged, deleted, redirected, or rewritten.

Required repair review packet, before any mutation:

1. Read both Entries, all active Forms and Senses, semantic references, revisions, status, and public-slug materialization.
2. Compare creation/update lineage, Capture/request IDs, tool receipts, and commit/deployment identity.
3. Prove one canonical Entry or record unresolved ambiguity; do not infer from UUID order.
4. If one survivor is proven, prepare a server-issued exact Governance packet for alias/form migration and one-hop redirect; preserve historical provenance and verify idempotent canonical read-back.
5. If ambiguity remains, return `AMBIGUOUS`/no selected URL and request owner review.

## Standalone Dictionary terms

The current projection law preserves dedicated/indexable Dictionary entries for:

- `Kính kim cương`
- `Kính rào`
- `ÔĐô 30 kim cương`
- `ÔĐô 36 kim cương`

Unit coverage confirms this law. Live MCP verification was unavailable in this audit, so the runtime state is not overstated.

## Bounded continuation packets

No enrichment packet was executed. The next authorized read/plan packet may request:

- exact Component read-back by UUID/stable key;
- direct Knowledge and public-safe Evidence/Source read-back;
- Graph neighborhood with registered predicates only;
- related Media, Video, WordPress Article, and Dictionary projections;
- exact duplicate Entry lineage read-back;
- a separately signed Governance packet only after owner confirmation and duplicate audit.

Every packet must be bound to the current Capture/request, exact canonical IDs and revisions, dependency closure, registered operation family, expiry, idempotency key, and server signature. A missing or stale packet remains fail-closed.

## Audit controls

- `NO_DATA_MUTATION`: true
- Vietnamese public source removed: no; historical provenance preserved, public display filtered
- Legacy article bodies migrated: no
- Direct database/WordPress writer used: no
- Dictionary Entries merged/deleted: no
- Literal “Côn hoa thị” equivalence asserted: no
