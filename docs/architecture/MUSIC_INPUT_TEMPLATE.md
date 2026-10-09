# NHK V3 — Music A–Z Input Template

**Trạng thái:** transient research worksheet — không phải schema, payload runtime hay quyền ghi  
**Nguồn field:** `MusicDataCollectionStandard::fields()` và ACTIVE `MUSIC_DATA_COLLECTION_STANDARD.md`

## 1. Ranh giới sử dụng

Biểu mẫu này dùng chung cho người dùng, AI researcher, MCP adapter và biên tập viên
để chuẩn bị cùng một loại input. Nó chỉ là packet tạm thời để chuyển vào
`UniversalInputEnvelope`/Capture hiện có. Không ghi trực tiếp Authority,
Knowledge, Source/Evidence, Graph, Media, Video, Dictionary hoặc WordPress.

Canonical changes continue through Capture → Proposal/Approval → Eligibility →
Controlled Apply → canonical read-back. Ví dụ là `EXAMPLE_ONLY / NO_LIVE_WRITE`.

## 2. Identity resolution preflight

This identity resolution step is reuse-first and fail-closed when scope is ambiguous.

| Câu hỏi | Ghi nhận |
|---|---|
| Display name nguyên bản | |
| Subject candidate / canonical Music owner đã đọc | |
| Canonical ID, stable key, revision đã đọc lại | |
| Identity scope: work/program/melody/edition/recording/clock use | |
| Tên trùng hoặc alias cạnh tranh | |
| Duplicate audit | `REQUIRED` / `COMPLETE` / `BLOCKED` |
| Route/public identity hiện tại | |
| Kết quả | `REUSE_EXISTING` / `AMBIGUOUS` / `REVIEW_REQUIRED` |

Không dùng tên, alias, slug, URL, checksum, file name hoặc AI memory để tự tạo
Music owner.

## 3. Mười hai loại input phải phân biệt

Mỗi dòng giữ nguyên text gốc, source identity, scope/facet, provenance,
locator/evidence candidate, uncertainty và ngày/source scope.

| Input class | Cách giữ trong packet | Không được suy ra |
|---|---|---|
| user factual assertion | nguyên văn, `EXPLICIT_USER_KNOWLEDGE`, subject candidate | `VERIFIED` hoặc universal fact |
| source locator | URL/DOI/archive locator, source context | Evidence được duyệt |
| evidence excerpt | đoạn trích giới hạn, source + locator | Claim độc lập |
| factual research statement | từng proposition atomic, source-scoped | Public truth nếu thiếu Evidence |
| historical hypothesis | hypothesis + competing readings + uncertainty | lịch sử đã xác minh |
| system inference | `SYSTEM_INFERENCE`, lineage, review-required | canonical fact |
| editorial instruction | non-semantic instruction context | Knowledge/Source |
| operational instruction | non-semantic workflow context | Proposal/permission |
| media metadata | asset/recording metadata + provenance | identity, Evidence hoặc authenticity |
| Dictionary lexical observation | Entry/Form/Sense candidate + lexical attestation | Knowledge claim hoặc Graph edge |
| score/edition metadata | edition/notation packet + source/rights | canonical score store |
| audio/recording metadata | classification, technical data, provenance, rights | historical authenticity/public delivery |

Input URL, instruction, metadata, mention và excerpt chỉ trở thành canonical truth
qua workflow sở hữu tương ứng.

## 4. A–Z research worksheet

Dùng đúng field keys từ executable registry. Các cột là nhãn chuẩn bị, không phải
database columns.

| Key | Nhóm | Field key hiện hành | Value/input | Owner + scope | Source/locator/evidence | Status | Duplicate/reuse | Review/public |
|---|---|---|---|---|---|---|---|---|
| A | Định danh | `canonical_name`, `identity_scope` | | Authority / Music | | | | |
| B | Tên và bí danh | `preferred_title`, `alias`, `title_language` | | Authority/Dictionary | | | | |
| C | Nguồn gốc | `origin_place`, `origin_date`, `origin_statement` | | Knowledge + Source/Evidence | | | | |
| D | Nhạc sĩ | `composer_attribution`, `attribution_status` | | Knowledge + Source/Evidence | | | | |
| E | Dòng thời gian | `timeline_event` | | Knowledge + Source/Evidence | | | | |
| F | Bối cảnh | `cultural_context` | | Knowledge + Source/Evidence | | | | |
| G | Cấu trúc | `form_description`, `phrase_sequence` | | Knowledge/edition | | | | |
| H | Ký âm witness | `notation_witness` | | Source/Evidence/edition | | | | |
| I | Ấn bản | `score_edition` | | Score presentation | | | | |
| J | Biên soạn | `arrangement_variant` | | Knowledge/edition | | | | |
| K | Nốt/nhịp/tuning | `pitch_assertion`, `rhythm_tempo_tuning` | | Knowledge/edition | | | | |
| L | Piano | `piano_reference` | | Media/MediaAsset | | | | |
| M | Bell/Chime | `bell_reference` | | Media/MediaAsset | | | | |
| N | Bản ghi | `historical_recording` | | Media/Source/Evidence | | | | |
| O | Cơ chế | `clock_mechanism_context` | | Knowledge/Graph | | | | |
| P | Brand/Model/Variant | `clock_model_compatibility` | | Graph + Knowledge | | | | |
| Q | Specimen | `documented_specimen` | | Authority/Specimen | | | | |
| R | Hình ảnh/sơ đồ | `image_diagram` | | Media/MediaUsage | | | | |
| S | Video | `video_reference` | | Video + Graph/Source | | | | |
| T | Catalogue/archive | `catalogue_archive` | | Source/Evidence | | | | |
| U | Knowledge/Dictionary | `knowledge_dictionary_entry` | | Knowledge/Dictionary | | | | |
| V | Graph | `registered_relation` | | Graph registered predicate | | | | |
| W | Nguồn/bằng chứng | `source_evidence_bundle`, `evidence_locator` | | Source/Evidence | | | | |
| X | Rights | `rights_license` | | Media/Source/Governance | | | | |
| Y | SEO/public | `public_presentation` | | Public Projection/SEO | | | | |
| Z | Review/coverage | `review_coverage` | | diagnostics | | | | |

Mỗi field phải copy/read metadata: applicability, value type, evidence, sources,
validation, uncertainty, duplicate, review, frontend, public states, examples
và missing-data behavior từ executable registry.

## 5. Claim/source/evidence worksheet

Use one claim/source/evidence row for each factual proposition.

~~~yaml
# transient preparation only; not a runtime write payload
claim_candidates:
  - original_text: ""
    subject_candidate: ""
    canonical_subject_readback: null
    facet: ""
    scope: ""
    provenance: EXPLICIT_USER_KNOWLEDGE | EXTERNAL_RESEARCH | SYSTEM_INFERENCE
    source_locator: null
    evidence_candidate: null
    source_date_or_period: null
    uncertainty: MISSING | UNKNOWN | CANDIDATE | DISPUTED | BLOCKED
    review_required: true
    derived_lineage: null
~~~

One factual statement per row. A Source exists independently from Evidence;
Evidence must support, contradict or qualify the exact proposition. Generated
copy, transcript, caption, OCR, URL and user assertion do not become approved
Evidence automatically.

## 6. Relationship worksheet

Use the registered relation worksheet below for existing endpoint/predicate
vocabulary only.

Record only candidates using existing endpoint/predicate vocabulary. Retain source
type, target type, direction, exact scope, provenance, Evidence and explainable
path. Reachability is discovery, not truth. A missing relation is not a reason to
invent one.

## 7. Status and public-readiness checklist

Allowed collection states are `MISSING`, `UNKNOWN`, `NOT_APPLICABLE`,
`DISPUTED`, `BLOCKED`, `CANDIDATE`, `VERIFIED` and `PUBLIC_READY`.

Before `PUBLIC_READY`, verify canonical identity, owner-specific evidence,
rights, public eligibility, route/read model and frontend read-back. Distinguish
canonical data available, public eligibility required, rendered frontend data,
missing feature, missing evidence, missing rights and temporarily unavailable.

A route existing does not mean the Music dossier is complete.

## 8. Missing-data report

The missing-data report is the final collection handoff, not a coverage score.

| Field/category | State | Exact missing dependency | Safe next collection task | Public effect |
|---|---|---|---|---|
| | `MISSING` / `UNKNOWN` / `DISPUTED` / `BLOCKED` | | | |

Never fill a gap with a guessed value to improve coverage.

## 9. Worked examples

Westminster, Sonodo and Ave Maria are documentation examples only. Any incomplete
name, history, edition, score, audio, clock relation or rights decision remains
unverified and is not written by this worksheet.
