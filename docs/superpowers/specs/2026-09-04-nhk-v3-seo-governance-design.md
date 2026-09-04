# NHK V3 Governed SEO Projection Architecture Design

**Status:** owner-approved architectural design, 2026-09-04.

> **NON-NORMATIVE DESIGN SPEC.** This document is subordinate to
> `docs/constitution/NHK_V3_CONSTITUTION.md`. It creates no semantic type,
> predicate, operation, writer, data migration, runtime capability or production
> mutation by itself.

## 1. Goal

Create one coherent SEO governance architecture for NHK V3 so Article,
Authority/Entity, Media/Image, Video, Knowledge-derived presentation, canonical
URLs, structured data, internal links and sitemaps all reuse the same canonical
owners and fail-closed publication rules.

The system must improve search discoverability without allowing SEO to become a
second semantic store or writer.

The governing principle is:

> **SEO, discovery and structured-data layers are projections only. They may
> synthesize presentation from eligible canonical data, but they may not create
> canonical semantic identity, Knowledge, Source/Evidence or Graph truth.**

This sentence is a design-level restatement of existing constitutional/public-
projection law. This design does **not** require a Constitution amendment.

## 2. Existing authority and constraints

Read and apply in this order:

1. `AGENTS.md`.
2. `docs/constitution/READ_FIRST.md`.
3. `docs/constitution/NHK_V3_CONSTITUTION.md`.
4. `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`.
5. current approved domain contracts.
6. executable registries/catalogs and current runtime boundaries.
7. fresh runtime read-back when environment availability matters.

Current non-negotiable owners:

| Concern | Canonical owner |
|---|---|
| Article editorial title/body/excerpt/order/public editorial URL | WordPress `wp_posts` boundary |
| Authority identity | Authority repository/service |
| Public URL identity/history | Public Identity boundary |
| Semantic relation | Graph |
| Knowledge claim | Knowledge |
| provenance/support | Source + Evidence |
| Media identity | Media |
| binary/derivative | MediaAsset |
| contextual image placement/alt/caption/role | MediaUsage + WordPress editorial placement |
| Video identity | Video external reference |
| SEO/meta/schema/sitemap/internal-link presentation | SEO/Public Projection layer only |

SEO never becomes a persistence fallback for another owner.

## 3. Existing SEO documents to preserve

The repository already contains useful approved contracts. They are extended,
not replaced:

- `docs/seo/ARTICLE_SEO_PROJECTION_CONTRACT.md`
- `docs/seo/VIDEO_SEO_PROJECTION_CONTRACT.md`
- `docs/seo/LIVING_KNOWLEDGE_SEO_STABILITY_CONTRACT.md`

The new architecture adds missing shared and domain boundaries without creating
an alternative hierarchy.

## 4. Target documentation architecture

### 4.1 Create

- `docs/seo/NHK_V3_SEO_CORE_CONTRACT.md`
- `docs/seo/ENTITY_SEO_PROJECTION_CONTRACT.md`
- `docs/seo/MEDIA_IMAGE_SEO_PROJECTION_CONTRACT.md`
- `docs/seo/SITEMAP_INDEXABILITY_CONTRACT.md`

### 4.2 Update

- `docs/seo/ARTICLE_SEO_PROJECTION_CONTRACT.md`
- `docs/seo/VIDEO_SEO_PROJECTION_CONTRACT.md`
- `docs/seo/LIVING_KNOWLEDGE_SEO_STABILITY_CONTRACT.md`
- `docs/constitution/READ_FIRST.md`
- `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`

### 4.3 Do not create yet

Do not create an implementation-status contract before implementation exists.
A future implementation/readiness checkpoint may be added only after executable
code and tests exist.

Do not create:

- an `SEO_CONSTITUTION`;
- a separate AEO/GEO semantic layer;
- an SEO Knowledge store;
- an SEO relation registry;
- a new entity type solely to satisfy structured data.

## 5. SEO Core Contract design

`NHK_V3_SEO_CORE_CONTRACT.md` becomes the cross-cutting SEO contract under the
Constitution.

It owns these rules only.

### 5.1 Projection-only invariant

SEO may project:

- page title proposal/rendered title;
- H1/H2 presentation;
- meta description;
- canonical tag;
- robots/indexability output;
- Open Graph/social metadata;
- structured data;
- preferred image/video metadata;
- internal links;
- sitemap entries;
- search snippets/navigation copy;
- FAQ editorial presentation.

SEO may not create or silently change:

- canonical UUID;
- stable key;
- Authority type;
- Knowledge claim;
- Source or Evidence;
- Graph edge;
- Media identity;
- Video identity;
- Public Identity allocation/history;
- Product–Specimen relation;
- semantic facts inferred from prose, OCR, filename, alt, caption or transcript.

### 5.2 Canonical identity alignment

SEO output must consume the canonical URL from the owning public projection.
It must not independently derive durable slugs from titles or canonical names
once persisted Public Identity is applicable.

All of these must agree for one public page:

- rendered route;
- self-referential canonical;
- internal links;
- sitemap URL;
- structured-data page URL / `@id` where applicable;
- Open Graph URL.

A disagreement is a publication blocker, not an invitation to choose a URL in
the SEO layer.

### 5.3 Search intent versus semantic identity

Search intent is editorial/planning metadata. It is not semantic identity.

A canonical Entity may support many search intents through one hub plus bounded
supporting Articles. A new keyword or query does not automatically justify a new
URL.

### 5.4 Structured-data truthfulness

Structured data must describe visible, eligible public content and canonical
objects already known by their owning contexts.

It must never manufacture:

- ratings;
- reviews;
- price/availability facts;
- chronology;
- ownership;
- Brand/Model/Variant relationships;
- Music/Movement compatibility;
- product/specimen linkage;
- evidence strength;
- video facts not supplied by validated Video metadata.

Structured-data eligibility and Google rich-result eligibility are not semantic
truth and are not guaranteed search outcomes.

### 5.5 External search guidance boundary

Search-engine documentation is external implementation guidance, not NHK
semantic authority. Search engines may change rendering/rich-result behavior
without changing NHK canonical truth.

Current Google guidance relevant to this design, verified 2026-09-04:

- Sitemap URLs should be preferred canonical URLs intended for Search.
- Redirect, `rel=canonical`, sitemap and consistent internal linking are
  canonicalization signals; sitemap is weaker than redirect/canonical.
- FAQ rich-result documentation has been removed after FAQ rich results stopped
  appearing in Google Search in May 2026.
- Image understanding uses page context, descriptive filenames and especially
  contextual alt text; preferred page image may be indicated through structured
  data/image properties or `og:image`.
- Video eligibility requires an indexable watch page, embedded visible video and
  stable valid thumbnail; `VideoObject` can expose validated metadata.
- Google states normal SEO fundamentals continue to apply to generative AI
  features; this design therefore creates no separate AEO/GEO truth layer.

These external rules may be updated operationally without mutating canonical NHK
semantic records.

## 6. Entity SEO Projection Contract design

`ENTITY_SEO_PROJECTION_CONTRACT.md` applies to the nine Authority types:

- brand
- model
- variant
- movement
- music
- component
- classification
- specimen
- product

### 6.1 Common entity SEO package

Each eligible Entity projection may contain:

- canonical identity reference;
- persisted public URL result;
- canonical display name;
- approved public aliases when useful editorially;
- concise visible summary assembled from eligible canonical data;
- structured key facts;
- representative Media;
- bounded related entities from Graph-derived projection;
- related Articles;
- related Videos;
- related public Knowledge-derived presentation where allowed;
- breadcrumb;
- SEO title/description projection;
- structured-data applicability result;
- indexability result with reason codes.

### 6.2 Type-specific profiles

A single generic keyword template is prohibited.

**Brand** may emphasize identity, history, aliases, Models, representative
Movements/Music only when relations/facts support them, Articles, Videos and
Media.

**Model** may emphasize parent Brand, bounded Variant set, documented technical
context, Articles, Videos and Media.

**Variant** may emphasize parent Model, registered Movement/Music relations,
technical configuration, evidence-backed observations, Media, Video and related
Articles.

**Movement** may emphasize technical identity and registered relationships.

**Music** may emphasize canonical musical identity and documented compatible
Movements/Variants through registered Graph relations only.

**Component** and **Classification** remain taxonomy-like Authority knowledge
hubs only where sufficient public projection exists; existence alone does not
force indexing.

**Specimen** presents one physical identity and evidence/observation context.
It must not inherit mutable Product copy as physical truth.

**Product** presents one commercial listing/offer/context and may present
approved Specimen-derived context only if a future dedicated relation is
registered and present. Until then no generic `about`, payload, taxonomy or
postmeta fallback may create Product–Specimen linkage.

### 6.3 Entity indexability

Authority existence is necessary but not sufficient for indexing.

Indexability must require, at minimum:

- active canonical identity;
- resolvable public identity/route under current contract;
- non-ambiguous route;
- public eligibility;
- sufficient visible differentiated content for that projection type;
- no policy/compliance blocker;
- no duplicate/canonical conflict.

The exact minimum content profile is type-specific and reason-based. It must not
be expressed only as a numeric SEO score.

## 7. Media/Image SEO Projection Contract design

`MEDIA_IMAGE_SEO_PROJECTION_CONTRACT.md` formalizes the current Media law for
search/public presentation.

### 7.1 Identity separation

The contract must state explicitly:

`Media != MediaAsset != MediaUsage != WordPress attachment != standalone SEO page`

A source-original and all derivatives belong to the same canonical Media
identity. Filename, checksum, asset UUID and attachment URL never mint a second
semantic identity.

### 7.2 Usage roles

At minimum, SEO respects existing roles:

- representative;
- evidence;
- technical_detail;
- Article featured/inline contextual roles where defined.

Evidence or technical-detail imagery never automatically replaces the
representative image because it is newer or higher resolution.

### 7.3 Preferred image eligibility

Preferred page image should be chosen through the deterministic Media precedence
contract and must be:

- relevant to the page;
- publicly deliverable;
- stable;
- not a placeholder where real eligible Media is required;
- not private evidence;
- sufficiently representative;
- suitable for the target metadata surface.

Preferred-image projection may feed structured data and Open Graph, but remains a
projection from Media state.

### 7.4 Filename, alt and caption

Filename is a lightweight search/accessibility signal only.

Alt text must be contextual, concise and useful for accessibility. It may name
the canonical subject where visually appropriate but must not keyword-stuff or
assert non-visible facts.

Caption may add editorial context but is not automatic Evidence.

OCR/EXIF/recognition/filename/alt/caption may generate candidate observations
for separate semantic review but never auto-create Knowledge/Evidence/Graph.

### 7.5 Public route rule

MediaAsset delivery URLs remain delivery identities, not standalone indexable
content pages. Current Owner rulings that retire unsupported standalone Media
detail exposure remain authoritative implementation evidence under the
Constitution.

## 8. Video SEO Projection Contract extension

Retain the existing `VIDEO_SEO_PROJECTION_CONTRACT.md` and extend it rather than
creating another Video contract.

### 8.1 Canonical identity

Video identity remains `(platform, external_id)`; for YouTube, the validated
YouTube ID is identity, not the source URL literal.

### 8.2 Semantic target

A validated explicit `about` target remains authoritative for the Video's
semantic/enrichment subject. SEO title/description generation must not broaden a
Variant target to Model/Brand merely because free text contains broader names.

### 8.3 Watch-page eligibility

An indexable Video watch page requires:

- valid active Video external reference;
- valid public canonical watch URL;
- visible playable/embed representation;
- stable eligible thumbnail;
- useful visible NHK editorial package;
- required semantic attachment/completeness under the Video contract;
- no compliance/indexability blocker.

### 8.4 VideoObject and sitemap

`VideoObject`, Clip/key moments and video sitemap output consume only validated
available Video/public metadata.

Transcript absence may be a warning under existing Video law. Timestamps,
duration, chapters and source facts must never be guessed.

Transcript/source description/AI summary are candidate inputs, not automatic
Evidence.

## 9. Article SEO Projection Contract extension

Retain the current Article contract and add explicit create/update publication
rules.

### 9.1 Create preflight

Before a new V3-managed knowledge Article is published, SEO planning must know:

- editorial purpose/search intent;
- canonical subject(s) resolved through current semantic preflight;
- differentiated information gain versus existing Article/Entity projections;
- proposed title/H1/meta;
- canonical public editorial URL from WordPress boundary;
- internal-link candidates using public canonical routes;
- Media requirements and preferred image eligibility;
- Video candidates where useful;
- structured-data applicability;
- compliance state;
- indexability result;
- unresolved blockers.

### 9.2 Cannibalization/pre-existing intent check

A new query phrase does not automatically create a new Article.

Before creation, compare the intended page purpose against:

1. existing Article intents/topics;
2. canonical Entity hub coverage;
3. existing Video watch-page coverage where Video is the primary answer.

Outcomes are reason-based:

- `CREATE_DIFFERENTIATED_ARTICLE`
- `ENRICH_EXISTING_ARTICLE`
- `ENRICH_ENTITY_PROJECTION`
- `USE_EXISTING_VIDEO_PAGE`
- `AMBIGUOUS_INTENT`

These are planning outcomes, not semantic operations.

### 9.3 Update classification

Article updates must distinguish:

- typo/editorial correction;
- same-intent content enrichment;
- Knowledge-backed material update;
- media/video enrichment;
- primary-intent change;
- stable SEO identity change.

A title change does not automatically change slug/canonical URL.

A Knowledge update does not automatically rewrite the Article body.

A stable-core change remains subject to `LIVING_KNOWLEDGE_SEO_STABILITY_CONTRACT.md`.

### 9.4 Freshness

`dateModified`/sitemap `lastmod` should reflect meaningful content changes, not
bulk timestamp refreshes for perceived SEO freshness.

Implementation must use deterministic change classification rather than an
SEO-agent instruction to "make the article look new".

## 10. Living Knowledge SEO Stability extension

Keep the existing stable-core rule:

- canonical public URL;
- slug;
- canonical tag;
- H1 identity;
- established SEO title/identity;
- primary search intent;
- robots/indexability;
- schema identity/`@id`;
- redirect rules

are protected by default.

Living Knowledge may enrich affected fragments/facets only after eligibility,
provenance, semantic scope and publication gates pass.

FAQ content is a normal editorial/knowledge projection. It is not a privileged
semantic store and no longer receives architectural priority merely for Google
FAQ rich results.

## 11. Sitemap and Indexability Contract design

`SITEMAP_INDEXABILITY_CONTRACT.md` owns the shared inclusion decision.

### 11.1 Required flow

```text
Canonical owner
    -> Public identity / native editorial URL owner
    -> Public eligibility
    -> Indexability
    -> Canonical URL projection
    -> Sitemap inclusion
```

Database existence alone never implies sitemap inclusion.

### 11.2 Inclusion rule

A sitemap entry must represent a canonical URL the site actually wants indexed.

Exclude:

- redirect source/history URL;
- `noindex` URL;
- private/unavailable projection;
- ambiguous route;
- placeholder-only/incomplete page where contract blocks indexing;
- technical endpoint;
- MediaAsset delivery URL;
- deleted/retired object;
- compliance-blocked public copy;
- duplicate non-canonical URL.

### 11.3 Sitemap families

Implementation may expose separate sitemap groups for observability, including:

- native Article/editorial;
- Authority/Entity;
- Video;
- images attached to eligible public pages where existing sitemap mechanisms
  support them.

Separation is operational, not semantic.

### 11.4 `lastmod`

`lastmod` must be tied to meaningful owner/projection change, not scrape time,
request time or arbitrary daily refresh.

### 11.5 Read-back

Publishing/sitemap completion claims require public read-back where the runtime
is available. `ENVIRONMENT_BLOCKED` is distinct from empty or success.

## 12. SEO Readiness and Publication Gate

A future executable SEO readiness gate is read/planning/publication policy only.
It must not persist semantic truth.

### 12.1 Canonical status vocabulary

Use five general outcome classes:

- `READY`
- `INCOMPLETE`
- `BLOCKED`
- `UNAVAILABLE`
- `NOT_APPLICABLE`

Every non-ready result carries deterministic reason codes.

### 12.2 Reason-code families

The implementation plan should prefer existing registry/reason vocabulary where
available and add only bounded SEO/publication reasons when needed.

Expected examples:

- `MISSING_PUBLIC_IDENTITY`
- `CANONICAL_URL_MISMATCH`
- `AMBIGUOUS_CANONICAL_SUBJECT`
- `DUPLICATE_OR_CANNIBALIZED_INTENT`
- `INSUFFICIENT_PUBLIC_CONTENT`
- `REPRESENTATIVE_IMAGE_MISSING`
- `VIDEO_THUMBNAIL_UNAVAILABLE`
- `VIDEO_NOT_WATCH_PAGE_ELIGIBLE`
- `STRUCTURED_DATA_INAPPLICABLE`
- `COMPLIANCE_BLOCKED`
- `RUNTIME_UNAVAILABLE`

Do not implement only a numeric score such as `82/100` as the publication
truth. A UI may calculate an advisory score later, but the gate itself is
reason-based.

### 12.3 Gate flow

```text
resolve canonical owner/subject
    -> validate semantic/public eligibility
    -> inspect Knowledge/Source/Evidence dependencies when claims require them
    -> inspect relations needed by the projection
    -> inspect Media/Video completeness
    -> resolve canonical public URL
    -> evaluate compliance
    -> evaluate SEO readiness
    -> evaluate indexability
    -> publish/project through the owning editorial/public boundary
    -> public read-back
    -> sitemap projection
```

Fail closed on ambiguity, missing owner, missing canonical URL, unsupported
claim, contract gap or runtime unavailability.

## 13. Relationship rules across Brand / Knowledge / Media / Video / Article

The SEO architecture must preserve these distinctions:

### 13.1 Authority/Entity

Represents **what the thing is**.

### 13.2 Knowledge

Represents **what is claimed/known about the thing**, as atomic scoped claims.

### 13.3 Source/Evidence

Represents **why a claim is supported** and with what provenance.

### 13.4 Media/Image

Represents a canonical visual Media identity and its assets/usages. Media may
support recognition or evidence context, but upload/placement alone is not a
fact.

### 13.5 Video

Represents an audiovisual external reference. It may be `about` an eligible
canonical target and may produce candidate Knowledge planning, but transcript,
sound or imagery does not automatically become Knowledge/Evidence.

### 13.6 Article

Represents editorial presentation/search intent in WordPress. It can reuse
canonical facts, relations, Media and Video but does not become Authority or the
canonical owner of those facts.

### 13.7 SEO

Represents public discovery/presentation over all of the above. It creates no
new semantic truth.

## 14. Internal linking architecture

Internal linking must consume canonical public routes only.

Preferred link sources:

- Article -> relevant Authority/Entity hub;
- Entity -> bounded related Entity pages;
- Entity -> related Articles;
- Entity -> eligible Video pages;
- Article -> eligible Video pages where Video is useful;
- supporting Article -> canonical hub for the primary subject.

Links must never expose:

- UUIDs/stable keys as public URLs;
- private Evidence URLs;
- technical MediaAsset delivery URLs as content pages;
- retired/historic route sources;
- inferred relations absent from Graph/current canonical contracts.

Anchor text is editorial projection and must remain descriptive, natural and
non-spammy.

## 15. FAQ policy

FAQ is an optional editorial projection for questions genuinely useful to the
reader.

Rules:

- FAQ answer content must reuse supported knowledge/editorial truth;
- FAQ must not manufacture facts to expand keyword coverage;
- duplicated FAQ blocks across many pages are discouraged unless context truly
  requires the same answer;
- FAQ content may support long-tail intent and user comprehension;
- Google FAQ rich-result availability is not an architectural requirement;
- absence of FAQ never blocks an otherwise complete page unless a domain-
  specific contract explicitly requires the content for users.

## 16. Search-engine and AI-feature policy

NHK does not create a separate semantic architecture for AI Overviews/AI Mode,
AEO or GEO.

The same canonical system serves traditional and generative search:

- original first-hand observations where appropriately scoped;
- atomic supported Knowledge;
- clear Entity relationships;
- accessible representative images;
- eligible video/watch pages;
- stable public URLs;
- useful differentiated Articles;
- public source/evidence presentation where policy permits.

Search-engine-specific rendering changes affect projection/adapters, not
canonical semantic truth.

## 17. Data flow for a Brand example

A well-built Brand public hub follows:

```text
Brand Authority identity
    -> aliases / canonical naming
    -> Knowledge claims + Source/Evidence
    -> Graph to Models / related canonical entities
    -> representative Media
    -> related Video
    -> related Articles
    -> Public Identity URL
    -> Entity SEO projection
    -> indexability gate
    -> canonical metadata / structured data / internal links
    -> sitemap entry
```

SEO does not create the Model set, history claims or relations. It only exposes
eligible canonical information.

## 18. Data flow for Article create/update

### Create

```text
editorial intent
    -> semantic preflight / canonical subject resolve
    -> cannibalization and existing-hub check
    -> research/source inventory
    -> Article SEO blueprint
    -> draft in WordPress owner
    -> governed semantic apply if requested/approved
    -> Media/Video placement
    -> compliance
    -> SEO/indexability gate
    -> publish
    -> public read-back
    -> sitemap
```

### Update

```text
requested change
    -> classify change risk/scope
    -> compare semantic dependencies and stable SEO core
    -> modify only owning context
    -> compliance/indexability re-evaluation where affected
    -> publish/update projection
    -> read-back
    -> meaningful lastmod update if warranted
```

## 19. Failure behavior

All projection flows fail closed when required state is ambiguous or
unavailable.

Examples:

- unknown canonical subject -> `BLOCKED`;
- multiple exact subject candidates -> `BLOCKED`;
- canonical URL owner unavailable -> `UNAVAILABLE`;
- runtime/database unavailable -> `UNAVAILABLE`, never "empty success";
- missing representative image where type contract requires it -> `INCOMPLETE`;
- unsupported promotional claim -> `BLOCKED` or omit the claim;
- structured data not applicable -> `NOT_APPLICABLE`, not failure;
- no approved public projection for a semantic object -> exclude from sitemap;
- historic URL -> redirect/history behavior, never second sitemap entry.

Last-known-good eligible projection may remain visible during transient
synthesis failure only where the existing contract explicitly permits it.

## 20. Implementation architecture

The eventual code should use shared read-only policy/projection services rather
than duplicate SEO checks in theme templates, REST serializers and sitemap
builders.

Target responsibilities:

### 20.1 SEO readiness policy

Consumes canonical/public projection snapshots and returns status + reasons.
It owns no semantic repository.

### 20.2 Indexability policy

Consumes readiness/public eligibility/canonical URL and returns indexability.
The sitemap, robots/meta projection and public templates consume the same result.

### 20.3 Canonical URL projection

Reuses WordPress editorial URL owner for native Articles and Public Identity/
approved URL policies for semantic public pages. SEO never derives a competing
canonical route.

### 20.4 Structured-data projectors

Domain-specific and read-only:

- Article projector;
- Entity projector where a valid schema mapping exists;
- Video projector;
- image/preferred-image projection.

### 20.5 Sitemap adapters

Consume canonical URL + indexability results. They do not independently query
"all database rows" and decide public eligibility.

### 20.6 Theme/REST/search consumers

Must reuse shared projection output rather than reconstructing canonical URLs or
indexability rules in presentation code.

## 21. Rollout strategy

Implementation should be split into independently reviewable slices.

Recommended order:

1. documentation contracts/router sync only;
2. shared SEO readiness + indexability result model and policy tests;
3. Entity SEO projection/readiness;
4. sitemap/indexability unification;
5. Media preferred-image projection;
6. Article create/update SEO gate and cannibalization planning;
7. Video watch-page/VideoObject/sitemap parity hardening;
8. Living Knowledge stable-core integration;
9. runtime read-back/observability and Search Console operational checklist.

Do not implement all domains in one giant change.

## 22. Testing strategy

Every implementation slice follows TDD and proves both positive and fail-closed
behavior.

Minimum cross-domain regression set:

### Canonical URL

- canonical/self/internal-link/sitemap URLs agree;
- historic/redirect URLs excluded from sitemap;
- missing Public Identity cannot be silently replaced by new SEO slug logic.

### Entity

- active eligible Entity can project;
- ambiguous/unroutable/thin Entity is blocked/incomplete with reason;
- Product page cannot infer Specimen relation.

### Article

- differentiated intent allows new Article planning;
- duplicate intent recommends existing projection instead of new URL;
- title update does not automatically change canonical URL;
- Knowledge change does not auto-rewrite Article body.

### Media/Image

- representative selection is deterministic;
- evidence/technical image does not replace representative by recency;
- private/placeholder asset excluded from preferred image/sitemap;
- alt/caption changes create no Knowledge/Evidence/Graph mutation.

### Video

- valid Video watch page projects `VideoObject`;
- invalid/unavailable thumbnail blocks Video index eligibility as applicable;
- explicit validated Variant `about` target remains Variant;
- transcript does not create Evidence;
- no local MP4/WordPress Post is created.

### Sitemap

- only canonical indexable URLs included;
- redirects/noindex/private/technical/incomplete pages excluded;
- `lastmod` changes only on meaningful tracked changes.

### Failure state

- runtime unavailable != empty success;
- structured data inapplicable -> `NOT_APPLICABLE`;
- compliance blocker -> no prohibited public claim.

## 23. Runtime and observability

Runtime verification must remain distinct from contract/unit proof.

Where target infrastructure is unavailable, report `ENVIRONMENT_BLOCKED` or the
current standard equivalent. Never claim indexing, Google canonical selection or
Search Console state from local code alone.

Useful operational read-back after deployment:

- rendered HTTP status/final URL/redirect hops;
- self canonical;
- robots;
- title/H1/meta;
- preferred image URL;
- structured data output;
- sitemap inclusion;
- Video visibility/thumbnail/embed on watch pages;
- duplicate/cannibalization diagnostics;
- Search Console inspection for selected high-value canaries.

Search Console data is external observation, not canonical NHK semantic truth.

## 24. Security, compliance and privacy

SEO/public projection must not expose:

- private MediaAsset storage keys;
- private Evidence/source data;
- internal UUID/stable key in visitor-facing routes unless a separately approved
  public identifier contract requires it;
- secrets/API credentials;
- operational/governance internals.

Public commercial/promotional copy remains subject to
`docs/compliance/PUBLIC_CLAIM_ADVERTISING_COMPLIANCE_CONTRACT.md`.

Unsupported superlatives, uniqueness, leadership or absolute claims are omitted
or blocked unless the required evidence/compliance standard is satisfied.

## 25. Non-goals

This design does not:

- guarantee ranking or rich results;
- guarantee Google-selected canonical;
- add FAQ rich-result dependency;
- invent a new Authority type;
- add a Product–Specimen relation;
- create Media semantic pages where the Constitution/current ruling does not
  authorize them;
- create indexable atomic Knowledge Claim pages by default;
- import/backfill/repair legacy data;
- mutate production/staging/V2;
- create a generic semantic writer;
- download external Video binaries;
- auto-convert transcript/OCR/AI prose into Evidence;
- create a separate AEO/GEO semantic subsystem.

## 26. Acceptance criteria for the documentation phase

The documentation phase is complete only when:

1. the four new SEO contracts exist;
2. existing Article/Video/Living Knowledge SEO contracts are updated without
   duplicating canonical ownership law;
3. `READ_FIRST.md` routes SEO work through SEO Core + relevant domain contract;
4. `CURRENT_DOCUMENTATION_STATUS_INDEX.md` identifies SEO as projection-only and
   lists the current contract set;
5. no Constitution conflict is introduced;
6. no historical checkpoint is rewritten as current law;
7. no code/data mutation occurs in the documentation-only slice;
8. a follow-up implementation plan decomposes the runtime work into bounded TDD
   slices.

## 27. External references checked for this design

These references are implementation/search-platform guidance only:

- Google Search Central — Build and submit a sitemap:
  `https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap`
- Google Search Central — Canonicalization:
  `https://developers.google.com/search/docs/crawling-indexing/canonicalization`
- Google Search Central — Consolidate duplicate URLs:
  `https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls`
- Google Search Central — Image SEO best practices:
  `https://developers.google.com/search/docs/appearance/google-images`
- Google Search Central — Video SEO best practices:
  `https://developers.google.com/search/docs/appearance/video`
- Google Search Central — Video structured data:
  `https://developers.google.com/search/docs/appearance/structured-data/video`
- Google Search Central — documentation updates, including FAQ rich-result
  deprecation/removal and generative-AI SEO guidance:
  `https://developers.google.com/search/updates`

External guidance may change; future updates should change SEO projection
contracts/adapters only when possible, never retroactively rewrite canonical NHK
semantic truth.
