# V3-1309 VIDEO BACKLOG RECOVERY + ENRICHMENT

## Executive result

The actual target was freshly proven through the `@V3-1309` canonical runtime:
the site is `https://demo.1945.vn`, the environment is `staging`, and the
canonical Video for golden case #372 is present. The local `nhk_v3` database
was not used as a substitute.

No remote mutation was performed. The Constitution and `AGENTS.md` make the
staging target read-only, and the connected runtime exposes no governed Video
Apply/reconciliation writer. Therefore recovery, content enrichment, Public
Identity allocation and republish are fail-closed pending an isolated
non-staging canonical write target. This is a target-scope block, not an empty
backlog conclusion.

## Target proof and checkpoint

| Field | Read-back |
|---|---|
| Site | `https://demo.1945.vn` |
| Environment | `staging` |
| WordPress | `7.1`, language `en-US` |
| DB/server identity exposed by safe runtime | MariaDB `10.11.13-MariaDB-cll-lve-log`; database name is not exposed by this read boundary |
| Runtime version | `0.1.0` |
| Documentation version | `8bc7971937567dde502c7915e9ef41aca1fc8ff12a6f84b449c4818f094645ba` |
| Manifest hash | `1c417670567b624e4a7a0af25c4068b55ac046b6376d4d80f177b6cc1c02032e` |
| Build identity | `a85123462b9d571929212df1f95dcebaef43cf654c0ab4cb5cc0e16cebba07c9` |
| Golden Capture | `01a094df-6e43-7226-a3a5-78a6c4c05c7e` |
| Golden Video | `01a094df-6ff2-7872-9bbe-ca4e843a68ef` |
| Golden Variant | `852da54d-457a-4397-a16d-52d9452ba766`, `nhk:variant:odo.36.8` |
| Golden route | `/video/so-372-odo-36-8-con-nguyen-ban-am-thanh-hay/` |
| Golden proof | `YES`: `nhk.video.get` returned the canonical Video; public detail and `/video/` collection were observed |

The Capture inventory is not exposed by the safe canonical connector
(`canonical_inventory` returned no Capture object type and no `capture_get`
boundary is available). This is recorded as unavailable read-back, not as
proof that the actual target has zero Captures.

## Inventory totals

| Object/result | Count |
|---|---:|
| Canonical Video rows | 6 |
| Active Video Graph edges | 11 |
| Video Proposals | 29 |
| Applied Proposals | 11 |
| Approved, never-applied Proposals | 18 |
| Applied orphan canonical IDs | 5 |
| Public Video routes currently present | 2 |
| Public Identity rows read for these Videos | 2 |
| Canonical Videos with current `publishable=true` | 5 |
| Canonical Videos requiring content review | 5 (golden #372 is the control) |

The two current public routes are `truOChTNbwA` and golden #372. Four active
canonical Videos have `public_url_audit=ALLOCATE`. Video #16 has active Graph
edges but an empty semantic-attachment read model. Video `V18Me9TdnkU` has an
attachment and active Graph edge but stale completeness reporting
`NO_SEMANTIC_ATTACHMENT`.

## Canonical Video working table

`Unknown` means the field was not safely exposed by the target connector; it
does not authorize reconstruction or a placeholder. `Observed` is deliberately
kept separate from the canonical `frontend VERIFIED` status.

| External ID | Capture / Post | Proposal / state | Video / semantic subject | Source / Claim / Evidence | Attachment / Graph | Completeness / identity / route | Frontend / content / exact blocker |
|---|---|---|---|---|---|---|---|
| `P4KaHX3LBOw` | Capture unknown / Post unknown | `01a06815-1e63-73c6-8ecc-77d14c583de6` / applied | `01a06815-1e51-7964-b004-1ba79e488ad1` / Variant `95873bfe-d978-4eda-a5a2-ce9ba79625df` | Source `01a06695-50d2-7f3b-a5a3-c27ebfe4e255`; Claim `01a06696-24ce-70be-a6c9-4fb4d7f3cfbd`; Evidence `01a06696-acae-7083-823d-91dbb30dca7f` | `about` Variant; active Graph edge | `publishable=true`, no blockers; Public Identity absent; audit `ALLOCATE`; no route | Detail/listing absent. `CONTENT_NEEDS_REVIEW`: source fact about Gai-Carillon, canonical Variant context, Knowledge `01a06696-24ce-70be-a6c9-4fb4d7f3cfbd`. Blocker: missing public identity/route and enrichment not completed |
| `TsQWw2Q6-HM` | Capture unknown / Post unknown | `01a072e9-45cd-73a0-8a1a-4a41752e3a76` / applied | `01a072e9-45a0-7d81-8ecf-52d3e091165f` / Variant `95873bfe-d978-4eda-a5a2-ce9ba79625df` | Source `01a072e8-28b2-7d7b-a708-6157298ba29f`; Claim `01a072e8-aa9d-71a2-b8bc-7012abdd813f`; Evidence `01a072e9-183c-7045-9e8a-84111260fa74` | `about` Variant + `about` Music `4b01eb30-2b44-4c9c-a000-781bb8cb9206`; active Graph edges | `publishable=true`, no blockers; Public Identity absent; audit `ALLOCATE`; no route | Detail/listing absent. `CONTENT_NEEDS_REVIEW`: quarter-hour Gai-Carillon source fact, Variant/Music context, Knowledge `01a072e8-aa9d-71a2-b8bc-7012abdd813f`. Blocker: missing public identity/route and enrichment not completed |
| `4d4oxh35cT8` | Capture unknown / Post unknown | `01a07971-3003-732b-9842-04c51a2669af` / applied | `01a07971-2fe3-77da-9424-998cf6f249e0` / Variant `852da54d-457a-4397-a16d-52d9452ba766` | Source/Claim/Evidence not safely exposed; no placeholder created | No semantic attachment read back; active Graph edges to Variant and four Classifications | `publishable=false`; blocker `NO_SEMANTIC_ATTACHMENT`; Public Identity absent; audit `ALLOCATE`; no route | Detail/listing absent. `CONTENT_NEEDS_REVIEW`: title/source specimen wording only plus exact Variant context; no related Knowledge safely reused. Blocker: attachment read-model inconsistency; requires governed reconciliation and current completeness |
| `truOChTNbwA` | Capture unknown / Post unknown | `01a07af5-335f-7e83-a3aa-7f805ef9fb7c` / applied | `01a07af5-3303-7a73-9f15-b7f675293dc5` / Variant `95873bfe-d978-4eda-a5a2-ce9ba79625df` | Source `01a07af3-d8ca-7269-af3e-e597e02b15f5` (private); Evidence `01a07af5-18d7-75fc-9170-5947d146c68a` (private); Claim not exposed | `about` Variant; active Graph edge | `publishable=true`, no blockers; Identity `01a07bc4-b2dc-7255-a6a3-9045e35a06f4`; current route; audit `KEEP` | Detail and `/video/` listing observed. Canonical VERIFIED flag not exposed. `CONTENT_NEEDS_REVIEW`: specimen/source facts and Variant context; related Knowledge `01a07af4-9cdc-702d-945d-4cfcdcfbe22f`. Blocker: no authoritative VERIFIED read-back and enrichment not completed |
| `V18Me9TdnkU` | Capture unknown / Post unknown | `01a094a1-3a18-73be-9937-41eb9409f485` / applied | `01a094a1-3824-7bba-9e3f-9b9fcaf20755` / Variant `852da54d-457a-4397-a16d-52d9452ba766` | Source/Claim not safely exposed; Evidence `01a094a1-3a06-739f-b4d0-b24c227f9f18` retained in attachment but private read-back | `about` Variant; active Graph edge `01a094d8-f87c-77ef-a870-47e837e6f4d3` | `publishable=false` with stale `NO_SEMANTIC_ATTACHMENT` despite attachment; Identity absent; audit `ALLOCATE`; no route | Detail/listing absent. `CONTENT_NEEDS_REVIEW`: specimen wording “thùng kính chuông, mặt số nổi nằm ngang” and Variant context; no unsupported universal claim. Blocker: stale completeness plus missing public identity/route |
| `iqbGOL967t4` | Capture `01a094df-6e43-7226-a3a5-78a6c4c05c7e` / Post 454 | `01a094df-7198-7c74-b599-3074c604116e` / applied | `01a094df-6ff2-7872-9bbe-ca4e843a68ef` / Variant `852da54d-457a-4397-a16d-52d9452ba766` | Evidence `01a094df-7188-799c-8e93-7ef37ded47cb`; Source/Claim not exposed in this read boundary | `about` Variant; active Graph edge `01a094df-72b4-71f3-ae0f-9125e0f162ff` | `publishable=true`, no blockers; Identity `01a094df-72cb-7a30-bb1e-bc783f014fd6`; current route; audit `KEEP` | Detail/listing observed and supplied status `VERIFIED`. `CONTENT_COMPLETE` as golden control; warning only `TRANSCRIPT_UNAVAILABLE`. No mutation |

## Proposal backlog and classification

The safe Proposal review surface read 29 rows in six deterministic waves. The
11 applied rows were not repaired or re-applied. Six are the owners above; the
other five are applied orphan candidates for the same external ID and are a
data-conflict group.

### Approved, never applied — 18 rows

| Proposal ID | External ID | Exact read-back blocker / recovery disposition |
|---|---|---|
| `01a09131-5da8-7f80-aa57-e23f8278eefa` | `EU1gVKPGdjg` | `NO_SEMANTIC_ATTACHMENT`; exact Variant `5f6c98ca-869a-4418-a8a4-1a32eb931c5e`; controlled Apply candidate on an authorized non-staging target |
| `01a08c68-ab9d-70ca-8a4b-4411779b56b6` | `ozfSWTDi-tM` | `NO_SEMANTIC_ATTACHMENT`; exact Variant 36/8; controlled Apply candidate |
| `01a07af3-517f-7315-a772-2441b7029c1e` | `truOChTNbwA` | `NO_SEMANTIC_ATTACHMENT`; canonical owner already exists, so search/reuse owner; never create duplicate |
| `01a0796f-ebe8-7592-8475-c0d482ba3efe` | `3x9naQn1H_4` | `NO_SEMANTIC_ATTACHMENT`; no exact subject; approved but not eligible for blind Apply |
| `01a0796e-c75f-72b3-99bb-753b2850c1b1` | `3x9naQn1H_4` | Duplicate Proposal/external identity conflict; fail closed; reuse/search required |
| `01a06b7d-7f70-7af6-995e-d4039723c027` | `b5O4J74HhVc` | `NO_SEMANTIC_ATTACHMENT`; no exact subject; controlled recovery requires subject resolution |
| `01a08c4b-d01a-7bb7-9458-8cbf1209e784` | `r1b8b2Rd-lk` | `NO_SEMANTIC_ATTACHMENT`; exact Variant 36/8; controlled recovery candidate |
| `01a08c41-3f26-746a-891c-b38ed1865e8a` | `LfBwZ5lRiBE` | `NO_SEMANTIC_ATTACHMENT`; exact Variant 36/8; controlled recovery candidate |
| `01a08c2a-7a5b-7881-aa3f-7a7288e1670f` | `0pTo9mJdzZY` | `CATEGORY_UNRESOLVED` + `NO_SEMANTIC_ATTACHMENT`; only Brand Odo resolved; fail closed |
| `01a08bfa-6cd4-736b-be4b-258bbf2d6616` | `X7QFsESWIvY` | `NO_SEMANTIC_ATTACHMENT`; no exact subject; fail closed until resolved |
| `01a07a27-b4aa-7696-bd20-381cadbd00dc` | `WjGvZ04x4eU` | `NO_SEMANTIC_ATTACHMENT`; no exact subject; fail closed until resolved |
| `01a077ef-ece0-7179-b2e5-79ca3310dd87` | `Jkp_XzVNIAg` | `NO_SEMANTIC_ATTACHMENT`; no exact subject; fail closed until resolved |
| `01a06b7d-ce19-788f-b166-124ef4bbd9e0` | unavailable | `PROPOSAL_BINDING_INVALID`; safe review exposed only `subject_id=video`, revision 3; no Apply |
| `01a06696-cd27-7f4a-b497-79d564de04da` | `P4KaHX3LBOw` | `SOURCE_UNAVAILABLE`; duplicate external candidate; canonical owner exists; no duplicate Video |
| `01a06690-d58a-79bb-a8db-2c6e6636b9e0` | `B1r1jhfxOAQ` | `SOURCE_UNAVAILABLE`; attachment references Variant/evidence but source eligibility is not proven |
| `01a0666c-2ebf-7e65-b3fe-c14fa383a97c` | `B1r1jhfxOAQ` | `SOURCE_UNAVAILABLE` + `SOURCE_NOT_EMBEDDABLE`; duplicate external candidate; fail closed |
| `01a06659-d156-71aa-8d7b-a5b36a5a7f5f` | `SaLpWgitdSE` | `SOURCE_UNAVAILABLE` + `SOURCE_NOT_EMBEDDABLE`; user-hint attachment is not canonical Evidence |
| `01a065d5-a7e0-7092-a798-2decd42213b5` | unavailable | `PROPOSAL_BINDING_INVALID`; safe review exposed only revision 3; no Apply |

### Applied orphan/data-conflict group — 5 rows

| Proposal ID | Orphan canonical Video ID | External ID | Disposition |
|---|---|---|---|
| `01a0675e-d02e-7bda-a931-464ad7eaf5c8` | `01a0675e-d01b-7c28-bfdd-c880d84c330e` | `P4KaHX3LBOw` | Applied immutable Proposal; canonical owner absent; duplicate/identity conflict; fail closed |
| `01a066b6-853e-740a-9360-4ba5e81d5d1f` | `01a066b6-852a-713a-81cf-4cccabfec717` | `P4KaHX3LBOw` | Same; payload reports `YOUTUBE_API_NOT_CONFIGURED` / `SOURCE_UNAVAILABLE` |
| `01a066b0-3414-7c61-bd1a-0a5e07a37686` | `01a066b0-33ee-727b-a5ad-8e6b27290c8e` | `P4KaHX3LBOw` | Same; no repair or re-apply |
| `01a06802-adca-7d15-aaa4-e93b1bc2ea0d` | `01a06802-adb7-7e79-8821-599897731020` | `P4KaHX3LBOw` | Same; no repair or re-apply |
| `01a0677b-171f-7e35-a0c8-7c7615fd2d44` | `01a0677b-1710-7295-9ec4-71c241dd066a` | `P4KaHX3LBOw` | Same; no repair or re-apply |

The six applied owner proposals were read back as applied and left immutable:
`01a06815-1e63-73c6-8ecc-77d14c583de6`,
`01a072e9-45cd-73a0-8a1a-4a41752e3a76`,
`01a07971-3003-732b-9842-04c51a2669af`,
`01a07af5-335f-7e83-a3aa-7f805ef9fb7c`,
`01a094a1-3a18-73be-9937-41eb9409f485`, and
`01a094df-7198-7c74-b599-3074c604116e`.

## Wave execution

| Wave | Read-only work | Mutation result |
|---:|---|---|
| 1 | Proposal review rows 1–5; canonical inventory and target proof | 0 mutations |
| 2 | Proposal review rows 6–10; canonical Video/Graph cross-check | 0 mutations |
| 3 | Proposal review rows 11–15; Source/Evidence/Knowledge reuse checks | 0 mutations |
| 4 | Proposal review rows 16–20; Public URL audit | 0 mutations |
| 5 | Proposal review rows 21–25; applied-owner/orphan cross-check | 0 mutations |
| 6 | Proposal review rows 26–29; final canonical/public read-back | 0 mutations |

The runtime rate-limited repeated Proposal eligibility calls. This does not
change the fail-closed result: no eligibility result was treated as Apply
authorization, and no mutation was attempted.

## Content enrichment result

No Video was enriched because editorial enrichment must be committed through
the governed canonical workflow, and that workflow cannot write to staging.
The read-only context nevertheless established safe reuse candidates:

- `P4KaHX3LBOw`: source fact “Bản nhạc đầy đủ Gai-Carillon trên Ô Đô 36/10”;
  Variant `95873...`; Knowledge `01a06696-24ce-70be-a6c9-4fb4d7f3cfbd`.
- `TsQWw2Q6-HM`: source fact about quarter-hour Gai-Carillon sound; Variant
  and Music `4b01eb30-2b44-4c9c-a000-781bb8cb9206`; Knowledge
  `01a072e8-aa9d-71a2-b8bc-7012abdd813f`.
- `truOChTNbwA`: specimen/source wording about the raised dial/xương cá;
  Variant `95873...`; Knowledge `01a07af4-9cdc-702d-945d-4cfcdcfbe22f`.
- `4d4oxh35cT8` and `V18Me9TdnkU`: only exact specimen wording and Variant
  context were safe to carry forward; no unsupported universal facts or new
  Knowledge were created.
- `iqbGOL967t4`: unchanged golden control. Its specimen facts remain scoped
  to the #372 recording; `TRANSCRIPT_UNAVAILABLE` remains warning-only.

All five non-control canonical pages are therefore `CONTENT_NEEDS_REVIEW`,
not content-complete. No duplicate Knowledge was created.

## Final status

| Result | Count |
|---|---:|
| Candidates found | 29 Proposals / 6 canonical Videos |
| Already `COMPLETE + VERIFIED` | 1 control (#372) |
| Recovered successfully | 0 |
| Content enriched | 0 |
| Frontend VERIFIED | 1 supplied control; one additional public page is only observed, not authoritative VERIFIED read-back |
| Still blocked / not accepted | 28 of the 29 Proposal candidates; the same set covers the five non-control canonical owners |
| Duplicate Capture created | 0 |
| Duplicate Video created | 0 |
| Duplicate Variant/semantic entity created | 0 |
| Duplicate Source/Claim/Evidence/Graph edge/Identity/route created | 0 |
| Articles auto-published | 0 |

### Remaining blockers and required next action

1. The target is `staging`; Constitution/AGENTS prohibit mutation there.
2. The connector exposes read-only canonical inventory and lookup surfaces, but
   no governed Video Proposal Apply, post-apply reconciliation, semantic
   attachment reconciliation, completeness recompute, Public Identity
   allocation or public URL projection writer.
3. Five applied P4 candidates are immutable orphan/data-conflict rows and must
   not be repaired or re-applied. The two duplicate approved external groups
   (`P4KaHX3LBOw` and `B1r1jhfxOAQ`) require canonical-owner search and
   identity resolution before any Apply.
4. The next safe action is to point the workflow at an isolated, governed
   non-staging canonical runtime with the current contracts and write boundary,
   then resume in waves. Article Posts must remain draft.

## Acceptance summary

The actual target proof is complete, the backlog inventory is complete within
the exposed safe boundaries, and the recovery outcome is intentionally
`FAIL_CLOSED`. No route, identity, semantic row, Proposal state, Article
status or frontend publication was changed by this audit.
