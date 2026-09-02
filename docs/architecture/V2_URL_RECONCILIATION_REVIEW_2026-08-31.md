# V2 URL Reconciliation Review — 2026-08-31

> **NON-NORMATIVE.** Đây là V2 read-only review evidence. Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

This is a review artifact for the 27 V2 URL records that remain without a
canonical V3 target after Mapper 6.14. It does not approve a redirect,
retirement, media import, or source-data mutation. No V2 or production data
was changed while producing this list.

Source artifact: `/private/tmp/nhk-v3-v2-full-export-url-6.14.json`  
Source SHA-256: `061b2b647407c888de890b3f34bc3be7c80803f3c1e923372de409d278e5deac`  
Expected residual count after the native-homepage no-op classification: 27

| Reason | V2 ID | Legacy path | Required governed decision |
|---|---:|---|---|
| `DOMAIN_TARGETED` | 810 | `/tri-thuc/sua-article-cu-phai-giu-identity-va-nang-chuan-bien-tap/` | Approve a canonical Knowledge target or retire as a domain-specific legacy post |
| `DOMAIN_TARGETED` | 811 | `/tri-thuc/luat-article-phai-co-ket-qua-tiep-thu-ro-rang/` | Approve a canonical Knowledge target or retire as a domain-specific legacy post |
| `DOMAIN_TARGETED` | 812 | `/tri-thuc/khong-mac-dinh-moi-cau-hoi-tao-mot-article/` | Approve a canonical Knowledge target or retire as a domain-specific legacy post |
| `DOMAIN_TARGETED` | 813 | `/tri-thuc/article-la-narrative-projection-danh-cho-nguoi-doc/` | Approve a canonical Knowledge target or retire as a domain-specific legacy post |
| `DOMAIN_TARGETED` | 814 | `/tri-thuc/moi-article-phai-co-anh-chinh-va-chu-dong-xin-them-anh-khi-can/` | Approve a canonical Knowledge target or retire as a domain-specific legacy post |
| `UNSUPPORTED_MEDIA_REFERENCE` | 31 | `/wp-content/uploads/wp1-thumbnail-1.jpg` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 33 | `/wp-content/uploads/wp1-thumbnail-2.jpg` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 35 | `/wp-content/uploads/wp1-thumbnail-3.jpg` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 51 | `/wp-content/uploads/2026/07/IMG_1422.jpg` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 802 | `/wp-content/uploads/2026/08/IMG_3551.jpg` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 803 | `/wp-content/uploads/2026/08/IMG_3573.jpg` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 808 | `/wp-content/uploads/2026/08/IMG_3547.jpg` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 809 | `/wp-content/uploads/2026/08/IMG_3550.jpg` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 815 | `/wp-content/uploads/2026/08/IMG_3574.jpg` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 818 | `/wp-content/uploads/2026/08/IMG_3581.jpg` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 819 | `/wp-content/uploads/2026/08/LOGO.png` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 820 | `/wp-content/uploads/2026/08/IMG_3612.jpg` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 843 | `/wp-content/uploads/2026/08/loudes-vedette.jpeg` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 845 | `/wp-content/uploads/2026/08/cropped-LOGO.png` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 846 | `/wp-content/uploads/2026/08/bo-may-junhan-w64.jpg` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 847 | `/wp-content/uploads/2026/08/junghans-w64-con-dong-bach-mat-truoc.jpg` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 848 | `/wp-content/uploads/2026/08/odo-36-8-bo-may-mat-sau.webp` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 849 | `/wp-content/uploads/2026/08/IMG_3612-1.jpg` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 850 | `/wp-content/uploads/2026/08/odo-36-8-ba-vach-bet-mat-sau.jpg` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 851 | `/wp-content/uploads/2026/08/IMG_3612-2.jpg` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `UNSUPPORTED_MEDIA_REFERENCE` | 852 | `/wp-content/uploads/2026/08/IMG_4413.jpg` | Confirm whether a governed MediaAsset mapping is required; otherwise retire the legacy URL |
| `RETIRED_LEGACY_GARBAGE` | 6 | `/wp-global-styles-nhk-v2/` | Approve permanent retirement with no V3 target |

## Resolved no-op

| V2 ID | Legacy path | V3 result | Evidence |
|---:|---|---|---|
| 758 | `/` | `READY_NOOP` to the native V3 homepage `/` | Route smoke returns HTTP 200 for `/`; governed local-dev apply recorded `READY_NOOP` with no redirect, deletion or duplicate post |

The root URL is not included in the 27 residual count. The source record was
normalized by the exporter and migration URL boundary to target `/` when the
legacy source is `/` and no target path was supplied.

Until the remaining decisions are recorded in a governed migration decision or an
approved target mapping, the records remain explicit skips and Cutover stays
`NOT READY`.

## Read-only candidate mappings for the five domain URLs

The restored export contains an exact title match between each residual
`nhk_knowledge` post and an existing V3 Knowledge claim. This is stronger than
a name-only guess because the legacy post title and the claim's
`canonical_name` are identical. The table is still a proposal aid only; it does
not approve a redirect or migrate an editorial body.

| V2 ID | Legacy path | Exact V3 claim candidate | Candidate stable key | Read-only status | Required governed check |
|---:|---|---|---|---|---|
| 810 | `/tri-thuc/sua-article-cu-phai-giu-identity-va-nang-chuan-bien-tap/` | Sửa Article cũ phải giữ identity và nâng chuẩn biên tập | `nhk:knowledge:editorial.article.legacy-rewrite` | UUID `57fabad0-40ef-4f59-a104-58e7fbd6a441`; revision 2; `ARCHIVED`; `UNVERIFIED`; non-public; no active target | Decide whether to retire the legacy URL or approve a separate active target |
| 811 | `/tri-thuc/luat-article-phai-co-ket-qua-tiep-thu-ro-rang/` | Luật Article phải có kết quả tiếp thu rõ ràng | `nhk:knowledge:editorial.article.learning-outcome` | UUID `dcf59e1e-8774-45e0-a61a-b4c569eada27`; revision 2; `ARCHIVED`; `UNVERIFIED`; non-public; no active target | Decide whether to retire the legacy URL or approve a separate active target |
| 812 | `/tri-thuc/khong-mac-dinh-moi-cau-hoi-tao-mot-article/` | Không mặc định mỗi câu hỏi tạo một Article | `nhk:knowledge:editorial.article.question-cluster` | UUID `a796159e-20c1-4b97-a17a-1528ed77341c`; revision 2; `ARCHIVED`; `UNVERIFIED`; non-public; no active target | Decide whether to retire the legacy URL or approve a separate active target |
| 813 | `/tri-thuc/article-la-narrative-projection-danh-cho-nguoi-doc/` | Article là narrative projection dành cho người đọc | `nhk:knowledge:editorial.article.narrative-projection` | UUID `51151d0b-8a63-471d-ad27-d568fc340fcf`; revision 2; `ARCHIVED`; `UNVERIFIED`; non-public; no active target | Decide whether to retire the legacy URL or approve a separate active target |
| 814 | `/tri-thuc/moi-article-phai-co-anh-chinh-va-chu-dong-xin-them-anh-khi-can/` | Mọi Article phải có ảnh chính và chủ động xin thêm ảnh khi cần | `nhk:knowledge:editorial.article.media-required` | UUID `b503cbc4-26d5-4713-aafa-7d6d7f30cc2c`; revision 2; `ARCHIVED`; `UNVERIFIED`; non-public; no active target | Decide whether to retire the legacy URL or approve a separate active target |

The candidate matches reduce identity uncertainty for these five records, but
none is currently an eligible public target: all five claims are archived and
unverified, with `ARCHIVED_OPERATIONAL_NOT_PUBLIC_KNOWLEDGE` disposition and no
active consolidation target in the export. The legacy posts also have empty
`post_content`; their editorial URL/body policy is not equivalent to a
Knowledge claim automatically. A governed retirement or separately approved
active target is therefore required, while WordPress `wp_posts` remains the
sole editorial body and URL authority.

## Decision classification matrix

| Case set | Count | Resolution class | Safe rule/evidence | Current action |
|---|---:|---|---|---|
| Native homepage `/` | 1 | `RETAIN` / `RULE_RESOLVABLE` | Exact native V3 route and recorded `READY_NOOP` | No redirect or mutation |
| Legacy `wp_global_styles` URL | 1 | `RETIRE` / `RULE_RESOLVABLE` | Non-editorial implementation record has no V3 semantic target | Await governed retirement recording |
| Attachment URLs with explicit Media identity | 3 | `EVIDENCE_RESOLVABLE` | IDs 818, 849 and 852 have canonical Media/asset provenance | Preserve provenance; publication remains governed |
| Attachment URLs without semantic identity | 15 | `AMBIGUOUS_REQUIRES_HUMAN` | Bytes/path/checksum do not establish intended semantic usage | Keep skip; recover evidence or retire case-by-case |
| Unavailable thumbnail URLs | 3 | `DEFERRED` | Exact V2 paths return 404; no bytes to verify | Recover from approved source or retire |
| Five legacy Knowledge URLs with exact claim candidates | 5 | `EVIDENCE_RESOLVABLE` identity, `AMBIGUOUS_REQUIRES_HUMAN` disposition | Exact candidate exists, but every target is archived, unverified and non-public | Approve active target or retire; never redirect to hidden claim |

This matrix prevents the residual count from being treated as 27 human
decisions. Only case-level ambiguous target/publication/retirement decisions
require human review; deterministic no-op, retirement rule and explicit
provenance evidence are recorded without applying mutation.
