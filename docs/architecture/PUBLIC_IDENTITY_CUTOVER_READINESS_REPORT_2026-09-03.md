# Public Identity Cutover Readiness Report

Status: `PENDING_OWNER_APPROVAL` / `PRE_CUTOVER_ONLY` — 2026-09-04

Task 12 was completed as a read-only evidence checkpoint. No production,
staging, V2 or development semantic data was projected or mutated. No
canonical URL, Video UUID, YouTube identity, relation, MediaAsset filename or
WordPress content was changed.

## Bounded canary

| Item | Expected |
|---|---|
| Video UUID | `01a06815-1e51-7964-b004-1ba79e488ad1` |
| YouTube ID | `P4KaHX3LBOw` |
| Canonical URL | `/video/odo-36-10-gai-carillon-p4kahx3lbow/` |
| Historic route | one direct `301` from the malformed mixed-case YouTube suffix |

The read-only canary boundary checks UUID and external-ID preservation, the
canonical path, zero duplicate Video rows, unchanged semantic relations, no
duplicate `200` route, one-hop history and non-`200` historic resolution. Its
receipt always reports `mutation_count=0` and `live_projection_performed=false`.

Live read-back is `ENVIRONMENT_BLOCKED` when `NHK_WP_TEST_PATH` is unavailable;
therefore no live canary pass is claimed. Owner approval and a separately
authorized governed execution remain required before any projection/cutover.

## Final review classification

- Public Identity persistence and Authority URL projection: implemented locally;
  target-runtime allocation/read-back remains environment-gated.
- Historic one-hop `301` and Video semantic URL policy: focused/unit covered;
  live route read-back remains pending.
- Media route ruling: standalone Media detail is a `CODE_GAP` and fails closed;
  asset delivery remains delivery-only/noindex.
- Knowledge route ruling: atomic Claim/Source/Evidence HTML detail is not
  indexable; Album/Gallery remains `REGISTRY_GAP`.
- SEO/internal URL consumers: shared canonical projection is wired locally;
  native WordPress editorial URLs remain independent.
- Collision behavior and identity safety: fail-closed checks are covered;
  no runtime slug derivation from `canonical_name` is authorized for durable
  identity, and no generic WordPress semantic fallback writer exists.

## Open items

- `ENVIRONMENT_BLOCKED`: guarded integration/live canary read-back while
  `NHK_WP_TEST_PATH` is unavailable.
- `CODE_GAP`: standalone Media detail/public identity route remains retired.
- `REGISTRY_GAP`: Album/Gallery public projection.
- `REGISTRY_GAP`: dedicated Product–Specimen canonical relation.
- `CONSTITUTION_CONFLICT`: none unresolved in this checkpoint; the Media
  constitutional ruling is recorded and obeyed.

Production canary/cutover is explicitly **PENDING OWNER APPROVAL**.
