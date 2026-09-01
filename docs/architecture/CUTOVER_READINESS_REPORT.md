# NHK V3 Cutover Readiness Report

Date: 2026-09-01
Repository: `main` at the current local checkpoint
Decision: **NOT READY — production cutover is not authorized or performed.**

Latest verification: guarded WordPress suite 214 tests/1,293 assertions;
Composer lint, MCP wire smoke, all-nine-type core route smoke 34/34 and
opt-in real Authority detail smoke 41/41 pass; diff check is clean.

The latest domain checkpoint also rejects malformed optional Governance
proposal `targetUuid` values before persistence while retaining semantic
subject IDs.

The current KnowledgeClaim hydration checkpoint brings the guarded suite to
75 integration tests / 449 assertions (215 tests / 1,295 assertions combined).

The current Governance checkpoint adds fail-closed handling for malformed or
non-array proposal `command_json` rows; the suite is now 216 tests / 1,297
assertions combined.

The follow-up Governance hydration checkpoint also omits proposal rows with
invalid durable fields such as a non-positive revision; the latest guarded
suite is 217 tests / 1,298 assertions combined.

NHK Admin UUID form inputs now use shared codec validation and fail closed
before repository access; latest combined coverage is 217 tests / 1,301
assertions (Unit 140/849, Integration 77/452).

Migration UUID validation now uses the shared codec and strict RFC 4122
version/variant checks, ledgering invalid UUID-shaped records as
`INVALID_IDENTITY`; latest combined coverage is 218 tests / 1,305 assertions.

Dry-run relation and URL target identity checks now share that strict UUID
boundary, rejecting nil/malformed UUIDs before mapping candidates are emitted;
latest combined coverage is 219 tests / 1,308 assertions.

Fresh local-dev runtime revalidation passed the complete MCP wire smoke and all
declared frontend route smoke checks on canonical `http://localhost`; the
read-only domain audit still finds 742 unique candidates requiring governed
mapping evidence, so no V2 identity or URL decision was inferred.

ApplyAttempt persistence hydration now also fails closed for malformed durable
rows; latest combined verification is 220 tests / 1,309 assertions.

Governance dependency reads now omit invalid UUID rows before closure/cycle
evaluation; latest combined verification is 221 tests / 1,310 assertions.

Graph edge hydration now omits malformed persisted rows from single and
paginated reads; latest combined verification is 222 tests / 1,312 assertions.

MediaAsset hydration now omits malformed persisted domain rows before public
delivery/query consumption; latest combined verification is 223 tests / 1,314
assertions.

Media identity hydration now also omits malformed persisted domain rows before
public Media query consumption; latest combined verification is 224 tests /
1,316 assertions.

Video identity hydration now also omits malformed persisted domain rows before
public Video/query consumption; latest combined verification is 225 tests /
1,318 assertions.

Current runtime revalidation also passed local MCP wire smoke and frontend route
smoke 34/34; bounded external Media/Source/Video reads reported zero writes,
Media total 242, draft Sources and no Video records. External adapter mapping
and active-data parity therefore remain explicit gates.

## What is ready

- WordPress `wp_posts` remains the editorial source of truth.
- Authority, Knowledge, Graph, Governance, Media and external Video boundaries
  are implemented with canonical identity, revision, provenance/readiness and
  fail-closed storage checks.
- Public entity archive/detail routes cover all nine Authority types.
- Public Media and Video archive/detail routes and responsive templates exist;
  Video embeds only validated YouTube external references.
- Homepage discovery is query-driven: featured/latest/category/topic modules
  use WordPress services, while Authority/Media/Video modules come from the
  plugin semantic query boundary and disappear when storage is unavailable.
- Search now has a theme query boundary that combines native WordPress Post
  results with grouped active semantic results from Authority, Media, Video and
  Knowledge repositories.
- Public REST and MCP detail reads now suppress retired Authority, Media and
  Video records even when a caller knows their UUID.
- Public Media REST responses omit provenance, asset storage metadata and Graph
  usage endpoint identifiers; PUBLIC assets include only a reader-safe
  `/media/asset/{uuid}/` URL; internal MCP/application serializers retain
  operational fields for governed operations.
- Public Video REST and theme detail responses expose only the validated
  external-reference display fields; persisted metadata remains on the
  internal MCP/application path.
- Public Authority Entity REST and theme query responses allowlist payload
  fields from the registered canonical type definition, filtering
  unregistered legacy/internal keys.
- Public semantic search in REST, theme and MCP indexes only those same
  registered entity fields, so private or legacy payload values cannot change
  public result membership or totals.
- Public Media discovery now requires both active state and `readiness=ready`
  across REST, theme, homepage, search and Graph-related paths; active draft
  records remain available only to internal governance/MCP reads.
- Media asset creation defaults to `PRIVATE`; public visibility requires an
  explicit caller decision, and binary delivery remains independently
  fail-closed.
- MCP semantic search follows the same Media readiness gate, so draft records
  do not appear in public search totals while ready records remain searchable.
- Public Knowledge REST/theme payloads omit persisted Source/Evidence metadata
  blobs while retaining reader-facing provenance fields; internal MCP reads
  retain full metadata for governed review.
- Public Knowledge claim payloads also omit the persisted provenance blob;
  reader-facing claim and approved evidence fields remain available while
  legacy verification/status internals stay on the governed internal path.
- Source and Evidence are PRIVATE-by-default when visibility is omitted;
  explicit publication requires `visibility=PUBLIC`, and governed Evidence
  ingest now persists that metadata instead of dropping it.
- V2 Source migration now preserves top-level visibility, verification state and
  legacy identifier in the durable metadata envelope, preventing replay from
  losing provenance publication state.
- V2 Source migration preserves a canonical normalized `source_type` when the
  legacy semantic type is absent, while retaining legacy type mapping fallback.
- The same resolver accepts normalized `source_type` from either the top-level
  record or its metadata envelope, preserving canonical type parity across
  exporter shapes.
- V2 Source and Evidence replay also fail closed for legacy archived/retired
  review states, preventing archived provenance from becoming active public data.
- Source/Evidence replay preserves the top-level V2 `review_state` in durable
  metadata for governed review and audit.
- The migration resolver also honors `review_state` when it is supplied inside
  the normalized metadata envelope, preventing archived/retired replay from
  becoming active due to field placement differences.
- Raw Graph REST reads are administrator-only and retain their operational
  endpoint-key/state/revision contract; public related-content rendering uses a
  reader-safe query boundary instead.
- Public Knowledge archive/detail/search reads now suppress claims with
  explicit unverified or non-public provenance states; local runtime evidence
  shows 24 reader-facing cards on page one with no status leakage, while the
  full V2 claim population remains retained internally for policy review.
- Theme-facing Media detail data now uses the same reader-safe serializer as
  public Media REST, omitting provenance, storage, checksum, visibility,
  metadata and Graph endpoint identifiers while retaining display facts; image
  assets now receive a reader-safe `/media/asset/{uuid}/` URL and render lazily
  through the public theme.
- Responsive archive/detail/search QA now confirms public anchors, editorial/
  entity/media/knowledge/semantic cards and footer links use the NHK palette
  instead of browser-default blue, with visible accent-secondary hover/focus
  states; stylesheet cache-busting is synchronized.
- Public REST semantic search now excludes retired Media and Video records
  from both result groups and totals; guarded runtime coverage verifies this
  with disposable integration fixtures.
- NHK Admin exposes health, lookup, governed proposal creation and lifecycle
  actions, including Graph relation proposals, through REST with capability and
  nonce checks. Its operational lookup/composer controls now have explicit
  label/id associations and labelled/described form regions.
- MCP exposes governed eligibility and Controlled Apply handlers in addition to
  proposal lifecycle operations. `nhk.media.ingest` provides a governed fast
  ingestion path for a complete Media identity/asset/usage packet, while
  `nhk.video.ingest`, `nhk.knowledge.ingest`, `nhk.source.ingest` and
  `nhk.evidence.ingest` persist validated canonical references, claims and
  provenance through the same lifecycle; local end-to-end lifecycles preserve
  PRIVATE asset/source visibility until publication or further mutation is
  explicitly approved.
- Native editorial aliases preserve `/tri-thuc/` and `/goc-chia-se/` route
  contracts while continuing to query WordPress categories/posts.
- V2 search `/tim-kiem/?q=...` now redirects with its query preserved to the
  native WordPress search parameter `/?s=...`.
- The `/comparison/` discovery surface is now a real read-only comparison
  route over two active canonical Authority references; it does not create a
  duplicate comparison data model.
- Comparison pages now emit dedicated document title, canonical, description
  and breadcrumb metadata instead of inheriting the site default.
- V2 archive aliases `/thuong-hieu/`, `/hien-vat/` and `/am-nhac/` now resolve
  to canonical V3 Authority archive contexts while emitting canonical V3 links
  and metadata.
- V2 detail slugs now have a fail-closed compatibility resolver: a unique active
  Brand slug redirects to `/brand/{stable-key}/`, and a unique active
  Brand/Model pair redirects to `/model/{stable-key}/`; native WordPress
  content and ambiguous names are never overridden.
- Post single pages now consume Graph-derived related entities, articles, Media
  and Video through an application query boundary; empty or unavailable
  related groups are omitted without changing the editorial body.
- A transport-neutral MCP registration seam exists; read adapters are real and
  mutations delegate to Governance.
- A local Streamable HTTP MCP endpoint exposes protocol tool schemas, validates
  modern request metadata, required response media types and Origin, and
  rejects governed calls without the matching WordPress capability; external
  client/deployment interoperability is still a separate gate.
- The V2 dry-run tool is no-write and emits bounded reason codes. A local
  read-only V2 inventory and a separate governed local-dev migration ledger
  are recorded; live V2 data has not been mutated.
- The dry-run report now provides per-type counts and skipped-reason buckets,
  rejects malformed records/checksums and marks explicit conflicts for review.
- The local development schema is current at 9/9; Evidence and MediaAsset
  metadata migrations and their governed backfills completed with zero
  conflicts. V2 PRIVATE media assets remain suppressed by public reads; the
  public asset route is fail-closed on visibility, MIME, storage-root,
  checksum and byte-size checks.
- The Media detail image URL is only a reader-facing route; binary delivery
  still validates the parent Media readiness, asset visibility and file
  integrity, so missing or non-public source bytes remain an honest 404/empty
  state rather than an invented image.
- A read-only `nhk_v3` inventory now confirms the three imported MediaAsset
  rows are PRIVATE but their absolute storage keys still point into the V2
  upload tree; none of those source files exists under the V3 upload root, so
  checksum and byte-size verification cannot pass. The public asset route also
  requires the parent Media identity to be active and `readiness=ready`. No asset was published or
  rewritten as a workaround.
- A follow-up read-only endpoint audit found 18 of the 21 exact legacy upload
  paths available on `demo.1945.vn` with allowlisted image MIME/size and three
  `wp1-thumbnail-*` paths returning 404. The available bytes are recovery
  candidates, not governed mappings: identity/usage, backup/restore and
  publication/privacy approval remain open. The complete status and hashes are
  recorded in `V2_MEDIA_SOURCE_RECOVERY_AUDIT_2026-09-01.md`; asset delivery
  remains fail-closed.
- Migration009 preserves all 1,581 legacy semantic projections as bounded,
  non-canonical context metadata with provenance and `body_migrated=false`;
  projection bodies are rejected and no Authority, Knowledge or WordPress
  editorial record is created.
- Public Knowledge reads now fail closed for inactive identities and for
  active Source/Evidence records carrying explicit non-PUBLIC visibility;
  the activation/public provenance policy remains a cutover gate.
- The public theme now has a skip link and explicit main targets, a keyboard-
  accessible responsive menu with synchronized ARIA state, visible focus
  styling, and explicit decorative image alt handling. The browser runtime
  verified the 390px menu state and no overflow; the guarded integration and
  localhost route smoke were subsequently re-verified outside the sandbox
  boundary. A 32-combination browser sweep across the public page, archive
  pagination and empty/404 states at 390px and 768px found no horizontal
  overflow; long Component stable keys were fixed and visually rechecked at
  both widths.
- SEO now declares an explicit archive policy through WordPress's single
  `wp_robots` output: canonical non-search pages are `index,follow`, while
  search and paginated archive states are `noindex,follow`; custom entity,
  Media, Video and Knowledge page vars are covered by the frontend contract
  test.
- Browser runtime also confirms the homepage canonical resolves to `/` rather
  than an editorial post URL; search and custom archive page-two states emit
  one consolidated `robots` directive.
- The homepage now replaces the repository-oriented default document title with
  the visitor-facing `Đồng Hồ Nhà Kho — Kho tri thức và sưu tầm` title and
  matching OpenGraph title; browser runtime confirms canonical `/` remains
  unchanged.
- Homepage meta description and OpenGraph description now also use visitor-facing
  NHK copy rather than the technical WordPress site description; browser runtime
  confirms both descriptions and canonical `/`.
- Entity archive, Knowledge, Media, Video and Comparison descriptions now remain
  route-specific; browser runtime confirms their titles/descriptions/canonicals
  and confirms the technical WordPress description does not leak into public
  archive metadata.
- Theme design tokens now have one NHK source; the legacy token/rule block was
  removed, contract coverage checks all 11 required tokens, the asset version
  is synchronized to 1.1.8, and cache-busting browser verification confirms no
  legacy tokens or horizontal overflow.
- Blank or whitespace-only semantic searches now fail closed instead of
  matching every record; unit regression and browser verification of `/?s=`
  confirm zero semantic cards.
- Public entity, Media, Knowledge, Video and Comparison templates now keep
  operational identifiers out of visitor-facing content while retaining
  canonical URL construction; comparison payload fields use reader-facing
  labels/values, and browser verification found no identifier labels/internal
  terms or overflow across five routes.
- Public relation, search, comparison and Knowledge type labels now map
  technical enum values to visitor-facing Vietnamese through one shared helper;
  canonical URL values remain internal to link construction.
- A fresh desktop browser sweep covered 14 public routes (editorial archives,
  all exposed Authority archives, Comparison, Knowledge, Media, Video and 404):
  every route had an expected H1/title, matched the viewport width without
  overflow, and contained no internal Authority/Proposal/MediaAsset wording.
  Video correctly remained an empty state because no active Video record exists;
  this does not close the active-Video detail screenshot gate.
- The fresh active Variant and Classification detail routes were also checked
  at 390px/844px: both had expected Vietnamese H1/title, no horizontal
  overflow, broken images, dead links, internal terminology or console errors;
  the single static footer and normal main padding were confirmed.

## Quality evidence

| Gate | Result |
|---|---|
| Unit tests | PASS — 140 tests, 846 assertions |
| Plugin PHP lint | PASS |
| Theme PHP lint | PASS |
| `git diff --check` | PASS at checkpoints |
| Guarded WordPress integration | PASS — `NHK_WP_TEST_PATH=public NHK_WP_TEST_DB=nhk_v3_test /opt/homebrew/bin/php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite 'NHK Integration'`; 74 tests, 447 assertions; combined current suite is 214 tests, 1,293 assertions; PredicateDefinition validates non-empty typed endpoint lists and cardinality/key contracts; GraphEdge domain objects validate relation UUID/predicate/revision and WPDB hydration normalizes HEX UUIDs; ApplyAttempt domain objects validate attempt/proposal/result UUIDs, positive numbering and allowed states; Authority domain objects reject malformed canonical UUIDs before persistence, and Authority repository hydration omits malformed/non-array payload rows from single and type collection reads; Proposal domain objects reject malformed optional target UUIDs while preserving semantic subject IDs; MediaAsset domain construction and WPDB hydration default missing visibility to PRIVATE, migration schema creation and upgrade normalize visibility defaults to PRIVATE without rewriting existing values, and malformed/non-array metadata is omitted fail-closed from repository reads; Source and Evidence domain metadata hydration omits malformed/non-array metadata rows from single and collection reads; Media provenance and Video metadata hydration now also omits malformed/non-array JSON rows from single and collection reads; explicit PUBLIC remains required for delivery; governed MCP Media/Video/Knowledge/Source/Evidence ingest lifecycles and reader-safe Source/Evidence reads, Governance proposal idempotency-key preflight with changed-content conflict rejection, strict Authority identity/content/state conflict handling and stable-key race-idempotent repository creation, strict Knowledge identity/content/state conflict handling, strict Source identity/content/state conflict handling, strict Evidence identity/content/state conflict handling and canonical UUID race-idempotent repository creation, stable-key race idempotency preflight, strict Video external-reference identity/content conflict handling and race-idempotent repository creation, Source metadata-preserving, normalized-type (top-level/metadata), archived-state and top-level/metadata review-state-preserving migration replay, malformed MediaAsset reason-coded skip, strict same-identity MediaAsset conflict handling, strict Media identity/state conflict handling, idempotent MediaUsage duplicate handling and sort-order conflict rejection at service and WPDB repository boundaries, structured migration review metadata in ledger details, Entity/Media/Video REST reads, readiness-gated Media discovery and binary delivery, public Media REST/MCP/theme reads filtered through binary delivery integrity, active-only public Entity REST list pagination/totals, canonical-field-only Entity archive search, validated public Video external-reference filtering across REST/MCP/query/archive/search/home/Graph-related content, reader-safe Video serializers without thumbnail/media identity or lifecycle fields, reader-safe PUBLIC Media asset URLs and lifecycle-free Media serializers across REST/MCP/theme, public Knowledge URL target validation, lifecycle-free public Knowledge/Source/Evidence serializers with active/public gates, lifecycle-free public Entity serializers with active/type/payload gates, explicit active filtering in theme Entity archive, allowlisted MCP Authority/Media/Video/Knowledge payloads, administrator-only raw Graph REST permission, Streamable HTTP Accept validation with standard initialize/header-only follow-up, header-only tools/call and initialized notification coverage, MCP REST CORS protocol-header allowlist coverage, public category label/archive metadata, Vietnamese date/editorial archive metadata, 404 SEO and HTML language localization coverage, plus bounded readiness-filtered REST/MCP semantic search pagination and review-only domain-target candidate audit coverage are included |
| Frontend route/rewrite smoke | PASS 34/34 for all nine Authority archive/page-two route types, V2 archive aliases, `/comparison/`, `/hello-world/`, default category archive, semantic search page 2, editorial/Knowledge/Media/Video archive page-two routes, native sitemap/RSS payload markers, V2 query-preserving search redirect and unknown MediaAsset 404; opt-in real Authority detail sweep passed 41/41 including Variant and Classification; the smoke now also asserts title/canonical metadata for editorial/category archives and localized 404 `noindex, follow`; local HTTP also verified V2 detail 301 redirects and comparison title/canonical metadata |
| REST/MCP runtime boundary | PARTIAL — V3 local checks remain PASS: active-only Entity/Media/Knowledge/Search reads returned 200, semantic Search/MCP groups are bounded per page with totals, retired Authority records are suppressed, invalid entity routes returned 404, unauthenticated Governance mutations/eligibility returned 401, live localhost CORS preflight returned 200 with the MCP protocol headers allowlisted, raw localhost MCP probes returned `200 application/json` for standard modern `initialize` and header-only follow-up `tools/list` with 18 protocol definitions (10 governed) including reader-safe Source/Evidence reads and `nhk.search` page 2 with five items per group plus totals, unauthenticated governed MCP call returned 403 and invalid Origin returned 403; bounded read-only external Source/Media/Video abilities are reachable but use a richer adapter schema, expose draft/mixed-visibility records and have no active Video record, so external mapping/deployment verification remains pending |
| Frontend visual QA | PARTIAL — desktop homepage, Post single, Search, Comparison, active Media detail/archive, Video empty state, Knowledge pagination, Authority archive/detail and 404 plus mobile homepage/editorial archive/Post/Authority detail/Media detail were visually inspected; this checkpoint additionally captured mobile screenshots for `/comparison/`, `/model/page/2/`, `/component/page/2/`, `/media/page/2/`, `/video/page/2/`, `/knowledge/page/2/`, `/thu-vien/`, `/category/uncategorized/` and 404; all nine route screenshots had expected Vietnamese H1/title, no overflow, broken images or empty/`#` anchors, and card/footer links now use the NHK palette; pagination exposes `aria-current="page"` on the active link, public Knowledge evidence presents the approved source title/type with inactive sources filtered, the default category archive and `/tri-thuc/`/`/goc-chia-se/` archives now have localized H1/title/description/canonical metadata, and public editorial dates are Vietnamese while machine-readable timestamps remain ISO; the shared public URL validator now rejects malformed data-derived HTTP links; public templates plus entity payload presentation are contract-tested to avoid internal domain terminology; a read-only local query confirms no active Video row exists for detail inspection, so active Video detail remains pending |
| V2 data inventory/counts/mappings | PARTIAL — restored 4,973-record baseline export/dry-run had 3,960 candidates and 1,013 skips; policy-normalized homepage `/` no-op brings the local-dev checkpoint to 3,961 migrated, 1,012 explicit skips and 0 conflicts, including 367 Knowledge claim redirects, 370 entity-registry redirects, 34 native-post URL redirects, two safe URL no-ops, 3 field-level PRIVATE MediaAsset rows, 19 Source and 40 Evidence rows |
| V2 backup restore | PARTIAL — reviewed staging conversion restores the dump and test snapshot; original dump is not MariaDB-portable without conversion, and live field-level reconciliation remains open |

## Blocking gates

1. Complete field-level reconciliation and final retirement/target approval using `V2_URL_RECONCILIATION_REVIEW_2026-08-31.md` for the 27 residual URLs (now explicitly classified as 5
   `DOMAIN_TARGETED`, 21 `UNSUPPORTED_MEDIA_REFERENCE` and 1
   `RETIRED_LEGACY_GARBAGE`), MediaAsset
   delivery/privacy policy,
   Source/Evidence public visibility and the 764
   domain-targeted custom/system posts; the new `V2_DOMAIN_TARGET_REVIEW_2026-08-31.md`
   breaks these into 742 domain records, 21 attachments and one global-styles
   record. Exact read-only Knowledge candidates are now identified for the five
   domain-targeted rows. Field-level verification shows all five are archived,
   unverified and non-public with no active consolidation target, so each still
   requires a governed retirement or separately approved active target; every other row
   requires a deterministic governed target or a documented
   retirement/skip decision.
2. Review the local-dev ledger counts, verify all imported semantic fields and
   relation semantics, and obtain explicit approval before any live V2
   migration. The versioned normalize/export/apply chain is evidence, not
   production authorization.
3. Complete the remaining browser visual QA for active Video detail and any
   newly populated data-gated detail routes. Homepage, Post, entity
   archives/details, Search, Comparison, Media, Video empty state, Knowledge,
   404, pagination and 390px/768px/1440px structural states have already been
   inspected, including a fresh nine-route mobile screenshot sweep; the current
   local dataset has no active Video row, so no fixture is created and the detail
   gate remains open.
4. Complete external MCP client/deployment interoperability verification and close mandatory
   red rows in `V2_V3_PARITY_MATRIX.md`.

Until every blocking gate is evidenced and the parity matrix is reconciled,
the system must remain pre-cutover. No production data, V2 live system or
production routing was changed during this work.
