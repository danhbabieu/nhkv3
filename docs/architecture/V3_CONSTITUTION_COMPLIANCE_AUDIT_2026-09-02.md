# NHK V3 Constitution Compliance Audit — 2026-09-02

> **NON-NORMATIVE.** The sole normative source is
> `docs/constitution/NHK_V3_CONSTITUTION.md`.

## Scope and evidence boundary

This is a documentation-only audit. The full Constitution was read from the
current working tree, including its 19 acceptance invariants in §26. The
Constitution commit audited is `857fb44af72e3e3c28827c04bcdb3800fe7f2522`;
the file itself was not modified. The audit indexed 34 deterministic,
non-normative law IDs across source-of-truth, registries, storage, data,
queries, writes, public projection, tests, runtime and operations.

The audit inspected 179 tracked NHK Core PHP source files and 59 tracked test
files. Concurrent Article Ingest source/tests and the untracked MCP plan were
observed but not staged or changed by this audit. Historical execution-state
counts are labelled historical; no data count below is represented as freshly
verified unless explicitly marked.

Fresh checks performed:

- `composer validate --no-check-publish`: passed, with the existing missing
  license warning.
- Unit PHPUnit suite: passed, `213 tests`, `1150 assertions` on the current
  concurrent working tree.
- `composer preflight`: failed 5 of 10 checks because WordPress bootstrap,
  NHK Core bootstrap, schema migration, authority hydration capability and REST
  bootstrap could not establish the local runtime.
- Guarded full PHPUnit invocation: WordPress returned a database-connection
  error before a valid PHPUnit result.
- Structural diagnostics and Graph distribution audit: blocked by
  `WORDPRESS_DATABASE_UNAVAILABLE`.
- MCP wire smoke and frontend route smoke: blocked because localhost:80 was
  unavailable.

Consequently, runtime and physical database claims are evidence gaps, not
passes. The audit itself performed no insert, update, delete, migration,
Controlled Apply, Graph write, WordPress content write or legacy-body import.

## Status taxonomy

The statuses below use the requested exact taxonomy. The law IDs are audit
references only and do not amend the Constitution.

## Master compliance matrix

| LAW ID | CONSTITUTION SECTION | LAW SUMMARY | CODE OWNER | STORAGE OWNER | DATA STATE | TEST EVIDENCE | RUNTIME EVIDENCE | STATUS | SEVERITY | ACTION | DEPENDENCIES |
|---|---|---|---|---|---|---|---|---|---|---|---|
| C01 | §2–§3 | WordPress `wp_posts` owns editorial truth and URLs | WordPress/theme integration | WordPress posts | Not freshly verified | WordPress boundary tests | Bootstrap unavailable | COMPLIANT | P1 | Keep editorial/semantic boundary | Runtime |
| C02 | §3 | Authority owns canonical semantic entity identity | Authority catalog/repositories | Authority tables | Historical rows only | Authority unit tests | Hydration unavailable | COMPLIANT | P1 | Preserve registry boundary | Runtime |
| C03 | §3 | Knowledge owns atomic claims, not articles | Knowledge domain/services | Knowledge tables | Not freshly verified | Knowledge tests | Unavailable | COMPLIANT | P1 | Keep claims separate | Governance |
| C04 | §3 | Source/Evidence owns provenance and support | Source/Evidence domain | Source/evidence tables | Not freshly verified | Evidence serializer tests | Unavailable | PARTIAL | P1 | Prove claim-support reads | Runtime |
| C05 | §3 | Graph is the single relation system | Graph service/repository | Graph tables | Historical distribution only | Graph service tests | DB unavailable | CONSTITUTION_CONFLICT | P0 | Remove direct semantic write bypasses | Governance |
| C06 | §3 | Governance owns durable semantic mutation | Controlled Apply/executors | Proposal/audit tables | Not freshly verified | Governance tests | Unavailable | CONSTITUTION_CONFLICT | P0 | Close direct mutation paths | C05 |
| C07 | §3 | Media, asset and usage retain separate boundaries | Media services | Media tables | Not freshly verified | Media domain tests | Unavailable | COMPLIANT | P1 | Add publication proof | Runtime |
| C08 | §3 | Video is an external canonical reference | Video service | Video table | Not freshly verified | Video tests | Unavailable | COMPLIANT | P1 | Keep external-reference model | Runtime |
| C09 | §4–§5 | Only registered Authority types are canonical | `CanonicalEntityTypeCatalog` | Authority storage | Registry has 9 types | Catalog tests | Unavailable | COMPLIANT | P1 | Lock registry tests | None |
| C10 | §5 | Model has one `model_of` Brand parent | Graph/structural context | Graph + Authority | Historical 30 Model findings | Cardinality tests | Diagnostics unavailable | PARTIAL | P0 | Diagnose and gate repair | C05, runtime |
| C11 | §5 | Variant has one `variant_of` Model parent | Graph/structural context | Graph + Authority | Historical 42 Variant findings | Cardinality tests | Diagnostics unavailable | COMPLIANT | P0 | Prove current state before repair | C10 |
| C12 | §5 | No canonical Variant→Brand shortcut | Graph validator/readers | Graph | Physical count unverified | No complete shortcut test | Unavailable | CONSTITUTION_CONFLICT | P0 | Remove payload-as-truth behavior | C10 |
| C13 | §5 | Unknown predicates/endpoints fail closed | `PredicateRegistry`, `GraphService` | Graph | Not freshly verified | Graph negative tests | Unavailable | COMPLIANT | P0 | Retain fail-closed validation | None |
| C14 | §6 | Movement/music scopes remain independent | Graph predicates/readers | Graph | Not freshly verified | Predicate tests partial | Unavailable | PARTIAL | P1 | Add scope matrix tests | C10 |
| C15 | §7 | Component/classification meanings stay distinct | Authority payloads/queries | Authority | Not freshly verified | Domain separation tests | Unavailable | PARTIAL | P1 | Add negative promotion tests | C09 |
| C16 | §8 | Specimen is one concrete physical object | Specimen domain | Authority table | Historical count 0 only | Separation test | Unavailable | COMPLIANT | P0 | Confirm product decision | C17 |
| C17 | §8 | Product is a listing/offer, not physical identity | Product domain | Authority table | Historical count 0 only | Class separation test | Unavailable | COMPLIANT | P0 | Confirm lifecycle contract | C16 |
| C18 | §8 | Product/Specimen ownership is unambiguous | Product/Specimen payloads | Authority storage | Current rows unverified | No lifecycle matrix | Unavailable | CODE_GAP | P0 | Human architecture gate before implementation | C16, C17 |
| C19 | §9 | Public identity needs durable UUID/key/slug/history boundaries | Public identity/query services | No public identity repository found | Persistence gap indicated | No rename/history test | Unavailable | PUBLIC_IDENTITY_STORAGE_GAP | P1 | Add governed identity storage | C05, C06 |
| C20 | §9 | Public projection must not leak internal IDs | Serializers/routes | Public responses | Static leak paths present | No complete parity test | Unavailable | CONSTITUTION_CONFLICT | P0 | Remove UUID/stable-key public exposure | C19 |
| C21 | §10 | One eligibility policy governs every public surface | Eligibility/query services | N/A | Convergence unproven | Partial reader tests | HTTP unavailable | CODE_GAP | P1 | Route all surfaces through policy | C19 |
| C22 | §10 | Route hierarchy derives from canonical Graph context | Route/structural queries | No durable slug history | Transitional payload parent | Route tests partial | HTTP unavailable | PARTIAL | P1 | Make Graph context authoritative | C10, C19 |
| C23 | §10–§11 | Hub/menu/SEO routes are Vietnamese canonical routes | Theme/public query | WordPress/theme | Runtime unverified | Theme static tests | HTTP unavailable | PARTIAL | P1 | Run live route matrix | C21, runtime |
| C24 | §12 | Semantic writes use Proposal→Approval→Apply→Audit | Governance services | Proposal/audit tables | Direct bypasses present | Governance happy paths | Runtime unavailable | CONSTITUTION_CONFLICT | P0 | Enumerate and close bypasses | C05, C06 |
| C25 | §13 | MCP reads/writes honor domain and Governance contracts | MCP handlers/contracts | Domain stores | 19 declared tools, live unverified | MCP unit tests | Wire smoke unavailable | PARTIAL | P1 | Add permission/diagnostic parity | C21, C24 |
| C26 | §14 | Infrastructure failures cannot become empty data | Graph/aggregation/context readers | N/A | Static broad catches found | No fail-loud reader tests | Runtime unavailable | CONSTITUTION_CONFLICT | P0 | Remove silent `Throwable` fallbacks | C21 |
| C27 | §15 | Article Ingest preserves editorial/semantic sequence | Article contract/coordinator | WP revision + receipt needed | Concurrent implementation in flux | Receipt/unit evidence partial | Runtime unavailable | PARTIAL | P1 | Reconcile coordinator contract | C24 |
| C28 | §15 | No Article entity/Album invention without law | Article/MCP contracts | N/A | No Album registry type | No Album requirement test | Unavailable | PARTIAL | P2 | Keep Album out; model grouping explicitly if needed | C07, C27 |
| C29 | §16 | Storage/runtime/hydration/application/REST failures stay distinct | Health/preflight | N/A | Checks exist, runtime fails | Health tests | Preflight failed | PARTIAL | P0 | Add failure-path proof | C26 |
| C30 | §17 | Existing data must be assessed without unauthorized repair | Diagnostics/migrations | Existing DB | Historical state only | Guard tests | DB unavailable | DATA_COMPATIBILITY_GAP | P0 | Recalculate read-only candidates | C10 |
| C31 | §18 | Deployment proves runtime, schema, hydration and REST | Composer/preflight | Config/schema | Local runtime unavailable | Preflight assertions | 5/10 failed | RUNTIME_EVIDENCE_GAP | P1 | Restore runtime evidence | C29 |
| C32 | §19–§25 | High-risk constitutional invariants have meaningful tests | PHPUnit suites | N/A | 197 unit tests pass | Several parity gaps | Runtime unavailable | TEST_GAP | P1 | Add negative/parity tests | C10, C19, C24, C26 |
| C33 | §3, §7 | Album is not required semantic type absent constitutional law | MCP/media grouping | N/A | No registry type | N/A by design | Unavailable | COMPLIANT | P2 | Do not invent Album | C07 |
| C34 | §20–§26 | Frontend uses Vietnamese-first accessible editorial projection | NHK theme | WordPress/theme | Static compliance partial | Theme tests limited | Route runtime unavailable | PARTIAL | P2 | Verify live empty/error/responsive states | C21, C23 |

Status count: `COMPLIANT 11`, `PARTIAL 11`, `CODE_GAP 2`,
`REGISTRY_GAP 0`, `STORAGE_GAP 0`, `PUBLIC_IDENTITY_STORAGE_GAP 1`,
`DATA_COMPATIBILITY_GAP 1`, `CONSTITUTION_CONFLICT 6`, `TEST_GAP 1`,
`RUNTIME_EVIDENCE_GAP 1` — total 34 laws. `IDENTITY_CONFLICT`,
`RELATIONSHIP_CONFLICT` and `DEPLOYMENT_GAP` were not assigned because the
available evidence did not prove those narrower categories.

## Subsystem scorecards

Counts below are counts of the 34 audit laws touching the subsystem, not
percentages and not a measure of data quality.

| SUBSYSTEM | COMPLIANT | PARTIAL | GAP | CONFLICT | HIGHEST | NEXT PHASE |
|---|---:|---:|---:|---:|---|---|
| Authority | 1 | 2 | 0 | 0 | P1 | Phase 1 |
| Graph | 2 | 1 | 0 | 2 | P0 | Phase 0 |
| Brand Backbone | 1 | 1 | 0 | 1 | P0 | Phase 0 |
| Movement/Music | 0 | 1 | 0 | 0 | P1 | Phase 2 |
| Component/Classification | 0 | 1 | 0 | 0 | P1 | Phase 2 |
| Specimen/Product | 2 | 0 | 1 | 0 | P0 | Phase 0 decision gate |
| Knowledge | 1 | 0 | 0 | 0 | P1 | Phase 5 |
| Source/Evidence | 0 | 1 | 0 | 0 | P1 | Phase 5 |
| Media | 1 | 0 | 0 | 0 | P1 | Phase 5 |
| Video | 1 | 0 | 0 | 0 | P1 | Phase 5 |
| WordPress/editorial | 1 | 1 | 0 | 0 | P1 | Phase 4 |
| Identity | 0 | 0 | 1 | 1 | P0 | Phase 1 |
| Eligibility/routing/SEO | 0 | 3 | 1 | 0 | P1 | Phase 3 |
| Governance | 0 | 0 | 0 | 2 | P0 | Phase 0 |
| MCP | 0 | 1 | 0 | 0 | P1 | Phase 3 |
| Article Ingest | 0 | 1 | 0 | 0 | P1 | Phase 4 |
| Health/deployment | 0 | 2 | 0 | 1 | P0 | Phase 0 |
| Frontend | 0 | 1 | 0 | 0 | P2 | Phase 5 |

## Brand Backbone and Graph

The runtime `CanonicalEntityTypeCatalog` contains exactly nine Authority
types: `brand`, `model`, `variant`, `movement`, `music`, `component`,
`classification`, `specimen`, and `product`. The runtime `PredicateRegistry`
contains eight predicates:

| PREDICATE | FROM → TO | CARDINALITY | SCOPE | VALIDATION / GOVERNANCE |
|---|---|---|---|---|
| `about` | all registered endpoints → all registered endpoints | many/many | claim/editorial association | registry + Graph service; Governance required for semantic write |
| `depicts` | media → all registered endpoints | many/many | media depiction | registry + Graph service; Governance required |
| `model_of` | model → brand | one/many | canonical structure | endpoint and cardinality validation |
| `variant_of` | variant → model | one/many | canonical structure | endpoint and cardinality validation |
| `uses_movement` | variant → movement | many/many | variant capability/configuration boundary | registry validation |
| `supports_music` | movement → music | many/many | movement capability | registry validation |
| `configured_with_music` | variant → music | many/many | variant configuration | registry validation |
| `observed_playing_music` | specimen → music | many/many | physical observation | registry validation |

`GraphService` fails closed for unknown predicates, invalid endpoints,
unsupported source/target pairs and self-relations. `WpdbGraphRepository`
handles exact-triple idempotency, retired-edge non-resurrection and
cardinality. That is compliant implementation evidence, not proof of the
current database.

The structural readers correctly model Model→Brand and Variant→Model paths,
but `StructuralContextQuery`, `BrandAggregationQuery` and
`RelatedContentQuery` contain broad `Throwable` fallbacks that can make
infrastructure failure look like empty structure. `PublicRouteResolver` and
`PublicEntityEligibilityPolicy` still use payload `brand_uuid`/`model_uuid`
for transitional routing. That is compatibility behavior only if it is never
treated as canonical Graph truth; the current path is not sufficiently
fail-closed, hence the P0 conflict on shortcut/ownership behavior.

No fresh database connection was available. The prior execution-state figures
(`30` Model and `42` Variant structural findings; historical `189` nodes,
`241` active edges and `2` persisted predicate rows) are retained as
historical evidence only. Fresh counts for every parent class, candidate
classification and physical Graph distribution are `UNVERIFIED`; the audit
did not create or repair any edge.

Direct/derived conclusion: direct `model_of` and `variant_of` are valid
structural relations. Brand associations for Movement, Music, Component,
Classification, Media and Video must be derived through declared paths or
remain absent. No canonical Variant→Brand shortcut is authorized. The broad
`about` registry requires a later scope/cardinality review before Product→
Specimen can be treated as an ownership contract.

## Specimen/Product P0 conclusion

The domain classes are distinct. Specimen payload owns physical fields such as
model reference, serial, acquisition and notes; Product payload owns listing
fields such as specimen reference, vendor, URL, price, currency and
availability. This supports the constitutional distinction: a Specimen is one
physical object and a Product is a listing/offer. The current code and tests do
not prove the full lifecycle questions: multiple Products over time for one
Specimen, Product without Specimen, ownership of condition and technical
observations, and media/provenance boundaries. The `specimen_uuid` Product
field plus broad `about` relation makes the relationship contract ambiguous.

This is a `CODE_GAP` and a P0 human decision gate, not permission to merge or
repair records. No current-row conclusion can be made because runtime data was
unavailable.

## Identity, eligibility, routing and SEO

The public identity contract currently serializes `id`, `stable_key`, `name`
and a slug derived from the canonical name. `PublicRouteResolver` derives
slugs and parent paths from payload UUIDs; no durable public identity/history
repository was found. A display-name rename can therefore alter a public slug,
and the public collection/detail serializers expose internal UUID/stable-key
fields. This is the strongest identity finding: durable public identity is a
`PUBLIC_IDENTITY_STORAGE_GAP`, while the exposure/instability is a P0
constitutional conflict before public expansion.

| SURFACE | ENTRY SERVICE | ELIGIBILITY | IDENTITY | ROUTE | DIVERGENCE |
|---|---|---|---|---|---|
| Homepage | `HomeQuery` / collection | collection path | collection identity | collection route | Runtime unverified |
| Hub/archive | `PublicEntityCollectionQuery` | archive path | collection identity | route resolver | Transitional parent payload |
| Detail | `EntityPageQuery` | detail/eligibility path | detail serializer | route resolver | Fallback branch is weaker |
| Search | `SearchQuery` / collection wiring | collection path | result serializer | result URL | Runtime unverified |
| REST | public/admin MCP/REST handlers | endpoint-specific | endpoint serializer | route/URL | Runtime unverified |
| Cards/breadcrumbs | theme + query serializers | inherited/partial | card identity | derived URL | Runtime unverified |
| SEO/sitemap/preview | theme/WordPress integration | not freshly proven | not freshly proven | not freshly proven | Runtime unverified |

There is a common eligibility service in the main collection wiring, but the
detail fallback and route payload compatibility mean convergence is not proven.
The requested Vietnamese roots and legacy redirects are present as code/docs
directionally, but no live HTTP matrix passed. Canonical, OpenGraph,
breadcrumb, pagination, search, sitemap and historic redirect parity remain
P1 evidence/implementation work; no UUID or stable-key should appear in the
public projection.

## Knowledge, provenance, media and video

Knowledge remains a separate atomic-claim domain and WordPress Post remains
editorial content. `PostKnowledgeLinkService` directly creates an `about`
Graph edge; because it is outside the controlled semantic mutation path, it is
a concrete Governance bypass risk. `V2MigrationService` directly writes posts,
terms, Authority records, media, knowledge, evidence, video and Graph edges;
the current Constitution/AGENTS scope forbids invoking that migration for this
work, especially for legacy article bodies.

Source and Evidence have separate domain boundaries and public evidence-chain
checks, but support-vs-existence parity was not runtime proven. Media,
MediaAsset and MediaUsage are separate classes/services; no checksum-based
semantic auto-merge was found. Publication/native WordPress featured and
content image behavior needs live proof. Video is modeled as a normalized
YouTube external reference; no local MP4 canonicalization or default download
was found.

Movement capability, Variant configuration and Specimen observation are
separate predicates. No authorized inference from rod/gong/hammer counts,
Brand, case style or visual similarity was found. Tests still need explicit
negative proof that observation does not promote to Variant configuration.

Album is not a Constitution-required Authority type and is absent from the
runtime registry. It is therefore `DESIGN_NOT_REQUIRED`/P2 rather than a
missing semantic entity. If grouping is later needed, use MediaUsage or an
editorial grouping contract without inventing an Album identity.

## Governance, MCP and Article Ingest

`ControlledApplyService` provides proposal lock, state, eligibility, attempt,
executor, audit and failure/rollback machinery. `AuthorityProposalExecutor`
handles Authority, Media, Video, Knowledge/Evidence and Graph operations. The
direct PostKnowledge path and broad V2 migration writer remain semantic write
bypasses. Normal WordPress editorial publication is exempt from semantic
Governance, but Article Ingest semantic steps are not.

The MCP contract describes 19 tools and 15 endpoint types, with read/write
permissions and public/private boundaries. Static tests cover portions of the
handlers. No Article CRUD/publish, binary upload, standalone MediaUsage or
Album tool is authorized by the current contract; live wire behavior was not
available. Diagnostics must eventually expose ineligible reasons rather than
hide them.

The Article Ingest contract is non-normative and its intended sequence is
semantic preflight → WordPress draft → governed semantic apply → read-back →
WordPress publish. The concurrent receipt/coordinator work was not authored by
this audit and was not staged. Fresh audit conclusion: cross-boundary
idempotency, WordPress revision binding, final outcome contract and runtime
coordination are not yet proven. Article Ingest must not call
`V2MigrationService` or direct `PostKnowledgeLinkService` mutation.

## Health, deployment and frontend

Health code separates storage, runtime, hydration, application and REST
layers. Row-level malformed Authority data is handled narrowly by the hydrator,
but broad `Throwable` catches in structural/aggregation readers violate the
fail-loud requirement when the exception represents infrastructure or
programming failure. This is P0 because a broken Graph/database can appear as
empty data.

Composer validation passed. Preflight correctly surfaced five failures, but
the local WordPress/MySQL runtime was unavailable, so deployment readiness is
not proven. The frontend has centralized tokens, Vietnamese-first labels,
semantic HTML direction, body/display/reading/wide widths and fallback
navigation. Live responsive, empty/error, route and SEO behavior remains
unverified; visual polish is P2 unless it causes semantic public failure.

## P0 / P1 / P2 backlog

### P0 — must fix before semantic data repair

| ITEM | PROBLEM / LAW | EVIDENCE AND RISK | BOUNDARY / PREREQUISITE | DATA IMPLICATION | TEST REQUIREMENT |
|---|---|---|---|---|---|
| P0-1 | Silent Graph/hydration failure / §3, §16 | Broad `Throwable` catches return empty arrays; outage becomes false absence | Change reader error contracts after defining failure taxonomy | No repair or backfill | Inject infrastructure exception; assert failure is distinct from empty |
| P0-2 | Governance bypass / §12 | `PostKnowledgeLinkService` writes Graph directly; V2 migration writes semantic domains directly | Route semantic writes through proposal/apply; keep editorial Post exemption | No migration/import | Bypass tests fail first; audit proposal, approval, apply, rollback |
| P0-3 | Product/Specimen decision / §8 | Payload and broad relation contract do not prove ownership/lifecycle | Human architecture decision before code | No merge, split, seed or repair | Multi-listing lifecycle matrix and forbidden ownership tests |
| P0-4 | Structural ownership / §5 | Payload parent is used in route/eligibility compatibility; historical missing-parent findings | Graph-canonical context and read-only diagnostics first | Do not repair 30/42 candidates | Wrong parent, missing, ambiguous, inactive, conflict fixtures |
| P0-5 | Public identity leak / §9 | Public serializers include UUID/stable-key; slug derives from name | Durable identity contract before expansion | No slug assignment/backfill | Rename/history/collision and no-internal-ID response tests |

### P1 — must fix before broad public data expansion

| ITEM | PROBLEM / LAW | EVIDENCE | IMPLEMENTATION BOUNDARY | TEST REQUIREMENT |
|---|---|---|---|---|
| P1-1 | Eligibility convergence / §10 | Detail fallback and surface parity not proven | One policy adapter for homepage, hubs, detail, search, REST, SEO | Cross-surface allow/deny parity |
| P1-2 | Durable historic slugs / §9–§11 | No persistence boundary found | Identity repository + governed rename/history | Rename, collision, redirect-chain tests |
| P1-3 | Route/SEO live parity / §10–§11 | HTTP unavailable; payload parent transitional | Route matrix and canonical metadata verification | Vietnamese roots, canonical/OG, breadcrumbs, sitemap |
| P1-4 | Music scopes / §6 | Predicates exist; negative promotion proof incomplete | Scope-aware query/write validators | Observation/configuration/capability separation |
| P1-5 | Article coordinator / §15 | Receipt/idempotency/revision/readback not runtime proven | Reconcile first; controlled writes only after CAS review | Race, retry, revision mismatch, publish refusal |
| P1-6 | MCP diagnostics / §13 | Wire unavailable and permission parity partial | Read-only diagnostic result contract | Read/write permission and ineligible-reason tests |

### P2 — completeness / next iteration

- Keep Album out of the semantic registry; specify MediaUsage/editorial grouping
  only if an actual requirement is approved.
- Complete media publication/native image, Video delivery and frontend live
  responsive/error-state evidence.
- Add read-only Graph distribution and candidate reports with explicit predicate
  labels; do not make the reports write-capable.

Every P0/P1 implementation must remain documentation-led by the Constitution,
preserve canonical UUID/stable-key and revision/idempotency invariants, and
separate candidate evidence from any later human-approved data repair.

## Required decision gates

| GATE | DECISION | WHY |
|---|---|---|
| A Product/Specimen | **P0: yes** | Identity, commerce and physical observations are not fully contractually separated; incorrect repair could corrupt identity. |
| B Governance bypass | **P0: yes** | Direct semantic writes can bypass approval/audit and mutate the single relation system. |
| C Silent failure | **P0: yes** | Runtime failure can be projected as empty truth, causing unsafe repair decisions. |
| D Identity instability | **P1 now; P0 before public expansion** | Durable history is missing and internal IDs leak, but no data mutation is required to document the gap. |
| E Structural ownership | **P0: yes** | Canonical parent truth affects identity, routes and all downstream repair; payload fallback is not Graph truth. |

The P1 gate confirms: public identity persistence remains a concrete gap;
Model/Variant parent state is transitional and unverified; eligibility is not
proven convergent; Brand aggregation is partial; historic slugs are absent;
search/REST parity is unverified; and the Article coordinator is not proven.

## Non-mutation proof and next phase

The audit commands were read-only checks and documentation inspection. No
`ControlledApply`, Graph edge creation, entity write, slug assignment,
WordPress Post mutation, legacy-body import, migration UP/DOWN, seed, repair or
database reset was run by this audit. The concurrent untracked file
`docs/superpowers/plans/2026-09-02-mcp-v3-content-operations.md` and concurrent
Article Ingest files were not staged.

The single next implementation phase is **PHASE 0 — P0 integrity fixes**:
fail-loud reader behavior, semantic-write boundary closure, the
Product/Specimen human decision gate and read-only structural diagnostics. It
does not include Graph backfill.

## Phase 0 implementation result — 2026-09-02

The Phase 0 code review and tests were completed against audited baseline
`8d480a2` without modifying the Constitution. The implementation is
**PARTIAL** because the Product/Specimen lifecycle and ownership decision
remains an explicit human gate.

| ITEM | RESULT | EVIDENCE / LIMIT |
|---|---|---|
| P0-1 fail-loud Graph readers | IMPLEMENTED | Related Content, Brand Aggregation and Structural Context no longer convert Graph failures to empty success; injected-failure and honest-empty unit coverage passes. |
| P0-2 Governance bypasses | IMPLEMENTED / RETIRED | Post→Knowledge requests a Draft governed relation proposal; direct `link()` fails closed; historical V2 writer entry point is unavailable. No migration or apply was executed. |
| P0-3 Product/Specimen ownership | HUMAN GATE OPEN | Current disjoint payload tests remain; lifecycle/condition/observation and multi-listing ownership are intentionally not chosen by code. |
| P0-4 structural ownership | IMPLEMENTED / DATA UNVERIFIED | Graph is canonical; safe payload fallback is explicitly non-canonical and warned; conflict/missing/inactive/ambiguous paths fail closed. Historical/current physical rows remain unverified and untouched. |
| P0-5 public identity leakage | IMPLEMENTED FOR CURRENT PROJECTIONS | Public serializers and public REST projections omit UUID/stable-key fields and UUID relationship payload fields. Durable public identity/history remains P1; internal Admin/MCP diagnostic and Governance identifiers remain allowed. |

Isolated local verification is `217` unit tests / `1163` assertions, Composer
PHP lint pass and `git diff --check` pass. The local WordPress/database
runtime was unavailable, so guarded integration, live preflight and HTTP
parity remain unverified. No database, WordPress Post, Graph edge, slug,
redirect, migration, seed, repair or legacy article-body operation was run.
The Phase 0 gate remains **BLOCKED pending the P0-3 human architecture
decision**, with no authorization for data repair or Graph backfill.

## Product / Specimen amendment checkpoint — 2026-09-02

This section updates only the Product/Specimen rows and the directly affected
Knowledge/Governance conclusions after the human-approved constitutional
decision. The original matrix above remains the pre-decision audit baseline.

| AREA | BEFORE | AFTER | REMAINING GAP | TEST EVIDENCE | RUNTIME EVIDENCE |
|---|---|---|---|---|---|
| Specimen identity/ownership | Specimen was registered as a physical object, but lifecycle and observation ownership were not fully contract-tested | **COMPLIANT** for the declared boundary: Specimen owns one physical object, physical identity/evidence, provenance, technical observations and condition observations | Persistence of Product linkage is not implemented; current physical rows remain unverified | `ProductSpecimenBoundaryTest` proves physical fields are accepted only on Specimen and lifecycle preserves identity | No fresh database mutation or count; runtime remains an environment gate |
| Product identity/ownership | Product was registered as a listing/offer, but the contract did not prevent a physical-identity field | **COMPLIANT** for the declared boundary: Product owns commerce/listing fields and cannot store physical identity, observation or provenance fields | A Product-specific/generic classification is currently supplied to the read-only assessment; no durable relation mechanism exists | `ProductSpecimenBoundaryTest` and `P0ConstitutionIntegrityTest` prove commerce edits do not replace Specimen and cross-owned fields fail closed | No Product/Specimen data was created or repaired |
| Product–Specimen cardinality/completeness | Payload `specimen_uuid` plus broad `about` made owner/cardinality ambiguous (`CONSTITUTION_CONFLICT`) | **PARTIAL** — law is fixed at Specimen `0..N` Products over time and Product `0..1` Specimen; specific-object Product without exactly one Specimen is blocked | **REGISTRY_GAP/CODE_GAP:** no dedicated registered relation/persistence contract; `about` is not used as ownership and `specimen_uuid` is removed from the Product schema | Assessment tests cover zero/one/multiple candidate outcomes without persistence | No relation or payload backfill; current rows untouched |
| Product commerce vs physical truth | No complete negative lifecycle matrix | **COMPLIANT** for current Authority boundary: price, availability, title, offer and listing lifecycle changes are isolated from Specimen identity | Condition copy projection and governed conflict proposal remain future integration work | Tests cover price/availability/title, Product retirement, Specimen survival and Product copy non-promotion | No browser/MCP runtime evidence added |
| Knowledge/commercial claims | Product copy could not be shown not to become Knowledge | **COMPLIANT** as a boundary rule: Product copy is not Knowledge, Source/Evidence or Graph truth and has no automatic promotion path | Evidence-backed promotion workflow remains the existing governed contract, not a Product shortcut | Product copy update test confirms only Product changes; no Knowledge service is invoked | No Knowledge/Source/Evidence rows changed |
| Governance | Semantic mutation path was previously a P0 bypass finding | **UNCHANGED by this amendment; existing Phase 0 Governance closure remains the applicable result** | Product–Specimen relation, physical identification and observations must use full Governance when a relation contract is later approved | Existing P0 Governance tests plus amendment contract tests; no direct relation writer added | No Controlled Apply or Graph write executed |

The approved decision resolves the human architecture gate, but it does not
authorize a relation predicate or storage field. Phase 0 Product/Specimen is
therefore **PARTIAL**, not fully complete: semantics and negative ownership
boundaries are implemented, while the relationship remains a separately
reviewed registry/storage task. No identity merge, inferred Specimen, Graph
backfill, payload repair, migration or data write occurred.
