# Odo Runtime Inventory — Read-Only Checkpoint

**Date:** 2026-09-03  
**Status:** `UNAVAILABLE` / `UNVERIFIED` — no runtime data mutation performed  
**Initial HEAD:** `02d7f3012e0b88ec011c66d130bd412fd059125a`  
**Pack checkpoint:** `6fd6cc3` (`docs: add Odo semantic reference pack`)

## Scope and evidence boundary

This is the required read-only inventory artifact. The Odo reference pack and
manifest are design inputs, not runtime observations. Runtime inventory could
not be completed because the read-only deployment preflight failed at
`WORDPRESS_BOOTSTRAP_FAILED`; the direct bootstrap probe returned WordPress's
database-connection error. Therefore all rows below sourced from the pack are
marked `DESIGN_INPUT`, not observed facts.

No direct SQL, WordPress write, semantic write, Graph mutation, migration,
seed, repair, merge, rekey, retirement, Media/Video creation or Post mutation
was performed.

## Runtime preflight

| Check | Result | Evidence |
|---|---|---|
| Git HEAD | PASS | `02d7f3012e0b88ec011c66d130bd412fd059125a` before pack checkpoint |
| Composer lock/autoload/runtime classes | PASS | `php tools/deployment-preflight.php` |
| WordPress bootstrap | FAIL | `WORDPRESS_BOOTSTRAP_FAILED` |
| NHK Core bootstrap | FAIL | dependent on WordPress bootstrap |
| Schema migration | FAIL | dependent on WordPress bootstrap |
| Authority hydration | FAIL | dependent on WordPress bootstrap |
| REST bootstrap | FAIL | dependent on WordPress bootstrap |

Runtime status is fail-closed. Counts, revisions, inbound/outbound edges,
Source/Evidence references, Media/Video references and Post references remain
`UNVERIFIED` until the existing local WordPress/database runtime is restored.

## Static canonical identity map

The following is the complete explicit identity map in the approved pack. It
is not a claim that these records currently exist or have these revisions in
the unavailable runtime.

| Type | UUID | Old key | Canonical key | Decision | Evidence status |
|---|---|---|---|---|---|
| brand | `d2af7739-3d1b-4666-ad0a-aeda0758f4d8` | `nhk:brand:o-do` | `nhk:brand:odo` | REKEY | DESIGN_INPUT |
| model | `984658bf-19a6-4daa-a220-2a6c13af81ed` | `nhk:model:o-do.24` | `nhk:model:odo.24` | REKEY | DESIGN_INPUT |
| model | `fdf5bfd5-d3f4-4281-a39e-77c9271bcf4a` | `nhk:model:o-do.30` | `nhk:model:odo.30` | REKEY | DESIGN_INPUT |
| model | `c01c109c-5d39-401e-a16e-6d61a0a52f50` | `nhk:model:o-do.36` | `nhk:model:odo.36` | REKEY | DESIGN_INPUT |
| model | `d39bfeae-40c4-47c2-a050-94ca56c8c93b` | `nhk:model:o-do.39` | `nhk:model:odo.39` | REKEY | DESIGN_INPUT |
| model | `dd76ee46-2f76-4c65-a70b-73aae8a7e698` | `nhk:model:o-do.20` | `nhk:model:odo.20` | REVIEW/KEEP | DESIGN_INPUT |
| model | `fc86a551-06eb-48da-a765-5578e70bf4c9` | `nhk:model:o-do.35` | — | RETIREMENT_REVIEW | DESIGN_INPUT |
| movement | `f6342492-729e-4d01-aa67-8fa19c60c619` | `nhk:movement:o-do.24` | `nhk:movement:odo.24` | REKEY | DESIGN_INPUT |
| movement | `200ac862-e7c3-4434-aa01-10edc47d31b7` | `nhk:movement:o-do.30` | `nhk:movement:odo.30` | REKEY | DESIGN_INPUT |
| movement | `08fea152-2faf-47f6-a8af-d58c0324e04a` | `nhk:movement:o-do.36` | `nhk:movement:odo.36` | REKEY | DESIGN_INPUT |
| movement | `1f66321f-9940-4359-a47b-7d68734da41e` | `nhk:movement:o-do.39` | `nhk:movement:odo.39` | REKEY | DESIGN_INPUT |
| movement | `d11c546f-5c9d-4399-a04b-ddb2a121bcd7` | `nhk:movement:o-do.20` | `nhk:movement:odo.20` | REVIEW/KEEP | DESIGN_INPUT |
| movement | `63eb0f6d-4b38-4a34-aa27-d02f5dbe76f5` | `nhk:movement:o-do.35` | — | RETIREMENT_REVIEW | DESIGN_INPUT |
| variant | `72c1ed8a-3626-465e-ae1d-af12c0fae68f` | `nhk:variant:o-do.24.54` | `nhk:variant:odo.24.54` | REKEY | DESIGN_INPUT |
| variant | `7301f50c-ef0d-4e95-a581-39e5063d4648` | `nhk:variant:o-do.24.57` | `nhk:variant:odo.24.57` | REKEY | DESIGN_INPUT |
| variant | `8d6da0b0-28e7-49d6-a73c-b1f30e13879d` | `nhk:variant:o-do.24.62` | `nhk:variant:odo.24.62` | REKEY | DESIGN_INPUT |
| variant | `11f8c058-e3bd-416b-adb0-f9bbb2854ad8` | `nhk:variant:o-do.24.58` | `nhk:variant:odo.24.58` | REKEY | DESIGN_INPUT |
| variant | `e2d0ab8b-761e-4c8a-a3db-978ce508670a` | `nhk:variant:o-do.24.20` | `nhk:variant:odo.24.20` | REVIEW | DESIGN_INPUT |
| variant | `9d21258b-e99f-44d2-a0a0-dec050b45338` | `nhk:variant:o-do.24.54.8-8-westminster` | `nhk:variant:odo.24.54.8-8-westminster` | REKEY | DESIGN_INPUT |
| variant | `3290b880-a449-4056-a890-13701d7bc5e0` | `nhk:variant:o-do.24.54.6-10-two-tune` | `nhk:variant:odo.24.54.6-10-two-tune` | REKEY | DESIGN_INPUT |
| variant | `e1452027-e2a4-4222-aeb5-fb45e6916b3c` | `nhk:variant:o-do.24.54.10-10-ave` | `nhk:variant:odo.24.54.10-10-ave` | REKEY | DESIGN_INPUT |
| variant | `f1f08304-19b2-45e5-aa9d-3a9ec460b366` | `nhk:variant:o-do.24.54.10-11-two-tune` | `nhk:variant:odo.24.54.10-11-two-tune` | REKEY | DESIGN_INPUT |
| variant | `5812357b-7a66-4f3d-aec5-d50d65d7f8f6` | `nhk:variant:o-do.24.54.10-10-two-tune` | `nhk:variant:odo.24.54.10-10-two-tune` | REKEY | DESIGN_INPUT |
| variant | `c2435242-6836-4feb-ac88-93799e27390c` | `nhk:variant:o-do.30.8` | `nhk:variant:odo.30.8` | REKEY | DESIGN_INPUT |
| variant | `fb58a9cf-f8ac-45aa-aced-98b1b403c43d` | `nhk:variant:o-do.30.10` | `nhk:variant:odo.30.10` | REKEY | DESIGN_INPUT |
| variant | `852da54d-457a-4397-a16d-52d9452ba766` | `nhk:variant:o-do.36.8` | `nhk:variant:odo.36.8` | REKEY | DESIGN_INPUT |
| variant | `dc874471-5554-48c0-a4da-8f9b81e2e283` | `nhk:variant:o-do.36.8.westminster` | `nhk:variant:odo.36.8.westminster` | REKEY | DESIGN_INPUT |
| variant | `79be7459-3fad-4ae3-acfe-6833cbd076c8` | `nhk:variant:o-do.36.8.two-tune` | `nhk:variant:odo.36.8.two-tune` | REKEY | DESIGN_INPUT |
| variant | `95873bfe-d978-4eda-a5a2-ce9ba79625df` | `nhk:variant:o-do.36.10` | `nhk:variant:odo.36.10` | REKEY | DESIGN_INPUT |
| variant | `1108f512-1250-472e-a0bf-8edf6a93dd94` | `nhk:variant:o-do.36.10.ave-maria` | `nhk:variant:odo.36.10.ave-maria` | REKEY | DESIGN_INPUT |
| variant | `5f6c98ca-869a-4418-a8a4-1a32eb931c5e` | `nhk:variant:o-do.36.10.two-tune` | `nhk:variant:odo.36.10.two-tune` | REKEY | DESIGN_INPUT |
| variant | `28b4a74e-5d9b-4de5-acf0-8d5f6df4ae6e` | `nhk:variant:o-do.36.39` | — | REVIEW | DESIGN_INPUT |
| variant | `f60febc6-0e81-460e-a7f2-1addbedcace4` | `nhk:variant:o-do.36.35` | — | RETIREMENT_REVIEW | DESIGN_INPUT |

## Confirmed and candidate duplicate records

| Source UUID/key | Target UUID/key | Decision | Runtime result |
|---|---|---|---|
| `32f43d4b-d6c8-4223-a89b-cc47f30cda77` / `nhk:component:o-do.dial.applied-pinned` | `48311ccd-9d45-4985-a620-ca579499f02c` / `nhk:component:odo.dial.applied-pinned` | Owner-confirmed same identity; merge required | `UNVERIFIED`; no merge operation exists in current runtime |
| `01bead27-1308-48c1-af99-c68318e2b577` / `nhk:component:o-do.dial.applied-glued` | `e326a326-ae8c-447f-a2a4-a83a3cf168d4` / `nhk:component:odo.dial.applied-glued` | Merge candidate only; do not merge | `UNVERIFIED`; intentionally untouched |

## Reference inventory required before mutation

The following must be collected from the Graph/domain read boundaries after
runtime restoration. No inference from names or pack prose is allowed.

| Scope | Required read | Current result |
|---|---|---|
| All Odo Authority records | active/retired UUID, type, stable key, name, revision, state, payload | `UNAVAILABLE` |
| All Odo UUIDs | active inbound and outbound Graph edges with predicate/revision | `UNAVAILABLE` |
| Source/Evidence | claims and evidence that reference Odo subjects or related proposals | `UNAVAILABLE` |
| Media/MediaUsage | canonical Media and usage references for Odo subjects | `UNAVAILABLE` |
| Video | Video references/attachments related to Odo subjects | `UNAVAILABLE` |
| WordPress Posts | `wp_post:<blog_id>:38`, `39`, `40`, `55` Graph references and editorial fingerprints | `UNAVAILABLE` |
| Odo 35 | model, movement and variant inbound/outbound references | `UNAVAILABLE` |

## Predicate resolution status

The current code registry contains `about`, `depicts`, `model_of`, `variant_of`,
`uses_movement`, `supports_music`, `configured_with_music` and
`observed_playing_music`. No mutation was attempted.

The following intents remain unresolved until actual endpoint rows, evidence
and directionality are reviewed:

- Model → Movement: no registered specific predicate.
- Variant → Component and Variant → Classification: no registered specific
  predicate; broad `about` cannot be silently treated as a domain contract.
- Knowledge/Source/Evidence and Post associations: only use `about` where the
  governed proposal explicitly establishes that association and the endpoint
  contract permits it.
- Product–Specimen: explicitly `REGISTRY_GAP`; do not use Product payload or
  broad `about` as an ownership relation.

## Runtime capability gaps and required stop codes

| Required phase | Current capability | Required outcome |
|---|---|---|
| Namespace rekey preserving UUID | No Authority `rekey` operation; repository update does not change stable key | `CONTRACT_EXTENSION_REQUIRED` before any proposal/apply |
| Confirmed component merge | No generic governed merge/reference-move/deprecation operation | `CONTRACT_EXTENSION_REQUIRED`; source remains untouched |
| Odo 35 retirement | Retirement exists, but reference audit and runtime are unavailable | `CONTRACT_EXTENSION_REQUIRED` for safe apply until audit/capability is available |
| Media placeholders | No current governed placeholder operation | Keep requirements only; no fake Media/file/URL |
| Video placeholders | No current governed placeholder operation | Keep requirements only; no Video entity |
| Post reconciliation | Runtime Article path is reconcile-only but WordPress is unavailable | Do not create proposal/apply or alter Posts |

## Next safe action after runtime restoration

1. Re-run read-only preflight and capture runtime counts/revisions.
2. Export all inbound/outbound relations and Source/Evidence/Media/Video/Post
   references through application read boundaries.
3. Resolve target collisions before any proposal.
4. Obtain a reviewed generic V3 `rekey` capability and generic merge/reference
   migration capability, or remain fail-closed.
5. Create proposals only after capability and reference audit are complete;
   stop at the separate Human Approval/Controlled Apply boundary.
