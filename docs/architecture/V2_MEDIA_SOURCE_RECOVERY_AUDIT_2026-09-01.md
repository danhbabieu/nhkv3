# V2 Media Source Recovery Audit — 2026-09-01

This is a read-only source-recovery audit for the 21 legacy attachment URL
records classified by the V2→V3 migration. It does not download bytes into
V3, create MediaAsset identities, publish assets, or change V2/live data.

Source export: `/private/tmp/nhk-v3-v2-full-export-url-6.14.json`  
Source export SHA-256: `061b2b647407c888de890b3f34bc3be7c80803f3c1e923372de409d278e5deac`  
Read-only endpoint base: `https://demo.1945.vn/wp-content/uploads/`

## Result

18 of 21 exact legacy upload paths returned HTTP 200 from the read-only V2
reference. The three `wp1-thumbnail-*` paths returned HTTP 404. The 200
responses have allowlisted image MIME types and nonzero byte sizes. Three of
the 18 candidates (attachments 818, 849 and 852) already have explicit
canonical Media identities and imported PRIVATE asset rows in the export; the
other 15 have no equivalent deterministic semantic mapping in the export. A
governed recovery/update still requires backup/restore evidence, checksum
capture from the approved source artifact, preservation of the existing
processed asset where applicable, and publication/privacy approval.

| V2 ID | Legacy path | HTTP | MIME | Bytes | Read-only response SHA-256 |
|---:|---|---:|---|---:|---|
| 31 | `wp1-thumbnail-1.jpg` | 404 | `text/html` | 38334 | `b3a9c8550dc480d7ea9b6618fad7b78144284fa758d3ab190a0e9fb5580cdb8a` |
| 33 | `wp1-thumbnail-2.jpg` | 404 | `text/html` | 38334 | `b3a9c8550dc480d7ea9b6618fad7b78144284fa758d3ab190a0e9fb5580cdb8a` |
| 35 | `wp1-thumbnail-3.jpg` | 404 | `text/html` | 38334 | `b3a9c8550dc480d7ea9b6618fad7b78144284fa758d3ab190a0e9fb5580cdb8a` |
| 51 | `2026/07/IMG_1422.jpg` | 200 | `image/jpeg` | 567838 | `c6d46207356bacb62638f77db4f5558bdbbe8725ddaf3698b5d8f010b9654f56` |
| 802 | `2026/08/IMG_3551.jpg` | 200 | `image/jpeg` | 358643 | `eafed2ad6875479627f82384968acc9fb3269a1c530c112455d7730cbf3dc16e` |
| 803 | `2026/08/IMG_3573.jpg` | 200 | `image/jpeg` | 482687 | `4eef14f89a79095b87b697d8a4c068a841247f5509bb1c0757afc1559b31d504` |
| 808 | `2026/08/IMG_3547.jpg` | 200 | `image/jpeg` | 263738 | `e105a5958e5161a453272c173a445d3d35e1d12b1a77e2d07d3d8e92add6b93e` |
| 809 | `2026/08/IMG_3550.jpg` | 200 | `image/jpeg` | 410577 | `9eb138e66109edef7535bcc2b8a553cb77386f156f08ff743bdfa476c35ce7d5` |
| 815 | `2026/08/IMG_3574.jpg` | 200 | `image/jpeg` | 273751 | `947d9a0eb5310f979b27645ca7442ba361d769b6ca2edeabe116e401625317b6` |
| 818 | `2026/08/IMG_3581.jpg` | 200 | `image/jpeg` | 2473720 | `dc083036a32647a28d4a01a6e71656e81cc1cd28aa571062448bd510b272d1ba` |
| 819 | `2026/08/LOGO.png` | 200 | `image/png` | 610537 | `19ce36840ca75453b1201c05012fa38f5f42e9ce9c759443e1f24b9e94dec3d1` |
| 820 | `2026/08/IMG_3612.jpg` | 200 | `image/jpeg` | 3426814 | `d43b7c635e9a69ce1ac2eb726c9857438e34bf7abb3be74b24a4c3f894af85cf` |
| 843 | `2026/08/loudes-vedette.jpeg` | 200 | `image/jpeg` | 111821 | `4051514bbc8a061f1e0411619fec266a07c060b0d8d4ec095944639f5032e4d8` |
| 845 | `2026/08/cropped-LOGO.png` | 200 | `image/png` | 119669 | `2e4605b79dd90d115faf4df2efe33bf06ff3c552b6cb422e6e200bc5c39198a0` |
| 846 | `2026/08/bo-may-junhan-w64.jpg` | 200 | `image/jpeg` | 10256 | `859d574b80259da580c415b00f09aadd1012dd6f20d4c9adcd72bf233f776151` |
| 847 | `2026/08/junghans-w64-con-dong-bach-mat-truoc.jpg` | 200 | `image/jpeg` | 6428 | `90f69011d3f6f0fea58a7846649291f0f75c82559df07ab982925ba3d679aa90` |
| 848 | `2026/08/odo-36-8-bo-may-mat-sau.webp` | 200 | `image/webp` | 7682 | `4e7353362897145098d193ee34a406099f54516f12a845c5d4606ebc2ba10bb8` |
| 849 | `2026/08/IMG_3612-1.jpg` | 200 | `image/jpeg` | 3426814 | `d43b7c635e9a69ce1ac2eb726c9857438e34bf7abb3be74b24a4c3f894af85cf` |
| 850 | `2026/08/odo-36-8-ba-vach-bet-mat-sau.jpg` | 200 | `image/jpeg` | 16527 | `c4cf283440fe06a60ca9b0e27cccbe127347d6b4ef73bd1ac447f523a946671b` |
| 851 | `2026/08/IMG_3612-2.jpg` | 200 | `image/jpeg` | 3426814 | `d43b7c635e9a69ce1ac2eb726c9857438e34bf7abb3be74b24a4c3f894af85cf` |
| 852 | `2026/08/IMG_4413.jpg` | 200 | `image/jpeg` | 1452543 | `edf5396fd2eef21b7052481fb7a0e8251aaa8f49c54f16b8c3cd954d5b1876a2` |

The equal hashes for IDs 849 and 851 and for the three 404 responses are
evidence for deduplication/review only; they must not be used to merge
semantic identities or replace the source records. HTTP availability also does
not prove ownership, intended usage, or permission to republish.

## V2 REST metadata cross-check

The read-only WordPress REST API was queried for all 18 HTTP-200 attachment
IDs. Every response reported the same MIME and `media_details.filesize` as the
downloaded response. The API returned `post=null` for 17 attachments; ID 845
returned `post=819`, which is the V2 logo attachment, not an editorial post or
semantic usage relation. Several responses returned `source_url=false` even
though the exact upload path returned HTTP 200, so the path/bytes table above is
the authoritative recovery evidence. The API exposed no deterministic
Media/Specimen/Product usage mapping for the 15 unmapped candidates. The export
does, however, carry explicit provenance for three existing MediaAsset rows:

| Attachment | Canonical Media UUID | Original filename | Observed source hash | Existing V3 asset state |
|---:|---|---|---|---|
| 818 | `839ba38c-d60e-4029-ad90-245bd73a267a` | `IMG_3581.jpg` | `dc083036a32647a28d4a01a6e71656e81cc1cd28aa571062448bd510b272d1ba` | PRIVATE, parent Media draft |
| 849 | `6c1783b3-d49e-4664-bec0-256d91db79b9` | `IMG_3612-1.jpg` | `d43b7c635e9a69ce1ac2eb726c9857438e34bf7abb3be74b24a4c3f894af85cf` | PRIVATE, parent Media draft |
| 852 | `11f5eb62-076e-44b6-a34a-740963e5c50c` | `IMG_4413.jpg` | `edf5396fd2eef21b7052481fb7a0e8251aaa8f49c54f16b8c3cd954d5b1876a2` | PRIVATE, parent Media draft |

These are identity/attachment relationships, not permission to replace the
existing processed V2 asset bytes. Their imported V3 asset checksums and
storage keys refer to processed V2 files that are not present in the current
workspace, so a recovery implementation must preserve provenance and choose
explicitly whether the original is a new asset variant or a governed repair.

Consequently, even the available files remain recovery candidates rather than
approved imports. For the 15 unmapped files, matching filenames, equal hashes
or a V2 attachment's own detail page cannot establish semantic identity or
intended public usage. For the three explicitly mapped files, identity is
known but publication, asset-variant semantics and source-byte handling remain
governed decisions.

## Resolution classes

The 21 attachment cases are not one undifferentiated human gate:

| Case set | Count | Class | Evidence |
|---|---:|---|---|
| Explicit canonical Media identity and source provenance | 3 | `EVIDENCE_RESOLVABLE` | Attachments 818, 849 and 852 have canonical Media/asset mappings |
| Available bytes without semantic usage identity | 15 | `AMBIGUOUS_REQUIRES_HUMAN` | HTTP/MIME/size/checksum evidence exists, but no deterministic Media/Specimen/Product usage target |
| Unavailable thumbnail paths | 3 | `DEFERRED` | Exact source paths return 404; recovery artifact is missing |

The three evidence-resolvable cases still require governed privacy/publication
and asset-variant decisions; they are not identity blockers. The 15 ambiguous
cases and any unrecovered 404 cases remain explicit skips. No checksum-only
merge or binary import is performed.

## Required next gate

For the 18 HTTP-200 candidates, preserve the original V2 backup, capture a
reproducible approved source artifact, verify the bytes and MIME independently,
resolve each candidate to an explicit Media/Specimen/Product usage mapping,
then ingest through governed `nhk.media.ingest` with PRIVATE-by-default
publication. The three 404 records require source recovery or explicit
retirement. Until those decisions are recorded, all 21 remain migration
ledger skips and public V3 delivery remains fail-closed.
