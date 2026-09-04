# ODO SEMANTIC REFERENCE PACK — NHK V3

> **Status:** APPROVED DATA DESIGN  
> **Scope:** Odo semantic data/reference pack for NHK V3  
> **Purpose:** Source-of-instruction for governed generation, merge, rekey, relationship creation, Knowledge enrichment, Media/Video placeholders, Article reconciliation, and future brand-pack reuse.  
> **Normative precedence:** `AGENTS.md` → `docs/constitution/READ_FIRST.md` → canonical NHK V3 Constitution → runtime registries/contracts → this pack.  
> **Important:** This document is **not** a new Constitution and must never override runtime Registry or governed-write contracts.

---

## 0. Owner decisions locked for this pack

1. Canonical display name is **Odo**.
2. Canonical stable-key namespace is **`odo`**. Do not create any new stable key containing `o-do`.
3. Existing `o-do` keys must be moved by **governed rekey / mapping / merge / deprecation**, preserving UUID and references when possible. Never hard-delete a referenced record merely to normalize spelling.
4. Odo 35 is excluded from the Odo reference pack and enters **retirement review**. It is not blindly deleted.
5. `Mặt số nổi chân cài Odo` and `Mặt số nổi chân cài` are the **same canonical component**. Canonical target: `nhk:component:odo.dial.applied-pinned`.
6. WordPress remains Editorial Authority for Article title/body/revisions/permalink. No Authority `article` body is created.
7. FAQ, Timeline, Recognition Profile and Recognition Rule are projections/derived semantic views unless the runtime Registry explicitly contains governed entity types for them.
8. Odo 36, Odo 54, Odo 57 and Odo 62 may all have Vietnamese “nội địa” Knowledge branches. The owner will enrich these branches later.
9. Media and Video knowledge will be added later. The system may create governed placeholder requirements/nodes **only if the current runtime contract supports placeholder creation without fake files or fake URLs**.
10. Odo is the first reusable **Brand Semantic Pack pattern**. Do not create architecture that only works for Odo.

---

# 1. Architectural role

Odo is a Brand semantic backbone, but the graph is not a rigid tree.

The public and semantic system must support navigation such as:

```text
Odo
↔ Model
↔ Movement
↔ Variant
↔ Component
↔ Classification
↔ Music
↔ Knowledge
↔ Source
↔ Evidence
↔ Media
↔ Video
↔ wp_post
```

and also reverse/facet entry points:

```text
Côn 111
↔ Odo variants using Côn 111
↔ Recognition Knowledge
↔ Media showing Côn 111
↔ Articles mentioning it
```

or:

```text
Westminster
↔ Odo
↔ other brands
↔ variants
↔ articles
↔ media/video
```

No new entity type may be created merely because a business concept exists in prose.

---

# 2. Mapping from business concepts to existing NHK V3 structures

| Business concept | V3 representation |
|---|---|
| Brand | `brand` |
| Clock Type | `classification:clock-type.*` |
| Model | `model` |
| Movement | `movement` |
| Variant | `variant` |
| Component | `component` |
| Recognition feature | `classification:recognition-feature.*` |
| Case form | `classification:case-form.*` |
| Material | `classification:material.*` |
| Music | `music` shared across brands |
| Fact / community knowledge / expert observation / claim | `knowledge` using actual runtime statement/status vocabulary |
| Source | `source` |
| Evidence | `evidence` |
| Article | native WordPress Post; Graph endpoint `wp_post:<blog_id>:<post_id>` |
| FAQ | projection from Knowledge + Article |
| Timeline | projection from dated Knowledge |
| Recognition Profile | graph projection from Model/Variant relations + Knowledge |
| Recognition Rule | structured Knowledge/inference statement + Evidence/confidence when contract supports it |
| Media | canonical Media + WP attachment projection/mapping according to V3 media law |
| Video | Video entity/record according to V3 video contract |
| Product line | reuse Model/Variant/Classification; do not invent type unless future Registry amendment explicitly adds one |
| Concept | Knowledge or Classification; do not invent `concept` type |

---

# 3. Canonical namespace law for Odo

## 3.1 Canonical rule

All new Odo-owned stable keys use:

```text
nhk:<entity-type>:odo...
```

Examples:

```text
nhk:brand:odo
nhk:model:odo.24
nhk:movement:odo.24
nhk:variant:odo.24.54
nhk:component:odo.gong-block.111
```

Shared entities keep their shared namespaces:

```text
nhk:music:westminster
nhk:music:ave-maria-lourdes
nhk:music:gai-carillon
nhk:music:sonodo
nhk:music:cloches-comtoises
nhk:classification:clock-type.wall-clock
nhk:classification:case-form.wide-case
```

## 3.2 Existing legacy namespace

Existing UUIDs are not recreated merely to normalize a stable key.

Required order:

```text
inventory references
→ preflight canonical target
→ preserve UUID where rekey is supported
→ otherwise create canonical mapping/merge proposal
→ move/rewrite governed relations
→ verify read-back
→ mark old key deprecated/superseded
→ retire old key only when no active references remain
```

Forbidden:

- direct SQL;
- blind delete + recreate;
- changing UUID just to normalize spelling;
- leaving two active canonical identities for one object;
- creating any new legacy-form stable key.

---

# 4. Current canonical identity map

The following records were observed in runtime and must be **reused**, not recreated.

## 4.1 Brand

| Current UUID | Legacy key | Canonical target |
|---|---|---|
| `d2af7739-3d1b-4666-ad0a-aeda0758f4d8` | `nhk:brand:o-do` | `nhk:brand:odo` |

Canonical display name: **Odo**.

## 4.2 Models

| Model | Current UUID | Legacy/current key | Action | Canonical target |
|---|---|---|---|---|
| Odo 24 | `984658bf-19a6-4daa-a220-2a6c13af81ed` | `nhk:model:o-do.24` | REKEY | `nhk:model:odo.24` |
| Odo 30 | `fdf5bfd5-d3f4-4281-a39e-77c9271bcf4a` | `nhk:model:o-do.30` | REKEY | `nhk:model:odo.30` |
| Odo 36 | `c01c109c-5d39-401e-a16e-6d61a0a52f50` | `nhk:model:o-do.36` | REKEY | `nhk:model:odo.36` |
| Odo 39 | `d39bfeae-40c4-47c2-a050-94ca56c8c93b` | `nhk:model:o-do.39` | REKEY | `nhk:model:odo.39` |
| Odo 20 | `dd76ee46-2f76-4c65-a70b-73aae8a7e698` | `nhk:model:o-do.20` | REVIEW/KEEP | proposed `nhk:model:odo.20` if retained |
| Odo Jacquemar | `30515de5-efe5-48e1-aec5-34130509a4dc` | `nhk:model:odo.jacquemar` | REUSE | unchanged |
| Odo 35 | `fc86a551-06eb-48da-a765-5578e70bf4c9` | `nhk:model:o-do.35` | RETIREMENT_REVIEW | exclude from active pack |

Odo 20 exists in runtime but is not developed in the owner master brief. Keep it in a review state; do not delete or expand it without evidence.

## 4.3 Movements

| Movement | Current UUID | Legacy/current key | Action | Canonical target |
|---|---|---|---|---|
| Máy Odo 24 | `f6342492-729e-4d01-aa67-8fa19c60c619` | `nhk:movement:o-do.24` | REKEY | `nhk:movement:odo.24` |
| Máy Odo 30 | `200ac862-e7c3-4434-aa01-10edc47d31b7` | `nhk:movement:o-do.30` | REKEY | `nhk:movement:odo.30` |
| Máy Odo 36 | `08fea152-2faf-47f6-a8af-d58c0324e04a` | `nhk:movement:o-do.36` | REKEY | `nhk:movement:odo.36` |
| Máy Odo 39 | `1f66321f-9940-4359-a47b-7d68734da41e` | `nhk:movement:o-do.39` | REKEY | `nhk:movement:odo.39` |
| Máy Odo 20 | `d11c546f-5c9d-4399-a04b-ddb2a121bcd7` | `nhk:movement:o-do.20` | REVIEW/KEEP | proposed `nhk:movement:odo.20` if retained |
| Máy Odo 35 | `63eb0f6d-4b38-4a34-aa27-d02f5dbe76f5` | `nhk:movement:o-do.35` | RETIREMENT_REVIEW | exclude from active pack |

---

# 5. Canonical variant map

## 5.1 Odo 24 community branches

| Variant | UUID | Legacy key | Canonical target |
|---|---|---|---|
| Odo 54 | `72c1ed8a-3626-465e-ae1d-af12c0fae68f` | `nhk:variant:o-do.24.54` | `nhk:variant:odo.24.54` |
| Odo 57 | `7301f50c-ef0d-4e95-a581-39e5063d4648` | `nhk:variant:o-do.24.57` | `nhk:variant:odo.24.57` |
| Odo 62 | `8d6da0b0-28e7-49d6-a73c-b1f30e13879d` | `nhk:variant:o-do.24.62` | `nhk:variant:odo.24.62` |
| Odo 58 | `11f8c058-e3bd-416b-adb0-f9bbb2854ad8` | `nhk:variant:o-do.24.58` | `nhk:variant:odo.24.58` |
| Odo 20 branch | `e2d0ab8b-761e-4c8a-a3db-978ce508670a` | `nhk:variant:o-do.24.20` | REVIEW; no automatic expansion |

54/57/62 are community/market naming branches under Odo 24. They are **not** automatically official manufacturer model codes or production years.

## 5.2 Odo 54 subvariants

| Variant | UUID | Legacy key | Canonical target |
|---|---|---|---|
| 8 côn 8 búa Westminster | `9d21258b-e99f-44d2-a0a0-dec050b45338` | `nhk:variant:o-do.24.54.8-8-westminster` | `nhk:variant:odo.24.54.8-8-westminster` |
| 6 côn 10 búa hai bài | `3290b880-a449-4056-a890-13701d7bc5e0` | `nhk:variant:o-do.24.54.6-10-two-tune` | `nhk:variant:odo.24.54.6-10-two-tune` |
| 10 côn 10 búa Ave Maria | `e1452027-e2a4-4222-aeb5-fb45e6916b3c` | `nhk:variant:o-do.24.54.10-10-ave` | `nhk:variant:odo.24.54.10-10-ave` |
| 10 côn 11 búa hai bài | `f1f08304-19b2-45e5-aa9d-3a9ec460b366` | `nhk:variant:o-do.24.54.10-11-two-tune` | `nhk:variant:odo.24.54.10-11-two-tune` |
| 10 côn 10 búa hai bài | `5812357b-7a66-4f3d-aec5-d50d65d7f8f6` | `nhk:variant:o-do.24.54.10-10-two-tune` | `nhk:variant:odo.24.54.10-10-two-tune` |

## 5.3 Odo 30

| Variant | UUID | Legacy key | Canonical target |
|---|---|---|---|
| Odo 30/8 | `c2435242-6836-4feb-ac88-93799e27390c` | `nhk:variant:o-do.30.8` | `nhk:variant:odo.30.8` |
| Odo 30/10 | `fb58a9cf-f8ac-45aa-aced-98b1b403c43d` | `nhk:variant:o-do.30.10` | `nhk:variant:odo.30.10` |

Do not clone Odo 36 configuration into Odo 30. Build only evidence-backed relations.

## 5.4 Odo 36

| Variant | UUID | Legacy key | Canonical target |
|---|---|---|---|
| Odo 36/8 | `852da54d-457a-4397-a16d-52d9452ba766` | `nhk:variant:o-do.36.8` | `nhk:variant:odo.36.8` |
| Odo 36/8 Westminster | `dc874471-5554-48c0-a4da-8f9b81e2e283` | `nhk:variant:o-do.36.8.westminster` | `nhk:variant:odo.36.8.westminster` |
| Odo 36/8 hai bài | `79be7459-3fad-4ae3-acfe-6833cbd076c8` | `nhk:variant:o-do.36.8.two-tune` | `nhk:variant:odo.36.8.two-tune` |
| Odo 36/10 | `95873bfe-d978-4eda-a5a2-ce9ba79625df` | `nhk:variant:o-do.36.10` | `nhk:variant:odo.36.10` |
| Odo 36/10 Ave Maria Lourdes | `1108f512-1250-472e-a0bf-8edf6a93dd94` | `nhk:variant:o-do.36.10.ave-maria` | `nhk:variant:odo.36.10.ave-maria` |
| Odo 36/10 hai bài | `5f6c98ca-869a-4418-a8a4-1a32eb931c5e` | `nhk:variant:o-do.36.10.two-tune` | `nhk:variant:odo.36.10.two-tune` |
| Odo 36 → Odo 39 legacy/config record | `28b4a74e-5d9b-4de5-acf0-8d5f6df4ae6e` | `nhk:variant:o-do.36.39` | REVIEW |
| Odo 36 → Odo 35 legacy/config record | `f60febc6-0e81-460e-a7f2-1addbedcace4` | `nhk:variant:o-do.36.35` | RETIREMENT_REVIEW |

---

# 6. Components: reuse, rekey and merge

## 6.1 Confirmed canonical merge

Owner-confirmed duplicate:

| Record | UUID | Key | Decision |
|---|---|---|---|
| Mặt số nổi chân cài Odo | `32f43d4b-d6c8-4223-a89b-cc47f30cda77` | `nhk:component:o-do.dial.applied-pinned` | MERGE SOURCE |
| Mặt số nổi chân cài | `48311ccd-9d45-4985-a620-ca579499f02c` | `nhk:component:odo.dial.applied-pinned` | CANONICAL TARGET |

Required merge sequence:

```text
read both records
→ compare payload/provenance/revisions
→ inventory inbound/outbound relations
→ move/merge relations to canonical UUID
→ move/merge Media/Evidence references
→ dedupe active triples
→ verify canonical record
→ mark source superseded/deprecated
→ retire source only after zero active references
```

Do not create a third component.

## 6.2 Duplicate candidate requiring review

Observed analogous pair:

- `nhk:component:o-do.dial.applied-glued`
- `nhk:component:odo.dial.applied-glued`

This pair is **not owner-confirmed in this pack**. Mark `MERGE_CANDIDATE`; do not merge until payload/provenance/reference review confirms identity.

## 6.3 Odo-owned component namespace normalization

All active Odo-owned components whose stable keys begin with `nhk:component:o-do.` must be collision-checked and moved to the same suffix under `nhk:component:odo.`.

Examples already observed in runtime include gong blocks 101/111/121, trough gong blocks, M gong blocks, rod mounts, hammer types, hand families, dial sizes/forms, glass, plate forms and berry plate.

Already-canonical `odo` records are reused, including:

```text
nhk:component:odo.hand.54
nhk:component:odo.gong-block.brown-unnumbered
nhk:component:odo.gong-block.square-boss
nhk:component:odo.gong-block.u
nhk:component:odo.dial.applied-pinned
nhk:component:odo.dial.applied-glued
```

---

# 7. Shared classifications: reuse, never duplicate per brand

## Clock types

```text
nhk:classification:clock-type.wall-clock
nhk:classification:clock-type.cabinet-clock
nhk:classification:clock-type.mantel-clock
nhk:classification:clock-type.table-clock
nhk:classification:clock-type.automaton-jacquemar-clock
```

## Case forms

```text
nhk:classification:case-form.wide-case
nhk:classification:case-form.medium-case
nhk:classification:case-form.long-case
```

## Recognition features relevant to Odo

Reuse the existing generic recognition features for foot types, coarse/fine berry plate, smooth/orange/open/spiral/flat/raised-foot plate, one/three plate, brass/aluminum plate, black/white posts, ellipse/diamond logos, hammer types, rod-mount types, gong-block markings, rod/hammer counts, sound-source types, dial sizes/forms and numeral construction/material/finish.

Do not create brand-prefixed Classification duplicates unless the concept is genuinely brand-specific and the Registry model requires it.

---

# 8. Shared Music nodes

Reuse:

```text
nhk:music:westminster
nhk:music:ave-maria-lourdes
nhk:music:sonodo
nhk:music:gai-carillon
nhk:music:cloches-comtoises
```

`Scotto` is not a separate Music node by default. Treat it as an alias/community-name candidate for Gai Carillon, subject to governed alias contract and evidence.

---

# 9. Desired semantic graph by branch

Predicate names below are **semantic intents**, not permission to invent predicates. At apply time each intent must resolve to an allowed Relation Registry predicate and allowed endpoint pair.

## 9.1 Brand backbone

Desired intents:

```text
Odo ↔ Model 24
Odo ↔ Model 30
Odo ↔ Model 36
Odo ↔ Model 39
Odo ↔ Jacquemar
Odo ↔ relevant Clock Types
Odo ↔ historical Knowledge
Odo ↔ shared Music through evidence-backed variants/knowledge
Odo ↔ relevant wp_posts
Odo ↔ Media/Video
```

Do not connect every Music/Component directly to Brand when the more precise relationship belongs to a Variant or Movement.

## 9.2 Odo 24

```text
Model Odo 24
↔ Movement Odo 24
↔ Variant Odo 54
↔ Variant Odo 57
↔ Variant Odo 62
↔ other retained 24 branches only when evidence supports them
```

Community names 54/57/62 must be represented as community naming/variant Knowledge, not production years.

## 9.3 Odo 54

Base recognition/association candidates:

```text
Odo 54
↔ Odo 24
↔ Máy Odo 24
↔ Chân vuông
↔ Thùng bè
↔ Búa tròn
↔ Vách trơn / Vách hoa dâu as observed, not absolute
```

Subvariant-specific candidates:

### 8/8 Westminster

```text
↔ 8 côn
↔ 8 búa
↔ Westminster
↔ Côn 101 when evidence supports the specimen/configuration
```

### 6 côn / 10 búa / two-tune

```text
↔ 6 côn
↔ 10 búa
↔ Westminster
↔ Sonodo
↔ Côn 101 or Côn 111 by configuration
```

### 10/10 Ave Maria

```text
↔ 10 côn
↔ 10 búa
↔ Ave Maria Lourdes
↔ Côn lòng máng or Côn 111 by configuration
```

### 10 côn / 11 búa / two-tune

```text
↔ 10 côn
↔ 11 búa
↔ Westminster
↔ Ave Maria Lourdes
↔ Côn 111 as common/observed, not universal without evidence
```

### 10/10 two-tune

```text
↔ 10 côn
↔ 10 búa
↔ Westminster
↔ Gai Carillon
↔ Côn lòng máng or Côn 111 by configuration
```

## 9.4 Odo 57 Recognition Profile projection

Candidate feature set:

```text
movement: Odo 24
foot: beveled / diagonal
plate: coarse berry
hammer: square
post: black
Odo marking: present
gong block: 101 or 111 depending on configuration
rod mount: threaded
```

This is an **Expert Observation / Recognition Knowledge** bundle unless documentary/specimen evidence raises individual statements to Fact.

Do not force “chân vát” and “chân chéo” into one canonical feature until image/evidence review confirms whether they are aliases or distinct forms.

## 9.5 Odo 62 Recognition Profile projection

Candidate feature set:

```text
movement: Odo 24
plate: fine berry
hammer: round
rod mount: pressed
gong block: 121 commonly observed
```

Cọc đen branch:

```text
post: black
Odo marking: reported absent in common observations
```

Cọc trắng branch:

```text
post: white
Odo marking: present
round plastic hammer tip
```

All frequency claims must use `usually observed`, `commonly observed`, or equivalent runtime Knowledge strength—not absolute language—unless corpus evidence supports universality.

## 9.6 Odo 36

Base candidates:

```text
Model Odo 36
↔ Movement Odo 36
↔ Clock Type wall clock
↔ Thùng dài
↔ Logo elip
↔ Búa vuông
```

Plate forms:

```text
Vách cam
Vách hở
Vách xoáy
Vách bệt
Vách chân kiềng
Một vách
Ba vách
Vách đồng thau
Vách nhôm where evidence confirms raised-foot machine rule
```

36/8 Westminster:

```text
↔ 8 côn
↔ 8 búa
↔ Westminster
↔ lòng máng black/white by observed plate configuration
```

36/8 two-tune:

```text
↔ Westminster
↔ Cloches Comtoises
```

36/10 Ave Maria Lourdes:

```text
↔ 10 côn
↔ 10 búa
↔ Ave Maria Lourdes
↔ Côn chữ M
```

36/10 two-tune:

```text
↔ 10 côn
↔ 10 búa
↔ Westminster
↔ Gai Carillon
↔ M traditional or M MF-style by evidence
```

Collector rarity/value must be split into separate Knowledge dimensions. Never equate rarity with collector value.

## 9.7 Odo 30

Existing identities:

```text
Odo 30
Máy Odo 30
Odo 30/8
Odo 30/10
```

Research/enrichment matrix:

```text
logo elip ↔ square hammer (limited observations)
logo diamond ↔ round hammer
plate form
gong block
music
dial
hands
case form
distinction from Odo 36
distinction from Odo 24/54
```

Do not clone 36 relations into 30.

## 9.8 Odo 39

Keep active identity. Enrich only when evidence is provided.

Required research buckets:

```text
variants
plate
gong
hammer
dial
hands
case
music
chronology
media
video
specimen corpus
```

---

# 10. Vietnamese “nội địa” Knowledge branch

Do not create a new `domestic_clock` entity type.

Create/reuse Knowledge nodes capable of later enrichment:

```text
K0: definition of “đồng hồ nội địa” in Vietnamese collector culture
K1: Odo nội địa — umbrella knowledge
K2: Odo 36 nội địa
K3: Odo 54 nội địa
K4: Odo 57 nội địa
K5: Odo 62 nội địa
```

Recommended proposed stable keys, collision-checked before creation:

```text
nhk:knowledge:odo.domestic.definition
nhk:knowledge:odo.domestic.overview
nhk:knowledge:odo.domestic.36
nhk:knowledge:odo.domestic.54
nhk:knowledge:odo.domestic.57
nhk:knowledge:odo.domestic.62
```

These are Knowledge records, not new model identities.

Each may later accumulate atomic Knowledge around originality, Vietnam circulation history, regional provenance, original movement/gong/dial, Vietnam-made dial, glued numerals, local gong finishing, local repair/modification, family history, collector value, cultural-historical value, oral history and specimen evidence.

---

# 11. Knowledge creation backlog

Each item below should become one or more **atomic Knowledge statements**, not one giant prose record.

## 11.1 Identity/community naming

- Odo 54 is a Vietnamese community/market name associated with a branch of Odo 24.
- Odo 57 is a Vietnamese community/market name associated with a branch of Odo 24.
- Odo 62 is a Vietnamese community/market name associated with a branch of Odo 24.
- The exact origin of the numbers 54/57/62 requires evidence.
- “1954” must not be treated as production year/model identity without evidence.
- “Odo 111” may exist as community wording, but `Côn 111` remains a Component identity.

## 11.2 Movement system

- 24/30/36/39 movement naming and pendulum-arm-length interpretation: create as Expert Observation or Fact only at evidence level actually supported.
- Typical case-form association: 24↔wide, 30↔medium, 36/39↔long, expressed as association not invariant.

## 11.3 Recognition

- Odo 57 recognition bundle.
- Odo 62 recognition bundle.
- cọc đen vs cọc trắng distinction.
- ty xoáy vs ty đóng observations.
- coarse vs fine berry plate observations.
- Odo 30 logo/hammer observations.
- Odo 36 plate/gong configurations.
- aluminum plate rule: only on raised-foot plate machines, kept at evidence-supported strength.

## 11.4 Components

Atomic Knowledge for Côn 101/111/121/121C, lòng máng, chữ U, chữ M, M traditional, M MF-style, brown unnumbered gong, round/square/plastic hammer, Odo hand families, dial construction, and pinned-vs-glued numerals.

## 11.5 Collector/cultural knowledge

- “đồng hồ nội địa” definition.
- originality value vs cultural-historical value are distinct.
- domestic Odo 36/54/57/62 branches.
- local dial/gong finishing claims remain Claim/Community Knowledge until evidence is attached.

---

# 12. Source/Evidence policy

Every new Knowledge statement must declare one of:

```text
FACT
COMMUNITY_KNOWLEDGE
EXPERT_OBSERVATION
CLAIM
RESEARCH_REQUIRED
```

These labels are pack semantics; runtime must map them to actual governed Knowledge status/type vocabulary.

Evidence may include manufacturer catalogue, archive record, advertisement, patent/trademark record, book/reference, specimen photograph, movement/gong marking photograph, video/audio, owner expert observation, multiple-specimen observation, named collector testimony, oral history, family provenance and regional provenance.

No specific oral-history claim is promoted to Fact without attributable source/evidence.

---

# 13. Media placeholder requirements

Current Odo semantic Media coverage is effectively absent. Build a placeholder backlog.

Do not fabricate file URLs or media bytes.

If runtime supports `media.create_placeholder`, placeholders may be created with subject relations and required-shot metadata. Otherwise store these only as media requirements until actual files arrive.

Required groups:

- Brand: logo elip, logo hình trám, triện/marking Odo.
- Plates: cam, hở, xoáy, bệt, chân kiềng, trơn, hoa dâu thô, hoa dâu mịn.
- Gong/hammer: 101, 111, 121, 121C, lòng máng đen/trắng, U, M, M traditional, M MF-style, côn nâu không số, búa tròn/vuông/nhựa, lưỡi búa nhựa tròn, ty xoáy/đóng.
- Dial/hands: 18/20/22/24, 20×20, 22×22, pinned/glued numerals, kim 54, số 8, tháp, bút, mắt ngỗng Odo, lá lúa.
- Case/glass: thùng bè, trung, dài, glass research series.

Glass research codes may use working labels `ODO-GLASS-001`, `ODO-GLASS-002`, … as research IDs only, not canonical component names.

---

# 14. Video placeholder requirements

Video must never stand alone.

Potential Odo video topics:

```text
Nhận diện Odo 54
Phân biệt Odo 54/57/62
Nhận diện Odo 36
Odo 30 vs Odo 36
Côn 101/111/121
Ty đóng vs ty xoáy
Búa vuông vs búa tròn
Mặt số nguyên bản và mặt số nội địa
Odo nội địa
Âm thanh từng variant
Westminster trên Odo
Ave Maria Lourdes trên Odo
Sonodo
Gai Carillon
Cloches Comtoises
```

Each Video should be related to as many actually-applicable Brand/Model/Movement/Variant/Component/Classification/Music/Knowledge/wp_post/Media/Source/Evidence nodes as the Registry allows.

Do not create empty Video entities unless the current Video contract explicitly supports governed placeholders.

---

# 15. Article reconciliation

WordPress Post is Editorial Authority.

Known Odo-facing posts:

```text
Post 38 — Odo history
Post 39 — Odo factories
Post 40 — đồng hồ nội địa
Post 55 — Odo 24 / Odo 54 naming
```

Do not create duplicate Posts merely to make semantic relations.

Post 55 future reconciliation target:

```text
wp_post:<runtime_blog_id>:55
```

Desired semantic associations:

```text
Odo
Odo 24
Odo 54
Knowledge: Odo 54 community naming
Knowledge: origin of “54” is not proven as production year
Knowledge: Côn 111 is component, not default model alias
Knowledge: Odo domestic / Vietnam circulation where evidence supports it
relevant Source/Evidence
```

Article reconciliation must use the approved Article Ingest/Reconciliation path and governed Graph writes.

---

# 16. Odo Brand Hub projection

The Odo public hub should be data-driven from graph/Knowledge, not hard-coded to Odo.

Potential sections:

```text
brand summary
history timeline
clock types
models
movements
variants
Vietnam community names
recognition guides
components
gong/hammer/hand/dial/plate facets
music
domestic Odo knowledge
articles
FAQ projection
media gallery
videos
related Knowledge
research-required gaps
```

The same hub renderer should be reusable by future Brand Semantic Packs.

---

# 17. Action vocabulary for the machine ingest manifest

Allowed pack actions:

```text
REUSE
REKEY
MERGE
MERGE_CANDIDATE
CREATE
RELATE
RETIREMENT_REVIEW
DEPRECATE_AFTER_VERIFY
RESEARCH
MEDIA_REQUIREMENT
VIDEO_REQUIREMENT
ARTICLE_RECONCILE
```

These are **pack instructions**, not guaranteed runtime operation names.

Every execution layer must translate them to governed runtime operations discovered from the actual operation registry.

---

# 18. Mandatory preflight before any mutation

For every manifest row:

1. Resolve entity by UUID and stable key.
2. Check target stable-key collision.
3. Inventory active inbound/outbound relations.
4. Inventory Source/Evidence/Media references.
5. Check expected revision.
6. Check idempotency key.
7. Resolve allowed predicate and endpoint pair from Relation Registry.
8. Fail closed on missing/ambiguous target.
9. Build Proposal.
10. Apply only through Controlled Apply / approved governed writer.
11. Read back Authority.
12. Read back Graph.
13. Read back public projection where applicable.
14. Record durable receipt/audit result.

No generic WordPress taxonomy/postmeta/SQL semantic fallback.

---

# 19. Ordered migration/application phases

## Phase A — read-only inventory

- export every Odo entity/relation/revision;
- identify all legacy stable keys;
- identify all existing canonical collisions;
- identify duplicates;
- identify Odo 35 references;
- identify article references;
- identify media/video coverage.

## Phase B — namespace normalization

- rekey non-colliding legacy records to canonical namespace;
- preserve UUID;
- verify each record after rekey.

## Phase C — confirmed merges

- merge confirmed pinned-dial duplicate;
- review glued-dial duplicate candidate;
- dedupe relations;
- deprecate source records after verification.

## Phase D — retirement review

- inventory Odo 35 model/movement/variant references;
- remove from active Odo pack;
- propose governed retirement only if no required references would be broken.

## Phase E — relationship completion

Build only Registry-valid edges for brand↔model, model↔movement, model↔variant, variant↔component, variant↔classification, variant↔music, Knowledge↔entity, Knowledge↔Source/Evidence, wp_post↔entity/Knowledge and Media/Video↔subjects.

## Phase F — Knowledge creation

Create atomic Knowledge backlog using actual governed Knowledge vocabulary.

## Phase G — Media/Video placeholders

Create only contract-supported placeholders; otherwise emit requirements.

## Phase H — Article reconciliation

Reconcile Posts 38/39/40/55 without duplicating editorial content.

## Phase I — verification

- zero new legacy stable keys;
- no active duplicate canonical identities;
- no dangling relations;
- no duplicate active triples;
- Odo 35 absent from active pack;
- Post URLs unchanged;
- graph hubs/search resolve expected entities;
- all mutations have Governance receipts.

---

# 20. Acceptance criteria

The Odo pack is complete only when:

1. Canonical display name is Odo everywhere governed by the pack.
2. No new legacy stable key can be created.
3. Existing legacy references are normalized safely, not destroyed.
4. Confirmed duplicate pinned-dial components resolve to one canonical identity.
5. Odo 35 is excluded and its retirement state is explicit.
6. Odo 24/30/36/39 identities are preserved.
7. Odo 54/57/62 remain community branches under Odo 24.
8. Component/Classification/Music records are reused rather than duplicated.
9. Technical/community statements are atomic Knowledge with proper evidence class.
10. Domestic Knowledge shells exist for Odo 36/54/57/62 when runtime permits creation.
11. Media/Video backlog is represented without fake files.
12. Posts remain Editorial Authority.
13. FAQ/Timeline/Recognition views consume canonical graph/Knowledge.
14. Every mutation is governed, revision-safe and idempotent.
15. The pack can be copied as a structural pattern for another brand without adding Odo-specific architecture.

---

# 21. Explicit non-goals

- No hard delete purely for spelling normalization.
- No Article body migration into Authority.
- No bulk creation from prose without Registry resolution.
- No automatic promotion of owner/community claims to Fact.
- No automatic inference of exact chronology for 54/57/62.
- No fake Media or Video URLs.
- No direct SQL.
- No semantic use of WordPress taxonomy/postmeta as a fallback graph.
- No Odo-only entity type or table.

## 22. Current media and semantic-isolation evidence — 2026-09-04

The canonical technical token is `odo`; human-facing display may remain `Odo`
or `ODO`. A September 2026 media incident affected attachments `#83` (Odo
62/6/10) and `#86` (Odo 36/8): DB metadata had `odo-*` while five physical
files still had `o-do-*`. The safe repair renamed originals and derivatives
together, preserved checksums, and verified canonical HTTP `200 image/webp`.
Legacy physical files, broken originals/derivatives and inline legacy URLs are
now zero.

Semantic rekey is not a Media rename. `_wp_attached_file`,
`_wp_attachment_metadata`, physical filenames, derivatives and inline URLs are
not implicit rekey targets. `OdoMediaIntegrityAuditor` and the read-only
`tools/odo-media-integrity-audit.php` must run before and after any
basename-sensitive change; `SemanticRekeyMediaIsolation` enforces this rule.
