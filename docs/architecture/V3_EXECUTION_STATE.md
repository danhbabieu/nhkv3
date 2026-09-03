# NHK V3 Execution State

## Owner Publication Override root-cause and isolated runtime checkpoint — 2026-09-03

The review boundary now has an explicit read-only `review()` operation. The
canonical MCP review tool delegates to it and cannot invoke the native
WordPress writer; normal publication still uses the existing request/publish
path. The stale MCP wire-smoke fixture now asserts the exact ordered 36-tool
catalog. P5 teardown returns cleanly when WordPress setup skipped before
`$wpdb` existed.

Fresh isolated runtime proof used `NHK_WP_TEST_PATH=public` and
`NHK_WP_TEST_DB=nhk_v3_test` with local MySQL 9.7.1. WordPress bootstrap and
`$wpdb` were available; additive migration 013 created/read back
`wp_nhk_owner_publication_decisions`. The Owner Override integration suite
passed 4 tests / 30 assertions, including isolated `wp_insert_post()` PASS,
OWNER_REVIEW_REQUIRED→authenticated approval, SYSTEM_BLOCKED, MCP review-only,
and retry returning the durable completed decision. Post 87 was not used.

The focused unit/contract suite passed 70 tests / 761 assertions. A focused
P4/P5/MCP integration run executed against the isolated database but retained
four unrelated failures: three existing MCP media/video/knowledge fixture
assertions and one existing P5 pagination baseline mismatch. No unrelated
working-tree files were staged or changed by this checkpoint.

## YouTube runtime configuration safety checkpoint — 2026-09-03

Root-cause tracing confirmed that the production Video adapter had no
`NHK_YOUTUBE_API_KEY` in the PHP runtime: the repository contains no
`$_ENV`/`$_SERVER` or dotenv loader for this key, and the deployed bootstrap
did not define the constant. A shell/SSH environment is therefore
insufficient when PHP-FPM uses `clear_env`; the `getenv()` call in the FPM worker
returns unavailable.

The adapter now uses `YouTubeApiConfiguration`, which deterministically
selects a non-empty `NHK_YOUTUBE_API_KEY` constant before `getenv()` and
returns only `{configured, source}` through the existing read-only health
diagnostic. Missing configuration still fails closed before any outbound
HTTP request. No secret, semantic record, Video, Proposal, Graph relation or
WordPress data was changed.

Focused proof: 25 tests / 99 assertions for YouTube, health and configuration.
The full NHK Unit suite ran 414 tests and has one unrelated failure in the
pre-existing uncommitted `OdoMediaIntegrityAuditorTest`; no Odo code was
changed in this checkpoint. Full PHP lint, Composer validation, diff checks
and secret review passed.

## Governed Living Knowledge apply-boundary correction — 2026-09-03

The final corrective slice audited the actual Governance vocabulary: the
effective operation allowlist is `McpToolCatalog::governedOperations()` and
the existing `AuthorityProposalExecutor`/KnowledgeService boundary supports
the current Knowledge/Source/Evidence lifecycle. The enrichment factory now
translates only through that runtime vocabulary, never invents an operation,
and returns typed `REGISTRY_GAP` or `UNSUPPORTED` diagnostics when mapping is
not available. No adapter or new operation was added.

Planner evidence candidates now require canonical claim and source resolution;
unresolved source input remains an ambiguous review candidate. Resolved
Evidence candidates preserve claim/source IDs, relation, excerpt, locator,
metadata and dependency revisions. Canonical ordering binds content,
dependency and idempotency fingerprints, so source/claim/relation changes
change intent keys. Create proposals use `expected_revision=null`; existing
target revisions remain repository-bound and stale eligibility fails closed.
The factory is translation-only and performs no KnowledgeService/repository
write.

Focused proof: 9 tests / 26 assertions for the corrected factory/planner,
plus 38 Governance/MCP/Knowledge regression tests / 232 assertions. The
full Proposal → Approval → Eligibility → Controlled Apply → read-back path
for living Knowledge enrichment remains `CODE_GAP` and is not claimed
complete. Video/Media/Article integration remains out of scope.

## Governed Living Knowledge corrective review — 2026-09-03

Corrective review fixed four defects before Video/Media/Article expansion:
retired claims no longer match as current same-claim; planner matching is
deterministic exact-match only and structured relation context classifies
add-Evidence/qualification/contradiction/ambiguous/unsupported; fragment
fingerprints include canonical claim/Evidence/Source dependencies with stable
ordering; and SEO results cover the complete stable core while MEDIUM changes
explicitly require stronger verification.

Internal planning can request active private Evidence through resolver
`publicOnly=false`; public projection remains restricted to eligible public
Evidence. No new semantic owner, predicate, operation or migration was added.

## Governed Living Knowledge + SEO Stable Projection checkpoint — 2026-09-03

Owner-approved incremental, contract-first design is recorded in
`docs/architecture/GOVERNED_LIVING_KNOWLEDGE_DESIGN.md` and
`docs/seo/LIVING_KNOWLEDGE_SEO_STABILITY_CONTRACT.md`; the implementation plan
is `docs/superpowers/plans/2026-09-03-governed-living-knowledge.md`.

Implemented without semantic data mutation: validated Knowledge facet/scope
profiles, read-only enrichment planner, current-truth packet/resolver,
governed proposal argument factory, deterministic facet fragment projector
with dependency fingerprints, and stable-core SEO risk guard. Existing claims,
Evidence and routes remain untouched. No new Authority type or Graph predicate
was added and no migration was required.

Focused proof is 9 tests / 22 assertions for the new slices. The complete Unit
suite is 382 tests / 1,822 assertions, exit 0, with one warning and one PHPUnit
deprecation. Composer validation is valid with the repository's existing
license warning; changed PHP files lint clean and `git diff --check` is clean.

Remaining `CODE_GAP`: end-to-end governed apply/read-back for enrichment,
persisted last-known-good fragment storage, public-render SEO verification, and
shared Video/Media/Article adapter wiring. `EXTERNAL_SYNTHESIS_ADAPTER_GAP`:
only deterministic synthesis/projection exists; no approved live AI provider
boundary is present. `PUBLIC_IDENTITY_STORAGE_GAP` remains unchanged: slug is
still derived at read time and no migration was run. Odo acceptance remains
read-only/runtime-gated; no fixture was used to claim live corpus behavior.

## Odo demo mutable-token migration — 2026-09-03

On the explicitly authorized demo runtime, backup and runtime inventory passed.
The guarded apply updated 193 non-collision mutable rows from `o-do` to `odo`
using a transaction and WordPress serialization-safe handling for postmeta and
options. Two Authority collisions remain intentionally unresolved: the glued
and pinned dial records have distinct UUIDs, names and semantic records. They
remain active with their legacy keys pending a governed identity decision; no
Graph edge or immutable audit/Evidence quotation was rewritten. The receipt is
`docs/semantic-packs/odo/ODO_MIGRATION_RECEIPT_2026-09-03.md`.

Read-back found 2 mutable Authority collision rows, 1 immutable audit row and
2 WordPress GUID rows; all other mutable stores have zero `o-do` matches. The
demo route check is `/odo/` HTTP 200 and `/o-do/` HTTP 301 → `/odo/`. Remote
HEAD changed independently from `54c9a26` at preflight to
`6d624650075293cfa8de4be21908697be05cc73f` at final verification; no tracked
working-tree changes were present.

## YouTube source availability probe restoration — 2026-09-03

Root-cause tracing of the `P4KaHX3LBOw` Video intake path found that
`Plugin.php` converted the presence of `NHK_YOUTUBE_API_KEY` into whether a
YouTube client callback existed at all. In the WordPress runtime without an
exported process variable, `VideoIntakeService` therefore received an adapter
with no client, performed no Data API request, and emitted an `unknown` source
snapshot without a diagnostic. The client also treated a missing API
`embeddable` field as true, which could fabricate `availability=available`.

The runtime now always wires the official `YouTubeDataApiClient`; it resolves
the key from the configuration constant or environment, reports deterministic
codes for missing configuration, timeout, malformed response, rate limit and
remote API errors, and preserves fail-closed unknown snapshots with the
diagnostic attached to the intake packet. Availability is `available` only
when the API returns a non-private item with an explicit embeddable state of
true. A fetched unavailable item records its fetch time and remains blocked.
The canonical watch/Shorts normalization and governed proposal/apply path are
unchanged; relation evidence refs remain preserved and no WordPress fallback
or semantic mutation was added.

Focused Video proof: 21 tests / 85 assertions. Full NHK Unit proof: 373 tests
/ 1,800 assertions, with one existing warning and one PHPUnit deprecation.

## Odo root legacy-redirect guard — 2026-09-03

Live demo reproduction showed that `/odo/` was redirected to the editorial
article `odo-10-con-10-bua-chuong-kep`, even though the public brand listing
linked to `/odo/`. Root-cause tracing found the legacy redirect hook running at
`template_redirect` priority 1 and applying the old `/odo/` mapping before the
semantic brand route could claim the request.

`LegacyUrlRedirects` now defers single-segment brand-root requests to the
semantic public-route boundary. A regression test covers the semantic brand
root and keeps nested article paths/model roots unaffected. Focused route tests
pass (9 tests / 47 assertions across the two route suites), PHP lint and
`git diff --check` pass. The full suite executes 461 tests but remains
environment-blocked by 8 pre-existing WordPress bootstrap errors and 12
mandatory integration-runtime failures. The demo was not mutated: its cutover
remains blocked by `REMOTE_DEPLOYMENT_CONFIG_REQUIRED`.

> **NON-NORMATIVE.** This is a mutable evidence/checkpoint record. If it
> conflicts with `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution
> controls.

## Odo WordPress canonical-redirect guard — 2026-09-03

The live response exposed `x-redirect-by: WordPress`, proving that the
remaining `/odo/` redirect was WordPress core's old-slug canonical redirect,
not the NHK legacy redirect hook. `LegacyUrlRedirects` now filters that core
redirect for single-segment semantic brand roots, allowing the already
registered public route boundary to resolve `/odo/`. Focused route tests pass
(10 tests / 49 assertions across the two route suites), PHP lint and
`git diff --check` pass. No WordPress metadata or demo runtime data was
mutated; the live server must pull this additional change and reload its PHP
runtime before verification.

> **NON-NORMATIVE.** This is a mutable evidence/checkpoint record. If it
> conflicts with `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution
> controls.

## Video evidence reference contract alignment — 2026-09-03

Root-cause tracing found that `nhk-v3/video-ingest` exposed relation
`evidence_refs` as arbitrary `object[]`, while
`VideoRelationCandidatePlanner` validated the obsolete `kind` field and never
resolved a canonical Evidence record. This conflicted with Constitution §13.2
Video Law (7), so it is recorded as `CONSTITUTION_CONFLICT` at the old
implementation boundary.

The catalog and runtime now use the canonical non-empty shape
`[{"evidence_id":"<Evidence UUID>"}]`. The planner resolves Evidence through
the existing Evidence, Knowledge and Source repositories and requires the
complete active/public usable chain. Arbitrary objects, bare strings, legacy
`id`, missing, malformed, nonexistent or inactive/unusable references fail
closed. The exact reference is preserved through the Video intake Proposal
payload; research matches without canonical Evidence are not promoted to
relations. No Governance shortcut, Graph bypass, WordPress writer, database
migration or semantic data mutation was added.

Evidence: focused Video/MCP tests pass; the `nhk-core` Unit suite passes. PHP
lint, Composer validation, `git diff --check` and secret scan pass. The
Constitution and normative Video contracts were not changed; the subordinate
Video Relationship implementation contract now states the canonical shape.

> **NON-NORMATIVE.** This is a mutable evidence/checkpoint record. If it
> conflicts with `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution
> controls.

## Odo canonical public-token correction — 2026-09-03

The historical Vietnamese slug normalization converted the display name `Ô Đô`
to `o-do`. `PublicRouteResolver::slug()` now applies a token-boundary
canonicalization so `Ô Đô`, `Ô Đô 36` and equivalent public names resolve to
`odo`, `odo-36` and related canonical paths without changing UUIDs. Substrings
such as `odometer` and `kim-odo-54` are not rewritten.

`PublicEntityRoutes` now sends a single HTTP 301 from a resolved legacy public
path such as `/o-do/` or `/o-do/o-do-36/` to `/odo/` or `/odo/odo-36/`.
Stable-key rekey/merge and WordPress data migration remain governed operations;
no database, Post, taxonomy, semantic record, Graph edge or audit history was
mutated in this checkpoint. Runtime apply remains blocked until the exact
WordPress/MySQL environment is verified and the required inventory, backup and
governance gates are satisfied.

## YouTube source epistemic-state correction — 2026-09-03

Root-cause tracing of the YouTube intake found that the local runtime has no
`NHK_YOUTUBE_API_KEY`, so `Plugin.php` selects no provider client and performs
no YouTube lookup. Separately, `YouTubeSourceSnapshot::fromArray()` coerced an
explicit provider `embeddable=null` to factual `false`; this was corrected to
preserve UNKNOWN while the existing completeness policy continues to fail
closed. A regression test proves the distinction. Focused Video tests pass:
13 tests / 51 assertions; full Unit suite passes: 357 tests / 1,731
assertions, with existing warnings/deprecation. No secret, Video, Proposal,
Graph relation, WordPress or remote runtime data was changed.

## Managed WordPress attachment promotion checkpoint — 2026-09-03

The WordPress attachment bridge now stages a validated WebP attachment as
Media `draft` with a PRIVATE primary asset, records read-back dimensions,
checksum, byte size, canonical filename and empty `sizes`, then delegates
completion to `MediaService::completeIngest()`. Completion promotes the asset
to PUBLIC and Media to ready; a readiness failure rolls the asset back to its
private staged state. Existing attachment mappings retry through the same
completion path and do not create duplicate Media or MediaAsset records.

Focused evidence: 2 tests / 11 assertions; Unit suite: 355 tests / 1,727
assertions, with one existing PHPUnit deprecation. Changed PHP files lint
clean, Composer validation and `git diff --check` pass. The bridge fail-closes
when WebP MIME, dimensions, file readability, checksum or byte size cannot be
verified. No rewrite, delivery gate, database record or demo runtime data was
mutated; demo runtime remains `NOT_EXECUTED_GOVERNANCE_GATE`.

> **NON-NORMATIVE.** This is a mutable evidence/checkpoint record. If it
> conflicts with `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution
> controls.

## Semantic reconciliation and publication-gate continuation — 2026-09-03

Root-cause investigation found that Article research wiring collapsed
`research_subject` to one `{type: ...}` object, so explicit Brand + Music scope
could not resolve deterministically. The inventory reader now preserves an
explicit subject map/list, reads each subject through the existing resolver,
and traverses Graph with the bootstrapped predicate registry; it does not infer
subjects or relations from title/name text. Missing relations remain empty or
diagnostic and are never fabricated.

Governance now exposes a read-only proposal review path through the existing
service, MCP catalog/transport/Ability bridge and REST API. Review returns the
proposal state, identity, operation, payload, revision and both binding
fingerprints needed for a valid approval, completing the submit → review →
approve handoff without weakening fingerprint checks.

`ArticlePublicationGate` now distinguishes permitted operational incompleteness
from hard integrity failures: missing real imagery, incomplete optional link or
structured-data enrichment, and explicitly unavailable rendered verification
are warnings; invalid structured state, unresolved subject, missing semantic
read-back, route/identity conflict, CAS conflict and compliance failure remain
hard blockers. New/modified factual claims marked by the research packet as
such require `SUPPORTED_WITHIN_SCOPE` Evidence; explicitly legacy claims with
evidence debt emit a warning only. No legacy data, Post, Graph edge, semantic
record, proposal or live runtime was mutated.

Fresh evidence: focused regression suite `35 tests / 227 assertions` passes
with one existing PHPUnit deprecation; changed PHP files lint clean and
`git diff --check` passes. Full suite remains environment-blocked by the
pre-existing WordPress bootstrap/database errors and mandatory integration
runtime failures documented below; no READY runtime claim is made. The
Post-75 acceptance path still requires guarded runtime data/read-back evidence
against the exact environment and is not claimed complete.

## Managed image primary policy checkpoint — 2026-09-03

The direct multipart image adapter now enforces `MAX_LONG_EDGE = 2048` for
the managed primary. Resizing remains aspect-preserving and only runs when an
input exceeds the bound; smaller inputs are not upscaled and no crop is
performed. The MCP schema and defaults use the same 2048 bound. Attachment
metadata explicitly stores an empty `sizes` map, so the scoped path persists
one primary WebP and creates no derivative cluster; existing files remain
untouched.

Focused evidence: `McpContractTest` covers the policy with fixture dimensions
`6000x4000 → 2048x1365` and `1200x800 → 1200x800`, plus the multipart contract
and zero-derivative read-back shape. Full Unit suite passes: 349 tests / 1,703
assertions. Changed PHP lint, Composer validation, `git diff --check` and
secret scan pass. Demo runtime is `NOT_EXECUTED_GOVERNANCE_GATE`; no upload,
WordPress attachment, semantic Media, Post, derivative or live data was
created or mutated.

## WordPress Abilities bridge checkpoint — 2026-09-03

Root-cause investigation confirmed WordPress 7.1 includes the Abilities API and
the plugin already hooks `wp_abilities_api_categories_init` and
`wp_abilities_api_init`, but the old `McpAbilityRegistration` allowlist exposed
only 15 of the 33 current catalog tools. The bridge now derives schemas and
descriptions from `McpToolCatalog`, registers all 33 Article, Category, Media,
Video, read, Knowledge/Source/Evidence and Proposal abilities in the
`nhk-v3-content-operations` category, and delegates execution back to the existing MCP
route/application boundaries. No second domain implementation or direct SQL
path was added.

Focused evidence: `McpContractTest` and `McpCapabilityManifestTest` pass (11
tests / 127 assertions); changed PHP lint and `git diff --check` pass. The full
suite has 418 tests executed with 8 existing WordPress bootstrap errors and 12
mandatory integration failures because no verified integration database is
available. The local plugin directory does not contain Easy MCP AI, so the
Abilities Browser parity cannot be verified in this workspace. Direct binary
image upload remains the existing multipart MCP transport; the Ability exposes
the same catalog contract without inventing a second binary persistence path.

## Owner publication override checkpoint — 2026-09-03

The sole Constitution now contains the Owner Publication Override Law and
acceptance invariants 65–74. Runtime code adds the three-outcome publication
classification, deterministic diagnostic registry, 30-minute Post/state/
policy/blocker/principal binding, dedicated append-only decision persistence
with migration 013, owner approval application service, mandatory native
WordPress read-back and MCP review/approval continuation tools. Failed quality
diagnostics remain attached to `published_with_exceptions`; no Authority,
Knowledge, Evidence, Graph, Media, MediaUsage or Governance semantic write is
performed by owner approval, and no Post-specific branch exists.

Focused proof: 24 tests / 217 assertions pass, including existing Article
publication, MCP contract and Governance-adjacent regressions. Full Unit proof
executes 484 tests with 8 existing WordPress bootstrap errors and 12 mandatory
integration failures because no verified `nhk_v3_test` runtime is available;
74 integration tests are skipped by the existing environment guards. Composer
validation, changed-file PHP lint and `git diff --check` pass. No live Post,
semantic record, Graph edge, V2/staging/production record or public URL was
mutated.

## Content Operations final-completion plan — 2026-09-03

The independently testable checkpoint plan is recorded in
`docs/superpowers/plans/2026-09-03-content-operations-final-completion.md`.
The first CP1 slice adds the read-only `ArticlePublicationGate` and explicit
`ArticlePublicationGateResult`. It requires a current draft state token,
canonical public identity and verified research, subject, duplicate-intent,
category, semantic plan/read-back, MediaUsage, real-image, claim-compliance,
SEO, internal-link, structured-data and public-route evidence. Focused proof:
3 tests / 11 assertions. It does not write WordPress, Governance, semantic
records, Graph, Media or live data, and it is not yet runtime-ready because the
native publish writer, publication evidence binding and rendered read-back are
still open.

## Article Research gate recheck — 2026-09-03

The current working tree passes the full NHK Unit suite: 307 tests / 1,516
assertions. Focused Article Research, related traversal, MCP contract and
capability-manifest coverage also passes: 18 tests / 145 assertions. Composer
validation passes with the existing no-license warning; changed PHP lint and
`git diff --check` remain clean.

The Article Research gate remains **PARTIAL** and must not claim
`ARTICLE_RESEARCH_PREFLIGHT_RUNTIME_READY`. Guarded integration evidence is
blocked by WordPress bootstrap: running with `NHK_WP_TEST_PATH=public` reaches
`Error establishing a database connection`, while the environment does not
provide a verified `nhk_v3_test` runtime. Public-route/public-readiness
coverage for every registered endpoint type and the complete acceptance matrix
also remain open. No Post, taxonomy, semantic record, Graph edge, Media,
Video, proposal or live data was mutated.

## Media ingest policy contract checkpoint — 2026-09-03

The existing `nhk.media.ingest` direct multipart adapter remains the one
canonical binary transport; no `nhk-v3/media-ingest` Ability or second
persistence path is introduced. The policy requires pre-persistence image
validation, EXIF orientation, bounded resize, contextual ASCII-safe filename,
WebP encoding, read-back and workfile/source cleanup. The scoped direct path
stores one primary WebP and bypasses global WordPress intermediate-size
generation; derivatives are allowed only when a real use contract requires
them. Existing legacy files remain untouched.

## P0 content-operations planning boundary — 2026-09-03

The first read-only slice of the MCP/Admin Content Operations Control Plane is
documented in `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`, with the
Article Semantic/SEO Research Preflight and SEO Projection contracts routed by
`docs/constitution/READ_FIRST.md`. `McpCapabilityManifest` projects the
currently registered `McpToolCatalog`; it does not introduce a new MCP tool,
endpoint, predicate or write path. Focused evidence is
`McpCapabilityManifestTest`: 2 tests / 7 assertions, PHP lint and
`git diff --check` pass.

This checkpoint is **PARTIAL**. Full Article inventory services (overlap,
Knowledge/Source/Evidence, relation plan, internal links, SEO blueprint),
shared WordPress editorial/taxonomy gateways, Admin consumers and the complete
read-back/public verification pipeline remain implementation gaps. No Post,
taxonomy, semantic record, Graph edge, Media, Video, migration or live data was
mutated. Concurrent working-tree changes in the semantic merge/Odo workstream
were preserved.

## Article research preflight continuation — 2026-09-03

The read-only research path now has a shared registry-driven semantic reader
(`RelatedSemanticQuery` with `PredicateTraversalPolicy`): active edges are
direction-validated, bounded to two hops, cycle-protected, deduplicated, and
returned with direct/derived classification plus best and alternative paths.
Article inventory projects existing Post references from Graph, reads a
bounded Knowledge → Evidence → Source chain, and delegates semantic link
candidates to the existing public eligibility and route policy. No Post,
taxonomy, semantic record, Graph edge, Media, Video or Governance mutation was
performed.

Focused evidence: 9 tests / 30 assertions; changed PHP files lint clean and
`git diff --check` passes. This checkpoint remains **PARTIAL**: the full
acceptance matrix, complete route/readiness serialization for every registered
endpoint, guarded integration evidence and final capability manifest READY
transition are still pending. The runtime must not yet claim
`ARTICLE_RESEARCH_PREFLIGHT_RUNTIME_READY`.

## P0 Article research preflight runtime slice — 2026-09-03

`ArticleResearchPreflight` and `ArticleResearchResult` now provide a read-only
research boundary and are available through the optional `research_topic` path
of `nhk.article.preflight`; the existing reconcile path is unchanged. The
runtime wiring uses bounded WordPress/repository reads and the existing
semantic resolver. It classifies supplied Graph candidates as direct/derived,
filters link candidates through the injected public-eligibility boundary and
produces category, Media, Video, SEO and claim-compliance planning sections.

Fresh evidence: 306 Unit tests / 1,510 assertions pass; changed PHP files pass
lint; Composer validation and `git diff --check` pass.

This remains **PARTIAL / NOT ARTICLE_RESEARCH_PREFLIGHT_RUNTIME_READY**:
runtime Post-to-subject reference projection, complete Source/Evidence
cross-inventory, public route eligibility resolution and shared Graph
two-hop traversal are not yet complete. The current runtime therefore reports
explicit gaps rather than claiming complete overlap/relation/link coverage.
No WordPress Post, taxonomy, semantic record, Graph edge, Media, Video,
proposal or live data was mutated.

## Odo demo MCP read-path checkpoint — 2026-09-03

`TARGET_RUNTIME=demo.1945.vn` is reachable through the deployed Streamable HTTP
MCP endpoint `POST /wp-json/nhk/v1/mcp` with protocol `2026-07-28`; initialize
returned `nhk-v3` `3.0.0`. Demo health is green across storage, runtime,
hydration, application and REST. Read-only `nhk.search` observed 76 semantic
entities, 8 Posts and 18 Knowledge records for `odo`, including the Odo 35
records and both pinned/glued dial identity pairs. The full evidence is in
`docs/semantic-packs/odo/ODO_RUNTIME_INVENTORY.md`.

The deployed catalog is older than the current worktree: its 21-tool proposal
schema does not advertise `rekey` or `merge`, and Graph REST incoming/outgoing
reads require an administrator credential (`401`). Public entity collections
also omit UUID/stable-key/revision/lifecycle and currently return zero Model and
Variant rows despite `nhk.search` finding them. Therefore no demo mutation is
allowed: revisions, lifecycle, Graph closure/deduplication, and all-reference
inventory remain `RUNTIME_UNVERIFIED` pending authenticated admin Graph access
or an equivalent connector.

The worktree now contains a generic `SemanticMergeService`, reference-adapter
contract and Graph adapter with focused unit coverage. This is implementation
scaffolding only: complete Knowledge/Source/Evidence/MediaUsage/Video/Post
adapters and deployed MCP registration remain required before merge can be
reported as complete. A local follow-up adds the `verify` adapter contract and
an append-only durable receipt repository backed by the existing Governance
audit table, plus applying/partial/completed receipt state and attempt metadata;
it is not wired into the deployed runtime and has not been demo-tested.

## Odo continuation checkpoint — 2026-09-03

The requested continuation was inspected at concurrent HEAD `cd9700e` (not
reset to `92dc93a`). The local generic merge follow-up passes focused tests
(`3 tests / 8 assertions`), Composer validation, full PHP lint and
`git diff --check`. The full suite remains environment-blocked with `8`
integration errors from unavailable `$wpdb` bootstrap and `12` mandatory
integration failures requiring `NHK_WP_TEST_PATH=public`; no test failure was
hidden or downgraded.

The merge remains `PARTIAL`: only the Graph adapter exists; the required
actual V3 reference-surface audit/adapters, production wiring, and independent
read-back are absent. No local or demo semantic data, WordPress Post, Graph
edge, migration, deployment or credential was changed by this checkpoint.
The request to deploy and apply Odo on `demo.1945.vn` conflicts with the
Constitution/AGENTS live-data and production/staging mutation prohibitions and
is stopped at that human gate.

## MCP direct image attachment checkpoint — 2026-09-03

The existing `nhk.media.ingest` adapter now accepts a direct multipart `file`
parameter in addition to its governed metadata packet. `McpApi` reads the
JSON-RPC envelope and WordPress file parameters separately; base64/data URLs are
not accepted. The WordPress-only binary adapter copies the upload to temporary
workfiles, validates the image, applies EXIF auto-orientation, performs
aspect-preserving max-dimension resize, applies requested quality, sanitizes the
caller-provided filename, and uploads only the processed output. WordPress
generated sizes are returned as derivatives. The source camera upload and
temporary workfiles are not retained by NHK after the request succeeds.

The direct path intentionally creates no NHK semantic Media identity from image
content and no Knowledge, Evidence or Graph relation. The new
`nhk.media.attachment.get` read tool returns the attachment ID, canonical URL,
filename, MIME, width, height, filesize and derivatives for read-back. A
write-guard prevents the existing native attachment-adoption hook from creating
semantic records for this adapter-only path.

Fresh evidence:

| Gate | Result | Evidence / limitation |
|---|---|---|
| MCP contract | **PASS** | `McpContractTest`: 9 tests / 108 assertions; catalog is 22 tools and multipart file routing is covered without base64. |
| UNIT | **PASS** | Unit suite: 297 tests / 1,466 assertions (current concurrent working tree). |
| PHP LINT | **PASS** | New/changed MCP, WordPress adapter, guard, Plugin and test PHP files pass `php -l`. |
| COMPOSER | **PASS** | `composer validate --no-check-publish`; existing no-license warning only. |
| DIFF | **PASS** | `git diff --check` exit `0`. |
| WP integration | **SKIPPED** | `NHK_WP_TEST_PATH` is unset; live image editor, upload, derivative and attachment read-back remain unverified in this shell. |

No database, WordPress Post, NHK Media semantic record, Knowledge claim,
Evidence, Graph edge, V2, staging or production data was mutated by this
checkpoint. Existing concurrent working-tree changes were preserved.

## Odo Semantic Pack installation checkpoint — 2026-09-03

### Task 3 checkpoint — 2026-09-03 — Odo manifest fail-closed validation

The existing generic `ArticleIngestPreflight` boundary now rejects create/ingest
semantic commands that introduce a target `stable_key` using the forbidden
legacy namespace token `o-do`, and it rejects target stable-key collisions
before proposal planning/persistence. Review follow-up tightened the same
boundary so duplicate `(entity_type, stable_key)` create/ingest targets within
one manifest also fail closed, and a payload-owned target `stable_key` now
returns `DEPENDENCY_UNAVAILABLE` when the required collision-preflight lookup
is not wired. The runtime wiring in `Plugin.php` reuses existing Authority,
Media, Knowledge and Source stable-key lookups; no Odo-only storage path, SQL
semantic mutation or new semantic capability was added.

Fresh evidence:

| Gate | Result | Evidence |
|---|---|---|
| RED | **PASS** | `vendor/bin/phpunit --configuration phpunit.xml.dist --filter ArticleIngestPreflightTest` initially failed on `test_create_rejects_forbidden_legacy_target_stable_key_namespace` because preflight accepted the forbidden key. |
| REVIEW RED | **PASS** | Same focused command later failed on `test_create_rejects_duplicate_target_stable_keys_within_same_manifest` and `test_create_rejects_target_stable_key_when_collision_preflight_is_unavailable`, proving both gaps before the follow-up fix. |
| FOCUSED | **PASS** | Same focused command after implementation: `7 tests / 14 assertions`. |
| GOVERNANCE/PREFLIGHT | **PASS** | `ArticleIngestPreflightTest`, `ArticleIngestCoordinatorTest`, `McpArticleContractTest`, `SemanticProposalPlannerTest`, `GovernanceCoreTest`, `GovernanceApplyContractTest`: `29 tests / 63 assertions`. |
| PHP LINT | **PASS** | `php -l` passes for `ArticleIngestPreflight.php`, `Plugin.php`, `ArticleIngestPreflightTest.php`. |
| DIFF | **PASS** | `git diff --check` exit `0`. |

No WordPress Post, Authority record, Knowledge claim, Source, Graph edge,
Media, Video, proposal, taxonomy or postmeta mutation was performed by this
task. Task detail and self-review are recorded in
`.superpowers/sdd/2026-09-02-odo-semantic-pack-implementation-plan/report-task-3.md`.

### Local runtime recovery recheck — 2026-09-03

The requested continuation point `bbf6f12147d8ea015485fb756fd4d46357d10fcb`
is present in the current history, but a concurrent Video sequence has advanced
the observed local HEAD to `e356d53ed3f89cd8a22ef44f6090d7ce0ad76b1a`. No reset
or checkout was performed. The exact Homebrew service was resolved as
`mysql (homebrew.mxcl.mysql)`; it is loaded and `Running: true`, with
`mysqld_safe --datadir=/opt/homebrew/var/mysql`. OS-level checks observed
`mysqld` PID `95616` listening on `127.0.0.1:3306`, `/tmp/mysql.sock` present,
and the configured `/opt/homebrew/var/mysql/nhk_v3` directory present.

No service restart was performed because MySQL is already running and its log
records `ready for connections`. The agent sandbox rejected TCP and Unix-socket
client handshakes with `Operation not permitted`; unrestricted escalation was
rejected and Computer Use could not access Terminal. PHP MySQL extensions pass,
but WordPress bootstrap still returns `Error establishing a database
connection`; `php tools/deployment-preflight.php` remains fail-closed at 5/10.
Authentication and server-catalog database existence remain unverified. No
database, Post, semantic record, relation, proposal, migration or Odo mutation
was performed.

The approved Odo reference pack and manifest were validated and checkpointed at
`6fd6cc3` (`docs: add Odo semantic reference pack`). YAML parsing passed, and
the pack's `o-do` occurrences are limited to forbidden-namespace rules and
legacy `from`/`source`/review references; no new canonical legacy key was
authored.

The required read-only runtime inventory is recorded at
`docs/semantic-packs/odo/ODO_RUNTIME_INVENTORY.md`. Runtime preflight is
currently **UNAVAILABLE** because WordPress bootstrap fails with
`WORDPRESS_BOOTSTRAP_FAILED` (database connection error). No Authority,
Knowledge, Source/Evidence, Graph, Media, Video or WordPress data was read
from this unavailable runtime and no mutation was attempted.

The earlier checkpoint recorded missing generic rekey/merge capability. The
current worktree has since added and tested generic Authority rekey, same-type
merge/reference movement through Graph, durable receipts and Controlled Apply
dispatch. Media/Video placeholders remain requirements-only by decision. Odo
35 retirement, relation completion, Knowledge creation and Post 38/39/40/55
reconciliation remain pending authenticated runtime inventory and governance.

The blocked apply report is recorded at
`docs/semantic-packs/odo/ODO_APPLY_REPORT.md` and checkpointed at `a10d265`
(`docs: record Odo apply boundary`). Unit tests, PHP lint, YAML validation and
diff checks pass; full integration remains blocked by the unavailable
WordPress/MySQL runtime. No proposal ID exists because no governed proposal
could be safely created or applied without runtime inventory and the missing
generic capabilities.

## Phase R3 release-gate cleanup checkpoint — 2026-09-02

This checkpoint records fresh verification against the current working tree
after the R3 red-test cleanup. The Constitution and all current runtime
contracts remain unchanged.

Verified changes:

- Retired V2 migration behavior tests were replaced with an explicit
  fail-closed, zero-write proof. No V2, staging, production, legacy Post or
  semantic data was migrated or repaired.
- `WpdbMediaAssetRepository` now bounds only malformed domain/identity rows;
  `TypeError`, autoload failures and other programming/infrastructure errors
  still surface. The receipt repository preflights idempotency replay so an
  expected duplicate does not emit a duplicate SQL warning.
- Public Authority REST/entity routing now resolves reader-facing slugs only;
  internal UUID/stable-key lookup remains available to internal application
  paths and governed MCP reads. Native `category`, `tag`, `author` and
  `knowledge` roots are reserved from broad entity rewrites.
- MCP integration assertions use the governed internal UUID read tools for
  Media, Video, Source and Evidence; public Knowledge Evidence remains
  intentionally non-standalone. The default WordPress category remains
  `Uncategorized` in storage and is projected as `Chưa phân loại` only at the
  public presentation boundary.

Fresh evidence:

| Gate | Result | Evidence / limitation |
|---|---|---|
| PREFLIGHT | **PASS** | `composer preflight`: 10/10 checks; Composer validation is valid with the existing no-license metadata warning. |
| UNIT | **PASS** | Full unit suite: 274 tests / 1,379 assertions, including the concurrently added Video semantic tests. |
| R2 / MEDIA | **PASS** | Guarded P6/media slice: 45 tests / 303 assertions; Media, MediaAsset and MediaUsage boundaries remain separate. MySQL 9.7 emits an existing dbDelta diagnostic while idempotently inspecting `nhk_media_usages.id`; it is not a PHPUnit warning. |
| MCP WIRE | **PASS** | `tools/mcp-wire-smoke.php --base-url=http://localhost`: all protocol, CORS, catalog and notification checks pass; catalog is 21 tools. |
| FRONTEND SMOKE | **PASS** | Fresh local HTTP route smoke: 46/46 routes pass, including `/knowledge/` and localized Uncategorized metadata. |
| FULL TEST | **PASS** | Fresh guarded `composer test`: 361 tests / 1,877 assertions, 0 errors, 0 failures, 2 authorized Post-55 skips, 0 PHPUnit warnings. |

The two skips are the guarded Post 55 tests because `nhk_v3_test` contains no
published Post 55 fixture; they remain skips rather than fabricated fixtures.
The local rewrite option was refreshed for route verification only. No semantic
record, Graph edge, WordPress Post, taxonomy term, slug, redirect mapping,
legacy body or production/staging/V2 data was changed. Deployment/cutover is
not claimed by this checkpoint; `READY_FOR_LEGACY_MEDIA_REPAIR: NO` remains in
force.

## Article Ingest Phase 1 checkpoint — 2026-09-02

The approved Phase 1 Article Ingest slice is implemented and committed at
`36e71c7`, following documentation reconciliation commit `26d64ec`. The
operation is reconcile-only for an existing runtime `wp_post:<blog_id>:55`.
It persists a durable receipt with unique idempotency key, request fingerprint,
editorial state token, child proposal IDs, dependency map, proposal states and
apply attempts. Same-key/different-fingerprint requests return
`IDEMPOTENCY_CONFLICT`; retries skip applied children and never compensate
semantic writes.

The coordinated MCP surface is `nhk.article.preflight` (read-only) and
`nhk.article.ingest` (capability `nhk_ingest_articles`, execute/resume). All
semantic writes remain Proposal → Human Approval → Eligibility → Controlled
Apply → repository → audit. Article create, editorial update, draft creation
and publish fail closed as `UNSUPPORTED_OPERATION`; no WordPress writer,
`V2MigrationService` or `PostKnowledgeLinkService` is reachable from the
Article path. The Post 55 fixture test and receipt integration test are guarded
to `nhk_v3_test`; no production, staging, V2 or live data was changed.

Evidence: unit suite `241 tests / 1,247 assertions`, Composer validation,
full PHP lint and `git diff --check` pass. Focused integration tests are
skipped because `NHK_WP_TEST_PATH`/test DB are not configured. The unconfigured
full suite remains blocked by the existing WordPress/database bootstrap and
mandatory integration environment failures; these are reported as failures or
skips, not converted to success. Production Post 55 is explicitly not applied;
the human-review packet is
`docs/architecture/ARTICLE_INGEST_POST_55_RECONCILIATION_PACKET.md`.

## Documentation-only checkpoint — 2026-09-02 — Related semantic navigation

The Constitution now explicitly establishes “Điều hướng quan hệ ngữ nghĩa và
phép chiếu nội dung liên quan” in §9.1 and adds acceptance invariants 26–35:
every registered canonical endpoint is a Graph entry point; related candidates
come only from governed Graph reads; derived traversal is bounded to two hops;
direct beats derived; paths are explainable; traversal honors registry
directionality; and semantic filtering precedes ranking and projection limits.

The implementation contract is
`docs/architecture/RELATED_SEMANTIC_PROJECTION_CONTRACT.md`. It records the
actual current runtime inventory: nine Authority types, 15 Graph endpoints and
eight registered predicates (`about`, `depicts`, `model_of`, `variant_of`,
`uses_movement`, `supports_music`, `configured_with_music`,
`observed_playing_music`). Article is a WordPress workflow rather than an
endpoint; Album/Collection remains a `SEMANTIC_GAP`.

The audit confirms that `RelatedContentQuery` is a one-hop read with fixed
page sizes and `BrandAggregationQuery` is a separate manual traversal. The
runtime does not yet provide a shared direction-aware two-hop engine,
standardized path/ranking/deduplication contract, unified related projection,
or MCP related read. These remain explicit P0/P1/P2 gaps, including the
existing direct-vs-derived precedence and public eligibility convergence risks.
No runtime code, registry entry, Graph edge, semantic record, WordPress Post,
taxonomy, post meta, migration, cache, V2/live data or publication was changed
by this checkpoint. The phased implementation plan is
`docs/superpowers/plans/2026-09-02-related-semantic-navigation.md`.

## Constitution compliance audit checkpoint — 2026-09-02

The documentation-only full Constitution compliance audit is complete. It is
recorded at
`docs/architecture/V3_CONSTITUTION_COMPLIANCE_AUDIT_2026-09-02.md` and its
non-normative TDD remediation plan is at
`docs/superpowers/plans/2026-09-02-v3-constitution-compliance-remediation.md`.
The highest findings are P0 semantic-write Governance bypasses, silent
Graph/runtime failure paths, unresolved Product/Specimen ownership, structural
parent truth using transitional payload fields, and public identity leakage /
missing durable slug history. Runtime/database evidence was unavailable, so
historical parent and Graph counts remain explicitly unverified. The audit
performed no database, Graph, WordPress, migration, seed, repair or legacy
article-body mutation. The next approved implementation gate is **Phase 0 —
P0 integrity fixes**; it does not authorize Graph backfill.

## Phase 0 P0 integrity implementation checkpoint — 2026-09-02

The Phase 0 implementation was performed against the sole normative
Constitution at audited baseline `8d480a2`, in an isolated temporary clone so
the concurrent Article Ingest changes and untracked MCP plan in the official
workspace remained untouched. The Constitution file is unchanged.

Completed code boundaries:

- Graph-derived Related Content, Brand Aggregation and Structural Context
  readers now propagate infrastructure/programming failures; only an honest
  empty Graph result remains empty.
- Post→Knowledge no longer has a direct Graph mutation boundary. Callers can
  request a Draft `relation_create` proposal with idempotency/fingerprint
  binding; the historical `V2MigrationService::apply()` writer is retired and
  fails closed. No migration was executed.
- Structural reads use Graph as canonical truth. A single safe payload parent
  is labelled `COMPATIBILITY_PAYLOAD`, `canonical=false` and
  `DATA_COMPATIBILITY_GAP`; missing, inactive, ambiguous and Graph/payload
  conflict cases fail closed. No edge or payload was changed.
- Public Authority, Media, Knowledge, Evidence and related projections omit
  internal UUID/stable-key fields and UUID relationship payload fields. Public
  entity REST detail now resolves stable keys; direct public Evidence REST
  lookup was removed because Evidence has no durable public key. Admin/MCP
  diagnostics and Governance contracts retain internal identifiers.
- Product and Specimen remain separate registry types with disjoint current
  payload ownership tests. The required lifecycle/ownership decision for
  multiple listings, physical condition/observation and Product-without-
  Specimen remains a human architecture gate.

Local evidence in the isolated clone: `217` unit tests / `1163` assertions,
Composer PHP lint, and `git diff --check` pass. Full runtime/preflight and
guarded integration evidence remain limited by the unavailable local
WordPress/database runtime. No database, WordPress Post, Graph edge, slug,
redirect, migration, seed, repair or legacy article-body operation was run.
Phase 0 is therefore **PARTIAL / blocked at the Product-Specimen human gate**;
the remaining P1 durable public identity/history work is not authorized by
this checkpoint.

## Current checkpoint — 2026-09-02

Root WordPress Post/Brand route-collision checkpoint — 2026-09-02: source
tracing confirmed that `PublicEntityRoutes::rewrite()` placed the one-segment
root Brand rule in `extra_rules_top`, ahead of WordPress `%postname%` rules.
The previous `PublicEntityRoutes::template()` then forced 404 when the broad
root rule's Brand lookup returned no entity. The root bridge now retains the
native `name` query, preserves Page resolution through the `request` filter,
returns control to WordPress when no Brand resolves, resets a positively
resolved entity route to 200, and records `IDENTITY_CONFLICT`/404 when both a
native WordPress object and Brand resolve the same root. Brand public-path
generation also fails closed when a public native root already exists; native
editorial links continue to use WordPress permalink APIs. No Post, slug,
Authority record, Graph edge, redirect or database row was changed.

Focused route tests pass 52 tests / 590 assertions; the complete Unit suite
passes 253 tests / 1,307 assertions; PHP lint, Composer validation and
`git diff --check` pass. The guarded Post 55 lifecycle test is present but
runtime verification is blocked because local MySQL cannot establish a
connection; local HTTP smoke cannot connect to localhost:80. Pre-deploy
staging inspection confirms `/odo/` and `/thuong-hieu/` resolve, `/brand/`
redirects one hop to `/thuong-hieu/`, the random root is 404, and the Post 55
slug remains 404 on the not-yet-deployed staging code.

Brand related-projection checkpoint — 2026-09-02: the public Brand detail
projection now includes every registered Authority group that has an active
Graph `about` edge to/from the Brand, in addition to the approved
`model_of`/`variant_of` structural path. Results are deduplicated by canonical
identity internally, prefer DIRECT over DERIVED, expose bounded hop/path
explanation, and omit the previous invalid three-hop Variant→Movement/Music
promotion. The theme renders Models, Variants, Movements, Music, Components,
Classifications, Specimens and Products; posts/media/videos remain in their
separate related-content groups. Public related Authority results now share
the eligibility policy. Keyword matches alone still do not create or imply a
Graph edge, and no semantic record or Graph edge was changed.

Evidence: focused Brand/public/frontend slice passes 57 tests / 581
assertions; the complete Unit suite passes 250 tests / 1,293 assertions;
Composer validation, plugin/theme PHP lint and `git diff --check` pass. The
full suite remains environment-blocked by the unavailable WordPress database
bootstrap and the mandatory `NHK_WP_TEST_PATH=public` integration gate.

Public entity runtime hotfix checkpoint — 2026-09-02: restored the missing
`NHK\\Core\\Shared\\Uuid\\UuidCodec` import in
`PublicEntityCollectionQuery`, preventing PHP from resolving the codec as the
non-existent `NHK\\Core\\Application\\Entity\\UuidCodec` during public detail
queries. Added a regression test for malformed canonical UUID input. Unit
verification is 246 tests / 1,266 assertions and plugin PHP lint passes;
WordPress bootstrap preflight remains unavailable in this isolated workspace.
No database, semantic record, Graph edge, migration or external deployment was
changed.

Public brand naming checkpoint — 2026-09-02: website public now applies the
presentation-only `PublicBrandNamePolicy` to WordPress title/excerpt/content
filters and all current semantic template text surfaces. Confirmed aliases such
as `ô đo`, `vê đét` and `junhan` render only as `Odo`, `Vedette` and `Junghans`;
JSON-LD is covered while tags, attributes, scripts, styles and form values are
preserved. The source `wp_posts` body and semantic records were not rewritten.
Focused unit/contract coverage was added; full verification remains subject to
the repository's existing WordPress/runtime availability.

Single-Constitution finalization checkpoint, 2026-09-02: the later
Constitution-finalization decision supersedes the earlier `5c60346` router
reconciliation. `AGENTS.md` again points directly to the sole normative
Constitution and `docs/constitution/READ_FIRST.md` is removed from the active
tree. The router decision remains historical evidence in Git only. This
documentation correction does not alter the concurrent Article Ingest runtime
implementation, tests, migration work, semantic data, Graph edges, WordPress
content, Post 55 or the preserved untracked MCP plan.

Documentation reconciliation checkpoint, 2026-09-02: the approved human
architectural decision restores `docs/constitution/READ_FIRST.md` as a short,
non-normative router and updates `AGENTS.md` to route
`AGENTS.md → READ_FIRST.md → NHK_V3_CONSTITUTION.md → relevant contracts`.
`docs/constitution/NHK_V3_CONSTITUTION.md` remains the sole normative source.
This supersedes the earlier historical note that the active tree should contain
only the Constitution under `docs/constitution/`. No PHP/JS code, database row,
WordPress Post, Post 55, semantic record, Graph edge, migration or external
state was changed. The pre-existing untracked MCP plan remains preserved and
unstaged. Phase 1 Article Ingest scope is reconciliation-only; create and
editorial update remain fail-closed pending the separately approved WordPress
write idempotency/CAS review.

Single-Constitution finalization checkpoint, 2026-09-02: forensic review found
that `READ_FIRST.md` was restored by the completed standalone commit `3abcfd4`
after `cb239af` retired it; no repository hook, generator or automation recreates
the file. The concurrent Codex process group remains active for an unrelated
Article Ingest design review, but three read-only observations showed stable
HEAD/status and unchanged Constitution-file hashes and mtimes. The final tree
must keep `AGENTS.md` direct and contain only
`docs/constitution/NHK_V3_CONSTITUTION.md` under `docs/constitution/`. The
pre-existing untracked MCP plan remains preserved and unstaged.

Documentation amendment checkpoint, 2026-09-02: the approved Article Ingest
boundary is now recorded in the Constitution and routed through
`docs/constitution/NHK_V3_CONSTITUTION.md`. The required sequence is semantic preflight
→ WordPress draft → semantic Governance/Controlled Apply → read-back
verification → WordPress publish. No Article entity, Graph endpoint, status or
operation name was added. No PHP/JS code, database row, Graph edge, WordPress
Post (including reconciliation case 55), slug, legacy body, migration or
external state was changed. The runtime coordinator, cross-boundary idempotency,
WordPress revision binding and final outcome contract remain implementation gaps.
`V2MigrationService.php` legacy body import and any direct
`PostKnowledgeLinkService` Graph mutation outside Governance are separately
recorded as `CONSTITUTION_CONFLICT` risks; Article Ingest must not call either
path. The pre-existing untracked MCP plan remains preserved and unstaged.

Historical constitutional forensic review checkpoint, 2026-09-02: the single
normative file remains `docs/constitution/NHK_V3_CONSTITUTION.md`. The retired
`READ_FIRST.md` artifact and all superseded constitutional fragments are absent
from the active tree; `AGENTS.md` now points directly to the single Constitution.
Tracked architecture, audit, V2 evidence, MCP and route documents that could be
misread as law now carry explicit NON-NORMATIVE notices, and an obsolete plan
reference was normalized. The Constitution vocabulary now explicitly defines
Variant Configuration, Specimen Observation and native WordPress featured/
content-image ownership. This checkpoint changed documentation only; no code,
database, semantic row, Graph edge, slug, redirect, migration, import, V2/live
state or push was changed. A pre-existing untracked MCP plan remains preserved
and unstaged.

Runtime verification checkpoint, 2026-09-02: implementation HEAD `67f7f79`
contains `abc2f67`, `b073780` and the hub checkpoint. Local WordPress and
MySQL were restored without replacing either database. Canonical preflight
passes 10/10; the guarded full PHPUnit suite passes 290 tests / 1,641
assertions after two test-only corrections for the live runtime registry and
WordPress Abilities return shape. Composer validation/lint and
`git diff --check` pass. The route matrix, exact Graph audit, REST, MCP wire
smoke and responsive browser checks were executed against the local runtime;
the read-only staging route matrix also serves the canonical Vietnamese hubs.

The local stored-menu inspection is now verified: no stored menu rows or
active menu locations exist, so the canonical theme fallback is rendered and
no targeted menu mutation was required. Local Authority remains 370 active
rows across the same nine type counts recorded below; Graph remains 189 nodes,
241 active edges and two persisted predicate rows. Runtime predicate
registration exposes eight predicates (the six approved structural predicates
plus existing `about` and `depicts`); it created no physical predicates or
edges. Structural diagnostics report 72 blocked Model/Variant findings with
missing parents and no candidates, so the Model hub and Brand structural
aggregation correctly remain empty rather than backfilling data.

The generic frontend smoke script still has three stale or
contract-incompatible expectations (`/knowledge/`, `/knowledge/page/2/` and
`/category/uncategorized/`); the contract-scoped route matrix passes. Staging
server preflight, stored-menu inspection and deployment were not available
over HTTP-only access, and no semantic, V2/live or Graph data was changed.

Constitutional consolidation checkpoint, 2026-09-02: the repository now has
one authoritative constitutional source at
`docs/constitution/NHK_V3_CONSTITUTION.md`. The eight fragmented files under
`docs/constitution/` were superseded and removed from the active tree; AGENTS,
active architecture/spec/plan notices and obsolete path references now point to
the single Constitution. The new document records the approved V3 law,
decision register, non-normative runtime status and V2 retirement notes. This
checkpoint changed documentation only: no database, semantic row, Graph edge,
slug, redirect, migration, import, V2/live state or product code was changed.

The approved Vietnamese public discovery implementation is present in the
working tree. Canonical hubs are wired for Brand, Model, Movement, Music,
Component, Classification, Specimen, Product and Comparison; legacy technical
archive roots redirect one hop to their Vietnamese canonical hubs. A single
`PublicEntityCollectionQuery` now supplies Authority archive/detail membership,
identity, eligibility and route-safe URLs to homepage, entity pages, search and
REST. Model/Variant transition eligibility uses valid payload parent evidence,
adds `DATA_COMPATIBILITY_GAP` when canonical Graph structure is not yet
available, and blocks missing or conflicting structural parents without
mutating data. Brand aggregation exposes read-only DIRECT/DERIVED paths.

The runtime Graph registry now contains exactly the six approved predicates:
`model_of`, `variant_of`, `uses_movement`, `supports_music`,
`configured_with_music` and `observed_playing_music`; no physical Graph edge
was created, retired or rewritten. Read-only distribution and structural
diagnostic commands are implemented. Unit verification is 191 tests / 1,105
assertions; Composer lint and `git diff --check` pass. Guarded WordPress
integration/preflight, HTTP route/MCP smoke, stored-menu inspection and the
exact 241-edge audit are currently blocked by the unavailable local WordPress
database/HTTP runtime. No semantic rows, legacy article bodies or live/V2 data
were changed.

The stored-menu before/after matrix is recorded as UNVERIFIED in
`docs/architecture/V3_MENU_ROUTE_AUDIT_2026-09-02.md`; the theme fallback and
all checked code-level navigation targets use canonical routes. Rewrite
flushing, targeted stored-menu edits, push, staging deployment and staging HTTP
verification remain pending the runtime gate.

Checkpoint commits: `abc2f67` (implementation), `b073780` (MCP evidence) and
this execution-state checkpoint. `origin/main` remains
`20c0d2a73c86940a2bf0036234d5f90172e79256`. The requested push was attempted
and rejected by the execution policy as external publishing; no workaround was
used. An unrelated untracked MCP plan file remains preserved and unstaged.

MCP V3 content-operations audit checkpoint, 2026-09-02: the clean HEAD
catalog contains exactly 19 tools, with `nhk.proposal.apply` in position 19;
the two previously stale catalog expectations are aligned to 19 in the
current integration contract. The audit confirms that Post CRUD/publish,
binary Media upload/standalone MediaUsage, MCP Graph reads and Album are not
authorized by current contracts. Product `specimen_uuid` plus the broad Graph
`about` allowlist is a `CONSTITUTION_CONFLICT`; Album is a `SEMANTIC_GAP`.
The current working tree (preserved pre-existing Brand work) registers six
additional approved predicates beyond the two at clean HEAD; this MCP task
does not add, remove or alter predicates. The only MCP code change in this
checkpoint adds the existing nine-operation allowlist to proposal input
validation. Targeted MCP unit tests pass; HTTP wire evidence remains blocked
at `http://localhost` because the local endpoint is not responding. No
bootstrap, snapshot, V2 migration, production data or push was performed.
The targeted MCP/Governance/Graph slice is 37 tests / 197 assertions. The
unconfigured full suite reported 8 integration errors, 12 mandatory integration
failures and 84 skips; the configured `NHK_WP_TEST_PATH=public` attempt reached
the WordPress database error before producing a PHPUnit summary. Composer PHP
lint and `git diff --check` pass.

Bootstrap continuation checkpoint, 2026-09-02: local MySQL's existing
`mysqld` listener is reachable on `127.0.0.1:3306`, although its Homebrew
launch-agent status remains `error 1` because the service wrapper detects an
already-running process. `nhk_v3` and `nhk_v3_test` are both non-empty and
were not replaced. The existing root `wp-config.php` is the supported local
bootstrap; WP-CLI resolves `nhk_v3`, `wp_`, and `http://localhost` for both
site URLs, so no duplicate `public/wp-config.php` was created. Canonical
preflight passes 10/10. Read-only Authority parity is 9/9 types with no
hydration loss (Brand 4, Model 30, Variant 42, Movement 18, Music 11,
Component 91, Classification 174, Specimen 0, Product 0); Graph counts are
189 nodes, 241 edges and 2 predicates. Homepage and REST return 200, the
read-only MCP wire smoke passes, and canonical available Brand/Music/Movement
routes return 200. The generic frontend route smoke retains stale or
contract-incompatible archive expectations, and the guarded PHPUnit suite
has two existing MCP catalog assertions expecting 18 tools while runtime
advertises 19; no code or data was changed to mask either result. The
required current old-iMac snapshot was not present; the only local dump is
`nhk-v3-local-2026-09-01.sql.gz` and was not imported.

Last updated: 2026-09-02, MCP V3 connector ability exposure checkpoint.

## MCP Ability catalog coverage and three-tier exposure probe — 2026-09-03

The catalog/Ability invariant is explicit: every catalog tool is mapped to an
Ability or has a non-empty exclusion reason. `nhk.proposal.eligibility` remains
read-only (`governed=false`) and is capability-gated by
`nhk_view_governance`; it is not classified as governed to force discovery.
`nhk.media.ingest` is explicitly excluded from the Ability bridge because its
canonical binary path is multipart. No base64/data URL adapter or second
persistence path was added.

| Layer | Evidence |
|---|---|
| WordPress registered NHK Ability count | 32 mapped; 1 catalog exclusion (`nhk.media.ingest`) |
| Easy MCP Browser visible count | 32 (`Nhk-v3`) |
| Easy MCP Browser enabled count | 32/32 (`12 read`, `20 write`) |
| Easy MCP exposed tool count | 32, per Browser statement that each enabled Ability is a tools/list tool |
| Actual connector/client MCP-29 tools/list | 8 read/status tools |
| `video-ingest` actual connector-visible | NO |
| Proposal lifecycle actual connector-visible | NO (`create`, `submit`, `approve`, `reject`, `eligibility`, `apply`) |

The discrepancy is across the Easy MCP Browser → actual connector/client
boundary. Current evidence is consistent with a stale connection/tool
snapshot, wrong MCP endpoint/profile or connector authorization/token scope;
reconnect/re-authorize and verify the same connector profile before treating
the server as unavailable. No Video backend change is authorized by this
probe. Image-from-chat transport was not verified; it remains
`IMAGE_FROM_CHAT_BLOCKED_BY_EASY_MCP_TRANSPORT` unless a compatible Easy MCP
transport is observed.

## MCP governed Video connector bridge checkpoint — 2026-09-03

The connector gap was traced to the boundary between the custom NHK
Streamable HTTP endpoint and WordPress Abilities/Easy MCP discovery. The
custom `McpToolCatalog` already contained governed Video and Proposal tools,
but `McpAbilityRegistration` exposed only eight read abilities, so a connector
discovering WordPress Abilities could not see the governed writers.

The bridge now exposes `nhk-v3/video-ingest` plus the six
`nhk-v3/proposal-*` lifecycle abilities. Each is public/REST-discoverable but
capability-gated and delegates to the registered `/nhk/v1/mcp` transport;
there is no direct WordPress writer, second proposal path, SQL semantic write,
taxonomy, post-meta or Graph bypass. Media/Knowledge/Source/Evidence writers
remain outside the Ability bridge.

Focused MCP verification passed: 28 tests / 108 assertions, PHP lint,
Composer validation and `git diff --check` passed. Full Composer PHPUnit ran
389 tests and remains environment-blocked with 8 integration errors and 12
bootstrap/configuration failures because the WordPress/MySQL runtime was not
available; 74 tests were skipped. Live MCP wire smoke is blocked because
`http://localhost:80` is not listening. Therefore no live Ability discovery,
Odo URL ingest, proposal apply, result UUID, read-back or data mutation is
claimed in this checkpoint.

The MCP V3 runtime catalog exposes exactly 19 tools. `nhk.semantic.resolve` is
the 19th catalog member added by this checkpoint (catalog position 2; position
19 is `nhk.proposal.apply`); stale assertions expecting 18 are now aligned to
19. The exact Authority registry, 15 Graph endpoint types, two predicates,
effective Governance operations, Knowledge profiles, Media/Video contracts and
native-WordPress Post boundary are recorded in
`docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`. The WordPress Abilities adapter
registers only eight existing read tools on WordPress 6.9+, feature-detected;
no semantic type, predicate, relation, field or operation was added. Album is a
`SEMANTIC_GAP`. Product's `specimen_uuid` plus Graph `about` allowance remains
a contract-level `CONSTITUTION_CONFLICT`; no Product/Specimen data changed.
Local HTTP/WordPress evidence remains blocked by the unavailable runtime; no
push was made.

The connector-gap implementation registers the existing NHK V3 semantic read
contracts under the explicit `nhk-v3` WordPress Abilities namespace and
`nhk-semantic` category on the lazy registry hooks. The allowlist contains only
`search`, `semantic-resolve`, `entity-get`, `media-get`, `video-get`,
`knowledge-get`, `source-get` and `evidence-get`; each is public/REST-exposed,
read-only, `read`-capability checked and delegates to `McpReadHandler`. Ingest
and proposal abilities remain absent while existing custom MCP writes remain
Governance/capability gated. Unit verification is 166 tests / 985 assertions;
guarded runtime verification and read-only wire smoke remain blocked by the
unavailable local WordPress/HTTP runtime. No taxonomy, post meta, semantic data,
V2/live state or push was changed.

The read-only P0 public identity audit added
`V3_PUBLIC_ENTITY_IDENTITY_MATRIX.md`. It confirms from current code that
Authority public slugs are derived from `canonical_name` at read time, with no
persisted public-slug or Authority alias-history contract. It also confirms
that detail, EntityPage archive, REST list, search and nested route resolution
do not share one public eligibility predicate; a route-less active entity can
therefore be represented differently across layers. This is recorded as a
`SLUG_CONTRACT_FAILURE` / data-code contract gap and a
`PUBLIC_ELIGIBILITY_FAILURE` / code gap, pending runtime proof. Model/Variant
parent payloads remain a separate structural-contract gap, while Music has no
Brand route requirement. The staging `/odo/` probe could not reach HTTP because
the host did not resolve, and the local WordPress database was unavailable;
all live row/count/cache conclusions remain UNVERIFIED. No database, V2, Graph,
slug, redirect or cache state was changed.

P0 implementation is in progress. The approved change separates Authority
row-level malformed-data omission from infrastructure/programming failures:
`AuthorityRowHydrator` catches only bounded row-data exceptions, while
`Error`, `TypeError`, missing runtime dependencies and unexpected failures
propagate. Registry-driven Authority parity and layered HealthCheck are now
covered by focused TDD tests. `tools/deployment-preflight.php` is the
read-only release gate for root Composer runtime, WordPress/nhk-core bootstrap,
migration state, Authority hydration and REST initialization. No database,
Graph edge or legacy data was changed. Staging synchronization remains blocked
until server shell access is available; the unrelated server
`public/error_log` must be preserved.

Latest local P0 evidence: Unit suite 165 tests / 982 assertions; Composer PHP
lint and `git diff --check` pass. `composer preflight` exits 1 as designed:
Git HEAD, composer.lock, root autoload, Symfony UID and NHK runtime classes pass;
WordPress bootstrap, schema, Authority hydration and REST checks fail because
the local WordPress database is unavailable. No database or semantic data was
changed. Origin push and staging deployment remain pending external access.

The repository now tracks the runtime-safe `config/application.php` required
by the staging `public/wp-config.php` bootstrap. It reads deployment values and
secrets from the process environment, fails closed when required values are
missing, and contains no credentials. Staging server repair and HTTP evidence
remain pending because this workspace has no SSH/server shell access.

The approved Brand backbone staged contract is documented in
`BRAND_BACKBONE_STRUCTURAL_CONTRACT_EVIDENCE_2026-09-02.md`,
`BRAND_BACKBONE_STRUCTURAL_DESIGN_SPEC_2026-09-02.md`, and
`BRAND_BACKBONE_STRUCTURAL_ACCEPTANCE_MATRIX_2026-09-02.md`, with the
implementation plan at
`docs/superpowers/plans/2026-09-02-brand-backbone-structural-contract.md`.
The package records `model_of` and `variant_of` as `REGISTRY_GAP`s, identifies
payload-driven parent fields/routes as a `CONSTITUTION_CONFLICT` when treated
as canonical structure, and records absent physical backbone edges as a
separate `DATA_COMPATIBILITY_GAP`. This checkpoint is read-only: no predicate,
entity, payload, relation, redirect, or legacy article body was changed.

Fresh read-only in-app browser visual QA checked `/`, `/tri-thuc/` and `/video/`.
The visitor-facing hero, navigation, discovery panel and honest empty states
rendered successfully; browser console error/warning logs were empty. Active
Video detail remains unavailable because the local query has no active Video row.

| Field | Current value |
|---|---|
| Workspace | `/Users/imac24-2125d/Developer/nhk-v3` |
| Branch / HEAD | `main` / current local checkpoint |
| Current phase | P11 readiness audit in progress; local-dev P10 apply is checkpointed, live parity gates remain open |
| Last accepted phase | P5 Canonical Domain Foundation |
| DB migration | current 9 / target 9 on `nhk_v3`; Knowledge, Evidence metadata, Migration006/007, MediaAsset metadata/visibility and ProjectionContext009 are UP-only applied; media/video storage ready |
| Tests | Unit suite: 153 tests, 934 assertions; guarded WordPress integration: 94 tests, 517 assertions; combined current evidence: 247 tests, 1,451 assertions (Unit + latest guarded evidence); Composer PHP lint, MCP wire smoke, all-nine-type core route smoke 34/34 and opt-in real Authority detail route smoke 41/41 pass; browser public-language/SEO and responsive route sweep remains recorded below |
| Blockers | Active Video/data-gated detail evidence, external MCP interoperability/deployment verification, ambiguous case-level identity/provenance/retirement decisions after deterministic resolution, final decisions for 27 explicitly classified URL candidates, MediaAsset publication/privacy policy and governed recovery of 18 available V2 upload candidates plus 3 unavailable thumbnails, Source/Evidence activation/public provenance policy and unresolved legacy mappings; V2/live remains read-only |
| Working assumptions | Media/Video routes are registered only when WordPress has a usable `$wpdb`; `nhk_v3_test` is the only destructive integration target; editorial aliases render empty states without creating fixture terms |
| Next executable task | Restore the existing local HTTP/WordPress runtime and collect fresh MCP wire/Abilities/read-only smoke evidence; do not add tools or semantic types before the Product/Specimen conflict and Album gap receive architecture decisions |
| Last parity count | V2 restored read-only inventory: 800 posts, 1,301 entities, 185 relations, 3 media assets with field-level metadata, 19 sources, 40 citation evidence rows and 1,581 semantic projections; latest local-dev apply migrated 3,961 rows and skipped 1,012 with 0 conflicts, including 1,581 non-canonical projection contexts, 367 Knowledge, 370 Authority and 34 native-post redirects |
| Pending migrations | None; `nhk_v3` is current 9/target 9 and Migration006 ledger, Evidence/MediaAsset metadata and ProjectionContext009 are active |
| Migration dry-run | Baseline full restored-backup export: 4,973 records, 3,960 candidates and 1,013 skipped; policy-normalized rerun classifies native homepage `/` as `READY_NOOP`, yielding 3,961 mapped and 1,012 skipped with 0 conflicts; projection contexts account for 1,581 mapped records |

## Autonomous work queue

| Queue field | Current value |
|---|---|
| `ACTIVE_GATE` | Approved Brand Backbone Structural Contract / V2 Structural Mapping |
| `NEXT_ACTIONABLE_GATE` | Implement contract tests and registry/query changes for `model_of`/`variant_of` only after package review; keep physical relation repair as a separate governed data gate |
| `TEMPORARILY_BLOCKED_GATES` | MCP local wire/runtime evidence: HTTP harness is not responding at `http://localhost:80` or `http://wordpress.local:8080`; retry `php tools/mcp-wire-smoke.php --base-url=http://localhost` after restoring the existing WordPress/Apache vhost |
| `HUMAN_BLOCKED_GATES` | Only case-level `AMBIGUOUS_REQUIRES_HUMAN` identity/provenance/publication/retirement decisions after deterministic evidence; production-scale legacy migration backup/restore; final cutover |
| `COMPLETED_GATES` | Visibility contract; 764-record classifier; structural mapping policy packet and concept/relation evidence matrix; MCP resolver implementation and transport contract |
| `DEFERRED_NONCRITICAL_WORK` | Unrequested defensive hardening, new indexes/caches without evidence, cosmetic redesign outside the frontend acceptance sweep |

## Checkpoint journal

- 2026-09-02: Closed the MCP connector registration gap with an explicit
  eight-ability read allowlist. Unit tests and PHP lint pass; guarded
  WordPress integration cannot start because the local database bootstrap is
  unavailable, and `tools/mcp-wire-smoke.php` cannot connect to `localhost:80`.

- 2026-09-01: Continued from accepted `27c79ae`. Confirmed its scope is limited
  to canonical public Authority routing and related Constitution/spec/tests;
  push remains blocked by DNS resolution for `github.com`. The current route
  audit records the approved boundary: Video has a canonical presentation slug
  route with UUID legacy redirect, while MediaAsset delivery is non-indexable
  and atomic Knowledge Claims remain consumed through public projections rather
  than receiving invented SEO pages. Frontend design law is now explicitly
  listed in the Constitution and AGENTS guidance; shared theme tokens cap normal
  display typography at 48px desktop / 36px mobile guardrails.

- 2026-09-01: V2 Video migration now parses every supported YouTube URL,
  rejects an explicit external-ID mismatch as a review conflict, and persists
  the canonical `https://www.youtube.com/watch?v=...` URL. This prevents valid
  `youtu.be`, `/shorts/` and `/embed/` references from being migrated into a
  form that the public-reference predicate would later hide. Unit verification
  remains 152 tests / 916 assertions; the guarded integration test now passes
  after the local MySQL service was restored, and the full suite is 246 tests /
  1,428 assertions.

- 2026-09-01: `DryRunService` now applies the same supported-YouTube URL,
  canonicalization and URL/external-ID conflict boundary as the real migration
  path, including the required canonical UUID. Fresh retained-export dry-run
  remains 4,973 source records, 3,961 mapped, 1,012 skipped and 0 conflicts;
  unit verification is 150 tests / 912 assertions.

- 2026-09-01: MCP optional UUID fields now advertise JSON Schema
  `type=[string,null]` and transport validation accepts explicit null before
  UUID/URI/pattern checks; governed handlers continue to normalize absent or
  empty values to null. Unit verification is 148 tests / 906 assertions;
  guarded integration is 93 tests / 509 assertions; combined verification is
  241 tests / 1,415 assertions.

- 2026-09-01: NHK Admin proposal detail now exposes the latest apply attempt
  state as Apply status and provides a labelled Eligibility / block reason
  summary with state-gate hints before the operator requests full Governance
  reason codes. The change is read-only and preserves all mutations behind the
  existing Governance API and capabilities. Unit verification is 148 tests /
  898 assertions; guarded integration remains 92 tests / 506 assertions;
  combined verification is 240 tests / 1,404 assertions.

- 2026-09-01: Admin Eligibility interaction now writes the Governance
  `ready` result or joined `BLOCKED` reason codes into the labelled summary,
  while the raw response remains available for operator inspection. Unit
  verification is 148 tests / 900 assertions; guarded integration remains 92
  tests / 506 assertions; combined verification is 240 tests / 1,406
  assertions.

- 2026-09-01: Dry-run structural validation now mirrors apply for required
  identity/content fields on Media, Knowledge, Source, Evidence and
  MediaAsset records; malformed records receive `INVALID_IDENTITY` before any
  candidate is reported as mapped. The retained-export totals remain unchanged.

- 2026-09-01: Dry-run Evidence validation now rejects non-`knowledge` target
  types before endpoint checks, matching the real migration boundary. Unit
  verification is 152 tests / 916 assertions; full verification is 246 tests /
  1,428 assertions.

- 2026-09-01: MCP Source and Evidence ingest now expose an explicit
  `visibility` enum (`PUBLIC|PRIVATE|HIDDEN`) and normalize it into the
  governed metadata boundary. Conflicting top-level and metadata visibility
  values fail before proposal creation; end-to-end Controlled Apply proves
  explicit public state reaches WPDB and public REST/MCP reads. Unit
  verification is 152 tests / 918 assertions; guarded integration is 94 tests /
  517 assertions; combined verification is 246 tests / 1,435 assertions.

- 2026-09-01: Read-only `tools/v2-domain-post-classify.php` now classifies the
  764 non-editorial domain-post records into `STRUCTURE_REFERENCE` (742),
  `REQUIRES_REVIEW` (21 attachments) and `RETIRE` (one `wp_global_styles`
  record). Every item carries a bounded reason code and
  `mapping_applied=false`; no body, identity, redirect or V2/V3 record was
  changed. The next gate is governed mapping/retirement approval for the
  structural and recovery lanes.

- 2026-09-01: The domain classifier now emits a bounded policy packet for
  every lane: V3 target boundary, legacy identity proof, governed relation
  shape, migration action and retirement rule. TDD coverage verifies the
  Authority packet; this remains review evidence and applies no identity,
  relation, redirect or retirement decision. Unit verification is 153 tests /
  934 assertions; guarded integration evidence remains 94 tests / 517
  assertions.

- 2026-09-01: The read-only MCP wire smoke now checks the 19-tool catalog and
  calls `nhk.semantic.resolve` with a non-mutating context packet. The local
  harness endpoint was unavailable during this checkpoint, so runtime PASS
  evidence is still pending; no mutation tool is invoked by the smoke.

- 2026-09-01: Added the read-only `McpSemanticContextResolver` foundation for
  the MCP semantic-context gate. It resolves Authority context by canonical
  UUID first, stable key second, then exact canonical name/alias; ambiguous
  name matches are returned as candidates and are never auto-resolved. The
  resolver emits missing/conflict/empty relation buckets and performs no
  mutation. Focused TDD coverage passes 1 test / 7 assertions; MCP transport
  wiring is now exposed as `nhk.semantic.resolve`; local wire evidence remains
  the next executable boundary.

- 2026-09-01: Admin proposal detail now reads and displays direct dependency
  UUIDs alongside the dependency binding fingerprint through the existing
  read-only dependency repository. Unit verification is 148 tests / 904
  assertions; guarded integration remains 92 tests / 506 assertions; combined
  verification is 240 tests / 1,410 assertions.

- 2026-09-01: Read-only migration revalidation against the retained full V2
  export reproduced 4,973 source records, 3,961 mapped, 1,012 skipped and
  zero conflicts; the independent domain-target audit reproduced 742 explicit
  candidates. No V2/live or local database state was modified.

- 2026-09-01: Fresh read-only revalidation after the MCP validation checkpoint
  passed all 34 declared frontend routes and all nine MCP wire checks,
  including CORS protocol headers, modern initialize/tools exchange, invalid
  Origin rejection and initialized notification handling; no database state
  changed.

- 2026-09-01: Read-only reference QA revalidated V2 `/tri-thuc/` (12 cards),
  `/thuong-hieu/` (15 cards), honest empty states for `/thu-vien/` and
  `/goc-chia-se/`, plus a representative article's breadcrumb/body/related
  navigation. Tinhte was inspected only for dense feed, featured hierarchy
  and quick-view patterns; no external branding or markup was copied.

- 2026-09-01: Canonical UUID route boundaries are now case-compatible across
  REST routes, public rewrites and the V2 read-only exporter, matching the
  shared semantic codec and MCP contract. Guarded Integration proves an
  uppercase UUID reaches the REST handler: 91 tests / 491 assertions; combined
  verification is 237 tests / 1,368 assertions.

- 2026-09-01: MCP Media ingest now advertises and recursively validates the
  complete nested asset/usage packet: required identity/content fields,
  enum-constrained kind/visibility/role, checksum and endpoint patterns,
  dimensions and non-negative sizes, plus unknown-property rejection. Generic
  provenance/metadata objects remain intentionally open. Unit verification is
  147 tests / 883 assertions; guarded integration remains 91 tests / 494
  assertions; combined verification is 238 tests / 1,377 assertions.

- 2026-09-01: Read-only revalidation against the retained full V2 export
  reproduced the policy-normalized dry-run: 4,973 source records, 3,961
  mapped, 1,012 skipped and 0 conflicts. The independent domain-target audit
  reproduced 742 candidate posts; all remain explicit-review items and no V2
  or live data was modified.

- 2026-09-01: Admin/REST/MCP proposal creation now normalizes an empty
  optional target UUID to null, allowing create/ingest packets from the Admin
  composer without inventing a target identity while preserving strict UUID
  validation for non-empty values. Unit verification is 149 tests / 887
  assertions; guarded integration is 93 tests / 500 assertions; combined
  verification is 242 tests / 1,387 assertions.

- 2026-09-01: Post-push runtime revalidation passed all 34 declared frontend
  routes and all MCP wire checks (CORS, protocol negotiation, 18-tool catalog,
  invalid-Origin rejection and initialized notification); the smoke commands
  are read-only and made no database changes.

- 2026-09-01: MCP ingest schemas now also reject empty required strings and
  invalid domain enum values before dispatch, while preserving recursive Media
  packet validation. Unit verification is 148 tests / 888 assertions; guarded
  integration is 92 tests / 500 assertions; combined verification is 240 tests
  / 1,388 assertions.

- 2026-09-01: Nested Media asset/usage schemas now require non-empty storage,
  MIME and endpoint-key strings in addition to their existing type, enum,
  pattern and numeric constraints. Unit verification is 148 tests / 891
  assertions; guarded integration is 92 tests / 500 assertions; combined
  verification is 240 tests / 1,391 assertions.

- 2026-09-01: MCP ingest schemas now also publish and enforce domain-shaped
  stable keys and URI format for Video URLs before governed dispatch. Unit
  verification is 148 tests / 893 assertions; guarded integration is 92 tests /
  502 assertions; combined verification is 240 tests / 1,395 assertions.

- 2026-09-01: Guarded MCP integration now proves malformed stable keys and
  non-URI Video URLs fail at schema validation before governed dispatch. Unit
  verification is 148 tests / 893 assertions; guarded integration is 92 tests /
  506 assertions; combined verification is 240 tests / 1,399 assertions.

- 2026-09-01: Guarded MCP integration now proves a valid uppercase canonical
  UUID passes the advertised schema and semantic transport validation while a
  nil UUID remains rejected with JSON-RPC `-32602`. Full verification is Unit
  146 tests / 877 assertions and guarded integration 90 tests / 489 assertions.

- 2026-09-01: MCP canonical UUID fields now advertise both JSON Schema
  `format=uuid` and case-compatible patterns; transport also validates
  semantic UUIDs before dispatch. Full verification is Unit 146 tests / 877
  assertions and guarded integration 90 tests / 485 assertions.

- 2026-09-01: MCP Proposal create schema now exposes the fields supported by
  the governed handler (`subject_id`, expected revision, target/dependency
  UUIDs, fingerprints and idempotency key), preserving strict validation while
  allowing linked proposals. Full verification is Unit 146 tests / 876
  assertions and guarded integration 90 tests / 485 assertions.

- 2026-09-01: MCP transport now validates tool-call arguments against the
  advertised schema before dispatch, including required fields, types,
  bounds, UUID patterns, nested item types and unknown-property rejection.
  Invalid calls return JSON-RPC `-32602`/HTTP 400. Full verification is Unit
  146 tests / 872 assertions and guarded integration 90 tests / 485 assertions.

- 2026-09-01: Hợp nhất UUID validation vào `UuidCodec::isValid()` tại các
  domain constructors Authority, Graph, Media, Video, Knowledge, Evidence và
  Governance, đồng thời thống nhất migration node mapping/dependency reads.
  Full verification: Unit 146 tests / 872 assertions và guarded integration
  89 tests / 479 assertions.

- 2026-09-01: MCP tool schemas now declare canonical UUID patterns for entity,
  Media, Video, Knowledge, Source, Evidence and Proposal ID fields, while
  runtime guards continue to reject nil UUIDs. Unit verification is 146 tests
  / 872 assertions and guarded integration remains 89 tests / 479 assertions.

- 2026-09-01: Graph endpoint resolvers now use the shared strict UUID codec
  instead of duplicated shape-only checks, so nil/malformed Authority, Media,
  Video, Knowledge, Source and Evidence graph keys fail as invalid endpoint
  references. Unit verification is 145 tests / 859 assertions and guarded
  integration remains 89 tests / 479 assertions.

- 2026-09-01: Unified migration UUID checks with the strict shared codec:
  dry-run and V2 migration now reject nil/malformed canonical UUIDs through
  the same validator, preserving bounded `INVALID_IDENTITY` outcomes. Unit
  verification is 145 tests / 858 assertions and guarded integration remains
  89 tests / 479 assertions.

- 2026-09-01: Hardened Graph-derived related content: malformed canonical
  entity/media/video IDs now fail closed before repository lookup, and Graph
  lookup exceptions produce empty public groups. Unit verification is 144
  tests / 857 assertions and guarded integration remains 89 tests / 479
  assertions.

- 2026-09-01: Extended the public UUID boundary to the Knowledge theme detail
  query: UUID-shaped but invalid claim keys now return an empty result before
  repository conversion. Runtime revalidation passed frontend route smoke
  34/34 and MCP wire smoke; Unit verification is 143 tests / 856 assertions
  and guarded integration remains 89 tests / 479 assertions.

- 2026-09-01: Closed the public UUID input boundary: a shared strict
  canonical UUID validator now guards REST, MCP, theme detail queries and
  public asset delivery; UUID-shaped but invalid IDs fail closed as 404/empty
  responses instead of reaching WPDB conversion. Unit verification is 142
  tests / 855 assertions and guarded integration is 89 tests / 479 assertions.

- 2026-09-01: Revalidated the read-only runtime and migration evidence from
  current artifacts: MCP wire smoke passed CORS, initialize, tools/list,
  invalid-Origin rejection and initialized notification; frontend route smoke
  passed 34/34; the current no-write dry-run reports 4,973 source records,
  3,961 mapped, 1,012 skipped and 0 conflicts; domain-target audit reports
  742 candidates, all still requiring explicit mapping evidence. No V2/live
  data was modified.

- 2026-09-01: Persistence hydrators now validate raw numeric state values
  before casting: non-domain values cannot silently become RETIRED or ACTIVE
  records. Authority, Media, Video, Source, Knowledge Claim and Evidence
  reads now omit malformed state rows. Unit verification remains 141 tests /
  852 assertions and guarded integration is 88 tests / 478 assertions.

- 2026-09-01: Closed the Governance state hydration boundary: out-of-range
  persisted numeric states for ApplyAttempt and Proposal are now omitted
  rather than silently coerced to a default state. Current verification is
  Unit 141 tests / 852 assertions and guarded integration 88 tests / 478
  assertions.

- 2026-09-01: Completed the persistence hydration boundary across Authority,
  Knowledge Source/Claim/Evidence and MediaUsage: malformed UUID, state,
  revision, relation, endpoint or other domain fields are omitted from reads
  and collections instead of leaking domain exceptions. Authority now also
  rejects non-positive schema/revision values at the domain boundary. Unit
  verification is 141 tests / 852 assertions and guarded integration is 87
  tests / 476 assertions.

- 2026-09-01: Revalidated the live local-dev read-only boundary after a
  graceful Apache restart: MCP wire smoke passed CORS, initialize, tools/list,
  invalid-Origin rejection and initialized notification; frontend route smoke
  passed all declared routes including sitemap/RSS, search, aliases,
  comparison, 404 and fail-closed asset checks on canonical `http://localhost`.
  The read-only domain-target audit still reports 742 unique same-domain
  candidates, all requiring explicit mapping evidence and approval.

- 2026-09-01: Hardened ApplyAttempt persistence hydration: malformed UUID,
  non-positive attempt number, invalid state or result identity rows are now
  omitted from `find()`/proposal collections instead of leaking domain errors
  into Controlled Apply or Admin. Current verification is Unit 141 tests / 852
  assertions and guarded integration 79 tests / 457 assertions.

- 2026-09-01: Closed the Governance command hydration boundary: proposal
  repository reads now omit malformed or non-array `command_json` rows from
  `find()` and idempotency lookup instead of leaking a `TypeError` into Admin
  or lifecycle services. Current verification is Unit 140 tests / 846
  assertions and guarded integration 76 tests / 451 assertions.

- 2026-09-01: Closed the KnowledgeClaim provenance hydration boundary: WPDB
  repository reads now omit malformed or non-array provenance rows from
  canonical lookup and collections instead of leaking a `TypeError` into
  semantic search or public readers. Current verification is Unit 140 tests /
  846 assertions and guarded integration 75 tests / 449 assertions.

- 2026-09-01: Closed the Authority payload hydration boundary: WPDB
  repository reads now omit malformed or non-array payload rows from canonical
  lookup and type collections instead of leaking a `TypeError` into API or
  Graph callers. Unit PHPUnit passed 140 tests/846 assertions and guarded
  integration passed 74 tests/447 assertions.

- 2026-09-01: Closed the semantic repository hydration boundary: Media
  provenance and Video metadata JSON are now parsed fail-closed, and corrupt
  rows are omitted from single and collection reads rather than leaking
  exceptions or partial objects. Unit PHPUnit passed 140 tests/846 assertions
  and guarded integration passed 73 tests/445 assertions.

- 2026-09-01: Closed the Knowledge metadata hydration boundary: Source and
  Evidence WPDB repositories now omit malformed or non-array metadata rows from
  single and collection reads, preventing corrupt provenance blobs from
  escaping as `TypeError` or partial public data. Unit PHPUnit passed 140
  tests/846 assertions and guarded integration passed 72 tests/441 assertions.

- 2026-09-01: Closed the MediaAsset hydration integrity boundary: WPDB
  repository reads now fail closed for malformed JSON and non-array metadata,
  omitting corrupt rows from both single and list lookups instead of leaking a
  `TypeError` into the request path. Unit PHPUnit passed 140 tests/846
  assertions and guarded integration passed 71 tests/437 assertions.

- 2026-09-01: Closed the Migration008 schema privacy boundary: newly created
  `nhk_media_assets.visibility` columns and existing columns whose default was
  still `PUBLIC` now use `PRIVATE`; the UP migration is idempotent and does not
  rewrite existing visibility values. Unit PHPUnit passed 140 tests/846
  assertions and guarded integration passed 70 tests/435 assertions.

- 2026-09-01: Closed the MediaAsset privacy default boundary: domain
  construction and WPDB hydration now default missing visibility to `PRIVATE`,
  matching MediaService and V2 migration behavior; public fixtures must opt in
  with explicit `PUBLIC`. Unit PHPUnit passed 140 tests/846 assertions and
  guarded integration passed 69 tests/434 assertions.

- 2026-09-01: Closed the optional Governance proposal target identity
  boundary: `Proposal` now rejects malformed RFC 4122 `targetUuid` values
  before persistence while preserving semantic subject IDs. Unit PHPUnit
  passed 139 tests/845 assertions and guarded integration passed 69 tests/434
  assertions; Composer PHP lint passed.

- 2026-09-01: Revalidated local runtime readiness without writes: `php
  tools/mcp-wire-smoke.php` passed CORS preflight, `initialize`, 18-tool
  `tools/list`, invalid-Origin rejection and initialized notification; the
  frontend route smoke passed 34/34. External read-only Media/Source/Video
  probes returned Media total 242, draft Source records and zero Video rows
  with `VIDEO_STORAGE_READY`, all reporting zero writes. External adapter
  schema/mapping and active-data parity remain open.

- 2026-09-01: Hardened `PredicateDefinition` endpoint contracts: source and
  target type lists must be non-empty typed lists with valid endpoint names;
  predicate key and cardinality validation remain fail-closed. Unit PHPUnit
  passed 139 tests/845 assertions and guarded integration passed 69 tests/434
  assertions.

- 2026-09-01: Hardened Governance dependency reads: invalid dependency UUID
  rows are omitted before closure/cycle evaluation, preventing corrupt
  persistence from poisoning eligibility or Controlled Apply. Current
  verification is Unit 141 tests / 852 assertions and guarded integration 80
  tests / 458 assertions.

- 2026-09-01: Hardened Graph edge hydration: malformed edge/node/predicate/
  state/revision data is omitted from single and paginated reads instead of
  leaking domain exceptions into Post Graph or relation APIs. Current
  verification is Unit 141 tests / 852 assertions and guarded integration 81
  tests / 460 assertions.

- 2026-09-01: Hardened MediaAsset hydration: malformed asset/parent UUIDs,
  dimensions, MIME/storage fields, visibility or checksum data now omit the
  row from single/list reads instead of leaking `InvalidMedia` or UUID errors
  into public delivery/query paths. Current verification is Unit 141 tests /
  852 assertions and guarded integration 82 tests / 462 assertions.

- 2026-09-01: Hardened Media identity hydration: malformed canonical UUID,
  stable key/name, readiness or revision rows are omitted from single/list
  reads instead of leaking `InvalidMedia` into public Media query paths.
  Current verification is Unit 141 tests / 852 assertions and guarded
  integration 83 tests / 464 assertions.

- 2026-09-01: Hardened Video identity hydration: malformed canonical UUID,
  external-reference fields, thumbnail UUID or revision rows are omitted from
  single/list reads instead of leaking `InvalidVideoReference` into public
  Video/query paths. Current verification is Unit 141 tests / 852 assertions
  and guarded integration 84 tests / 466 assertions.

- 2026-09-01: Extended the Governance proposal hydration boundary: rows with
  invalid durable domain fields (such as non-positive revision) are now omitted
  from repository reads instead of leaking domain-construction exceptions into
  Admin or lifecycle services. Current verification is Unit 140 tests / 846
  assertions and guarded integration 77 tests / 452 assertions.

- 2026-09-01: Hardened the Graph predicate domain contract: source/target
  endpoint lists must be non-empty typed lists with valid endpoint identifiers;
  predicate key and cardinality validation remain fail-closed. Unit PHPUnit
  passed 138 tests/844 assertions and guarded integration passed 69 tests/434
  assertions.

- 2026-09-01: Hardened NHK Admin UUID lookup inputs: entity and proposal
  forms now canonicalize UUIDs through the shared codec and fail closed on
  malformed values before repository access. Current verification is Unit 140
  tests / 849 assertions and guarded integration 77 tests / 452 assertions.

- 2026-09-01: Closed the Graph relation identity boundary: `GraphEdge` now
  validates UUID, predicate and positive revision, while the WPDB hydrator
  normalizes MariaDB `HEX(edge_uuid)` output back to RFC 4122 before domain
  construction. Unit PHPUnit passed 137 tests/843 assertions and guarded
  integration passed 69 tests/434 assertions.

- 2026-09-01: Closed the Governance apply-attempt domain boundary: attempt and
  proposal UUIDs, optional result UUID, positive attempt number and persisted
  state values are now validated before durable writes. Unit PHPUnit passed
  136 tests/842 assertions and guarded integration passed 69 tests/434
  assertions.

- 2026-09-01: Migration identity validation now uses the shared UUID codec
  plus RFC 4122 version/variant checks for Authority, Media, Knowledge,
  Source, Evidence, Video, MediaAsset and URL targets. Invalid UUID-shaped
  records are ledgered as `INVALID_IDENTITY` rather than `MIGRATION_FAILED`.
  Current verification is Unit 140 tests / 849 assertions and guarded
  integration 78 tests / 456 assertions.

- 2026-09-01: Dry-run relation and URL target validation now uses the same
  shared UUID/RFC 4122 boundary as apply, rejecting nil or malformed UUIDs
  before they can appear as mapped candidates. Current verification is Unit
  141 tests / 852 assertions and guarded integration 78 tests / 456 assertions.

- 2026-09-01: Closed the Authority domain identity boundary: `AuthorityEntity`
  now validates canonical UUID format before any repository or Graph operation.
  Malformed identity construction is fail-closed with a typed endpoint error;
  Unit PHPUnit passed 135 tests/841 assertions and guarded integration passed
  69 tests/434 assertions.

- 2026-09-01: Hardened `WpdbProposalRepository::create()` with idempotency-key
  preflight and a shared content comparator. Identical retries now return the
  existing proposal without a duplicate SQL warning; changed command payload,
  fingerprints, target or expected revision remain a conflict, while the
  unique-index race fallback stays fail-closed. Guarded PHPUnit passed 69
  tests/434 assertions, with no database state retained.

- 2026-09-01: Hardened `WpdbEvidenceRepository::create()` with canonical UUID
  preflight, strict duplicate comparison and a race-safe insert fallback.
  Identical claim/source/relation/excerpt/locator/metadata/state/revision
  packets are idempotent; changed evidence metadata now fails closed before
  persistence. Guarded PHPUnit passed 68 tests/432 assertions, with no
  database state retained.

- 2026-09-01: Hardened `WpdbAuthorityRepository::create()` with canonical UUID
  and stable-key preflight plus complete identity/state comparison. Identical
  packets remain idempotent across UUID races; changed schema, payload, state,
  revision or retirement state now fails closed before SQL. Guarded PHPUnit
  passed 67 tests/430 assertions, with no database state retained.

- 2026-09-01: Hardened `WpdbVideoRepository::create()` with canonical UUID and
  external-reference preflight. Identical external-reference packets are now
  race-idempotent across UUIDs, while changed title, metadata, URL, thumbnail,
  state or revision fails closed before persistence; the insert-failure path
  remains race-safe. Guarded PHPUnit passed 66 tests/429 assertions, with no
  database state retained.

- 2026-09-01: Preserved stable-key race idempotency while hardening
  repositories: Media, Source and Knowledge now preflight both canonical UUID
  and stable key. Same semantic packet from a concurrent UUID returns the
  winner without SQL warnings; changed packets remain conflicts. Guarded
  PHPUnit passed 199 tests/1,267 assertions.

- 2026-09-01: Hardened `WpdbKnowledgeRepository::create()` with canonical
  UUID preflight and complete duplicate comparison across stable key, claim
  text/type, provenance, active state and revision. Changed provenance now
  fails closed before SQL; guarded PHPUnit passed 199 tests/1,266 assertions.

- 2026-09-01: Hardened `WpdbSourceRepository::create()` with canonical UUID
  preflight and complete duplicate comparison across identity, title, source
  type, locator, metadata, active state and revision. Changed Source packets
  now fail closed before SQL instead of being silently treated as idempotent;
  guarded PHPUnit passed 198 tests/1,265 assertions.

- 2026-09-01: Hardened `WpdbMediaRepository::create()` with canonical UUID
  preflight and full identity/state comparison. A duplicate is idempotent only
  when stable key, name, readiness, provenance, active state and revision all
  match; changed state is rejected before SQL. Guarded PHPUnit passed 197
  tests/1,264 assertions and no database state was retained.

- 2026-09-01: Hardened `WpdbMediaUsageRepository::create()` with identity
  preflight and race-safe sort-order comparison. Identical endpoint/role/
  sort-order packets are idempotent; a changed sort order is rejected without
  emitting a duplicate SQL warning. Guarded PHPUnit passed 196 tests/1,263
  assertions and no database state was retained.

- 2026-09-01: Standardized direct Media usage creation: identical
  `(media, endpoint, role, sort_order)` packets are idempotent, while reuse of
  the same endpoint/role with a different sort order fails closed before
  persistence. Unit coverage proves both paths; guarded PHPUnit passed 195
  tests/1,262 assertions with no database state retained.

- 2026-09-01: Hardened `WpdbMediaAssetRepository::create()` with strict
  duplicate comparison across parent, kind, storage, checksum, MIME, size,
  dimensions, visibility and metadata. UUID preflight now avoids emitting a
  duplicate SQL warning, while the insert-failure path remains race-safe;
  same-identity changed-content packets fail closed. Guarded PHPUnit passed
  195 tests/1,259 assertions and no database state was retained.

- 2026-09-01: Hardened the resumable V2 MediaAsset migration boundary: a
  missing MIME type is now classified as `skipped / INVALID_IDENTITY` with a
  durable ledger reason instead of surfacing as an unbounded `MIGRATION_FAILED`
  conflict. The guarded integration test proves processed/migrated/skipped/
  conflict counts and the ledger reason; full PHPUnit passed 194 tests/1,258
  assertions, with no production or V2 state changed.

- 2026-09-01: Standardized direct Media asset duplicate semantics at the
  application boundary: an identical `storage_key` and content packet is
  idempotent, while a changed checksum or asset metadata fails closed before
  persistence. TDD coverage verifies both paths; the full guarded suite passed
  193 tests/1,253 assertions, with Composer lint, diff-check and secret review
  passing and no database state changed.

- 2026-09-01: Closed a Media publication-safety gap: direct `MediaService::addAsset()`
  now defaults to `PRIVATE`, matching governed Media ingest and the fail-closed
  publication policy; explicit visibility and metadata remain available to a
  caller with publication authority. TDD first reproduced the unsafe `PUBLIC`
  default, then the fix passed the full guarded suite at 193 tests/1,250
  assertions, Composer lint, diff-check and secret review; no database state
  changed.

- 2026-09-01: NHK Admin semantic lookup now exposes Evidence alongside Media,
  Video, Knowledge Claim and Source, using the existing public Evidence REST
  route and the same nonce-protected read script. The contract test covers the
  new option and user-facing scope; full guarded PHPUnit passed 193 tests,
  1,249 assertions, with Composer lint and diff/secret review passing.

- 2026-09-01: Strengthened P5 type-specific payload validation. Canonical
  model/variant/specimen relation fields now fail closed unless they contain a
  UUID, and Product URLs accept only valid HTTP(S) references; format rules
  are declared in the type schema and checked by the generic Authority
  service/registry. TDD coverage proves malformed UUID/URL rejection and valid
  HTTPS acceptance. Guarded PHPUnit passed 193 tests/1,248 assertions and
  Composer lint passed; no existing data changed.

- 2026-09-01: Closed the missing Authority route-smoke coverage for the
  registered Variant and Classification types. The contract test now requires
  both archive/page-two routes and the smoke includes real active stable-key
  details for Variant and Classification; core routes passed 34/34 and the
  opt-in real-detail sweep passed 41/41. Guarded PHPUnit passed 190 tests,
  1,245 assertions; Composer lint and the smoke-script PHP lint passed.

- 2026-09-01: Browser QA inspected real active Variant and Classification
  detail routes at desktop and 390px/844px. Both rendered Vietnamese H1/title,
  had one static footer, no horizontal overflow, broken images, dead links,
  internal domain terminology or console errors; the apparent full-page blank
  area was confirmed as normal page height/padding rather than a duplicate
  footer. No data or runtime code changed.

- 2026-09-01: Re-ran the read-only local HTTP route smoke from the current
  checkpoint. All 34 core routes passed, including Variant and Classification
  archive/page-two routes; the two real active detail routes also returned
  HTTP 200. No database or production state changed.

- 2026-09-01: Extended the read-only external MCP probe across Media and
  Source pages 1/2 plus invalid pagination bounds. Media returned stable
  `total=242`; Source page 2 returned one record without a `total` field;
  Video remained `total=0`; invalid page/limit values were rejected by schema
  validation and successful calls reported `writes=0`. This strengthens the
  recorded pagination/error/schema mismatch evidence without any data change.

- 2026-09-01: Added read-only `DomainTargetCandidateAudit` and CLI
  `tools/v2-domain-target-audit.php`. TDD coverage proves same-domain exact
  title/slug matches remain review candidates, cross-domain matches are
  excluded, ambiguous candidates are surfaced, and no item is marked mapped.
  The restored export reports 742/742 unique same-domain candidates with zero
  ambiguous cases. Guarded PHPUnit passed 190 tests/1,241 assertions and
  Composer lint passed; the unguarded integration attempt correctly failed
  closed without the required environment variables, while no V2/V3 data
  changed.

- 2026-09-01: Read-only V2 endpoint recovery audit found 18/21 exact legacy
  upload paths returning HTTP 200 with allowlisted image MIME/size and three
  `wp1-thumbnail-*` paths returning 404. Temporary downloads were hashed for
  evidence and removed; no bytes, identities, mappings or publication state
  were written to V3. The candidates and SHA-256 values are recorded in
  `V2_MEDIA_SOURCE_RECOVERY_AUDIT_2026-09-01.md`; governed MediaAsset mapping,
  usage resolution, backup/restore and privacy approval remain required.

- 2026-09-01: The new read-only `tools/v2-domain-target-audit.php` compared
  each of the 742 domain-targeted posts only with same-domain canonical
  records. Against the restored export it found one candidate for all 742,
  with no none/ambiguous cases; every item remains explicit-mapping review
  because the export lacks a legacy-post identity link and governed approval.
  No URL, body or semantic identity was changed.

- 2026-09-01: External MCP canonical-ID cross-check resolved three Media IDs
  in both the external adapter and local V3 database. Local public REST
  returned the expected fail-closed 404 for each because the parent Media is
  draft or its processed asset is not deliverable; this is a policy gate, not
  an identity mismatch. The external adapter still exposes richer PRIVATE
  payloads, so wire-level mapping/deployment parity remains open.

- 2026-09-01: Read-only V2 REST metadata cross-check covered all 18 available
  attachment IDs and matched API MIME/filesize to the observed bytes; it
  exposed no deterministic usage mapping for 15 candidates (`post=null`),
  while the export already carries explicit Media/asset provenance for
  attachments 818, 849 and 852. Several `source_url=false` API fields
  reinforce that exact path/bytes evidence must be retained separately. No
  V2/V3 state changed.

- 2026-09-01: A fresh HTTP route sweep passed 35/35 using active local-dev
  stable keys for Brand `nhk:brand:junghans`, Model `nhk:model:ffr.69`,
  Movement `nhk:movement:o-do.36`, Music `nhk:music:ave-maria-lourdes` and
  Component `nhk:component:odo.hand.54`. This strengthens canonical Authority
  detail evidence without changing database state; Specimen/Product/Video
  detail remain data-gated where no active local row exists.

- 2026-09-01: Synchronized `V3_MASTER_PLAN.md` with the current P5–P11
  evidence: public Entity active/type/payload boundaries, Media binary
  deliverability filtering, lifecycle-free public semantic serializers and
  the current 188-test/1,229-assertion verification baseline. No runtime or
  database state changed.

- 2026-09-01: Theme-facing Entity archive now explicitly requests retired
  records and applies its own `active()` filter before matching, counting and
  paginating. This keeps the public boundary fail-closed even if a repository
  implementation changes its default retired-record behavior. Guarded PHPUnit
  passed 188 tests/1,229 assertions. No V2 or production data changed.

- 2026-09-01: Public Authority Entity serializers across REST, MCP and the
  theme-facing query now omit lifecycle fields (`active`, `revision`) while
  retaining active/type/allowlisted-payload checks before serialization.
  Guarded PHPUnit passed 188 tests/1,228 assertions. No V2 or production data
  changed.

- 2026-09-01: Public Knowledge, Source and Evidence serializers across REST,
  MCP and the theme-facing Knowledge query now omit lifecycle fields
  (`active`, `revision`) while retaining active/public claim-source gates before
  serialization. Contract regression coverage passed; guarded PHPUnit passed
  187 tests/1,218 assertions. No V2 or production data changed.

- 2026-09-01: Public Media detail, REST and MCP reads now reuse the same
  fail-closed `PublicMediaAssetDelivery` boundary as the binary route. A
  PUBLIC asset is serialized only when its parent Media is active/ready, MIME
  is allowlisted, storage stays inside the configured root, and size/checksum
  match the file. Missing or corrupt files therefore cannot become broken
  public URLs. Guarded PHPUnit passed 186 tests/1,198 assertions; Composer
  lint, MCP wire smoke, route smoke 30/30 and diff checks passed. No V2 or
  production data changed.

- 2026-09-01: Public Media serializers across REST, MCP and the theme-facing
  query now omit lifecycle fields (`readiness`, `active`, `revision`), matching
  the reader-safe Video contract. Active/ready checks remain enforced before
  response generation. RED→GREEN contract coverage passed; guarded PHPUnit
  passed 185 tests/1,197 assertions. No V2 or production data changed.

- 2026-09-01: Public Media detail serializers now include the reader-safe
  `/media/asset/{uuid}/` URL for PUBLIC assets in both REST and MCP, matching
  the theme query while continuing to omit storage/checksum/visibility and
  provenance metadata. Guarded PHPUnit passed 184 tests/1,185 assertions;
  Composer lint, MCP wire smoke, route smoke 30/30 and diff checks passed. No
  V2 or production data changed.

- 2026-09-01: Entity archive search now matches only canonical name, stable key
  and registered public `allowedFields`; private/unregistered payload fields
  cannot alter public result membership, totals or pagination. The regression
  test reproduced the prior leak before the fix. Guarded PHPUnit passed 183
  tests/1,180 assertions; Composer lint, MCP wire smoke, route smoke 30/30 and
  diff checks passed. No V2 or production data changed.

- 2026-09-01: Public Entity archive REST reads now enforce `active()` before
  pagination and totals, matching the detail/theme/search public boundary and
  preventing retired Authority records from being emitted by list queries.
  Guarded PHPUnit passed 183 tests/1,178 assertions; Composer lint, MCP wire
  smoke, route smoke 30/30 and diff checks passed. No V2 or production data
  changed.

- 2026-09-01: The same validated YouTube external-reference boundary now
  covers homepage Video modules and Graph-derived related content, preventing
  invalid persisted references from becoming public links. Contract and unit
  coverage passed; guarded PHPUnit passed 182 tests/1,174 assertions; Composer
  lint, MCP wire smoke, route smoke 30/30 and diff checks passed. No V2 or
  production data changed.

- 2026-09-01: Public Video boundaries now require a validated YouTube external
  reference, not merely an active row. A shared domain predicate fail-closes
  unsupported platforms, malformed IDs and canonical URL/ID mismatches across
  Video detail, archive, REST, MCP and semantic search. Public REST/MCP Video
  serializers also omit thumbnail/media identity and lifecycle revision fields.
  Guarded PHPUnit passed 179 tests/1,166 assertions; Composer lint, MCP wire smoke, route smoke 30/30
  and diff checks passed. No V2 or production data changed.

- 2026-09-01: A second read-only V2 route pass recorded concrete reference
  outcomes: homepage and brand/model archive are populated, while V2
  `/model/` and `/tim-kiem/?s=odo` resolve to 404; Video, Media and Sharing
  archives expose honest empty states. The authenticated reference session's
  WordPress admin toolbar was excluded from public comparison. This evidence
  is recorded in `V2_REFERENCE_INVENTORY_2026-08-31.md`; no V2 or database state
  changed, and parity remains open.

- 2026-09-01: A route-wide computed-style audit found remaining browser-default
  blue on quick links, pagination and semantic anchor wrappers. The theme now
  gives every public anchor the NHK accent base color, preserving explicit
  header/footer/card overrides; cache-busting is synchronized at 1.1.8. Mobile
  audit across 13 routes found zero default-blue links, zero overflow and zero
  broken images; guarded PHPUnit passed 176 tests/1,146 assertions, route smoke
  30/30, MCP wire smoke and Composer lint passed. No database state changed.

- 2026-09-01: Semantic search result cards were also falling back to browser
  default blue. The shared `.semantic-card strong` rule now uses the NHK text
  token, with stylesheet cache-busting synchronized at 1.1.7. Mobile search
  verification inspected 24 semantic results and confirmed readable colors, no
  overflow and no broken images; guarded PHPUnit passed 176 tests/1,145
  assertions, route smoke 30/30 and MCP wire smoke passed. No database state
  changed.

- 2026-09-01: Editorial post-card titles were also falling back to browser
  default blue on category/search feeds. The shared `.card h3 a` rule now uses
  the NHK text token, with stylesheet cache-busting synchronized at 1.1.6.
  Mobile category screenshot verification confirmed readable card/footer links,
  no overflow and no broken images; guarded PHPUnit passed 176 tests/1,144
  assertions, route smoke 30/30 and MCP wire smoke passed. No database state
  changed.

- 2026-09-01: Responsive route screenshots found default browser-blue links on
  entity/media/knowledge cards and the dark footer. Theme link colors now use
  NHK text/light tokens with accent-secondary focus/hover states, and stylesheet
  cache-busting is synchronized at version 1.1.5. Mobile screenshot verification
  confirmed readable colors, no overflow and no broken images; guarded PHPUnit
  passed 176 tests/1,143 assertions, route smoke 30/30 and MCP wire smoke passed.
  No database state changed.

- 2026-09-01: A fresh mobile screenshot sweep covered Comparison, Model page 2,
  Component page 2, Media page 2, Video page 2, Knowledge page 2, Media alias,
  default category and 404. Every route had the expected Vietnamese H1/title,
  no horizontal overflow, broken images or empty anchors; active Video detail
  remains data-gated because `nhk_v3` has no active Video row.

- 2026-09-01: Media detail now exposes only a reader-safe `/media/asset/{uuid}/`
  URL for each serialized asset; image assets render lazily in the public theme,
  while the binary route remains fail-closed on active/ready Media, public
  visibility, MIME, storage-root, SHA-256 and byte-size checks. Contract tests
  and guarded PHPUnit passed 175 tests/1,140 assertions; MCP wire smoke, route
  smoke 30/30, Composer lint and diff checks passed. No database state changed.

- 2026-09-01: The public theme now emits `<html lang="vi">` while preserving
  WordPress's other language attributes. Browser verification confirmed the
  Vietnamese language contract and no mobile overflow; full guarded PHPUnit
  passed 167 tests/1,090 assertions and route smoke passed 30/30.

- 2026-09-01: The route smoke harness now accepts data-gated detail checks for
  all Authority types, Media, Video, Comparison, Post and Knowledge, so active-
  record QA can be added without creating public fixtures. The options are
  contract-tested and remain opt-in until real active records exist.

- 2026-09-01: NHK Admin now shows a read-only migration-ledger summary grouped
  by source, status and reason code, making the 764 explicit domain/media/system
  skips operationally visible without allowing direct domain-table writes or
  identity inference. The panel is contract-tested and fails closed when the
  ledger table is unavailable.

- 2026-09-01: Public REST now exposes `/knowledge/evidence/{uuid}` with the
  same active/public claim-source gate and reader-safe fields as MCP
  `nhk.evidence.get`; inactive, private, or orphaned evidence remains 404.
  Integration coverage verifies REST/MCP parity and omits persisted metadata.

- 2026-09-01: Integration coverage now also proves private Evidence detail is
  not publicly readable, including when its claim is unverified; the guarded
  suite remains fail-closed at the REST boundary.

- 2026-09-01: Knowledge evidence presentation now reads the locator through a
  null-safe fallback, accepting either the direct REST/MCP `locator` field or
  an adapter-provided `source_locator` without notices. Full guarded PHPUnit
  passed 170 tests/1,104 assertions and route smoke remained 30/30.

- 2026-09-01: Migration dry-run skips now carry structured review metadata:
  domain-targeted posts identify the intended canonical domain while requiring
  explicit mapping and forbidding name-only matches; attachments require source
  recovery; global styles are retirement-only. Full guarded PHPUnit passed 172
  tests/1,112 assertions; no V2/V3 data was mutated.

- 2026-09-01: Actual V2 apply now persists the same structured review metadata
  into migration-ledger `details_json` for domain-targeted posts, unsupported
  media references and retirement-only global styles. Integration coverage
  passed 173 tests/1,119 assertions; no V2/V3 data was mutated.

- 2026-09-01: NHK Admin migration-ledger summary now reads persisted review
  metadata and groups records by safe action: explicit mapping, source
  recovery, retirement-only disposition or not classified. The panel remains
  read-only. Full guarded PHPUnit passed 173 tests/1,122 assertions.

- 2026-09-01: Browser visual QA added 390px and 1440px Comparison screenshots,
  plus structural checks for nine remaining archive/detail/alias routes at
  390px, 768px and 1440px. All 27 route-size checks had H1, no overflow,
  broken images or empty/`#` anchors; active Video detail remains unavailable
  because the local dataset has no active Video row.

- 2026-09-01: Admin migration-ledger review action now falls back to the
  reason code when older ledger rows lack structured review details, so the
  existing skip inventory remains actionable without a database backfill.
  Full guarded PHPUnit passed 173 tests/1,125 assertions.

- 2026-09-01: Live localhost MCP verification passed CORS preflight with all
  protocol assertion headers allowlisted; standard `initialize` and
  header-only `tools/list` returned HTTP 200 JSON-RPC responses with the 18
  registered tools. External adapter mapping/deployment remains a separate
  open gate.

- 2026-09-01: The no-write dry-run now emits `review_by_action` aggregates in
  addition to per-item review metadata, covering explicit mapping, source
  recovery and retirement-only dispositions. Full guarded PHPUnit passed 173
  tests/1,127 assertions; no V2/V3 data was mutated.

- 2026-09-01: Actual migration apply now returns `review_by_action` aggregates
  alongside the resumable ledger result, including already-ledgered rows;
  focused RED→GREEN integration coverage confirms the contract. Full guarded
  PHPUnit passed 174 tests/1,136 assertions.

- 2026-09-01: Added the repeatable no-write `tools/mcp-wire-smoke.php` for
  CORS preflight, standard MCP `initialize`, `tools/list` catalog and
  `notifications/initialized`; it passes against localhost without PHP 8.5
  deprecation warnings. Full guarded PHPUnit passed 174 tests/1,133
  assertions.

- 2026-09-01: MCP wire smoke now also verifies an invalid Origin is rejected
  with HTTP 403; the complete no-write smoke passes all nine checks. Full
  guarded PHPUnit passed 174 tests/1,135 assertions.

- 2026-09-01: Route smoke now asserts title/canonical metadata for the two
  editorial archives, the default category archive and the 404 route, including
  the 404 `noindex, follow` contract. The enhanced smoke passes 30/30; no
  runtime or data state changed.

- 2026-09-01: 404 pages now emit the reader-facing title `Không tìm thấy
  trang — Đồng Hồ Nhà Kho`, a bounded description, canonical homepage URL and
  `noindex, follow`; browser verification passed at 390px with no technical
  copy or overflow. Full guarded PHPUnit passed 167 tests/1,086 assertions and
  route smoke passed 30/30.

- 2026-09-01: Editorial archive routes `/tri-thuc/` and `/goc-chia-se/` now
  emit visitor-facing document/OpenGraph titles, descriptions and canonical
  URLs instead of the default `NHK v3` title. Browser verification passed at
  390px with no overflow; full guarded PHPUnit passed 167 tests/1,083
  assertions and route smoke passed 30/30.

- 2026-09-01: Public editorial dates now render in Vietnamese (`20 tháng 8,
  2026`) across homepage, cards, sidebar and single-post metadata while
  retaining ISO `datetime` values for machines and SEO. Browser verification
  found no English month names on homepage, search, post or category archive;
  full guarded PHPUnit passed 167 tests/1,080 assertions and route smoke passed
  30/30.

- 2026-09-01: Category archives now use reader-facing titles and metadata:
  `Uncategorized` renders as `Chủ đề: Chưa phân loại`, the document/OpenGraph
  title and description are localized, and canonical points to the queried
  category URL instead of WordPress's stale post canonical. Browser verification
  passed; full guarded PHPUnit passed 167 tests/1,074 assertions and route smoke
  passed 30/30.

- 2026-09-01: Public category presentation now translates the default
  `Uncategorized` label to `Chưa phân loại` across homepage cards, post cards,
  topic links and single-post breadcrumbs while preserving validated category
  links. Browser verification found no `Uncategorized` residue on homepage or
  `?s=odo`; full guarded PHPUnit passed 167 tests/1,069 assertions and route
  smoke passed 29/29.

- 2026-09-01: MCP registration now extends WordPress's REST CORS allowlist with
  `MCP-Protocol-Version`, `Mcp-Method` and `Mcp-Name`, so browser-based
  Streamable HTTP clients can complete preflight before sending the already
  validated JSON-RPC request. Guarded MCP integration coverage and the full
  suite passed 167 tests/1,065 assertions; the local HTTP daemon was not
  running for a live preflight curl in this checkpoint.

- 2026-09-01: MCP Streamable HTTP now accepts standard modern clients: the
  `initialize` protocol version may be declared in `params.protocolVersion`,
  subsequent requests may rely on `MCP-Protocol-Version` alone, and custom
  `Mcp-Method`/`Mcp-Name` headers are optional compatibility assertions. Real
  local JSON-RPC probes for `initialize` and `tools/list` returned 200; explicit
  version/header mismatches remain rejected. Guarded PHPUnit passed 164 tests/
  1,058 assertions and route smoke passed 29/29.

- 2026-09-01: Standard MCP coverage now includes header-only `tools/call`
  without custom method/name headers and `notifications/initialized` returning
  HTTP 202 with no body. Full guarded PHPUnit passed 166 tests/1,062 assertions.

- 2026-09-01: Search metadata now overrides the technical WordPress blog
  description with a visitor-facing result summary in both standard and
  OpenGraph descriptions. Browser verification confirmed Vietnamese title,
  description and canonical `/`; guarded PHPUnit passed 162 tests/1,054
  assertions and route smoke passed 29/29.

- 2026-09-01: Search document titles now use the visitor-facing Vietnamese
  format `Tìm kiếm: {term} — Đồng Hồ Nhà Kho`, replacing WordPress's default
  `Search Results for ...` title. Browser verification confirmed the search H1,
  title and canonical remain correct; guarded PHPUnit passed 162 tests/1,053
  assertions and route smoke passed 29/29.

- 2026-09-01: Public archive/comparison shell copy now uses visitor-facing
  Vietnamese labels (`Kho bài viết`, `Khám phá NHK`) instead of leftover
  English implementation labels. Contract coverage and browser checks found no
  old labels or overflow; guarded PHPUnit passed 162 tests/1,052 assertions and
  route smoke passed 29/29.

- 2026-09-01: Editorial featured and single-post thumbnails now provide the
  post title as an accessible alt fallback when attachment metadata is empty;
  decorative article-card thumbnails retain empty alt because their adjacent
  title link is the accessible content label. Contract coverage, full guarded
  PHPUnit (162 tests/1,050 assertions), route smoke 29/29 and PHP lint passed.

- 2026-09-01: Homepage section and topic links now use the shared public URL
  validator; sections without a valid destination or posts and topics with a
  failed category link are hidden rather than rendered as empty discovery
  modules. Homepage runtime remained 200 with the expected H1, two visible
  content modules, no fatal error, overflow or empty anchors. Guarded PHPUnit
  passed 161 tests/1,047 assertions and route smoke passed 29/29.

- 2026-09-01: Fresh desktop runtime QA covered 15 public routes across
  homepage, editorial archives, all populated Authority archives, empty
  Specimen/Product/Media/Video/Comparison states, Knowledge and 404. Every
  route rendered an H1 without fatal-error text, horizontal overflow, broken
  images or empty/`#` anchors. This is additional desktop evidence only; no
  new mobile coverage is claimed.

- 2026-09-01: Public data-derived URLs now pass through the shared
  `nhk_v3_public_url` HTTP(S) validator before rendering. Entity/Post related
  cards, homepage semantic modules, Video source links and Knowledge evidence
  locators hide missing or malformed URLs instead of emitting sanitized empty
  anchors. Guarded PHPUnit passed 161 tests/1,045 assertions, route smoke
  passed 29/29, browser checks found no fatal errors or empty/`#` anchors, and
  lint/diff checks passed.

- 2026-09-01: Public link rendering now fails closed at the theme boundary:
  related cards filter missing URLs, semantic search skips unknown groups rather
  than emitting `#`, and Video source links are hidden when the canonical source
  URL is unavailable. Browser checks across nine public routes found no empty or
  `#` links; guarded PHPUnit passed 161 tests/1,043 assertions, route smoke
  passed 29/29 and lint/diff checks passed.

- 2026-09-01: Public relation, search, comparison and Knowledge type labels
  now pass through `nhk_v3_public_type`, mapping technical enum values such as
  `wp_post` and `brand` to visitor-facing Vietnamese labels while preserving
  canonical URL values internally. Browser checks found no raw enum labels and
  no overflow. Unit 109/656, guarded integration 50/380, combined PHPUnit
  160/1,036, lint and route smoke 29/29 passed.

- 2026-09-01: Public entity, Media, Knowledge, Video and Comparison
  templates no longer render operational UUID/stable-key/revision fields or
  internal identifier labels; stable keys remain only in canonical URL
  construction. Comparison payload labels/values now use the reader-facing
  public serializers. Browser verification across five routes found no
  operational labels/internal terms and no overflow. Unit 109/648, guarded
  integration 50/380, combined PHPUnit 160/1,028 and lint passed.

- 2026-09-01: Semantic search now fails closed for blank/whitespace terms;
  this prevents empty `stripos` matches from enumerating every semantic record.
  Unit regression and browser verification of `/?s=` show zero semantic cards;
  route smoke remains 29/29. Unit 109/623, guarded integration 50/380 and
  combined PHPUnit 159/1,003 passed.

- 2026-09-01: Theme CSS now has one warm NHK design-token source and no
  legacy `--ink`/`--line`/`--paper`/`--max` declarations or rules. Contract
  coverage verifies all 11 required tokens; cache-busting browser verification
  confirms the tokens are active, legacy tokens are absent and the homepage
  remains overflow-free. Theme asset version is synchronized to 1.1.4. Unit
  109/621, guarded integration 50/380, combined PHPUnit 159/1,001, lint and
  route smoke 29/29 passed.

- 2026-09-01: Archive SEO descriptions now remain route-specific after the
  homepage description override was moved before custom context resolution.
  Browser verification covered `/`, Authority, Knowledge, Media, Video and
  Comparison routes: titles, descriptions and canonicals are correct, with no
  technical description leakage. Unit 108/604, guarded integration 50/380,
  combined PHPUnit 158/984, lint and route smoke 29/29 passed.

- 2026-09-01: Homepage SEO metadata now overrides the technical WordPress
  description as well as the document title: visitor-facing description and
  OpenGraph description are emitted alongside the branded title and canonical
  `/`. Browser/route checks passed; full PHPUnit passed 158 tests/981
  assertions.

- 2026-09-01: Browser runtime exposed the repository-oriented default homepage
  title (`NHK v3 — ...`); the theme now emits the visitor-facing
  `Đồng Hồ Nhà Kho — Kho tri thức và sưu tầm` title and matching OpenGraph
  title while retaining canonical `/`. Contract, browser and route smoke
  checks passed; full PHPUnit passed 158 tests/980 assertions.

- 2026-09-01: Browser runtime sweep inspected 14 public routes at the active
  desktop viewport, including homepage, editorial archives, all currently
  exposed Authority archives, Comparison, Knowledge, Media, Video and 404.
  Each route produced the expected H1/title, had no horizontal overflow, and
  exposed no internal Authority/Proposal/MediaAsset terminology; Video remains
  an honest empty state because no active Video is available.

- 2026-09-01: Source migration now resolves normalized `source_type` from
  top-level or metadata fields after legacy-type fallback, preserving the
  canonical V3 vocabulary across exporter shapes. Full guarded PHPUnit passed
  158 tests/979 assertions and plugin lint passed; no V2/live data changed.

- 2026-09-01: Migration state resolution now checks V2 `review_state` in both
  normalized top-level fields and the metadata envelope, while preserving the
  value and keeping archived/retired Source/Evidence inactive. Full guarded
  PHPUnit passed 158 tests/979 assertions; no V2/live data changed.

- 2026-09-01: The earlier local-filesystem MediaAsset audit confirmed that the
  V2 storage root recorded by all three imported assets is absent on the current
  host and that no exact legacy filename exists in the V3 upload root or known
  local artifact root. A later read-only V2 endpoint audit found 18/21 paths
  available, but this still provides no governed identity/usage mapping; public
  delivery remains fail-closed and no asset was rewritten or published.

- 2026-09-01: Source/Evidence migration now retains top-level V2
  `review_state` inside the durable metadata envelope as well as using it to
  fail closed archived/retired rows. Guarded coverage verifies both inactive
  state and metadata preservation; full PHPUnit passed 158 tests/979
  assertions, with no V2/live data changed.

- 2026-09-01: V2 Source and Evidence migration now fail closed for legacy
  `review_state=ARCHIVED` or `RETIRED`, even if a source row says PUBLIC;
  archived provenance cannot become an active public endpoint on replay.
  Guarded regression coverage passed 158 tests/977 assertions, with no V2/live
  data changed.

- 2026-09-01: V2 Source migration now preserves a canonical normalized
  `source_type` when `legacy_type` is absent, including the full V3 source-type
  vocabulary; legacy semantic type mapping remains available as a fallback.
  Guarded regression coverage passed 157 tests/974 assertions in the full
  suite, with no V2/live data changed.

- 2026-09-01: V2 Source migration now copies top-level visibility,
  verification-state and legacy-id fields into the durable metadata envelope,
  matching Evidence migration and preserving the PRIVATE/public policy during
  replay. Guarded migration coverage verifies the persisted Source metadata;
  full PHPUnit passed 156 tests/972 assertions and no V2/live data changed.

- 2026-09-01: Source and Evidence now default to PRIVATE when no explicit
  visibility is supplied, matching the cutover policy that provenance is not
  public by accident. Governed Evidence ingest now propagates its metadata
  through `KnowledgeService::cite()` into persistence, so explicit
  `visibility=PUBLIC` is honored and tested end-to-end. Full guarded PHPUnit
  passed 156 tests/971 assertions; no V2/live data changed.

- 2026-09-01: MCP now exposes reader-safe `nhk.source.get` and
  `nhk.evidence.get` tools in addition to `nhk.knowledge.get`. Both require
  active/public source, evidence and claim endpoints and omit persisted
  metadata; governed ingest and mutation paths remain unchanged. Transport
  integration verifies both tools after governed ingest. Full guarded PHPUnit
  passed 155 tests/966 assertions and plugin lint/diff-check passed.


- 2026-09-01: The official `tools/frontend-route-smoke.php` was re-run against
  localhost after the semantic-search privacy checkpoint: all 29 declared
  public, alias, page-two, sitemap/RSS, redirect and fail-closed routes passed.
  No database or V2 data changed.

- 2026-09-01: Public semantic search in REST, theme and MCP now indexes only
  canonical entity fields registered in `allowedFields`; unregistered legacy or
  private payload values cannot affect public result membership or totals.
  Regression coverage passed for an entity whose match existed only in a raw
  private field. Full guarded PHPUnit passed 155 tests/954 assertions, and
  plugin lint/diff-check passed; no development/V2 data changed.

- 2026-09-01: Raw Graph REST reads are now administrator-only because the
  diagnostic response includes endpoint keys, edge state and revisions. Public
  Post/entity related content continues through `RelatedContentQuery`, which
  resolves active records to reader-facing titles and URLs. Focused contract
  coverage verifies that the registered raw route rejects an anonymous
  permission check; no development/V2 data changed. Guarded PHPUnit passed
  153 tests/950 assertions.

- 2026-09-01: MCP Authority entity reads now allowlist payload keys through
  the registered canonical entity definition, matching REST/theme public
  entity reads; legacy/internal fields cannot cross the read adapter.
  Focused contract coverage verifies the allowed field survives and a private
  field is removed.

- 2026-09-01: MCP Media reads now follow the public Media boundary: only
  active ready Media is returned, and public asset/usage serializers omit
  provenance, storage/checksum/visibility/metadata and Graph endpoint
  identifiers. Internal governance repositories remain available on their
  governed paths; focused contract coverage verifies the reader-safe shape.

- 2026-09-01: MCP Video reads now omit persisted metadata and expose only the
  validated external-reference display fields already used by the public
  REST/theme contract. Focused MCP coverage verifies metadata cannot cross the
  unauthenticated read boundary.

- 2026-09-01: MCP Knowledge reads now omit claim provenance and Evidence
  metadata, exposing only the same reader-safe claim/evidence fields as public
  REST/theme reads. Focused coverage verifies public evidence remains available
  while persisted metadata blobs are removed.

- 2026-09-01: Read-only external NHK abilities were probed with bounded
  Source/Media/Video list calls and recorded in
  MCP_EXTERNAL_INTEROPERABILITY_EVIDENCE_2026-09-01.md. The adapter was
  reachable with zero writes; it returned draft Source records, 242 mixed
  visibility Media records and zero Video records with storage ready. Its
  richer adapter schema is not yet wire-level V3 MCP parity, so external
  interoperability remains PARTIAL and no deployment claim was made.

- 2026-09-01: Migration URL targets for Knowledge now require an active
  public claim, matching the public Knowledge route's readiness/provenance
  boundary; non-public claims are recorded as MISSING_ENDPOINT instead of
  creating a redirect to a public 404. Guarded PHPUnit passed 150 tests/935
  assertions, and no development/V2 data changed.

- 2026-09-01: The migration dry-run URL validator now mirrors apply's
  structural path and entity-target checks, so malformed paths or incomplete
  typed UUID targets are reported as INVALID_URL_MAPPING before any apply
  attempt. This remains a no-write validation improvement; no development/V2
  data changed.

- 2026-09-01: Public MediaAsset delivery now requires the parent Media
  identity to exist, remain active and have `readiness=ready`, in addition to
  the existing PUBLIC visibility, MIME allowlist, storage-root containment,
  checksum and byte-size checks. Draft/retired parent Media therefore cannot
  expose a binary through the public asset route, while internal governance and
  MCP asset reads remain unchanged. Guarded PHPUnit passed 149 tests/930
  assertions, route smoke passed 29/29, and no development/V2 data changed.

- 2026-09-01: Public `SearchApi` Media and Video groups now require active
  records before matching or counting results, aligning REST search with the
  active-only theme/MCP contracts. Guarded runtime coverage creates retired
  Media/Video fixtures only in `nhk_v3_test`, verifies both totals are zero,
  and cleans them in `finally`. Guarded PHPUnit passed 141 tests/904
  assertions, route smoke remained 29/29, lint/diff-check and secret review
  passed, and no development/V2 data changed.

- 2026-09-01: Public Media REST detail responses now omit the persisted
  provenance blob, matching the reader-safe theme-facing Media serializer;
  internal MCP/application serializers remain unchanged. Guarded runtime
  coverage verifies the boundary after governed Media ingest while the asset
  remains PRIVATE. Guarded PHPUnit passed 142 tests/907 assertions, route
  smoke remained 29/29, lint/diff-check and secret review passed, and no
  development/V2 data changed.

- 2026-09-01: Public Video REST and theme detail responses now expose only
  validated external-reference display fields; persisted Video metadata stays
  available to internal MCP/application serializers. Focused REST, theme
  query and contract coverage passed, and no development/V2 data changed.

- 2026-09-01: Public Authority Entity REST and theme query boundaries now
  allowlist payload keys from the registered canonical type definition, so
  unregistered legacy/internal fields cannot leak through raw or migrated
  records. Runtime integration verified a public field survives while a
  private field is removed. Guarded PHPUnit passed 146 tests/915 assertions,
  route smoke remained 29/29, lint/diff-check and secret review passed, and
  no development/V2 data changed.

- 2026-09-01: Public Media discovery now requires both `active` state and
  `readiness=ready` across REST detail, theme archive/detail, native semantic
  search, homepage modules and Graph-derived related content. A read-only
  local audit found 238 active draft Media rows; these remain available to
  internal governance/MCP reads but are no longer public. Guarded PHPUnit
  passed 147 tests/920 assertions, route smoke remained 29/29,
  lint/diff-check and secret review passed, and no development/V2 data changed.

- 2026-09-01: MCP `nhk.search` now applies the same Media readiness gate as
  REST, theme, homepage and Graph-related discovery; active draft Media is
  omitted from MCP semantic totals while ready Media remains searchable.
  Focused regression coverage passed, and no development/V2 data changed.

- 2026-09-01: The public `MediaVideoPageQuery` detail boundary now matches
  the sanitized Media REST contract: provenance blobs, asset storage/checksum/
  visibility/metadata fields and Graph usage endpoint identifiers are omitted
  from theme-facing data; internal MCP serializers remain unchanged. The
  focused detail test verifies public MIME/dimension facts and the absence of
  internal fields. Guarded PHPUnit passed 139 tests/899 assertions, route
  smoke remained 29/29, lint/diff-check and secret review passed, and no
  database state changed.

- 2026-09-01: MCP semantic Knowledge search now applies the same public
  readiness gate as REST, theme archive/detail and other semantic search
  paths; explicit unverified/non-public claims no longer appear in paginated
  MCP groups or totals. The regression covers page-two slicing with six
  public and one unverified match. Guarded PHPUnit passed 139 tests/894
  assertions, route smoke remained 29/29, lint/diff-check and secret review
  passed, and no database state changed.

- 2026-09-01: Knowledge claim public readiness is now enforced across archive,
  detail, REST, semantic search and MCP public reads: explicit
  `UNVERIFIED`, `NEEDS_CONFIRMATION`, `PRIVATE` and `HIDDEN` provenance states
  are suppressed, while claims without an explicit status retain the V3
  default. Local read-only counts show 527 active repository claims but only
  66 with verified V2 metadata; browser archive runtime shows no unverified
  status leakage. Guarded PHPUnit passed 139 tests/892 assertions, route smoke
  remained 29/29, lint/diff-check and secret review passed, and no database
  state changed.

- 2026-08-31: Public Knowledge claim payloads now omit the persisted
  provenance blob in addition to Source/Evidence metadata blobs. Reader-facing
  claim text/type and approved source title/type/locator/excerpt remain
  available, while legacy status, source and verification internals stay in
  governed MCP/internal reads. Guarded runtime and contract coverage passed
  138 tests/887 assertions with no warnings; route smoke remained 29/29,
  lint/diff-check and secret review passed, and no database state changed.

- 2026-08-31: Guarded runtime integration now asserts that public Source
  responses and nested public Evidence responses omit persisted metadata blobs,
  while the authenticated MCP ingest/read lifecycle remains intact for
  internal governance. The full guarded suite passed 138 tests/884 assertions;
  no database state changed beyond disposable `nhk_v3_test` fixtures.

- 2026-08-31: Public Knowledge payloads now omit persisted Source/Evidence
  metadata blobs, which may contain legacy IDs, verification internals or
  visibility controls; reader-facing title/type/locator/excerpt fields remain
  available, while MCP/internal repositories retain the full metadata for
  governed review. Contract and query tests cover the boundary. Guarded
  PHPUnit passed 138 tests/882 assertions with no warnings, route smoke
  remained 29/29, lint/diff-check and secret review passed, and no database
  state changed. Public provenance approval remains a cutover gate.

- 2026-08-31: The public REST Media serializer now omits internal usage
  endpoint type/key values while retaining only the usage identity, reader-
  relevant role and ordering. A focused contract prevents Graph endpoint
  identifiers from crossing the public boundary; MCP/internal application
  serializers remain unchanged. Guarded PHPUnit passed 137 tests/879
  assertions, composer lint and diff checks passed, and no database state
  changed. Source/Evidence public provenance policy remains intentionally
  open and was not bypassed.

- 2026-08-31: Source/Evidence public reads now fail closed when an active
  record carries explicit `visibility=PRIVATE` (or any non-PUBLIC value) in
  its persisted metadata. The same gate is applied to Knowledge evidence
  filtering, Source REST detail and MCP Knowledge reads; records without a
  visibility field retain the existing V3-compatible public default. Unit
  and guarded integration coverage passed 137 tests/879 assertions, route
  smoke remained 29/29, and no database state changed. This safety gate does
  not approve the outstanding public provenance policy.

- 2026-08-31: Browser QA extended the mobile route evidence to nine remaining
  archive/detail/alias surfaces (`brand`, `model`, `music`, `component`,
  `specimen`, `product`, `/hien-vat/`, `/am-nhac/` and Góc chia sẻ page 2).
  At 390px each had document width equal to the viewport, a bounded main
  column and no detected internal public terminology; populated Brand/Model
  details also received visual inspection. A read-only local database check
  found no active Video row, so active Video detail QA remains an evidence
  blocker and no fixture was created. No database state changed.

- 2026-08-31: The public REST Media serializer now returns only reader-safe
  asset fields (id, kind, MIME, dimensions and size), omitting storage keys,
  checksums, visibility state and internal metadata. The focused contract
  covers the asset boundary; guarded PHPUnit passed 136 tests/877 assertions,
  route smoke passed 29/29 and composer lint/diff checks passed. No database
  state changed.

- 2026-08-31: Media detail public rendering no longer exposes internal
  `storage_key` or operational labels; it now presents reader-facing resource,
  profile-code, display-status and usage labels while retaining fail-closed
  asset delivery. Runtime media detail verification confirmed the sensitive
  storage key is absent and the honest empty state remains visible. Guarded
  PHPUnit passed 135 tests/873 assertions, route smoke passed 29/29 and
  composer lint/diff checks passed. No database state changed.

- 2026-08-31: The reader-facing field-label contract was extended to
  `model_uuid`, `brand_uuid` and `serial_number`, preventing technical UUID
  keys from leaking into populated Model/Specimen pages. Guarded PHPUnit
  passed 135 tests/841 assertions, route smoke passed 29/29, and composer
  lint/diff checks passed. No database state changed.

- 2026-08-31: Public entity field labels now translate Product/Specimen
  payload keys such as `specimen_uuid`, `vendor`, `price`, `url` and
  `availability` into reader-facing Vietnamese labels without changing
  payload identity or values. The frontend contract covers the mapping;
  guarded PHPUnit passed 135 tests/838 assertions, route smoke passed 29/29,
  and theme lint/diff checks passed. No database state changed.

- 2026-08-31: A post-fix browser sweep covered 13 known public routes at
  390px and 768px (26 route/viewport checks), including homepage, editorial
  archives, Authority page-two routes, Knowledge/Media/Video page-two,
  Comparison, Product/Specimen empty states and 404. Every document/body/main
  width matched its viewport; paginated archives retained `noindex,follow`
  and 404 retained `index,follow`. No new responsive defect was found.

- 2026-08-31: Guarded PHPUnit was rerun with the required external local
  network access using `NHK_WP_TEST_DB=nhk_v3_test` and
  `NHK_WP_TEST_PATH=public`: 135 tests and 833 assertions passed. The earlier
  connection failure was sandbox TCP isolation rather than a MySQL/data
  regression; no V2 or development database was reset.

- 2026-08-31: Responsive browser QA found horizontal overflow from the
  unwrapped `.entity-pagination` rule in the later-loaded `entity.css`:
  Knowledge page 2 reached 1,057px and Media page 2 reached 447px at 390px.
  The owning stylesheet now wraps and bounds pagination, and its enqueue
  version was bumped to `1.0.2` for cache invalidation. Browser recheck at
  390px and 768px reports document widths equal to the viewport for both
  routes; mobile screenshots were visually inspected. Focused frontend
  contract is green at 15 tests/233 assertions. No data changed.

- 2026-08-31: The route smoke harness gained explicit data-gated
  `--brand-alias=/legacy/|/canonical/` and
  `--model-alias=/legacy/|/canonical/` redirect checks. Against the local
  WordPress runtime, `/odo/` → `/brand/nhk:brand:o-do/` and
  `/odo/odo-39/` → `/model/nhk:model:o-do.39/` both returned HTTP 301; the
  existing 29 default routes remained green. No fixture or database state
  changed.

- 2026-08-31: The focused frontend contract passed at 14 tests/230
  assertions, PHP lint passed and the expanded runtime smoke passed 31/31.
  A guarded full-suite rerun was attempted after a local MySQL service
  restart, but MySQL exited again before WordPress could connect over
  `127.0.0.1:3306`; the prior accepted 134-test/827-assertion result remains
  the latest complete suite evidence, and this infrastructure gap is kept
  open for the next checkpoint.

- 2026-08-31: Cutover readiness and master-plan evidence were synchronized
  with the current guarded suite (89 unit tests/461 assertions; 133 combined
  tests/808 assertions) and the policy-normalized migration checkpoint
  (3,961 mapped, 1,012 skipped, 0 conflicts, 27 residual URL candidates).
  No implementation or database state changed in this documentation-only
  checkpoint; the repository remains pre-cutover.

- 2026-08-31: Media, Video and Knowledge archive templates now render bounded
  page links from their query-service totals, covering the semantic archive
  pagination contract without introducing a second data source. The focused
  frontend contract is green at 13 tests/211 assertions; full suite evidence
  is 133 tests/808 assertions. Local route smoke was retried after an Apache
  graceful restart but localhost:80 still had no listener, so prior 21/21
  runtime evidence remains the latest successful route checkpoint.

- 2026-08-31: Guarded PHPUnit was rerun with `NHK_WP_TEST_DB=nhk_v3_test` and
  `NHK_WP_TEST_PATH=public`: 133 tests and 808 assertions passed. The same
  checkpoint's route smoke was rerun outside the sandbox against Apache and
  passed all 21/21 declared routes, including the semantic archive page-two
  routes.

- 2026-08-31: Pagination links for Search, Authority, Media, Video and
  Knowledge now expose `aria-current="page"` only on the active page. The
  focused accessibility contract is green at 14 tests/216 assertions; guarded
  PHPUnit is green at 134 tests/813 assertions, PHP lint is green, and the
  external-sandbox localhost route smoke is 21/21.

- 2026-08-31: Public Knowledge detail evidence now carries the approved
  source title/type and falls back to the source locator when an evidence
  locator is absent; inactive sources remain filtered out. Guarded PHPUnit
  is green at 134 tests/816 assertions and the source presentation contract
  is covered by the KnowledgePageQuery unit test.

- 2026-08-31: The route smoke harness now includes `/media/page/2/`,
  `/video/page/2/` and `/knowledge/page/2/`; the expanded external-sandbox
  runtime smoke passed 24/24 checks. The harness contract and guarded suite
  are green at 134 tests/819 assertions; no route changes or data mutations
  were introduced by this coverage extension.

- 2026-08-31: The route smoke harness now also includes `/tri-thuc/page/2/`
  and `/goc-chia-se/page/2/`, matching the V2 editorial archive pagination
  contract. External-sandbox runtime smoke passed 26/26 checks; guarded
  PHPUnit passed 134 tests/821 assertions and the smoke script passed PHP
  syntax validation. No data or production route state changed.

- 2026-08-31: SEO smoke now validates native `/wp-sitemap.xml` payloads
  contain `<sitemapindex` and `/feed/` payloads contain `<rss`, in addition
  to HTTP 200 status. External-sandbox runtime smoke passed 28/28 checks;
  guarded PHPUnit passed 134 tests/825 assertions and plugin/theme lint
  passed. No custom sitemap alias or production routing change was introduced.

- 2026-08-31: SEO smoke now verifies the V2 `/tim-kiem/?q=odo` compatibility
  redirect returns HTTP 301 and a `Location` containing `/?s=odo`, preserving
  the native WordPress search owner. Expanded runtime smoke passed 29/29;
  guarded PHPUnit passed 134 tests/827 assertions. No production routing or
  V2 data was modified.

- 2026-08-31: Public entity payload rendering now maps technical field labels and
  filters internal phrases such as canonical, stable key, external reference and
  atomic claim at the theme presentation boundary without changing source data.
  The frontend contract is now 87 unit tests/452 assertions; guarded full suite
  passes 131 tests/799 assertions. Browser QA confirms the active Odo detail has
  no internal payload terminology and no horizontal overflow. Route smoke was
  attempted after this checkpoint but the shell could not reach the local HTTP
  listener; this remains an environment evidence gap, not a route assertion.

- 2026-08-31: Read-only `nhk_v3` MediaAsset inventory verified all three imported
  assets remain PRIVATE and their storage keys still reference the old V2
  absolute upload tree. None of the three source files is present under the V3
  upload root, so checksum/byte verification cannot pass and public delivery
  remains correctly fail-closed; no database or file state was changed.

- 2026-08-31: Local Apache was available again and the read-only frontend route
  smoke was re-run outside the sandbox: all 21/21 checks passed, including
  public archives, V2 aliases, semantic search page two, comparison and 404.
  The earlier shell-listener evidence gap is closed; active Video data and
  broader screenshot coverage remain separate gates.

- 2026-08-31: Browser route sweep found and corrected a missing whitespace in
  the homepage hero headline. The frontend contract now asserts the rendered
  headline boundary; browser textContent is `Mỗi chiếc đồng hồ mang một câu
  chuyện.` and the full guarded suite passes 131 tests/799 assertions.

- 2026-08-31: Added `V2_DOMAIN_TARGET_REVIEW_2026-08-31.md`, a read-only
  breakdown of all 764 skipped V2 WordPress records: 742 domain records, 21
  attachments and one global-styles record. The restored export lacks a
  deterministic legacy-post-to-semantic-ID field, so name/slug joins remain
  prohibited; governed target mappings or retirement decisions are still
  required before redirects or body migration.

- 2026-08-31: Read-only title reconciliation found exact one-to-one candidates
  for all five residual `DOMAIN_TARGETED` URLs in
  `V2_URL_RECONCILIATION_REVIEW_2026-08-31.md`, mapping them to existing
  `nhk:knowledge:editorial.article.*` claims. The matches reduce ambiguity but
  remain pending UUID/revision/provenance and governed redirect-or-retire
  decisions; no migration or redirect was applied.

- 2026-08-31: Field-level verification found UUID and revision 2 for each of
  the five candidates, but all are `ARCHIVED`, `UNVERIFIED`,
  `ARCHIVED_OPERATIONAL_NOT_PUBLIC_KNOWLEDGE` and have no active target. They
  are identity matches, not public redirect targets; governed retirement or a
  separately approved active target is required.

- 2026-08-31: Native homepage URL V2 ID 758 (`/`) was normalized to `/` and
  applied locally as `READY_NOOP`; route smoke confirms HTTP 200 with no
  redirect or duplicate editorial record. URL reconciliation now has 27
  residual candidates; policy-normalized dry-run totals are 3,961 mapped,
  1,012 skipped and 0 conflicts. The change is limited to local `nhk_v3` and
  does not modify V2 or production.

- 2026-08-31: Preflight completed. HEAD `2247c87`; existing governance edits
  preserved. Governance documents being bootstrapped.
- 2026-08-31: P4 acceptance completed on `nhk_v3_test`; Migration003 applied
  UP-only to `nhk_v3`; runtime health reported migration 3/3 and Graph,
  Authority, Governance storage ready. P5 is now active.
- 2026-08-31: P4 governance/docs checkpoint committed as `49b6d47` and pushed
  to `origin/main`; P5 catalog/registry implementation is next.
- 2026-08-31: P5 canonical catalog added for nine target types with explicit
  field schemas and validation; unit/integration evidence is 60 tests, 234
  assertions, 0 skipped. P5 is ready to close and P6 is next.
- 2026-08-31: P6 domain contracts and Migration004 added; `P6MigrationIntegrationTest`
  passes on `nhk_v3_test`.
- 2026-08-31: MediaMigration004 applied UP-only to `nhk_v3`; runtime health
  reports migration 4/4 and media/video storage ready. P6 persistence services
  and Graph relations remain the next executable work.
- 2026-08-31: P6 domain/schema checkpoint committed as `51ff8bf` and pushed to
  `origin/main`; P6 remains active for persistence services and shared Graph
  endpoint integration.
- 2026-08-31: The autonomous UI/logic/database/data-parity directive was
  merged into the operating documents. Frontend may proceed in parallel once
  contracts are stable; actual V2 migration remains backup/restore-gated.
- 2026-08-31: P6 persistence slice added for Media/Asset/Usage and Video,
  including optimistic repository updates, idempotent external references and
  Media/Video Graph endpoint resolvers. Focused and all-unit evidence passed;
  WordPress integration is environment-gated by `NHK_WP_TEST_PATH`.
- 2026-08-31: P7 Knowledge Claim, Source and Evidence contracts, UP-only
  Migration005, WPDB repositories, service boundary and shared Graph endpoint
  resolvers were added. Post links use the single `about` Graph predicate and
  never duplicate WordPress editorial body. Unit evidence remains green;
  Migration005 is pending WordPress integration environment.
- 2026-08-31: P9 responsive editorial theme scaffold was expanded on the
  existing user-owned theme files: NHK shell/navigation/search, discovery
  homepage, editorial archive/search, Post, 404 and reusable article cards.
  Warm NHK design tokens, mobile navigation, two-column desktop feed/sidebar,
  accessible labels and empty states are present; browser smoke/visual QA and
  semantic entity routes remain pending.
- 2026-08-31: P8 read API and Admin health surface added. Read endpoints expose
  Media, Video, Knowledge Claim and Source with nested evidence/assets/usages,
  returning 503 until their migration storage is ready. Admin is capability
  protected and intentionally read-only for now; governed proposal mutations
  and MCP remain next.
- 2026-08-31: Governed proposal REST create/submit/approve/reject and unified
  semantic search were added. Search keeps native WordPress Post search and
  groups active Authority, Media, Video and Knowledge results under one API;
  capability checks remain fail-closed for mutation routes.
- 2026-08-31: Canonical entity list/detail REST endpoints were added for the
  nine Authority types with active-only pagination and type-safe 404 handling,
  providing the initial data source for domain-specific frontend pages.
- 2026-08-31: MCP tool catalog and Governance handler were added. Read tools
  are explicitly non-mutating; every mutation tool is marked governed and
  delegates to `GovernanceService` for authorization, idempotency and lifecycle
  policy. External MCP transport wiring remains pending.
- 2026-08-31: Graph read REST routes were wired to all registered endpoint
  resolvers with cursor pagination and public retired-edge suppression. Graph
  reads no longer materialize missing graph nodes. A no-write V2 dry-run CLI
  and reason-code service were added; checksum collisions remain review-only
  duplicate candidates. Checkpoint `27ce072` is pushed to `origin/main`.
- 2026-08-31: Governance REST now exposes capability-protected eligibility and
  Controlled Apply. Authority proposal execution supports create/ingest,
  rename, update, retire and reactivate through the existing transaction,
  revision, idempotency and audit boundaries. Checkpoint `74ed7eb` is pushed to
  `origin/main`; WP integration remains environment-gated.
- 2026-08-31: MCP read adapter now exposes real Authority, Media, Video,
  Knowledge and native WordPress Post query methods, while the mutation bridge
  remains delegated to GovernanceService. A `nhk_mcp_register_tools` hook
  provides a transport-neutral registration seam. Checkpoint `6ea8362` is
  pushed to `origin/main`; external transport is still not fabricated.
- 2026-08-31: MCP tool definitions now expose protocol input schemas and a
  capability-gated Streamable HTTP POST endpoint. The local runtime accepts
  modern JSON-RPC `2026-07-28` metadata, retains legacy `2025-11-25`
  initialization compatibility, validates Origin and mirrored headers, and
  delegates all calls to the existing read/Governance handlers. Guarded
  transport tests and local HTTP smoke pass; external client/deployment
  interoperability remains open.
- 2026-08-31: P11 residual-gate audit re-ran the current quality gates after
  restoring the local MySQL runtime: unit 82/286, guarded WordPress
  integration 41/260, plugin/theme PHP lint and route smoke 20/20 all pass.
  The Cutover Report was corrected to record the current 12-tool MCP catalog;
  V2 field-level/policy decisions, active Video coverage and external MCP
  interoperability remain open, so production cutover stays unauthorized.
- 2026-08-31: Browser visual QA added real-data mobile checks for the homepage,
  editorial empty archive, native Post, active Authority detail and active
  Media detail; DOM inspection confirmed one main landmark and one footer on
  the long homepage. Local read-only DB inspection confirms 242 Media rows,
  3 assets and 0 Video rows, so no artificial Video detail fixture was added.
  Route inventory and Cutover evidence now reflect the healthy local runtime;
  broader route screenshots, active Video detail and policy/data gates remain
  open.
- 2026-08-31: Canonical Video ingest is now a governed vertical slice: the
  executor delegates validated YouTube URL ingestion, update, retire and
  reactivate to VideoService; MCP exposes `nhk.video.ingest` with capability
  gating, and Admin exposes a labelled Video URL control. Unit and guarded
  integration evidence now passes at 83/298 and 42/279 respectively, including
  Video create → submit → approve → apply; active public Video data is still
  absent locally, so no browser fixture was created.
- 2026-08-31: The guarded Video lifecycle test now also verifies the active
  canonical Video through `GET /nhk/v1/video/{uuid}` after apply. Current
  evidence is 83 unit tests/300 assertions and 42 integration tests/282
  assertions; combined evidence is 125 tests/582 assertions. Public active
  Video browser QA remains data-gated because `nhk_v3` has zero Video rows.
- 2026-08-31: Local HTTP verification after Video wiring returned 20/20
  expected public route statuses; a real `/wp-json/nhk/v1/mcp` POST returned
  the 13-tool catalog and included `nhk.video.ingest`. Unit/integration/lint
  evidence remains green, while external deployment interoperability and V2
  reconciliation are still separate cutover gates.
- 2026-08-31: Canonical entity frontend routes now cover archive, filtered
  archive pagination and stable-key/UUID detail for all nine Authority types.
  `EntityPageQuery` owns repository access; the theme only presents the
  context, with responsive empty states and semantic facts. Checkpoint
  `dea84fd` is pushed to `origin/main`; runtime route smoke and related Graph,
  media and video modules remain pending.
- 2026-08-31: NHK Admin now provides capability-gated entity/proposal lookup,
  health, proposal state/revision/dependency visibility, eligibility and
  submit/approve/reject/Controlled Apply actions through REST with WP nonce;
  apply attempt history is visible. Checkpoint `59bb952` is pushed to
  `origin/main`; runtime browser smoke remains environment-gated.
- 2026-08-31: Theme SEO hooks now emit canonical, description, OpenGraph,
  BreadcrumbList and Article metadata for editorial/entity surfaces, while
  WordPress remains the sitemap/RSS owner. Checkpoint `4e0252c` is pushed to
  `origin/main`; runtime metadata validation remains environment-gated.
- 2026-08-31: Media/Video public query services and rewrite/template routes
  were added for `/video/`, `/video/{uuid}`, `/thu-vien/`, `/media/` and
  `/media/{uuid}`. Media renders readiness-aware asset metadata and Video
  renders a YouTube privacy embed from its canonical external reference;
  local MP4 copying is not introduced. Unit evidence is 58 tests/155
  assertions; runtime route smoke remains WordPress-environment gated.
- 2026-08-31: Checkpoint `e8c4c27` was pushed with public Media/Video
  templates, route wiring, query-service tests and the source-level frontend
  route inventory. Unit evidence is 58 tests/155 assertions. The guarded full
  WordPress command was attempted with `NHK_WP_TEST_DB=nhk_v3_test` and
  `NHK_WP_TEST_PATH=public`, but local WordPress stopped at a database
  connection error; no V2 migration or production action was performed.
- 2026-08-31: NHK Admin gained a capability-gated governed proposal composer
  for create/ingest/rename/update/retire/reactivate. The form sends only to
  the Governance REST boundary with a WP nonce; it does not write domain
  tables directly. Checkpoint `16ea31a` is pushed; runtime lifecycle smoke is
  still blocked by the local WordPress database connection.
- 2026-08-31: P11 readiness audit started. `CUTOVER_READINESS_REPORT.md`
  records the green local unit/lint gates and the unresolved WordPress DB,
  browser smoke, V2 inventory, backup/restore, URL reconciliation and
  external MCP transport gates. Decision is NOT READY; production cutover was
  not performed.
- 2026-08-31: Cutover Readiness Report checkpoint `86e5838` is pushed to
  `origin/main`. The repository is clean and remains explicitly pre-cutover;
  external/runtime gates are documented rather than inferred as passed.
- 2026-08-31: Governed relation proposals now support Graph create, retire and
  reactivate with endpoint/predicate validation and edge revision checks;
  Controlled Apply records Graph edge IDs and avoids nested transaction commits.
  MCP exposes governed `proposal.apply`; the Admin composer can author relation
  proposals. Checkpoint `9ba07a5` is pushed to `origin/main`.
- 2026-08-31: Homepage data access moved into `NHK_V3_Home_Page_Query`, with
  featured/latest/category/topic modules and a plugin semantic filter for real
  Authority/Media/Video data. Empty storage hides semantic modules. Checkpoint
  `ee09ad4` is pushed; browser smoke remains blocked by the local DB.
- 2026-08-31: Native category aliases now preserve `/tri-thuc/` and
  `/goc-chia-se/` with pagination while keeping WordPress as editorial source;
  Admin semantic lookup now covers Media, Video, Knowledge, Source and Graph
  endpoints. Checkpoint `41cc81a` is pushed; runtime rewrite/REST smoke is
  still gated by the local database connection.
- 2026-08-31: Route/Admin readiness documentation checkpoint `a694a89` and
  state closure `6f65b4a` are pushed to `origin/main`; runtime rewrite/REST
  smoke remains pending until the local WordPress database is available.
- 2026-08-31: Media/Video SEO now has document titles, canonical/OpenGraph,
  breadcrumbs and `VideoObject`; frontend contract tests enforce the
  HomePageQuery boundary and these metadata surfaces. Checkpoint `e9ea590` is
  pushed; unit evidence is 61 tests/170 assertions.
- 2026-08-31: Unified semantic search now has a theme `SearchPageQuery` and
  plugin `SearchSemanticQuery`; native WordPress Post results remain the
  editorial source while active Authority/Media/Video/Knowledge results are
  grouped and linked. Checkpoint `668cb28` is pushed; browser/REST smoke is
  still gated by the local database connection.
- 2026-08-31: Search readiness documentation checkpoint `5601aef` is pushed
  to `origin/main`; the repository remains pre-cutover with all unresolved
  runtime and V2-data gates explicitly recorded.
- 2026-08-31: Read-only frontend route smoke harness was added at
  `tools/frontend-route-smoke.php`; its localhost attempt reported connection
  refused for all expected routes, with no false pass. Checkpoint `eee6ede`
  is pushed; unit evidence remains 62 tests/173 assertions.
- 2026-08-31: P10 dry-run reconciliation now reports source/mapped counts by
  type, skipped reasons, malformed records and explicit conflict review while
  preserving no-write behavior and checksum non-merge semantics. Checkpoint
  `350e189` is pushed; unit evidence is 63 tests/181 assertions.
- 2026-08-31: Local MySQL/MariaDB TCP and Apache runtime were restored for V3;
  the guarded suite passed 88 tests and 351 assertions. A standard local
  WordPress rewrite file and empty-editorial alias handling made core frontend
  smoke pass, including a real `/hello-world/` post route.
- 2026-08-31: The V2 backup was restored into guarded staging with a reviewed
  MariaDB compatibility conversion. The expanded read-only export/dry-run
  produced 4,933 records: 2,180 mapped, 2,753 skipped
  (`INVALID_URL_MAPPING` 799, `UNSUPPORTED_LEGACY_TYPE` 1,954). Temporary V2
  tables were removed, the V3 test snapshot was restored, and no V2 record was
  migrated.
- 2026-08-31: Final route smoke passed 15/15 checks including `/hello-world/`.
  Visual automation remains pending because Playwright has no browser binary
  and the available system Chrome aborts in the headless connector.
- 2026-08-31: Migration006 added a durable source checksum/status ledger and
  `tools/v2-migrate.php` added guarded plan/apply with source offsets. After a
  reviewed normalized V2 restore, the full 4,933-record export was applied to
  local `nhk_v3`: 1,545 migrated, 3,388 explicit skips, 0 conflicts. A second
  run produced the same counts and no duplicate targets. The guarded test DB
  was restored from snapshot and remains free of `nhkv2_*` tables.
- 2026-08-31: MediaAsset persistence was corrected at the repository boundary:
  V3 keeps BIGINT internal Media foreign keys while repositories resolve
  canonical Media UUIDs on write/read. Focused media regression and the
  guarded full suite pass at 90 tests/367 assertions. The final governed
  local-dev apply is 1,548 migrated, 3,385 skipped and 0 conflicts; all three
  V2 MediaAsset rows are present with verified parent IDs. Checkpoint
  `da748fd` is committed locally and this documentation checkpoint is
  `3854448`; production/live migration remains blocked.
- 2026-08-31: The V2 exporter now emits 19 governed Source records and 40
  citation Evidence records, preserving source metadata, citation excerpts,
  endpoint identity and V2 PRIVATE visibility. The local-dev apply reached
  1,607 migrated, 3,366 skipped and 0 conflicts; all 40 Evidence rows join a
  migrated Knowledge claim and Source. Guarded suite is 91 tests/373
  assertions; staging test DB was restored and has no `nhkv2_*` tables.
- 2026-08-31: Evidence metadata persistence was extended with UP-only
  Migration007. Verification state, visibility, excerpt metadata and legacy
  citation IDs now survive the Evidence repository boundary; the 40 local-dev
  rows were idempotently backfilled with 0 conflicts. Guarded suite is 91
  tests/375 assertions and `nhk_v3` reports migration 7/7.
- 2026-08-31: Mapper 6.6 classified the one proven `/tim-kiem/` URL as a
  `READY_NOOP` and recorded the remaining 799 URL candidates as explicit
  `INVALID_URL_MAPPING` skips. The local-dev ledger is now 1,608 migrated,
  3,365 skipped and 0 conflicts; guarded suite is 92 tests/381 assertions.
- 2026-08-31: UP-only Migration008 added MediaAsset visibility and metadata
  persistence. Mapper 6.7 re-exported all three V2 assets with field-level
  metadata and reconciled them to PRIVATE in local development; public Media
  REST/query boundaries suppress those assets. The full guarded suite passes
  93 tests/385 assertions, route smoke passes 17/17, and the local ledger
  remains 1,608 migrated, 3,365 skipped and 0 conflicts.
- 2026-08-31: Mapper 6.8 added governed 301 redirects for 34 `nhk_article`
  source paths to their imported native WordPress posts. The local ledger now
  records 1,642 migrated, 3,331 skipped and 0 conflicts; 35 URL rows are
  migrated (34 redirects plus one safe no-op), 765 URL candidates remain
  explicit `INVALID_URL_MAPPING` skips, and local HTTP verification returned
  301 with the expected native target. Guarded suite is 94 tests/391
  assertions.
- 2026-08-31: Public Knowledge REST now fail-closes inactive PRIVATE Source
  and Claim identities with 404; internal repositories retain private rows for
  governed review. Full guarded suite passes 95 tests/392 assertions, local
  route smoke remains 17/17, and no production/V2 data was changed.
- 2026-08-31: Read-only analysis of the normalized V2 dump found explicit
  `_nhk_projection_source_id` links for 776 projected posts, all resolving to
  canonical entity UUIDs. Mapper 6.9 now emits redirects for the 370 active
  Authority entities with public V3 routes, stores entity aliases in a
  fail-closed WordPress option registry, and classifies Knowledge/no-route
  projections as `DOMAIN_TARGETED`; the guarded export/dry-run/apply rerun
  completed at 2,012 migrated, 2,961 skipped and 0 conflicts. The URL ledger
  now has 405 migrated rows (370 entity redirects, 34 native-post redirects
  and one `READY_NOOP`), 372 `DOMAIN_TARGETED` rows and 23 invalid mappings;
  the rerun was idempotent and staging was restored to a V3-only snapshot.
- 2026-08-31: Mapper 6.11 added governed redirects for 75 archived Knowledge
  URLs with active consolidation targets. The restored export/dry-run/apply
  reached 3,330 mapped, 1,643 skipped, 2,379 migrated and 0 conflicts; URL
  reconciliation is now 772 migrated (367 Knowledge, 370 Authority, 34
  native-post and one no-op), with 5 archived/no-target Knowledge URLs and 23
  malformed/system URLs explicitly skipped. Knowledge evidence remains
  fail-closed unless both Evidence and its Source are active; staging was
  restored to V3 migration 8/8.
- 2026-08-31: V2 media usage audit confirmed exactly 0 rows in
  `nhkv2_nhk_media_usage`; no usage rows require migration. Media parity is
  therefore recorded as usage-contract PASS, while the three imported PRIVATE
  MediaAsset rows remain gated on delivery/privacy policy approval.
- 2026-08-31: Mapper 6.12 retained the 3,330/1,643 dry-run and 2,379/2,594/0
  apply counts while splitting the 28 residual URL skips into 5
  `DOMAIN_TARGETED`, 21 `UNSUPPORTED_MEDIA_REFERENCE`, 1
  `RETIRED_LEGACY_GARBAGE` and 1 `INVALID_URL_MAPPING`; the full rerun stayed
  idempotent.
- 2026-08-31: Mapper 6.13 classified the remaining WordPress global-style URL
  as `RETIRED_LEGACY_GARBAGE`. Full-artifact dry-run remained 3,330 mapped /
  1,643 skipped; local-dev apply and rerun remained 2,379 migrated /
  2,594 skipped / 0 conflicts with URL skips now 5 `DOMAIN_TARGETED`, 21
  `UNSUPPORTED_MEDIA_REFERENCE` and 2 `RETIRED_LEGACY_GARBAGE`. Apply output
  hashes matched across both runs.
- 2026-08-31: Mapper 6.14 made `legacy_semantic_projection` stable keys
  collision-safe by retaining the historical semantic key for the first row
  and suffixing later duplicates with their `projection_id`. Dry-run now
  mirrors apply boundaries for custom/system posts, categories and relation
  predicates: 2,379 mapped / 2,594 skipped / 0 conflicts. Batch 14 contains
  all 4,973 source keys with matching reason buckets, and its apply rerun
  hash matched exactly. Staging was restored to 0 V2 tables, 17 V3 tables and
  migration 8/8.
- 2026-08-31: Browser visual QA succeeded for desktop homepage, Knowledge
  archive/detail, Authority detail and 404 surfaces. Responsive/tablet/mobile
  coverage remains pending; the browser connector is available for follow-up.
- 2026-08-31: Browser verification found Knowledge cards in unified Search
  rendering placeholder `#` links despite active claim data. The theme now
  maps active Knowledge results to canonical `/knowledge/claim/{UUID}/` URLs,
  with a frontend contract regression assertion; Search `Odo` has zero
  Knowledge `#` links and the read-only route smoke passes 16/16.
- 2026-08-31: A field-level review artifact was added for all 28 residual URL
  candidates from the hashed Mapper 6.14 export. It records the five
  domain-targeted posts, 21 unsupported media references and two retired
  legacy paths without changing their explicit-skip status or V2/V3 data.
- 2026-08-31: A fail-closed MediaAsset delivery boundary was added. It serves
  only PUBLIC assets whose MIME type is allowlisted, whose resolved file stays
  under the configured current storage root, and whose size and SHA-256 match
  persisted metadata; PRIVATE/HIDDEN, legacy absolute-path and missing assets
  return 404. Unit evidence is 70 tests/205 assertions and route smoke is
  17/17, including an unknown asset route.
- 2026-08-31: Guarded WordPress integration passed after the delivery boundary
  and rewrite registration changes: `nhk_v3_test` remains migration 8/8 and
  the suite is 103 tests/419 assertions with no V2 tables restored.
- 2026-08-31: Desktop Authority archive QA found the archive context omitted
  its entity type, producing the duplicate heading “Khám phá khám phá”, a
  generic document title and malformed stable-key links. The archive context
  now preserves `type` for the theme/query boundary; the fix is covered by a
  frontend contract assertion and browser verification.
- 2026-08-31: Authority archive title handling now uses the preserved entity
  type for localized document titles and canonical SEO title output; the
  previous generic site title is removed from this surface.
- 2026-08-31: Authority archive browser verification now reports the localized
  title “Khám phá thương hiệu — Đồng Hồ Nhà Kho”, a non-duplicated heading,
  canonical `/brand/nhk%3Abrand%3Ajunghans/` card links and route smoke 17/17.
- 2026-08-31: Guarded WordPress integration was rerun after the Authority
  archive fix and passed on `nhk_v3_test`: 103 tests/421 assertions.
- 2026-08-31: REST Entity/Media/Video details and MCP Entity/Media/Video reads
  now fail closed for retired records, matching the active-only public page
  boundary. Unit evidence is 70 tests/210 assertions; guarded integration
  remains green at 103 tests/424 assertions.
- 2026-08-31: Local REST smoke verified active entity/media/knowledge/search
  reads (`200`), wrong or missing entity routes (`404`) and unauthenticated
  Governance create/eligibility/apply (`401`). Runtime MCP registration
  captured 11 tools, 5 governed tools and both read/governance handlers; at
  that checkpoint, an external MCP transport was not present or inferred.
- 2026-08-31: Local MCP transport smoke returned `tools/list` 200 with 11
  definitions, rejected an unauthenticated governed proposal call with 403,
  and rejected an invalid Origin with 403. The endpoint is local-runtime
  evidenced, not production or external-client approval.
- 2026-08-31: Post single now requests Graph-derived related entities,
  articles, Media and Video through `nhk_v3_post_related_content`; the theme
  renders the groups only when active/public query results exist. The filter
  wiring was browser-verified after catching and fixing a real argument-order
  fatal on `/hello-world/`; desktop Post visual QA is clean, route smoke is
  17/17, unit evidence is 72 tests/215 assertions and guarded integration is
  108 tests/440 assertions.
- 2026-08-31: Read-only V2 reference QA confirmed archive contracts for
  `/thuong-hieu/`, `/hien-vat/` and `/am-nhac/`. V3 now registers these as
  compatibility aliases into canonical brand/specimen/music contexts; alias
  route smoke is 20/20 and `/thuong-hieu/` emits canonical `/brand/` metadata.
  V2 detail slugs such as `/odo/odo-39/` remain mapped only through verified
  ledger evidence and are not guessed by name.
- 2026-08-31: V2 detail QA confirmed `/odo/` and `/odo/odo-39/` as canonical
  discovery paths. A fail-closed compatibility resolver now redirects only a
  unique active Brand or Brand/Model public-slug match to the canonical
  stable-key route, while native WordPress content and ambiguous names win or
  remain unresolved. Unit evidence is 74 tests/223 assertions; local HTTP
  verification is pending because MySQL/Apache are currently stopped.
- 2026-08-31: V2 search QA confirmed `/tim-kiem/?q=Odo` as a grouped search
  contract. `PublicEditorialRoutes` now preserves `q` while redirecting to
  native WordPress `/?s=Odo`; no duplicate search persistence is introduced.
  Unit evidence is 75 tests/226 assertions; current HTTP verification remains
  blocked by stopped MySQL/Apache.
- 2026-08-31: The previously linked `/comparison/` discovery surface was
  completed as a read-only Authority comparison route. It accepts two
  `type/stable-key` references, resolves only active canonical entities through
  `EntityPageQuery`, and renders semantic payload facts without a duplicate
  persistence model. Unit evidence is 77 tests/237 assertions; local runtime
  verification is now available.
- 2026-08-31: Browser QA of `/comparison/` found its semantic UI healthy but
  its document metadata inherited the site default. Theme SEO now emits a
  dedicated comparison title, description, canonical `/comparison/` and
  breadcrumb; the correction is covered by the frontend contract test.
- 2026-08-31: Local runtime verification passed the new discovery surfaces:
  `/comparison/` returns 200 with its dedicated title; `/odo/` and
  `/odo/odo-39/` return 301 to canonical Brand/Model stable-key routes; and
  `/tim-kiem/?q=Odo` returns 301 to `/?s=Odo`. The guarded suite passes 113
  tests/462 assertions and route smoke passes 20/20.
- 2026-08-31: Active local Media detail QA passed at
  `/media/0068236c-1033-4aef-ac97-b711a30ccb4d/` with HTTP 200, dedicated
  title/canonical metadata and the expected fail-closed empty state for its
  draft/PRIVATE asset. Desktop visual inspection passed; active Video detail
  remains unverified because no active Video record is present in local data.
- 2026-08-31: Migration009 added the non-canonical
  `nhk_legacy_projection_contexts` sink. It stores only bounded projection
  metadata and provenance, explicitly records `body_migrated=false`, and
  rejects projection bodies. The updated dry-run maps all 1,581 projection
  rows; the local-dev apply reached 3,960 migrated / 1,013 skipped / 0
  conflicts, and read-only checks confirmed 1,581 sink rows, 1,581 false body
  flags and zero projection-derived Authority entities. No V2 or production
  data was changed.
- 2026-08-31: Responsive QA found the 768px header search/nav row clipped at
  the right edge. The theme now switches the header to its accessible menu
  layout through 820px, with stylesheet cache-busting version 1.1.1. Browser
  checks at 390px and 768px found no horizontal overflow on homepage, search,
  comparison, Authority detail or Media detail; the menu toggle exposes all
  ten navigation links. Full archive/detail/pagination visual coverage remains
  open.
- 2026-08-31: The full public route inventory was checked at 390px and 768px
  (38 route/viewport combinations). Theme content had no horizontal overflow
  after the grid min-width fixes; the only remaining measured overflow was the
  logged-in WordPress admin toolbar on the Component page, outside the public
  theme shell. Pagination visual coverage and an active Video detail remain
  unavailable in the current local dataset.
- 2026-08-31: Pagination QA checked the declared archive page-2 routes at
  390px and 768px (26 route/viewport combinations); all theme content was
  overflow-free, and `/model/page/2/` received a mobile visual inspection with
  working archive heading, filter and entity cards. Remaining pagination
  visual coverage is the broader route/page-state sweep; the current local
  dataset has no active Video detail to inspect.
- 2026-08-31: Frontend accessibility/performance hardening added a skip link,
  explicit `main` targets on all public templates, a keyboard-visible menu
  control with `aria-controls`/synchronized `aria-expanded`, focus-visible
  styling and decorative-card image alt handling. Browser runtime checks at
  390px confirmed the menu exposes all ten links and no horizontal overflow;
  `node --check`, PHP lint, unit tests (80/259) and diff check pass. A fresh
  shell route/integration retry is currently blocked by the local service
  connection state and is not counted as a pass.
- 2026-08-31: SEO archive policy was made explicit through the single
  WordPress `wp_robots` output: canonical non-search pages emit
  `index,follow`, while search and paginated archive states emit
  `noindex,follow`, including custom entity/Media/Video/Knowledge page vars;
  the frontend contract test covers the policy.
- 2026-08-31: Browser runtime verification confirmed the homepage canonical is
  `http://localhost/` rather than the first editorial post, while search and
  custom archive page-two states emit one consolidated `robots` directive with
  `noindex,follow`; unit evidence is now 81 tests/265 assertions.
- 2026-08-31: Added a responsive long-title/long-key guard for article,
  semantic, entity, media, knowledge and related cards, plus a zero-width
  filter input constraint. Unit tests remain 81/265, PHP lint and diff check
  pass; full route smoke remains subject to the local service gate.
- 2026-08-31: Re-ran the guarded WordPress integration outside the sandbox
  network boundary: 38 tests/235 assertions pass on `nhk_v3_test`; the
  localhost frontend route smoke also passes 20/20. Combined current test
  evidence is 119 tests/502 assertions; no V2 live or production data was
  changed.
- 2026-08-31: Responsive QA found a real tablet overflow on the Component
  archive caused by long stable keys. The theme now wraps `.entity-card-key`
  values and bumps the stylesheet cache version to 1.1.3. Browser recheck
  covers 32 route/page-state and 390px/768px combinations with zero overflow,
  valid main/heading landmarks, and the Component archive visually inspected
  at both widths; active Video detail and broader screenshot coverage remain
  open.
- 2026-08-31: Additional mobile screenshots passed for Media pagination,
  Video empty state, Knowledge pagination and 404. These states retain
  usable hierarchy, controls and footer layout; remaining screenshot QA is
  route-specific coverage beyond the inspected set and an active Video detail
  when a valid local record exists.
- 2026-08-31: NHK Admin operational forms now associate every lookup,
  proposal-composer and semantic/Graph control with an explicit label/id and
  expose form context through labelled/described regions. A source-level
  accessibility contract test covers the associations; the unit suite is now
  82 tests/277 assertions.
- 2026-08-31: MCP now exposes governed `nhk.media.ingest`. The tool converts
  a complete Media packet (identity, provenance, assets and usages) into a
  Governance proposal; controlled apply uses the MediaService transaction
  boundary and defaults ingested assets to PRIVATE. End-to-end integration
  coverage passes create → submit → approve → apply with the asset still
  private; current evidence is 82 unit tests/286 assertions and 40 integration
  tests/257 assertions.
- 2026-08-31: Streamable HTTP now rejects modern requests that do not advertise
  both JSON and SSE response media types, returning the protocol
  HeaderMismatch error; this guard is integration-tested. Current evidence is
  82 unit tests/286 assertions and 41 integration tests/260 assertions.
- 2026-08-31: Governed Knowledge, Source and Evidence ingest now uses the same
  Controlled Apply boundary as Authority, Media and Video. Eligibility resolves
  revisions across all canonical repositories; MCP exposes three capability-gated
  ingest tools. Guarded integration proves Source → Knowledge Claim → Evidence
  create/submit/approve/apply and public claim/source reads with nested evidence;
  current evidence is 83 unit tests/322 assertions and 43 integration tests/328
  assertions, combined 126/650. Public Source/Evidence activation policy and V2
  provenance reconciliation remain cutover gates.
- 2026-08-31: Semantic search was bounded per page in both the theme query and
  REST API, with per-group totals exposed for navigation. WordPress search page
  2 now remains HTTP 200 when native Post results are exhausted but semantic
  results continue; browser verification for `/?s=odo&paged=2` shows 12 cards
  per group, 17 navigation pages and no horizontal overflow. Unit evidence is
  84 tests/327 assertions; guarded integration is 44 tests/347 assertions after
  adding the REST bounded-page contract test; combined current suite is
  128/674.
- 2026-08-31: MCP `nhk.search` now exposes optional `page`/`per_page` inputs,
  returns semantic group totals and bounds each group per page while excluding
  retired Authority records. Unit evidence is 85 tests/332 assertions; guarded
  integration remains 44 tests/347 assertions; combined current suite is
  129/679.
- 2026-08-31: REST Search now also suppresses retired Authority records, keeping
  REST, theme and MCP semantic reads aligned on active-only visibility. The
  contract assertion is included in the 85-test/334-assertion unit suite;
  guarded integration remains 44 tests/347 assertions and combined evidence is
  129/681.
- 2026-08-31: Public templates and SEO descriptions no longer expose internal
  domain language such as Authority reference, Knowledge claim, Canonical ID,
  entity Video or Semantic search. The contract is covered by the frontend unit
  suite; current evidence is 86 unit tests/359 assertions, 44 guarded
  integration tests/347 assertions and combined 130/706.
- 2026-08-31: Public copy parity was extended across homepage, entity, Knowledge,
  Media, Video and comparison templates: technical labels such as canonical,
  semantic, atomic claim, external reference, Revision and Canonical ID were
  removed from user-facing copy while URL/schema contracts stayed intact. The
  contract remains covered by the frontend unit suite; current evidence is
  86 unit tests/404 assertions, 44 guarded integration tests/347 assertions and
  combined 130/751.
- 2026-08-31: The public terminology contract was extended to every primary
  template, including homepage, entity detail/archive and native Post related
  video cards. Technical wording is absent from rendered public copy; full
  verification is 86 unit tests/446 assertions, 44 guarded integration
  tests/347 assertions, combined 130/793, lint pass and route smoke 21/21.
- 2026-08-31: A raw HTTP client probe against the local Streamable HTTP endpoint
  returned `200 application/json` for modern `tools/list` with all 16 tools;
  modern `tools/call` for `nhk.search` page 2/per-page 5 returned JSON-RPC
  success with five items per semantic group and totals entities 76, media 143,
  videos 0 and knowledge 200. This strengthens local protocol evidence only;
  external client/deployment interoperability remains open.

- 2026-09-02: Human-approved Product/Specimen architecture was finalized in
  the sole normative Constitution. Specimen is the canonical identity of one
  physical object; Product is the canonical identity of one commercial
  listing/offer/context. The locked cardinality is Specimen `0..N` Products
  over time and Product `0..1` Specimen. Specific-object Product without
  exactly one Specimen is incomplete/blocked; generic/pre-specimen Product may
  remain unlinked only where the current contract permits it. Product owns
  commerce fields and copy; Specimen owns physical identity, provenance,
  observations and condition. Commercial copy is not Knowledge and cannot
  silently overwrite physical truth.

  The constitutional router reconciliation was also finalized for this
  checkpoint: `AGENTS.md` points directly to the Constitution and
  `docs/constitution/` contains only `NHK_V3_CONSTITUTION.md`; no competing
  normative file was added.

  The amendment is recorded in `docs/constitution/NHK_V3_CONSTITUTION.md`,
  and the non-normative MCP contract, compliance audit and remediation plan
  were reconciled. Runtime code adds the read-only
  `ProductSpecimenAssessment`/result boundary, separates Product commercial
  fields from Specimen physical-observation fields, and removes the
  unapproved Product `specimen_uuid` payload field. The existing broad `about`
  predicate is not used as Product–Specimen ownership; no dedicated relation
  predicate or persistence mechanism is registered. That relationship remains
  an explicit `REGISTRY_GAP`/`CODE_GAP` for a later contract task.

  Focused Product/Specimen + Phase-0 regression evidence passes `40` tests /
  `131` assertions and the broader Phase-0 regression slice passes `37` tests /
  `77` assertions. RED evidence was observed before implementation when the
  new boundary fields/assessment were absent. Composer validation, full PHP
  lint, `git diff --check` and secret review pass. Current unit execution is
  `233` tests / `1,211` assertions with two unrelated concurrent Article
  Ingest/MCP failures; these files were preserved and not repaired here.
  Guarded full PHPUnit reaches WordPress bootstrap and exits without a valid
  PHPUnit summary because the database connection is unavailable; read-only
  preflight reports `5/10` failed at WordPress/Core bootstrap, schema,
  hydration and REST checks. No database, WordPress Post, semantic record,
  Graph edge, slug, migration, seed, repair, import or backfill was run.

  The implementation is uncommitted because the managed filesystem rejects
  `.git/index.lock` creation and also blocked creation of the requested branch;
  current HEAD at this checkpoint is `26d64ec`. No push was attempted after
  that policy block. The concurrent Article Ingest/MCP working-tree changes and
  untracked MCP plan remain preserved.

## WordPress Ability registration hotfix — 2026-09-02

The admin-screen notice was traced to Article Ingest being registered through
`wp_register_ability()` during `rest_api_init`, after WordPress 6.9 had already
closed the `wp_abilities_api_init` registration window. This affected only the
unapproved Article Ability adapter; the approved WordPress Ability surface is
the exact eight read-only abilities documented in the MCP contracts. The
Article Ingest handler remains available through the governed MCP REST
transport, but is no longer exposed as a WordPress Ability.

Removed the late Article Ability registration path and added guarded
integration assertions for the absence of `nhk-v3/article-preflight` and
`nhk-v3/article-ingest`. Unit verification passes `246` tests / `1,269`
assertions; PHP lint and `git diff --check` pass. The full suite still reports
the repository's pre-existing unavailable WordPress/MySQL environment failures
(`$wpdb` bootstrap errors and missing `NHK_WP_TEST_PATH`). No database,
WordPress Post, semantic record, Graph edge, migration or external deployment
was changed.

## Media Ingest / Image SEO constitutional checkpoint — 2026-09-02

The Constitution amendment, non-normative spec and implementation plan are now
recorded for the Media Ingest, Image SEO and Article Media slice. The shared
`MediaIngestGateway`, controlled registries, Article media coordinator, SEO
Blueprint persistence, batch context and read-only legacy audit boundary are
implemented in the current working tree. Existing Media, MediaAsset and
MediaUsage identities remain distinct, and no new semantic relation or
auto-created Evidence/Knowledge/Graph record is introduced by ingest.

Static and unit evidence for this checkpoint is green: Composer validation,
PHP lint, the full NHK Unit suite (`262` tests / `1,338` assertions), focused
Article Media tests, `git diff --check` and the changed-file secret review.
The full PHPUnit run reached `369` tests with `8` WordPress bootstrap errors,
`12` mandatory-runtime failures and `87` skips; the regular preflight reports
the same unavailable WordPress/MySQL bootstrap. MCP wire smoke could not
connect to `http://localhost:80`. No database, Post, semantic record, Graph
edge, migration execution, slug, repair, import or legacy article-body
operation was run from this checkpoint.

The requested branch/commit checkpoint could not be created because the
managed filesystem rejects Git ref and index-lock writes. The working-tree
changes are intentionally preserved on the current branch; no push or merge
was attempted. Remaining implementation gates are the byte-upload transport,
the WordPress attachment-to-canonical-Media selection adapter, live runtime
verification, and any separately governed legacy audit/repair decision.

## Media Phase R runtime proof — 2026-09-02

This additive checkpoint preserves the current working-tree implementation and
records fresh local evidence after the MySQL service was restored. Migration
checks used `nhk_v3`; destructive fixture cleanup was limited to exact
fixtures on `nhk_v3_test`. No V2, staging, production, legacy Post, Graph
edge, slug, repair or backfill write was performed.

| Gate | Result | Evidence / limitation |
|---|---|---|
| SOURCE_UNIT_GATE | **PASS** | `composer validate`, full Unit suite `262 tests / 1,338 assertions`, focused Media/MCP/P6 slice `20 / 148`, PHP lint, diff check and changed-file secret review pass. |
| WORDPRESS_RUNTIME_GATE | **PASS** | `composer preflight` reached `10/10`; WordPress, NHK Core, schema, hydration and REST bootstrap checks passed. |
| MIGRATION_011_GATE | **PASS** | On `nhk_v3`, migration 011 rerun found current/target `11/11`, the Blueprint table and required columns/indexes present, and no duplicate rows/schema mutation. |
| MEDIA_PERSISTENCE_ROUNDTRIP | **PASS** | Isolated `nhk_v3_test` fixture persisted/read back a Blueprint, updated mutable SEO intent with canonical fields preserved and storage revision incremented, and repeated reconciliation without duplicate mandatory usages/Blueprint rows. |
| ARTICLE_RUNTIME_GATE | **FAIL** | Native Post creation produced exactly two distinct placeholder Media usages and two Blueprints with incomplete diagnostics. Real replacement reached `MEDIA_COMPLETE`, but `featured_media` stayed `0` and inline placement was not written into `post_content`; editorial selection is not synchronized by this adapter. |
| MCP_RUNTIME_GATE | **FAIL** | Real localhost MCP HTTP `tools/list` returned JSON-RPC 200 with 21 tools and correct protocol headers. Governed MCP Media ingest completed through proposal → approval → apply and persisted Media. MCP Article preflight/ingest returned explicit `RECONCILIATION_CONFLICT`/`EDITORIAL_STATE_CHANGED`, not success. |
| GENERIC_MCP_BYPASS_GATE | **FAIL** | No generic WordPress write tool is in the NHK MCP catalog, but native WP REST exposes Post create/update and Media upload/update including featured-media paths; these remain a technical bypass unless separately wrapped by an approved policy boundary. |
| ARTICLE_INGEST_RUNTIME_GATE | **FAIL** | The actual Article Ingest/MCP path failed closed on the editorial reconciliation conflict; persistence diagnostics were returned and no semantic bypass occurred. |
| BULK_INGEST_RUNTIME_GATE | **PASS** | Real `nhk_v3_test` batch fixture created two independent Media identities under one batch context, normalized camera-style filenames, and produced no automatic relation. |
| PRODUCT_SPECIMEN_MEDIA_GATE | **PASS** | One Media and Asset were shared through separate Product and Specimen Usages; removing the Product Usage left Media, Asset and Specimen Usage intact. No Product–Specimen relation was inferred. |
| SEO_RUNTIME_GATE | **FAIL** | Projection excludes placeholders and carries contextual Media state, but returns `image_url=null`; the theme still renders native WP thumbnail output/`og:image` and has no proven MediaUsage-driven inline image, `srcset`, `sizes`, dimensions or structured-data image integration. |
| IMAGE_SITEMAP_RUNTIME_GATE | **FAIL** | Projection eligibility was proven false for placeholder and true for a real public asset, but no actual image-sitemap integration was found/proven. |
| FULL_TEST_GATE | **FAIL** | Fresh guarded run: `361 tests`, `14 errors`, `5 failures`, `1 warning`, `2 skips`. Errors/failures are classified below; this is not a pass. |
| DEPLOYMENT_GATE | **FAIL** | Runtime proof is mixed and generic WP REST bypass remains open; no deployment or production cutover was attempted. |
| LEGACY_REPAIR_GATE | **NO** | Explicitly not authorized by this phase. |

## Phase R2 local runtime recovery and root-cause checkpoint — 2026-09-02

The local runtime outage was investigated read-only after preserving the
uncommitted R2 worktree in `/private/tmp/nhk-r2-runtime-recovery-20260902/`.
The primary classification is **MYSQL_DAEMON_DOWN**. MySQL's error log records
`Received SHUTDOWN from user <via user signal>` at `2026-09-02T09:03:59Z`, a
clean shutdown at `09:04:00Z`, and Homebrew `mysqld_safe` restarting the same
datadir at `09:04:10Z`. The repeated `A mysqld process already exists` entries
show a duplicate/restart race around the service wrapper. No evidence supports
wrong port, socket mismatch, authentication failure, missing database,
WordPress configuration mismatch, PHP MySQL extension failure, or a second
active MySQL instance.

Current raw connectivity evidence:

- `mysqld` is running as PID `64761`, binary
  `/opt/homebrew/Cellar/mysql/9.7.1/bin/mysqld`, datadir
  `/opt/homebrew/var/mysql`.
- Homebrew service `mysql` is loaded/running; TCP listens on `127.0.0.1:3306`.
- The server reports socket `/tmp/mysql.sock` (the `/private/tmp` equivalent
  also passes); both TCP and socket authentication pass with the local
  WordPress configuration.
- WordPress `wp-config.php` resolves `DB_NAME=nhk_v3` (no
  `NHK_WP_TEST_DB` override), `DB_HOST=127.0.0.1`, default port `3306`,
  prefix `wp_`, and a defined empty password. Both `nhk_v3` and `nhk_v3_test`
  exist. PHP 8.5.7 exposes `mysqli`, `mysqlnd` and `pdo_mysql`.

Fresh recovery evidence:

| Gate | Result | Evidence / limitation |
|---|---|---|
| MYSQL_PROCESS | PASS | Homebrew `mysql` running; one `mysqld` listener on 3306 |
| TCP / SOCKET / AUTH / DATABASE | PASS | Direct `mysqladmin`, MySQL query and PHP mysqli probes pass |
| WORDPRESS_BOOTSTRAP | PASS | `public/wp-load.php` boots `NHK v3`; `$wpdb` uses `nhk_v3` |
| HTTP_HEALTH | PASS | `nhk/v1/health?details=1` returns 200; migration 12/12 and all five layers healthy |
| MCP_WIRE | PASS | `tools/mcp-wire-smoke.php --base-url=http://localhost` passes all checks and the governed 21-tool catalog contract |
| DEPLOYMENT_PREFLIGHT | PASS | 10/10 checks pass |
| R2_FOCUSED_TESTS | PASS | 110 tests / 871 assertions |
| NHK_UNIT | PASS | 265 tests / 1,355 assertions |
| FULL_GUARDED_TESTS | FAIL / CLASSIFIED | 364 tests: 14 retired V2-writer/malformed-asset errors, 5 existing route/identity failures, 1 receipt warning, 2 expected Post-55 skips; no DB bootstrap errors |
| FRONTEND_ROUTE_SMOKE | PARTIAL | 44/46 pass; `/knowledge/` returned 404 and Uncategorized metadata markers were absent |
| MIGRATION_012_SCHEMA | PASS | Mapping and Blueprint tables exist with expected columns; no migration was run against `nhk_v3` during recovery |

The configured guarded suite used only `nhk_v3_test` for destructive fixture
setup/cleanup. No R2 source file was changed during runtime recovery. No V2,
staging, production, legacy Post, Graph edge, semantic record, slug,
attachment, backfill, repair or publication operation was performed. The
preserved R2 worktree remains uncommitted; legacy media repair remains
**READY_FOR_LEGACY_MEDIA_REPAIR: NO**.

### Full-suite classification

The 14 errors are `PREEXISTING_FAILURE`: one P6 malformed-asset integration
case and thirteen V2 migration integration cases call the constitutionally
retired `V2MigrationService` writer, which throws the explicit retired-writer
exception. They are not an environment outage and were not repaired.

The five failures are also `PREEXISTING_FAILURE`: three MCP transport cases
call public REST routes with canonical UUIDs although the current routes use
stable keys/public slugs (and the public evidence route is not registered),
and two P5 Authority cases call the stable-key Entity route with a UUID. The
Phase R diff changes reader fields, not those route contracts. The one warning
is the associated P5 undefined-id symptom. The two skips are `EXPECTED_SKIP`
because `nhk_v3_test` has no published Post 55 fixture. Environment failures:
`0` in the completed 361-test run.

At the pre-R2 checkpoint, the repository smoke script still expected the
historical 19-tool catalog and the Article editorial adapter, public SEO
integration and bypass boundary remained open. The direct live wire request
already exposed the current 21-tool catalog; the R2 section below records the
implementation and current live-proof limitation.

## Media Phase R2 — WordPress editorial bridge implementation — 2026-09-02

The R2 implementation adds the canonical Media/MediaAsset to WordPress
attachment mapping, controlled attachment creation with pre-upload filename
normalization, idempotent featured and managed inline-primary projection,
reverse native-attachment adoption, native post/REST/admin lifecycle reconciliation,
Article Ingest state refresh after media writes, contextual SEO image metadata,
and an image sitemap provider. The MCP smoke contract is now the governed
21-tool catalog.

Fresh static evidence: the full Unit suite is `265 tests / 1,355 assertions`;
Composer validation, PHP lint, diff checks and changed-file secret review pass.
The WordPress preflight and focused integration path are blocked before test
execution by the current local database bootstrap (`Error establishing a
database connection`), so live migration, REST lifecycle, SEO and sitemap
proof is unavailable in this checkpoint. No database mutation was performed.

| Gate | Result | Evidence / limitation |
|---|---|---|
| WORDPRESS_MEDIA_ATTACHMENT_BRIDGE | **PASS (static/unit)** | Contract, bridge, mapping migration 012 and coordinator adapter coverage are present. |
| WORDPRESS_FEATURED_INLINE_SYNC | **BLOCKED (live proof)** | Native write hooks and CAS-protected synchronize path are implemented; WP runtime is unavailable. |
| WORDPRESS_REVERSE_ADOPTION | **BLOCKED (live proof)** | `add_attachment` adoption path and recursion guard are implemented; WP runtime is unavailable. |
| ARTICLE_INGEST_STALE_CONFLICT | **PASS (unit/static)** | Editorial token is checked before media work and refreshed after the own media write. |
| WORDPRESS_NATIVE_WRITE_ENFORCEMENT | **PASS (static)** | Post/REST lifecycle hooks are wired; no generic MCP WordPress writer is catalogued. |
| SEO_IMAGE_SITEMAP | **PASS (static/unit), BLOCKED (live proof)** | Projection and provider are implemented with placeholder/unmapped filtering; runtime proof is unavailable. |
| MCP_CATALOG | **PASS (static/unit)** | Smoke contract is exact 21 names, with no `--apply` transport bypass. |
| FULL_TEST_GATE | **FAIL / ENVIRONMENT BLOCKED** | Current local WP bootstrap cannot establish its database connection; do not treat this as a clean full-suite pass. |
| DEPLOYMENT_READY | **NO** | No production/staging/V2 mutation or deployment was attempted. |
| READY_FOR_LEGACY_MEDIA_REPAIR | **NO** | Explicitly outside R2 scope. |

## Video V3 semantic ingestion checkpoint — 2026-09-03

This checkpoint adds the YouTube-first Video semantic intake slice while
preserving the Constitution as the only normative authority. The work is
source/projection/governance code only: no YouTube import, legacy migration,
WordPress Post write, production/staging write, Graph backfill, slug repair or
publication was performed.

| Gate | Result | Evidence / limitation |
|---|---|---|
| VIDEO_CONSTITUTION | **PASS (static)** | Video Law amendment and detailed §13.2 invariants cover external identity, source/editorial separation, no fabricated transcript, evidence-backed relations, completeness, reconciliation and unavailable-source history. |
| VIDEO_SOURCE_IDENTITY | **PASS (unit)** | YouTube watch, short, embed, short-host and playlist-with-video forms normalize to one external ID; tracking parameters do not duplicate identity; arbitrary hosts and malformed IDs fail closed. |
| YOUTUBE_ADAPTER | **PASS (unit/static)** | Official Data API adapter boundary, bounded timeout, API-key environment configuration, source snapshot normalization, unavailable/rate-limit/error classification and deterministic source hash are implemented. |
| TRANSCRIPT_AND_CHAPTER_POLICY | **PASS (unit/static)** | Default is `NO_TRANSCRIPT`; authorized/user-supplied transcript kinds are explicit; timestamp chapters require increasing source-description evidence and are never fabricated. |
| VIDEO_INTAKE | **PASS (unit/static)** | One-shot enriched `nhk.video.ingest` builds a source, research, relation, Hub, editorial, SEO, completeness and ambiguity packet, then creates one governed Proposal. Existing external identity selects update/reconcile mode. |
| VIDEO_ORPHAN_GATE | **PASS (unit/static)** | Every Video ingest apply rejects incomplete packets, missing semantic attachments and missing Graph executor; category is not an attachment. Approved attachments are created through Graph in the governed apply path. |
| VIDEO_RELATIONS | **PASS (unit/static)** | Planner requires canonical UUID, registered predicate, explicit/inferred origin and evidence; public related traversal is bounded to two hops, direct-first and non-materialized. Direction/path explanation convergence remains open. |
| VIDEO_HUB | **PASS (unit/static)** | Eight fixed editorial/navigation Hub keys have one primary and optional evidence-based secondary results; Hub classification is not a Graph relation or WordPress taxonomy. |
| EDITORIAL_AND_SEO | **PASS (unit/static)** | NHK editorial package is distinct from source description; canonical SEO projection emits visible-content VideoObject, Open Graph and evidence-backed Clip parts; unavailable/incomplete videos emit no VideoObject. |
| WATCH_AND_SITEMAP | **PASS (static/unit)** | Watch projection supports unavailable-source state, source attribution, editorial content and related sections; video sitemap route is rewrite-registered and includes only active, available, indexable entries with HTTPS thumbnails. Live public data coverage remains unproven. |
| SEARCH_AND_HOME_PROJECTION | **PASS (unit/static)** | Video discovery uses editorial/source fields, Hub and resolved canonical subject context, while unavailable sources are excluded from normal discovery. |
| VIDEO_SYNC | **PASS (unit/static)** | Read-only comparison reports `NO_CHANGE`, `SOURCE_CHANGED`, `SOURCE_UNAVAILABLE` or `REVIEW_REQUIRED`; source changes do not overwrite NHK editorial fields or Graph relations. A separate MCP sync tool is not yet exposed. |
| MCP_GOVERNANCE | **PASS (unit/static)** | Enriched MCP video intake is governed and returns a single Proposal preview; it does not approve, apply or publish. Legacy-shaped calls retain the old input shape but cannot apply without semantic attachments. |
| NHK_UNIT | **PASS** | `281 tests / 1,411 assertions`. |
| COMPOSER_AND_LINT | **PASS** | `composer validate --no-check-publish` valid with existing license warning; new/changed PHP files lint clean. |
| DIFF_CHECK | **PARTIAL** | Our implementation checks are clean; repository-wide `git diff --check` reports one trailing-space line in the concurrently modified user file `docs/semantic-packs/odo/ODO_RUNTIME_INVENTORY.md`. |
| WORDPRESS_INTEGRATION | **BLOCKED** | Guarded integration run cannot bootstrap `$wpdb` without `NHK_WP_TEST_PATH`/WordPress database setup; observed 8 bootstrap errors, 12 mandatory-runtime failures and 74 skips. No runtime data was mutated. |
| MCP_WIRE_SMOKE | **BLOCKED** | No live wire probe was run in this checkpoint because the WordPress bootstrap/runtime endpoint is unavailable. Prior execution state records the earlier 21-tool smoke evidence; the enriched workflow still requires a live probe. |
| DEPLOYMENT | **NO** | No production/staging/V2 deployment, cutover or data write was attempted. |
| COMMIT | **PENDING** | Logical commit is still subject to the managed filesystem's Git ref/index-lock permission gate; no push or merge was attempted. |

Remaining `IMPLEMENTATION_GAP`: expose a dedicated governed sync-preview MCP
operation, converge direction-aware/path-explainable related results and live
runtime/wire-probe the enriched YouTube intake with configured API/database.
Album/Collection support remains a registry gap and was not invented. There is
no unresolved `CONSTITUTION_CONFLICT` in this checkpoint.

## Odo V3 governed namespace rekey checkpoint — 2026-09-03

Added the generic governed Authority `rekey` operation. It preserves the
canonical UUID and semantic payload/name/state, requires an exact old stable
key plus optimistic revision, atomically updates the scoped stable key in the
repository, increments revision once, and records old/new keys in the audit
context. MCP/admin/article preflight operation registries are aligned. This is
implementation capability only: no Odo, demo, V2, staging or production rows
were read for mutation or changed. Demo runtime inventory remains a mandatory
precondition before any Odo proposal apply.

## Odo generic merge continuation checkpoint — 2026-09-03

Completed the local generic merge wiring slice without mutating any runtime.
`ControlledApplyService` now receives a `SemanticMergeService` configured with
the registered Graph reference adapter and the durable audit-backed receipt
repository. Merge proposal eligibility checks source and target revisions from
the merge payload independently; it no longer compares the target against the
source revision. Receipt serialization now exposes the required snake-case
contract fields while retaining backward-compatible properties. Focused merge
and governance tests pass: 10 tests / 44 assertions.

The reference-surface audit records that Knowledge, Source, Evidence,
MediaUsage and Video do not own direct Authority UUID references. Their
movement is therefore `NOT_APPLICABLE`; Graph remains the single association
movement authority, and `wp_post` is Graph-only with editorial read-back.
The matrix is `docs/semantic-packs/odo/ODO_SEMANTIC_REFERENCE_SURFACE_MATRIX.md`
and the future no-mutation cutover packet is
`docs/semantic-packs/odo/ODO_DEMO_GOVERNED_APPLY_PLAN.md`.

No demo, V2, staging, production, WordPress Post, semantic record, Graph edge,
proposal or database row was changed. Unrelated concurrent worktree changes
remain unmodified and unstaged.

## Odo generic capability and authenticated-read gate — 2026-09-03

The generic rekey/merge slice is complete in the current worktree. Graph
incoming and outgoing movement now verifies retired source edges correctly and
retains an active target triple; direct adapter coverage includes move and
dedupe cases. Focused Odo gates pass: 42 tests / 223 assertions, changed PHP
lint passes, Composer validation passes with the existing no-license warning,
and `git diff --check` is clean. The approved surface matrix is authoritative:
Knowledge, Source, Evidence, MediaUsage and Video are `NOT_APPLICABLE` for
direct Authority merge adapters, and `wp_post` is `GRAPH_ONLY`.

The demo public read evidence is retained, but the required administrator
Graph inbound/outbound read could not be authenticated in this runner. The
project contains no deploy or demo admin credential/configuration, and no
secret was printed or committed. The exact stop is
`DEMO_ADMIN_SEMANTIC_CREDENTIAL_REQUIRED`; do not create proposals from
unverified revisions, deploy without the standard deployment authority, or
perform controlled apply. No demo, staging, production, V2, WordPress Post,
semantic record, Graph edge or proposal was mutated.

## Article Research preflight gate closure attempt — 2026-09-03

Added `PublicEndpointEligibilityResolver` as the shared route/readiness
boundary for Article Research links. It fail-closes malformed identity,
inactive, not-ready, private/hidden, unavailable dependency and missing public
route states. The registered endpoint audit covers `wp_post`, all nine
Authority families, `media`, `video`, `knowledge`, `source` and `evidence`;
no UUID or stable-key URL is synthesized.

Fresh focused evidence: 19 tests / 79 assertions pass, including table-driven
public/inactive/private/draft/invalid/no-route/unavailable cases. PHP lint and
`git diff --check` pass. The explicit matrix is
`docs/architecture/ARTICLE_RESEARCH_ACCEPTANCE_MATRIX.md`.

Integration diagnosis is conclusive for this runner: `NHK_WP_TEST_PATH=public`
is correct, but WordPress bootstrap returns `Error establishing a database
connection` before `TestDatabaseGuard` can verify the exact `nhk_v3_test`
database. Capability remains `PARTIAL` /
`IMPLEMENTATION_READY_RUNTIME_UNVERIFIED`; runtime READY must not be claimed
until guarded integration evidence is available. No credentials, service,
development database, Post, taxonomy, semantic record, Graph edge, proposal,
Media, Video or live data was mutated.

## WordPress Category and Editorial Draft Gateway checkpoint — 2026-09-03

Added two independently reviewable typed application boundaries. Category
Gateway delegates to native WordPress taxonomy storage and provides
deterministic ID/slug/exact-name resolution, conflict detection, idempotent
create, parent validation, fingerprint CAS update, assignment/unassignment
and guarded delete. Editorial Draft Gateway delegates to native `wp_posts`,
reuses the existing Article operation receipt repository, resolves retries
before creating, never stores Article body in receipts, requires native
state-token CAS for updates, and returns `DRAFT_INCOMPLETE_FOR_PUBLICATION`.
Neither boundary publishes, trashes, mutates semantic/Graph data or ingests
Media/Video.

Fresh evidence: focused gateway/MCP tests `16 tests / 142 assertions`; full
Unit suite `322 tests / 1,577 assertions` with one existing PHPUnit deprecation;
Composer validation and changed-PHP lint pass. The typed MCP catalog now has
30 tools and the capability manifest exposes Category and draft-only Article
operations. No WordPress Ability bridge was added because the existing custom
MCP transport is the client surface and adding a second write path would
duplicate the application boundary.

Guarded integration was attempted with `NHK_WP_TEST_PATH=public`; WordPress
bootstrap returned `Error establishing a database connection` before exact
`nhk_v3_test` verification. Both slices therefore remain
`IMPLEMENTATION_READY_RUNTIME_UNVERIFIED`; runtime READY is not claimed. No
development, staging, production, V2, Post, taxonomy, semantic record, Graph
edge, proposal, Media or Video data was mutated.

## Article publication eligibility boundary checkpoint — 2026-09-03

Added the read-only `ArticlePublicationGate` and explicit
`ArticlePublicationGateResult` in commit `f7fc96e`. The gate consumes verified
evidence from existing bounded contexts and requires a current draft state
token, canonical public identity, acceptable research, resolved subject and
category, completed semantic plan/read-back, complete real-image MediaUsage,
claim compliance, SEO, internal links, structured data and public route
readiness. It returns explicit blocker codes; it does not publish, mutate
WordPress, invoke Governance, create semantic records or infer Graph truth.

Focused evidence is 3 tests / 11 assertions; the full Unit suite is 325 tests /
1,588 assertions with one existing PHPUnit deprecation. Composer validation,
changed-file PHP lint, diff check and secret review pass. This checkpoint is
`IMPLEMENTATION_READY_RUNTIME_UNVERIFIED`: the native publish writer,
publication-evidence binding, uncertain-result recovery and rendered
read-back remain open. Existing uncommitted `V3_EXECUTION_STATE.md` and
`ARTICLE_RESEARCH_ACCEPTANCE_MATRIX.md` changes were preserved and are not
part of the checkpoint commit.

## Article native publication writer and reversible lifecycle checkpoint — 2026-09-03

Added the native WordPress `EditorialDraftGateway` publication writer and
reversible trash/restore operations. Publish requires the current draft
state-token, an explicit evidence packet accepted by `ArticlePublicationGate`,
then performs one native status transition and reads the Post back. All three
operations use the existing body-free Article receipt repository for
idempotency and reject mismatched request fingerprints; trash/restore never
perform permanent deletion. MCP catalog, transport and capability manifest
now advertise the typed Article lifecycle operations with the same
`nhk_ingest_articles` capability.

Fresh evidence: focused writer/gate/contract tests `16 tests / 147
assertions`; full Unit suite `327 tests / 1,600 assertions`, with one existing
PHPUnit deprecation. Changed PHP lint and `git diff --check` pass. Exact
`nhk_v3_test` integration remains unavailable because WordPress bootstrap
fails with `Error establishing a database connection` before
`TestDatabaseGuard` can verify the database. Rendered public read-back,
publication evidence persistence and uncertain post-transition recovery remain
open follow-up gaps; no Post, taxonomy, semantic record, Graph edge, Media,
Video or live data was mutated.

## Final-completion continuation runtime recheck — 2026-09-03

The exact guarded integration attempt was repeated with
`NHK_WP_TEST_PATH=public`; WordPress terminated during bootstrap with
`Error establishing a database connection`, before `TestDatabaseGuard` could
verify `nhk_v3_test`. This is an infrastructure/runtime block, not an
integration PASS. No fallback to `nhk_v3` was used.

The publication writer slice is committed as `d94f3f7`; the broader final
completion plan remains open. In particular, rendered Article SEO/public
verification, durable publication-evidence binding and uncertain-result
recovery, complete Admin parity, standalone Graph read exposure, and the
remaining Article/MediaUsage acceptance scenarios are not claimed complete.

## Final-completion CP2/CP3 rendered verification checkpoint — 2026-09-03

Added `RenderedArticleVerifier` and `RenderedArticleVerificationResult` for
actual public HTML. The verifier distinguishes `unavailable_runtime` from a
rendered public route and records field-level results for title, H1,
permalink/canonical, meta description, robots/indexability, category, internal
links, featured/inline media, contextual alt/caption, related content,
structured data, claim compliance, semantic readiness and Media completeness.
`ArticlePublicationGate` now requires `rendered_public_verification`; stored
DTO evidence alone cannot satisfy publication.

Article receipts now expose durable, body-free `publication_evidence`, persisted
through the existing diagnostics column and redacted for editorial body keys.
The native publication writer read-backs after uncertain transitions and records
`PUBLICATION_RESULT_UNCERTAIN` as retryable when the final state cannot be
verified, preventing duplicate publication action on retry.

## DEMO cutover infrastructure design checkpoint — 2026-09-03

Approved design written to
`docs/superpowers/specs/2026-09-03-demo-cutover-infrastructure-design.md`.
The design defines a thin shell, generic PHP orchestration, explicit
`demo.1945.vn` allowlisting, deterministic `nhk-core` deployment, authenticated
runtime preflight, live-revision proposal planning, human approval before
Controlled Apply, read-back and redacted evidence. Implementation is approved
but real DEMO deployment/cutover remains explicitly out of scope.

Focused evidence: 12 tests / 40 assertions pass, with one existing PHPUnit
deprecation. Full suite: 426 tests, 1625 assertions, 8 integration errors and
12 guarded-acceptance failures caused by unavailable WordPress bootstrap/DB;
no Unit regression was observed. Local MySQL restart was attempted, but the
service exits with a stale `mysqld_safe`/existing-process condition and remains
unreachable on 127.0.0.1:3306 and `/tmp/mysql.sock`. Exact `nhk_v3_test` was
not verified; status remains `RUNTIME_VERIFICATION_BLOCKED`. No fallback to
`nhk_v3` and no live data mutation occurred.

## DEMO cutover infrastructure implementation checkpoint — 2026-09-03

Implemented the generic `DemoCutoverRunner`, typed cutover context/results,
redacted evidence helper, generic port bundle, safe repository-local adapters,
PHP command and thin executable shell at `scripts/nhk-demo-cutover`. The exact
requested invocation reaches the local safety boundary and stops with
`REMOTE_DEPLOYMENT_ADAPTER_UNAVAILABLE`; no remote deployment, WordPress,
semantic, Graph or live data mutation occurred. Unknown packs stop with
`PACK_MANIFEST_UNAVAILABLE`, and the runner apply path rejects missing or
mismatched approval fingerprints before Controlled Apply.

Focused evidence: 7 Demo tests / 24 assertions pass; Composer validation,
changed-file PHP lint and `git diff --check` pass. Full PHPUnit executed 438
tests / 1,675 assertions with 8 existing WordPress bootstrap/database errors,
14 existing media/acceptance failures and 74 skips; no Demo test failed. Live
authenticated runtime, remote deploy adapter, Governance wiring and real DEMO
cutover remain intentionally unverified and out of scope for this task.

## DEMO remote deployment adapter checkpoint — 2026-09-03

Root-cause tracing found that the previous `LocalCutoverAdapters` composition
hard-coded the deploy port to `REMOTE_DEPLOYMENT_ADAPTER_UNAVAILABLE`; no
concrete adapter, transport dependency, resolver or external target
configuration existed. The local repository and Mac configuration contained no
reusable rsync/scp/deploy primitive and no `~/.ssh/config` alias.

The deploy port now resolves `RemoteDeploymentAdapter`, a generic target-
allowlisted adapter. Its external contract is one environment key,
`NHK_DEMO_DEPLOY_CONFIG`, pointing to a non-Git INI file with
`ssh_target=demo.1945.vn`, `remote_path=<plugin destination>`, and either a
readable `ssh_key=<path>` or an available `SSH_AUTH_SOCK`. It transfers only
`public/wp-content/plugins/nhk-core/` via deterministic checksum rsync, excludes
tests and secret-like files, performs no database or semantic operation, and
verifies `nhk-core.php` remotely. Failures are classified as configuration,
credential, transport or verification failures without emitting command output.

Focused evidence: 11 Demo/deployment tests / 38 assertions pass, with one
existing PHPUnit deprecation. The requested human command now resolves the
adapter and stops at `REMOTE_DEPLOYMENT_CONFIG_REQUIRED` because the external
config is absent; it no longer emits `REMOTE_DEPLOYMENT_ADAPTER_UNAVAILABLE`.
No remote deployment, WordPress, semantic, Graph or live data mutation was
performed. Full-suite classification remains the existing WordPress
bootstrap/database errors and guarded integration failures; no regression from
this adapter was observed.

## Continuation verification checkpoint — 2026-09-03

The requested continuation was read-only where external prerequisites were
missing. The old Video Proposal `01a065d5-a7e0-7092-a798-2decd42213b5` remains
untouched; no new Video ingest, Proposal, approval or Apply was attempted.
The current Video contract permits only registered outbound `about` semantic
attachments and does not permit `depicts` for Video intake. The required
YouTube API key is not present in this shell, and the new demo deployment is
blocked by `REMOTE_DEPLOYMENT_CONFIG_REQUIRED`, so no live Video receipt can be
claimed.

The local catalog has 33 tools and 32 Ability mappings. The exact intentional
exclusion is `nhk.media.ingest` because its canonical transport is multipart
binary and WordPress Ability input cannot carry the file part. All other 32
tools map one-to-one, including `nhk.proposal.eligibility` →
`nhk-v3/proposal-eligibility`. The live demo Abilities Browser is stale: it
shows 32 abilities including `nhk-v3/media-ingest` and does not show
`nhk-v3/proposal-eligibility`.

The deployed revision could not be verified from the live page. A controlled
canonical test URL `/anh/dong-ho-co-mat-kinh-cuong-hinh-kim-cuong.webp` still
renders the demo 404 page, therefore no HTTP 200 or `image/webp` claim is made.
Local focused coverage passes 36 tests / 252 assertions; full Unit execution
completes with 8 existing WordPress bootstrap errors, 12 guarded integration
failures, 74 skips and 1 deprecation. Composer validation and `git diff
--check` pass. No code, domain/backend, semantic data, WordPress data or
external deployment was mutated.

## DEMO remote runtime adapter continuation — 2026-09-03

The cutover transport now includes `RemoteRuntimeAdapter`, which invokes only
the versioned `nhk-core/bin/nhk-core-maintenance.php` entrypoint over the
allowlisted SSH target. The entrypoint loads the real WordPress boundary at
the remote `public/wp-load.php`, supports only `health`, `inventory`,
`dry-run`, `backup/snapshot`, `governance-plan`, `controlled-apply` and
`read-back`, and rejects unknown operations without transport. Health and
inventory are read-only; snapshot output is written outside the public root
with a SHA-256 receipt. No SQL/eval/arbitrary WordPress mutation interface is
exposed.

`LocalCutoverAdapters` now routes `verify` to remote health and `preflight` to
remote inventory after deployment. Focused Demo/runtime coverage passes 14
tests / 48 assertions; Composer validation, PHP lint and `git diff --check`
pass; the changed-file secret review found no credentials or private keys.
The requested command still stops before transport with
`REMOTE_DEPLOYMENT_CONFIG_REQUIRED` because no external deployment config is
present. Full PHPUnit completes with 460 tests, 8 pre-existing WordPress
bootstrap errors, 12 mandatory integration failures, 74 skips, 1 warning and
1 deprecation. No remote deployment, inventory, snapshot, proposal, approval,
apply, WordPress data or semantic data was changed. Governance wiring for the
remaining remote planning/apply operations and the constitutional human
cutover gate remain required before any demo mutation can be considered.

## Odo integrity prevention continuation — 2026-09-03

Added `SemanticRekeyMediaIsolation` and wired the Governance rekey executor to
reject WordPress attachment/path fields. Semantic `o-do` → `odo` operations
remain limited to Authority identity and relations; they cannot rename media
files or attachment metadata. Added the read-only reusable
`tools/odo-media-integrity-audit.php`, covering attachment metadata,
derivatives, upload files, inline URLs, featured IDs and canonical/legacy
path classifications. Regression coverage includes the #83/#86 mismatch,
reverse mismatch, both-variant collision, missing derivative and orphan file.

Fresh live read-only evidence still shows two active component collisions,
both revision 1: the pinned pair is owner-confirmed and the glued pair remains
`MANUAL_IDENTITY_DECISION_REQUIRED`. No live merge was applied in this turn;
the external mutation remains behind the trusted human-authorization gate.
