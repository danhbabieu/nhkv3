# NHK V3 Public Entity Identity Matrix

> **NON-NORMATIVE.** This is an identity audit and gap record. If it conflicts
> with `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution controls.

Status: P0 read-only parity audit, synchronized 2026-09-04. This is an evidence record and
contract matrix; it does not assign slugs, create aliases, alter redirects,
write V2, import data, or repair Graph relations.

## Current runtime matrix

The runtime registry is the source of the type list. The current catalog has
nine Authority types. The persisted Public Identity implementation boundary now
exists in code: `PublicIdentityService`, `PublicIdentityRepository`, WPDB
persistence, additive migration 014, history records and exact one-hop historic
route resolution are present. Focused unit evidence exists, but migration 014
was not executed in the guarded runtime when that implementation checkpoint was
recorded, and this audit does not prove that live/current canonical routes are
fully allocated from persisted identities in the target environment.

Accordingly, **missing implementation is no longer the correct classification**.
The remaining boundary is runtime activation/data/read-back parity: until the
migration, allocation/current identity rows and consumers are verified in the
actual environment, downstream systems must not claim durable public identity
is live merely because the repository/service exists.

Current route compatibility may still derive paths from `canonical_name` where
no persisted Public Identity has been allocated/consumed. That compatibility
behavior is not authorization to regenerate a published durable slug silently.

| Type | Internal identity | Current compatibility slug source | Canonical pattern | Parent requirement | Legacy/history behavior | Public eligibility note |
|---|---|---|---|---|---|---|
| brand | `canonical_uuid` / `stable_key` | persisted identity when allocated/consumed; otherwise current compatibility derivation from `canonical_name` | `/{brand-slug}/` | none | persisted history implementation supports exact one-hop lookup when rows exist; older V2 redirect data is migration evidence, not the general identity store | active, valid, unambiguous route required |
| model | `canonical_uuid` / `stable_key` | persisted identity when allocated/consumed; compatibility route currently may use name-derived slug | `/{brand-slug}/{model-slug}/` | current route compatibility still resolves `payload.brand_uuid`; canonical structural vocabulary is `model_of` | exact persisted history is a separate identity/history boundary when allocated | active parent and unique valid route required |
| variant | `canonical_uuid` / `stable_key` | persisted identity when allocated/consumed; compatibility route currently may use name-derived slug | `/{brand-slug}/{model-slug}/{variant-slug}/` | current route compatibility still resolves `payload.model_uuid`; canonical structural vocabulary is `variant_of` | exact persisted history is a separate identity/history boundary when allocated | active ancestor chain and unique valid route required |
| movement | `canonical_uuid` / `stable_key` | persisted identity when allocated/consumed; otherwise compatibility derivation | `/bo-may/{movement-slug}/` | none in route resolver | persisted exact one-hop history when rows exist | active + unique valid route |
| music | `canonical_uuid` / `stable_key` | persisted identity when allocated/consumed; otherwise compatibility derivation | `/ban-nhac/{music-slug}/` | none; missing Brand relation must not block route | persisted exact one-hop history when rows exist | active + unique valid route |
| component | `canonical_uuid` / `stable_key` | persisted identity when allocated/consumed; otherwise compatibility derivation | `/linh-kien/{component-slug}/` | none | persisted exact one-hop history when rows exist | active + unique valid route |
| classification | `canonical_uuid` / `stable_key` | persisted identity when allocated/consumed; otherwise compatibility derivation | `/phan-loai/{classification-slug}/` | none | persisted exact one-hop history when rows exist | active + unique valid route |
| specimen | `canonical_uuid` / `stable_key` | persisted identity when allocated/consumed; otherwise compatibility derivation | `/hien-vat/{specimen-slug}/` | no Product ownership implied; compatibility `model_uuid` is not Graph truth | persisted exact one-hop history when rows exist | active + unique valid route |
| product | `canonical_uuid` / `stable_key` | persisted identity when allocated/consumed; otherwise compatibility derivation | `/san-pham/{product-slug}/` | no approved Product–Specimen canonical relation exists | persisted exact one-hop history when rows exist | active + unique valid route |

## Evidence-backed contract findings

1. Authority identity remains separate from the URL string. Persisted Public
   Identity storage/history is now implemented in code, including optimistic
   revision/idempotency and exact one-hop history. The outstanding question is
   environment activation and allocation/read-back, not whether a repository
   implementation exists.
2. The historic route service resolves persisted history exactly and one hop.
   This does not prove every historical V2/current route has a corresponding
   persisted identity/history row; coverage remains a data/runtime question.
3. Current route/query compatibility is not yet proven to consume persisted
   identity for every entity path in the target runtime. Any fallback derived
   from `canonical_name` must be treated as compatibility behavior, not durable
   slug ownership after publication.
4. `PredicateRegistry` currently registers `model_of` (`model` → `brand`, ONE)
   and `variant_of` (`variant` → `model`, ONE), together with the other approved
   technical predicates. Physical Graph backfill/completeness is a separate
   data/runtime question. Current route compatibility may still consume parent
   UUID payload fields; those fields are not the canonical Graph writer and do
   not prove structural edges are present.
5. Product–Specimen remains a separate `REGISTRY_GAP` / contract-extension
   requirement. Historical `specimen_uuid`-style payload data, broad `about`,
   taxonomy or post meta must not be interpreted as a canonical ownership link.
6. Music has no route-level Brand requirement in the current resolver. A
   missing Brand relation must not hide a legitimate active Music row.

## Required audit output when guarded runtime access is available

Verify, do not infer:

* migration 014 current/target state and schema in the exact guarded database;
* persisted identity/current-history row counts and owner coverage by type;
* allocation/read-back for representative Authority and Video identities;
* exact one-hop redirect from a persisted historical path to its current path;
* collision/native-route/CAS/idempotency behavior against the real database;
* which public route consumers use persisted identity versus compatibility
  derivation;
* `/odo/` and representative nested/entity/video HTTP status, redirect count,
  final URL, title/H1/canonical and no redirect loop;
* per-entity exclusion reasons for inactive, ambiguous, unallocated or invalid
  public identities.

No failure of DNS/database availability is evidence that the underlying
Authority or Public Identity row is absent.

## Root-cause classification status

| Classification | Status | Evidence |
|---|---|---|
| PUBLIC_IDENTITY_IMPLEMENTATION | present locally | service/repository, WPDB persistence, migration 014 and historic resolver exist in current code |
| PUBLIC_IDENTITY_RUNTIME_ACTIVATION | unverified / runtime-gated | implementation checkpoint recorded migration 014 not executed because guarded DB runtime was unavailable |
| PUBLIC_IDENTITY_DATA_COVERAGE | unverified | this audit does not prove allocation/current/history rows for all public entities |
| CURRENT_ROUTE_CONSUMER_PARITY | partial/unverified | compatibility routing may still derive from canonical name where persisted identity is absent/not consumed |
| HISTORIC_ONE_HOP_RESOLUTION | implemented locally | exact persisted-history lookup and 301 route integration are present; live DB/HTTP proof remains separate |
| REGISTRY_FAILURE | not established | all nine Authority definitions load; approved structural predicates are registered |
| STRUCTURAL_RELATION_GAP | data/backfill/runtime completeness gap | `model_of` / `variant_of` vocabulary is registered; physical edge coverage is not proven here |
| PRODUCT_SPECIMEN_RELATION_GAP | established contract/registry gap | no dedicated approved Product–Specimen relation; payload/about shortcuts are not canonical ownership |
| CACHE_STALENESS | unverified | requires target-environment response evidence |
| CODE_VERSION_PARITY_FAILURE | unverified | requires deployed revision/read-back evidence |
| CONSTITUTION_CONFLICT | applies if compatibility payload/derived-route behavior is promoted as canonical persistence contrary to approved contracts | compatibility is not a second semantic/public-identity writer |

No corrective live-data action is authorized by this matrix. Allocation,
migration execution, slug/history population, redirect/data repair and Graph
backfill require their applicable runtime/governance gates and read-back.
