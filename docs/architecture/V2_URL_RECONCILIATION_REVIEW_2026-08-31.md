# V2 URL Reconciliation Review — 2026-08-31

This is a review artifact for the 28 V2 URL records that remain without a
canonical V3 target after Mapper 6.14. It does not approve a redirect,
retirement, media import, or source-data mutation. No V2 or production data
was changed while producing this list.

Source artifact: `/private/tmp/nhk-v3-v2-full-export-url-6.14.json`  
Source SHA-256: `061b2b647407c888de890b3f34bc3be7c80803f3c1e923372de409d278e5deac`  
Expected residual count: 28

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
| `RETIRED_LEGACY_GARBAGE` | 758 | `/` | Resolve against the native V3 homepage policy; do not create a duplicate redirect |

Until these decisions are recorded in a governed migration decision or an
approved target mapping, the records remain explicit skips and Cutover stays
`NOT READY`.
