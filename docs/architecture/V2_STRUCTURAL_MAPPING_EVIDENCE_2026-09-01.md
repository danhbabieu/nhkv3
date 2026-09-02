# V2 → V3 Structural Mapping Evidence — 2026-09-01

> **NON-NORMATIVE.** Đây là V2 read-only mapping evidence. Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

This is a read-only structural/domain mapping artifact. It defines the
canonical V3 boundary and records evidence available in the retained V2
export. It does not apply identity mappings, create relations, import article
bodies, create redirects or retire source records.

## Concept mapping matrix

| V2 concept | V3 concept | Canonical identity policy | Relation semantics | Migration action | Evidence / confidence |
|---|---|---|---|---|---|
| `nhk_brand` | Authority `brand` | Exact canonical UUID/stable key when explicit; otherwise legacy-post ID binding is required | Brand owns model scope; `brand → model` is a governed `about` edge | Resolve evidence first, then governed mapping | 4 canonical brands; 30 explicit brand→model UUID edges; HIGH for relation shape, case-specific for post identity |
| `nhk_model` | Authority `model` | Exact UUID/stable key or explicit legacy binding; `brand_uuid` must validate | Model belongs to one Brand; `model → variant` is a governed `about` edge | Resolve evidence first, then governed mapping | 30 models and 42 explicit model→variant UUID edges; HIGH for relation shape |
| `nhk_variant` | Authority `variant` | Exact UUID/stable key or explicit legacy binding; `model_uuid` must validate | Variant belongs to one Model; movement/music/component/classification links are typed governed edges | Preserve variant-level configuration; do not promote specimen observations | 42 variants; direct model/variant relation evidence present; HIGH for structure |
| `nhk_movement` | Authority `movement` | Reusable technical identity; exact UUID/stable key or explicit legacy binding | Link to Variant/Model only when explicit; no brand inference from name | Governed mapping; no text-only coercion | 18 canonical rows and explicit movement links in relation export; EVIDENCE_RESOLVABLE when endpoint UUIDs exist |
| `nhk_music` | Authority `music` | Reusable work identity; exact UUID/stable key or explicit legacy binding | Link to Variant/Movement only when explicit; côn/búa count never implies music | Governed mapping | 11 canonical rows and explicit music links; EVIDENCE_RESOLVABLE when endpoints exist |
| `nhk_component` | Authority `component` | Reusable technical component identity; exact UUID/stable key or explicit legacy binding | Link as reusable component relation; côn/gông, búa, thùng, mặt số, kim, kính and quả lắc remain typed component candidates | Governed mapping after semantic review | 91 canonical rows; component representation exists; field-level semantic review remains |
| `nhk_classification` | Authority `classification` | Shared label/configuration identity; exact UUID/stable key or explicit legacy binding | Classification labels/configuration are not physical objects and are not promoted to components | Governed mapping | 174 canonical rows and 121 Knowledge→classification edges; HIGH for boundary |
| `nhk_knowledge` | Knowledge claim | Exact claim UUID/stable key or explicit legacy binding; claim text is not a post body substitute | Claims may cite Source/Evidence and relate to Authority through governed graph edges | Governed mapping only with provenance/readiness policy | 655 claims; 66/44/41/12/12/31/121/2 explicit typed Knowledge relations by endpoint lane |
| attachment | MediaAsset | Checksum + MIME/size + provenance; checksum alone never merges identity | Usage must point to explicit Media/endpoint; no inferred semantic ownership | Recover/verify, then governed MediaAsset mapping or retirement | 18 available, 3 unavailable; only 3 explicit canonical Media mappings |
| `wp_post` with editorial type | WordPress native post | Native post identity and URL/body fields | Semantic links are governed `wp_post → about → endpoint` edges | Separate editorial migration review; body remains in `wp_posts` | 34 `nhk_article` rows handled separately |
| `wp_global_styles` | No V3 semantic target | Not applicable | No semantic relation | Record governed retirement | 1 implementation record |
| Specimen | Authority `specimen` | Physical-object identity; never inferred from Product/listing | Observations attach to the Specimen only | Create only from explicit physical-object evidence | No source rows in retained export; deferred, not auto-created |
| Product | Authority `product` | Listing/offer identity; never used as Specimen identity | Product may reference a Specimen only when explicit | Create only from explicit offer evidence | No source rows in retained export; deferred, not auto-created |

## Relation evidence

The retained export contains 427 relation rows. The explicit endpoint-shape
counts below are evidence for relation direction and cardinality; they are not
permission to synthesize missing edges:

| Direction / endpoint shape | Rows | V3 interpretation |
|---|---:|---|
| `brand → model` | 30 | One model may be scoped to one brand in the current model payload; apply only after endpoint identity validation |
| `model → variant` | 42 | Variant belongs to its model; duplicate/conflicting parents require review |
| `knowledge → brand` | 66 | Claim is about a brand; not ownership |
| `knowledge → model` | 44 | Claim is about a model; not model identity |
| `knowledge → variant` | 41 | Claim is about a variant; not specimen observation |
| `knowledge → movement` | 12 | Claim concerns movement |
| `knowledge → music` | 12 | Claim concerns music; never infer from gong/hammer counts |
| `knowledge → component` | 31 | Claim concerns reusable component/configuration |
| `knowledge → classification` | 121 | Claim uses a classification label/configuration |
| `knowledge → knowledge` | 2 | Claim-to-claim relation; preserve only as an explicit governed relation |
| `media → brand/movement/variant` | 4 | Media semantic usage requires explicit endpoint and asset provenance |
| `wp_post → media` | 21 | Editorial/legacy post media relation; does not establish MediaAsset ownership |
| `article → media` | 1 | Editorial reference shape; keep native post as URL/body truth |

The current V3 Graph supports the `about` and `depicts` predicates. Therefore
the above relation evidence maps to governed endpoint edges, while ownership,
cardinality and physical-observation semantics remain explicit policy checks.

## Two-brand fixtures

The export contains both fixture brands without production hard-coding:

- Odo: `nhk:brand:o-do`, UUID
  `d2af7739-3d1b-4666-ad0a-aeda0758f4d8`.
- Vedette: `nhk:brand:vedette`, UUID
  `4cd79149-5cad-4427-aab4-0cea3aebe8c1`.

They are evidence fixtures only. Production code resolves registered entity
types and explicit Graph endpoints; it does not special-case either brand.
The same mapping rules apply to Junghans and FFR.

## Resolution classes

- `AUTO_RESOLVABLE`: exact canonical UUID or stable key is present and the
  target type/state is valid.
- `EVIDENCE_RESOLVABLE`: an explicit relation or provenance packet identifies
  one target, but governed apply is still required.
- `RULE_RESOLVABLE`: the V3 boundary is unambiguous from the type contract,
  but a case-level identity packet is still required.
- `AMBIGUOUS_REQUIRES_HUMAN`: two or more valid targets remain after exact
  identity, explicit relation and provenance checks.
- `DEFERRED`: no source representation exists in the retained export; do not
  invent a record.
- `RETIRE`: the source is non-editorial garbage or an approved unusable asset;
  retirement still requires a governed decision.

The 742 domain-post rows are therefore structural references, not 742 assumed
human identity decisions. The next machine step is to join only explicit
legacy-post identity/relation evidence; title/slug similarity alone remains a
review candidate and never an automatic mapping.
