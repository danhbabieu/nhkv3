# NHK V3 — Music Data Collection Standard

**Trạng thái:** ACTIVE read-only collection standard — 2026-10-08
**Phạm vi:** Music research, editorial intake, collector review, audio/score
readiness, administrator diagnostics and automated enrichment planning.

This is one reusable standard for every canonical Music entity. Westminster is
the worked example, not a special semantic owner, route, schema or permission
packet.

## 1. Boundary and ownership

The executable companion is `MusicDataCollectionStandard` and the read-only
coverage evaluator is `MusicCoverageAssessment`. Both are transient
collection/read-model boundaries. Their `field_name` values are intake
questions, not persisted Authority fields and not permission to create a new
canonical field, endpoint, predicate, relation, asset owner or writer.

Canonical ownership remains:

| Concern | Owner | Collection rule |
|---|---|---|
| Music identity, aliases and public identity | Authority / Public Identity | Resolve and reuse the canonical Music owner; never create identity from a name alone. |
| Atomic historical, musical and technical claims | Knowledge | One proposition per claim; preserve subject scope and uncertainty. |
| Provenance and support | Source / Evidence | Keep URL or archival locator, retrieval context, support type and visibility. |
| Semantic relations | Graph | Use only registered endpoint types and predicates; reachability is discovery, not truth. |
| Images and audio binaries | Media / MediaAsset / MediaUsage | Use governed ingest and public-delivery eligibility; a URL is not an asset. |
| Video references | Video | Preserve the Video owner and explicit semantic target. |
| Editorial prose and editorial URLs | WordPress | Do not copy Article body into Knowledge or this standard. |
| Lexical wording | Dictionary | Lexical curation does not become Authority, Knowledge, Evidence or Graph truth. |

## 2. Field contract

Every intake field returned by the executable standard carries all of the
following properties:

| Property | Required meaning |
|---|---|
| `field_name` | Stable intake question key; not a database column. |
| `display_name_vi` | Vietnamese-first researcher/editor label. |
| `purpose` | Why the field exists and what it must not be used to infer. |
| `applicability` | `CORE`, `RECOMMENDED`, `CONDITIONAL` or `OPTIONAL`. |
| `value_type` | Expected scalar, claim, list, locator, relation, asset or packet shape. |
| `canonical_owner` | Existing NHK owner responsible for canonical truth. |
| `subject_scope` | Exact Music/work/edition/recording/clock/specimen scope. |
| `evidence_requirements` | Source, locator, claim atomization and uncertainty requirement. |
| `allowed_source_types` | Permitted research source classes; not an authorization grant. |
| `validation_rules` | Type, status, scope, identity and conflict checks. |
| `public_visibility_rule` | Existing public eligibility and redaction policy. |
| `related_entity_types` | Existing entity types that may be related, without inferring a relation. |
| `westminster_example` | A supported example or an explicit unresolved boundary. |
| `missing_data_behavior` | How to preserve `MISSING`, `UNKNOWN`, `NOT_APPLICABLE`, `DISPUTED` or `BLOCKED`. |
| `review_requirement` | Research/editorial review and, for mutation, the existing Governance lifecycle. |

The standard never converts `UNKNOWN` into a guessed value. `CANDIDATE` means
research input, not a verified fact. `VERIFIED` means the owner/evidence
policy permits the assertion. `PUBLIC_READY` additionally means the existing
public projection and rights/readiness gates pass.

## 3. Status vocabulary

| Status | Meaning |
|---|---|
| `MISSING` | The field is applicable but no value or claim has been collected. |
| `UNKNOWN` | The state is explicitly unresolved or cannot be determined from the current read model. |
| `NOT_APPLICABLE` | The field does not apply to this Music entity or no conditional object exists. |
| `DISPUTED` | Source-scoped assertions conflict and must remain separate. |
| `BLOCKED` | A dependency such as rights, identity, evidence or governed delivery prevents progress. |
| `CANDIDATE` | A bounded research candidate exists but has not passed verification. |
| `VERIFIED` | The claim/asset/relation passed its owner-specific verification boundary but may not be public. |
| `PUBLIC_READY` | Verified and eligible for the owning public projection. |

## 4. Intake checklist

The canonical executable registry contains the complete field metadata. The
following checklist is the editorial routing view; field keys are intentionally
intake-only.

| Group | Vietnamese section | Applicability | Intake fields | Westminster example / review boundary |
|---|---|---|---|---|
| A | Định danh bản nhạc | CORE | `canonical_name`, `identity_scope` | Westminster Quarters is a Music identity; separate melody identity from Westminster clock use. |
| B | Tên và bí danh | CORE | `preferred_title`, `alias`, `title_language` | Cambridge Quarters and Westminster Chimes remain aliases/candidates until Authority review. |
| C | Nguồn gốc và địa lý | CORE | `origin_place`, `origin_date`, `origin_statement` | Great St Mary’s/Cambridge and 1793 are source-scoped research statements. |
| D | Nhạc sĩ và quy thuộc | RECOMMENDED | `composer_attribution`, `attribution_status` | Do not assign a composer without accepted evidence. |
| E | Dòng thời gian lịch sử | CORE | `timeline_event` | Composition, adoption, installation, first performance and dissemination remain separate events. |
| F | Mục đích và bối cảnh văn hóa | RECOMMENDED | `cultural_context` | Public quarter-hour clock context requires an authoritative source. |
| G | Hình thức và cấu trúc | CORE | `form_description`, `phrase_sequence` | Grove/Starmer notation witnesses are not automatically a canonical score. |
| H | Nhân chứng ký âm | CORE | `notation_witness` | Retain exact edition/page/figure locator and access rights. |
| I | Ấn bản bản nhạc | RECOMMENDED | `score_edition` | `grove-cambridge-quarters-d-major-v1` remains a non-canonical edition candidate. |
| J | Biên soạn và chuyển giọng | CONDITIONAL | `arrangement_variant` | Separate original, transcription, arrangement and transposition. |
| K | Nốt, nhịp, tempo và tuning | CORE | `pitch_assertion`, `rhythm_tempo_tuning` | G/G-sharp is a source-scoped conflict; pitch class alone does not establish octave or tuning. |
| L | Tham chiếu Piano | CONDITIONAL | `piano_reference` | Local Piano WAV is a synthetic/non-public reference, not a historical performance. |
| M | Tham chiếu Bell/Chime | CONDITIONAL | `bell_reference` | Local Bell WAV is `BELL_SIMULATION`, never historical Big Ben sound. |
| N | Bản ghi âm xác thực | CONDITIONAL | `historical_recording` | Requires exact recording identity, provenance, checksum and licence. |
| O | Cơ chế đồng hồ | CONDITIONAL | `clock_mechanism_context` | Parliament mechanism context must not become a universal clock capability. |
| P | Thương hiệu/mẫu/biến thể đồng hồ | CONDITIONAL | `clock_model_compatibility` | Variant/Movement evidence must not be promoted to Model/Brand truth. |
| Q | Hiện vật được ghi nhận | CONDITIONAL | `documented_specimen` | One physical specimen remains distinct from Product/listing identity. |
| R | Hình ảnh và sơ đồ | RECOMMENDED | `image_diagram` | Media usage needs exact subject/use and public eligibility; it is not automatically Evidence. |
| S | Video | RECOMMENDED | `video_reference` | Preserve Video owner and explicit `about` target. |
| T | Catalogue và lưu trữ | RECOMMENDED | `catalogue_archive` | Keep edition, archive, locator and rights/access posture. |
| U | Knowledge và Dictionary | CORE | `knowledge_dictionary_entry` | Knowledge owns facts; Dictionary owns lexical curation and wording. |
| V | Quan hệ Graph | CORE | `registered_relation` | Only registered predicates such as `supports_music` or `configured_with_music` may be considered. |
| W | Nguồn và bằng chứng | CORE | `source_evidence_bundle`, `evidence_locator` | Preserve support, qualification, conflict and exact locator. |
| X | Quyền và licensing | CORE | `rights_license` | Parliament audio and derivative score/audio rights require exact review before delivery. |
| Y | SEO và trình bày public | CORE | `public_presentation` | Persisted public identity, public-safe fields and honest empty states only. |
| Z | Review, coverage và nghiên cứu tiếp | CORE | `review_coverage` | Coverage is a diagnostic/read-model result, not a numeric truth score. |

## 5. Reusable collection workflow

Every current or future Music entity follows the same path:

```text
Discover canonical Music identity
→ inspect current coverage
→ retrieve existing related owners and claims
→ identify missing fields and uncertainty
→ research eligible sources
→ atomize factual claims
→ duplicate/reuse Knowledge and Source/Evidence
→ classify status and scope
→ validate score/recording/media packets
→ review licensing and public eligibility
→ prepare governed proposals when mutation is needed
→ existing approval/eligibility/Controlled Apply lifecycle
→ canonical read-back
→ public dossier projection
→ browser QA
→ recompute coverage and choose the next task
```

New submissions still enter through canonical Capture. This standard does not
create a second writer, approval queue, direct WordPress writer or audio
ingestion path. A read-only assessment may be run when Governance or runtime
identity is unavailable; it must report that dependency as blocked rather than
pretending to apply the data.

## 6. Coverage assessment contract

`MusicCoverageAssessment::assess()` accepts an existing Music `AuthorityEntity`,
the read-only dossier packet and an optional normalized score/audio packet. It
returns:

- per-category and per-field status using the vocabulary above;
- counts for complete sections, verified-but-not-public material, research
  gaps, rights blockers and unsupported relation candidates;
- reusable owner/read-model signals already present in NHK;
- one deterministic `next_highest_value_task` without creating a proposal;
- a redacted subject identity containing type and name only.

It does not return UUIDs, stable keys, revisions, private diagnostics or raw
claim bodies beyond the existing read-model input, and it performs no writes.
Coverage is not the number of Graph edges. A relation is useful only when its
scope, predicate, provenance and evidence are justified.

## 7. Westminster worked example

The current Westminster research material remains non-canonical and is used as
worked evidence only:

- Great St Mary’s supports a Cambridge origin statement; it does not by itself
  prove every Westminster installation, score encoding or recording.
- UK Parliament supports bounded Elizabeth Tower/bell/mechanism context. Its
  pitch statements are source-scoped and the G/G-sharp disagreement remains
  `DISPUTED`, not silently resolved.
- Grove notation and W. W. Starmer are notation witnesses/research sources;
  they do not establish public octave, tempo, tuning, mechanical timing or one
  universal arrangement without specialist review.
- The local Piano WAV is a generated reference; the Bell WAV is a synthetic
  `BELL_SIMULATION`. Neither is a historical Big Ben recording.
- Historical audio remains blocked until exact provenance, checksum, licence,
  Media/MediaAsset readiness and canonical read-back pass.
- A Variant, Model, Movement, Brand, Specimen or Product relation is retained
  at its original scope. Names and reachability never widen it.

The source map and audio/score readiness files under
`docs/research/westminster/` remain research evidence and are not authorization
packets.

## 8. Reusable preparation worksheets

The transient worksheets below use the executable field metadata without adding
runtime fields or write capabilities:

- `MUSIC_INPUT_TEMPLATE.md` — identity preflight, A–Z collection, claim/source/
  evidence, relation, uncertainty and public-readiness worksheet.
- `DICTIONARY_INPUT_TEMPLATE.md` — Entry/Form/Sense, lexical attestation,
  owner revalidation, ambiguity and delegated-public projection worksheet.
- `SCORE_AUDIO_INPUT_TEMPLATE.md` — score edition, recording classification,
  rights, checksum, Media readiness and governed delivery worksheet.

They are preparation guides only. New submissions still enter through Capture;
examples are `EXAMPLE_ONLY / NO_LIVE_WRITE` and are never canonical records.

## 9. Review and approval

Researchers and editors review source statements, scope and uncertainty.
Audio engineers review signal/render metadata and distinguish synthetic output
from historical recordings. Administrators review rights, owner readiness and
Governance eligibility. Automated enrichment may suggest candidates and
coverage tasks, but canonical changes remain Capture → Proposal/Approval →
Eligibility → Controlled Apply → canonical read-back.

No value is public merely because it exists in this standard, a fixture, a
local JSON file, a WAV checksum, a browser preview or a source URL.

## Universal Capture recovery boundary — 2026-10-09

This standard remains read-only intake guidance. Music candidates enter through
Capture and the same governed Proposal/Approval/Eligibility/Controlled Apply
and canonical read-back lifecycle as other registered Authority types; this
document adds no Music field, owner, relation, score store or writer. Explicit
user statements, derived interpretation, Source/Evidence and readiness remain
separate decisions.

Notation, audio, and rights are distinct dependencies. A Westminster worked
example is noncanonical research context until the registered contracts make a
candidate eligible. It cannot authorize a public dossier, live semantic write,
or direct media/score persistence.
