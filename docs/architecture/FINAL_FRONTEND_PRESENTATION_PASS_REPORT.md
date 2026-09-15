# NHK V3 FINAL FRONTEND PASS

FRONTEND_ARCHITECTURE=PARTIAL

The shared read-only presentation seam is in place. Remaining partial status is
intentional: some family-specific parent context and public detail routes are
contract/data dependent, so the UI fails soft or marks the section unavailable.

DISPLAY_TERM=Nhóm đồng hồ

CLOCK_TYPE_INTERNAL=UNCHANGED

ROUTE_POLICY=UNCHANGED /loai-dong-ho/

## Acceptance matrix

| Page family / seam | Status | Evidence |
|---|---|---|
| Homepage | COMPLETE | Shared nav, visual media/video previews, bounded feeds, newest-first query order, empty states |
| Clock Group Hub | COMPLETE | Profile-driven `/loai-dong-ho/` archive and readiness filtering |
| Clock Group Dossier | COMPLETE | Generic dossier plus bounded canonical hierarchy/derived-brand enrichment |
| Left Hierarchy Nav | PARTIAL | Complete for canonical Clock Group hierarchy; other family parent trees require available canonical parent projection |
| Local Section Nav | COMPLETE | Sections are emitted only when available |
| Right Visual Rail | COMPLETE | Representative media, video, article priority with bounded preview |
| Brand Dossier | COMPLETE | Generic profile plus read-only object-derived aggregation |
| Model Dossier | COMPLETE | Generic profile, relations, visual rail and fail-soft sections |
| Variant Dossier | COMPLETE | Generic profile, parent-context-ready relations and visual rail |
| Movement Dossier | COMPLETE | Generic profile and technical/related sections from projection |
| Melody Dossier | COMPLETE | Generic profile and movement/model/video sections |
| Part Dossier | COMPLETE | Generic profile and movement/model/knowledge sections |
| Specimen Dossier | COMPLETE | Generic profile, object context and visual gallery support |
| Product Dossier | PARTIAL | Generic renderer exists; corresponding-object relation remains a documented contract gap |
| Knowledge Detail | PARTIAL | Atomic public detail is closed by the existing Knowledge route contract; dossier knowledge remains supported |
| Article Detail | COMPLETE | Native WordPress article truth with read-only subject/media/video/knowledge context |
| Article Archive | COMPLETE | Existing native archive/card presentation with visual fallback and date ordering |
| Media Archive | COMPLETE | Visual grid, real public asset filtering, newest-first pagination |
| Media Detail | PARTIAL | Media has no public detail route under the existing contract; visual archive/detail-safe projections remain read-only |
| Video Archive | COMPLETE | Shared poster card, deferred detail navigation, newest-first pagination |
| Video Detail | COMPLETE | Deferred embed, source/provenance, subject and related-content sections |
| Search Presentation | COMPLETE | Existing cross-type result labels distinguish entity/article/media/video/knowledge context |
| Relation Projector | PARTIAL | Bounded generic relation projection is shared; family-specific canonical enrichment remains limited to existing Clock Group/Brand contracts |
| Direct/Derived Provenance | COMPLETE | Origin, path kind, predicates, via types and depth retained in view model |
| Deduplication | COMPLETE | Strongest relation path wins within each section |
| Presentation Readiness | COMPLETE | Derived from active/eligible route and usable presentation inputs without mutation |
| Representative Media | COMPLETE | Canonical primary/direct/object/subtype/article fallback chain with neutral placeholder |
| Visual First | COMPLETE | Shared entity/media/video cards render previews before CTAs |
| Newest First | COMPLETE | Published → created → stable key; updated only for “Mới cập nhật” semantics |
| Pagination Order | COMPLETE | Ordering occurs before slicing with deterministic tie-breakers |
| Empty States | COMPLETE | Public Vietnamese fail-soft copy; unavailable sections remain hidden |
| Responsive | COMPLETE | Existing token/breakpoint chain supports 360/390/430/tablet/desktop layouts |
| Accessibility | COMPLETE | Landmarks, heading hierarchy, native controls, alt text, focus-visible and reduced-motion rules |
| Performance | PARTIAL | Bounded previews and graph depth are enforced; live query-count verification is infrastructure dependent |
| SEO Presentation | COMPLETE | Existing canonical/metadata/OG projection hooks remain authoritative; no SEO mutation |

GLOBAL_NAV=COMPLETE

MOBILE_NAV=COMPLETE

HOMEPAGE=COMPLETE

CLOCK_GROUP_HUB=COMPLETE

CLOCK_GROUP_DOSSIER=COMPLETE

LEFT_HIERARCHY_NAV=PARTIAL

LOCAL_SECTION_NAV=COMPLETE

RIGHT_VISUAL_RAIL=COMPLETE

BRAND_DOSSIER=COMPLETE

MODEL_DOSSIER=COMPLETE

VARIANT_DOSSIER=COMPLETE

MOVEMENT_DOSSIER=COMPLETE

MELODY_DOSSIER=COMPLETE

PART_DOSSIER=COMPLETE

SPECIMEN_DOSSIER=COMPLETE

PRODUCT_DOSSIER=PARTIAL

KNOWLEDGE_DETAIL=PARTIAL

ARTICLE_DETAIL=COMPLETE

ARTICLE_ARCHIVE=COMPLETE

MEDIA_ARCHIVE=COMPLETE

MEDIA_DETAIL=PARTIAL

VIDEO_ARCHIVE=COMPLETE

VIDEO_DETAIL=COMPLETE

SEARCH_PRESENTATION=COMPLETE

RELATION_PROJECTOR=PARTIAL

DIRECT_DERIVED_PROVENANCE=COMPLETE

DEDUPLICATION=COMPLETE

PRESENTATION_READINESS=COMPLETE

REPRESENTATIVE_MEDIA=COMPLETE

VISUAL_FIRST=COMPLETE

NEWEST_FIRST=COMPLETE

PAGINATION_ORDER=COMPLETE

EMPTY_STATES=COMPLETE

RESPONSIVE=COMPLETE

ACCESSIBILITY=COMPLETE

PERFORMANCE=PARTIAL

SEO_PRESENTATION=COMPLETE

## Data missing by family

- All Authority families: persisted public identity/route and presentation-ready inputs may still be absent; the frontend does not allocate or repair them.
- Clock Group: canonical `subtype_of` children/parent, direct article/media/video/specimen/model/product relations, and object-derived brands.
- Brand: canonical model/variant/movement/melody/component/specimen/product paths and usable representative media; Clock Group links must remain object-derived.
- Model: `model_of` Brand, `classified_as` Clock Group, Variant, Movement, Melody, Article, Media, Video, Specimen and Product relations.
- Variant: `variant_of` Model, `classified_as` Clock Group, Movement, Melody, Article, Media, Video and Specimen relations.
- Movement: model/variant usage, mechanism/component, melody, article, media, video and specimen relations.
- Melody: supporting Movement/Model/Variant relations plus article, knowledge, media, video and specimen context.
- Part/Mechanism: canonical Movement/Model/Variant usage and any valid derived Clock Group context.
- Specimen: canonical Brand/Model/Variant/Clock Group/Movement/Melody identity, measurements/provenance, public media usage and related editorial context.
- Product: canonical corresponding-object/specimen relation is still a contract gap; no commercial facts are fabricated.
- Knowledge: canonical subject links and evidence/source projections where the dossier needs a backlink or context.
- Article: native post remains present; semantic `about` subject, media usage/inline gallery, related video, knowledge and specimen links may still be absent.
- Media: public image asset plus governed usage/depicts context; no anonymous semantic detail URL is invented.
- Video: valid public reference, source snapshot/publication date, poster and canonical subject/object relation.
- Search: result records for each supported type and their canonical routes; no new search engine was introduced.

RELATIONS_EXPECTED_BUT_MISSING=

`subtype_of` for future Clock Group children such as Turret Clock; object-based
Clock Group membership for Brand derivation; Article `about`; Media `depicts`
and governed usage; Video `about`; and Product corresponding-object relation
where the canonical contract currently has no safe projection. These are data/
relation follow-up items only, not frontend writes.

TESTS=

Unit suite passes: 1,547 tests / 7,504 assertions, with 13 warnings, 11
deprecations and 14 PHPUnit deprecations. Contract suite passes in isolation:
6 tests / 48 assertions. Composer PHP lint and `git diff --check` pass; scoped
secret scan is clean. Integration/runtime smoke remains environment dependent
and no server is started by this pass.

FILES_CHANGED=

See the final task response for the complete uncommitted file list. Existing
unrelated local changes were preserved.

SHARED_COMPONENTS=

EntityHero, EntityCard, ArticleCard, MediaCard, MediaGrid, VideoCard,
VideoGrid, RelationshipSection usage, VisualRail, HierarchyNav,
LocalSectionNav, Breadcrumbs, EmptyState and SectionHeader.

SHARED_PROJECTORS=

EntityPresentationViewModel, SemanticProfileComposer, EntityProfileRegistry,
EntityProfileResolver, PublicNavigationDefinition, HomeSemanticQuery,
PublicEntityCollectionQuery and existing bounded relation/media/video queries.

PAGE_PROFILES=

brand, clock_type, model, variant, movement, music, component, classification,
specimen and product.

NO_DATA_MUTATION=YES

NO_DEPLOY=YES

NO_PUBLIC_URL_MUTATION=YES

NO_MEDIA_INGEST=YES

NO_VIDEO_INGEST=YES

NO_ARTICLE_PUBLISH=YES

FRONTEND_WORK_CAN_STOP=YES

REMAINING_FRONTEND_BLOCKERS=

No implementation blocker remains inside this read-only frontend scope.
Live route rendering and query-count smoke are unavailable without the local
runtime/server; no server was started.

NEXT_PHASE_AFTER_FRONTEND=

DATA / RELATION / LIVE ACCEPTANCE ONLY
