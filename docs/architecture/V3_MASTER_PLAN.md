# NHK V3 Master Plan

Status is based on code and test evidence, not commit titles.

| Phase | Status | Evidence / next gate |
|---|---|---|
| P0 Bootstrap | ACCEPTED/CLOSED | Repository and V3 boundaries established. |
| P1 Legacy Audit | ACCEPTED/CLOSED | `09_P1_SOURCE_AUDIT.md`, `07_LEGACY_INHERITANCE_MATRIX.md`. |
| P2 Graph Core | ACCEPTED/CLOSED | `12_P2_ACCEPTANCE_MATRIX.md`. |
| P3 Authority Core | ACCEPTED/CLOSED | `14_P3_ACCEPTANCE_MATRIX.md`, `15_P3_INTEGRATION_ACCEPTANCE.md`. |
| P4 Governance Core | ACCEPTED/CLOSED | All acceptance rows pass; Migration003 UP-only applied to `nhk_v3`; health is 3/3. |
| P5 Canonical Domain Foundation | ACCEPTED/CLOSED | Nine registry-backed canonical types, typed and type-specific payload format validation, generic persistence/lifecycle/update and Graph endpoint resolution are covered by unit/integration evidence; public Entity REST/MCP/theme reads enforce active/type/allowlisted-payload gates and omit lifecycle fields. |
| P6 Media + Video | IN PROGRESS | Media identity/asset/usage and external Video persistence plus public archive/detail vertical slices are implemented; governed Media and Video ingest now use the same Controlled Apply boundary, public Media serializers use lifecycle-free reader-safe fields and only serialize PUBLIC assets resolved by binary integrity delivery, while WP runtime and V2 data gates remain. |
| P7 Knowledge + Source + Evidence + Post Graph | IN PROGRESS | Atomic claims, provenance, persistence, optimistic lifecycle mutations and Post semantic links are implemented; governed Knowledge/Source/Evidence ingest is proven through Controlled Apply, public reads omit lifecycle/provenance metadata and enforce active/public claim-source gates, while V2 field-level and public provenance policy remain open. |
| P8 Admin + MCP operational layer | IN PROGRESS | NHK Admin console now includes semantic/Graph lookup for Media, Video, Knowledge Claim, Source and Evidence plus governed proposal composer with explicit label/id associations; relation mutations, eligibility/Controlled Apply across canonical repositories, semantic read/search APIs, governed `nhk.media.ingest`/`nhk.video.ingest`/`nhk.knowledge.ingest`/`nhk.source.ingest`/`nhk.evidence.ingest` and capability-gated local Streamable HTTP MCP transport are wired; standard modern `initialize`, header-only `tools/list`/`tools/call` and initialized notification are accepted with mismatch guards, and WordPress REST CORS allows the MCP protocol assertion headers; external adapter mapping/deployment interoperability and runtime QA remain pending. |
| P9 Frontend/UI parity | IN PROGRESS | Responsive NHK editorial theme now includes HomePageQuery discovery, grouped semantic search, read-only Authority comparison, featured/latest/topics/semantic modules, canonical entity/related, Post Graph-derived related groups, Media gallery and Video archive/detail surfaces with SEO metadata; skip-link/main targets, keyboard-visible menu ARIA state, focus-visible styling, decorative image handling, long-key wrapping and NHK link contrast are contract-tested; desktop visual inspection, a 32-combination 390px/768px route/page-state sweep and nine additional mobile route screenshots pass, while active Video detail remains data-gated. |
| P10 V2 → V3 Data Migration | IN PROGRESS | Versioned V2 normalize/export, guarded backup/restore, Migration006 ledger, Migration007 Evidence metadata, Migration008 MediaAsset metadata/visibility, Migration009 non-canonical projection context, governed native-post/entity/Knowledge URL redirects and resumable apply are implemented; policy-normalized local-dev checkpoint imported 3,961 rows including 1,581 bounded projection contexts, field-level PRIVATE MediaAsset metadata, 367 Knowledge redirects, 370 entity redirects, 34 native-post redirects, two safe URL no-ops and Source/Evidence citation rows, while 27 explicitly classified URL candidates, MediaAsset delivery/privacy policy and domain-targeted posts remain gated; V2 media usage inventory is zero. |
| P11 Reconciliation + parity + cutover readiness | IN PROGRESS / NOT READY | `CUTOVER_READINESS_REPORT.md` records implemented evidence and explicit blockers; latest guarded suite is 228 tests/1,328 assertions, all-nine-type core route smoke is 34/34, opt-in real Authority detail smoke is 41/41, and MCP wire smoke passes; Proposal optional target UUID validation, MediaAsset PRIVATE-by-default construction/hydration/schema, repository-wide fail-closed domain hydration, Media/Video fail-closed JSON hydration, and Authority fail-closed payload/state/revision hydration are covered; read-only domain candidate audit covers all 742 skipped domain posts without automatic mapping; count/semantic/UI/logic reconciliation is still gated by V2 data and WordPress runtime. |

P11 latest checkpoint: KnowledgeClaim provenance hydration is fail-closed for
malformed or non-array persistence rows; guarded integration is 75 tests / 449
assertions and combined coverage is 215 tests / 1,295 assertions.

Governance proposal `command_json` hydration is also fail-closed for malformed
or non-array rows; latest combined coverage is 216 tests / 1,297 assertions.

Proposal hydration additionally fails closed for invalid durable fields such as
non-positive revisions; latest combined coverage is 217 tests / 1,298 assertions.

NHK Admin entity/proposal UUID lookups now canonicalize through the shared
codec and reject malformed values before repository access; latest combined
coverage is 217 tests / 1,301 assertions.

V2 migration identity validation now applies the shared codec and strict RFC
4122 checks across semantic and URL targets; invalid UUID-shaped records are
reason-coded `INVALID_IDENTITY`, with latest coverage at 218 tests / 1,305
assertions combined.

Dry-run relation and URL target validation now uses the same strict UUID
boundary before reporting mapped candidates; latest coverage is 219 tests /
1,308 assertions combined.

Fresh local-dev MCP wire and frontend route smoke revalidation passed on the
canonical host; the read-only domain-target audit remains 742 explicit mapping
review candidates.

ApplyAttempt repository reads now omit malformed durable rows before Controlled
Apply/Admin consumption; latest combined verification is 220 tests / 1,309
assertions.

Dependency repository reads now omit invalid UUID rows before closure/cycle
evaluation; latest combined verification is 221 tests / 1,310 assertions.

Graph edge hydration now fails closed for malformed persisted edge/node/state
data before Post Graph and relation APIs consume it; latest combined
verification is 222 tests / 1,312 assertions.

MediaAsset hydration now fails closed for malformed persisted domain rows before
public delivery/query consumption; latest combined verification is 223 tests /
1,314 assertions.

Media identity hydration now fails closed for malformed persisted domain rows
before public Media query consumption; latest combined verification is 224 tests
/ 1,316 assertions.

Video identity hydration now fails closed for malformed persisted domain rows
before public Video/query consumption; latest combined verification is 225 tests
/ 1,318 assertions.

Repository-wide persistence hydration now also fails closed for malformed
Authority, Knowledge Source/Claim/Evidence and MediaUsage domain rows; latest
verification is 141 Unit tests / 852 assertions and 87 guarded Integration
tests / 476 assertions (228 / 1,328 combined).

## Locked direction

WordPress owns article content; Authority owns canonical entities; Knowledge
owns atomic claims; one Graph connects semantic endpoints; Governance controls
durable semantic mutation; Media is first-class; Video is an external reference.
V2 implementation and schema are reference material, not a template.

## Autonomous delivery direction

The remaining phases are executed as tested vertical slices and are not paused
for per-phase confirmation. The public target is functional, data and UI/UX
parity or better with V2, while retaining the clean V3 boundaries. The NHK
frontend is a discovery-oriented editorial surface with warm, restrained,
classic visual cues and modern responsive interaction. It uses real query and
application services, never hard-coded public fixtures or raw database access
from templates.

P6 must finish Media/MediaAsset/MediaUsage and canonical external Video
persistence plus shared Graph endpoint coverage. P7 adds Knowledge, Source and
Evidence and Post semantic links, including governed claim/source/evidence
mutation lifecycles. P8 adds daily-operational Admin and governed
read/mutation APIs including MCP. P9 builds the responsive frontend and route
inventory. P10 performs read-only V2 inventory and dry-run first, then only a
backup-gated resumable migration. P11 reconciles counts, semantics, routes,
logic, UI, SEO and URLs and produces Cutover Readiness; production cutover
remains human-gated.
