# NHK V3 Public Entity Identity Matrix

Status: P0 read-only parity audit, 2026-09-02. This is an evidence record and
contract matrix; it does not assign slugs, create aliases, alter redirects,
write V2, import data, or repair Graph relations.

## Current runtime matrix

The runtime registry is the source of the type list. The current catalog has
nine Authority types. `PublicRouteResolver` is the URL authority. In the
current implementation, the public slug is derived at read time from
`canonical_name`; no Authority column or contract field stores a canonical
public slug, and no Authority alias/history repository is queried.

| Type | Internal identity | Display name source | Public slug source | Canonical pattern | Parent requirement | Legacy pattern / redirect | Indexable | List query | Detail query | Fail-closed reasons |
|---|---|---|---|---|---|---|---|---|---|---|
| brand | `canonical_uuid` / `stable_key` | `canonical_name` | `PublicRouteResolver::slug(canonical_name)` | `/{brand-slug}/` | none | V2-style bare slug may be resolved and redirected to the same resolver output when the match is unique; historic alias registry not implemented | yes when active, slug valid, unambiguous, non-reserved | `EntityPageQuery::archive('brand')`, active rows; does not require non-null route in the archive item itself | UUID/stable-key detail, active-only; route detail resolves by current derived slug | inactive, empty/invalid slug, reserved root, duplicate slug, hydration loss, unavailable storage |
| model | `canonical_uuid` / `stable_key` | `canonical_name` | derived from `canonical_name` | `/{brand-slug}/{model-slug}/` | active `payload.brand_uuid` resolving to active brand | legacy two-segment slug redirect only when both current derived slug matches are unique; no historic alias table | yes only with active canonical parent and unique child slug | active rows; parent/route eligibility is not centralized in the archive contract | UUID/stable-key detail is active-only; public nested route requires active parent and unique child | missing/invalid parent, inactive parent, duplicate child slug, invalid slug, hydration loss, unavailable storage |
| variant | `canonical_uuid` / `stable_key` | `canonical_name` | derived from `canonical_name` | `/{brand-slug}/{model-slug}/{variant-slug}/` | active `payload.model_uuid` resolving through active model/brand | no dedicated legacy variant redirect contract | yes only with active canonical parent chain and unique child slug | active rows; parent/route eligibility is not centralized in the archive contract | UUID/stable-key detail is active-only; nested public route requires parent chain and unique variant slug | missing/invalid parent chain, inactive ancestor, duplicate child slug, invalid slug, hydration loss, unavailable storage |
| movement | `canonical_uuid` / `stable_key` | `canonical_name` | derived from `canonical_name` | `/bo-may/{movement-slug}/` | none in route resolver | `/bo-may/` namespace; no historic slug registry | yes when active and unique | active rows | UUID/stable-key detail, active-only | inactive, empty/invalid or duplicate slug, hydration loss, unavailable storage |
| music | `canonical_uuid` / `stable_key` | `canonical_name` | derived from `canonical_name` | `/ban-nhac/{music-slug}/` | none; missing Brand relation must not block route | `/am-nhac/` archive alias; no historic entity-slug registry | yes when active and unique | active rows | UUID/stable-key detail, active-only | inactive, empty/invalid or duplicate slug, hydration loss, unavailable storage |
| component | `canonical_uuid` / `stable_key` | `canonical_name` | derived from `canonical_name` | `/linh-kien/{component-slug}/` | none | namespace route; no historic slug registry | yes when active and unique | active rows | UUID/stable-key detail, active-only | inactive, empty/invalid or duplicate slug, hydration loss, unavailable storage |
| classification | `canonical_uuid` / `stable_key` | `canonical_name` | derived from `canonical_name` | `/phan-loai/{classification-slug}/` | none | namespace route; no historic slug registry | yes when active and unique | active rows | UUID/stable-key detail, active-only | inactive, empty/invalid or duplicate slug, hydration loss, unavailable storage |
| specimen | `canonical_uuid` / `stable_key` | `canonical_name` | derived from `canonical_name` | `/hien-vat/{specimen-slug}/` | none in current public resolver; payload may contain `model_uuid`, but it is not used for this route | `/hien-vat/` archive alias; no historic slug registry | yes when active and unique | active rows | UUID/stable-key detail, active-only | inactive, empty/invalid or duplicate slug, hydration loss, unavailable storage |
| product | `canonical_uuid` / `stable_key` | `canonical_name` | derived from `canonical_name` | `/san-pham/{product-slug}/` | none in current public resolver; payload may contain `specimen_uuid`, but it is not used for this route | namespace route; no historic slug registry | yes when active and unique | active rows | UUID/stable-key detail, active-only | inactive, empty/invalid or duplicate slug, hydration loss, unavailable storage |

## Evidence-backed contract findings

1. Authority identity is separate from the current URL string, but the URL
   slug is not persistent. A display-name update changes the URL generated by
   every read. This is a `SLUG_CONTRACT_FAILURE` / `DATA-CODE CONTRACT GAP`,
   not evidence of missing Authority rows.
2. The current resolver has no one-hop historical-slug lookup. Existing V2
   redirect persistence is migration-owned (`nhk_v2_entity_redirects`) and is
   not a general Authority slug-history service. A historic URL therefore
   cannot be proven to redirect from the Authority contract alone.
3. `EntityPageQuery::detail()` and `EntityPageQuery::archive()` do not share a
   single public eligibility predicate. Detail requires type, storage-ready,
   resolvable identity and active state. Archive enumerates active rows and
   serializes them even when `publicPath()` is null. REST list is another
   active-only path and does not attach canonical URLs. This is a
   `PUBLIC_ELIGIBILITY_FAILURE` / `CODE_GAP` candidate when a UI path filters
   URL-less serialized items.
4. Model and Variant route resolution depends on payload parent UUIDs. The
   structural `model_of` / `variant_of` predicates remain registry gaps, so
   physical existence and structural completeness must be reported separately.
   Treating absent physical edges as proof of absent entities would be a
   `CONSTITUTION_CONFLICT`.
5. Music has no route-level Brand requirement in the current resolver. A
   missing Brand relation must not hide a legitimate active Music row.

## Required audit output when runtime access is available

The following fields remain `UNVERIFIED` in this workspace because staging DNS
and the local WordPress database were unavailable during the audit:

* `/odo/` HTTP status, `Location`, final URL, redirect count, generated HTML,
  cache headers, title, H1 and canonical tag;
* physical, hydrated, list, public, slug, duplicate, invalid-slug and
  resolvable counts for each registered type;
* the exact `/odo/` entity UUID, revision, state, stored source, aliases and
  current database value;
* the per-entity exclusion reason and counts for state, route ambiguity,
  parent completeness and hydration errors;
* Knowledge, Media, Video, Source/Evidence and native Post physical-to-public
  parity counts using their own repositories.

The attempted read-only probe failed before HTTP with DNS error
`Could not resolve host: demo.1945.vn`; it is not valid evidence that `/odo/`
is a 404 or that the Brand row is absent. Local application preflight is
already documented as database-unavailable in `V3_EXECUTION_STATE.md`.

## Root-cause classification status

| Classification | Status | Evidence |
|---|---|---|
| STORAGE_FAILURE | not established | no staging or local DB response |
| HYDRATION_FAILURE | not established for current rows | bounded hydrator tests pass; live counts unavailable |
| REGISTRY_FAILURE | not established | all nine catalog definitions load locally |
| SLUG_CONTRACT_FAILURE | established as code gap | slug is regenerated from `canonical_name`; no persisted history contract |
| ROUTE_FAILURE | plausible, not endpoint-proven | resolver fail-closes ambiguity/reserved roots and nested parents |
| PUBLIC_ELIGIBILITY_FAILURE | established as contract divergence | list/archive/detail use different route/eligibility conditions |
| STRUCTURAL_RELATION_GAP | established as design/runtime gap | `model_of` / `variant_of` are not registered; payload parent fields drive routes |
| CACHE_STALENESS | unverified | staging response unavailable; no purge performed |
| CODE_VERSION_PARITY_FAILURE | unverified | no staging headers/body or deployment revision |
| DATA_COMPATIBILITY_GAP | possible, not row-proven | live stored slug/alias fields unavailable |
| CONSTITUTION_CONFLICT | applies only if payload parent fields are treated as canonical Graph structure | constitution requires registered typed relations |

No corrective data action is authorized by this report. Slug assignment,
alias-history persistence, redirect changes and Graph repair require a follow-up
contract/implementation decision after runtime counts and the `/odo/` response
are captured.
