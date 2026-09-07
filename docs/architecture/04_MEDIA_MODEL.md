# Media model

> **NON-NORMATIVE CURRENT MODEL / RUNTIME GUIDE.** Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

Media là canonical semantic identity độc lập. `Media`, `MediaAsset` và
`MediaUsage` không được gộp:

- `Media` = canonical semantic media identity;
- `MediaAsset` = source-original/derivative binary + technical/storage metadata;
- `MediaUsage` = contextual placement, role, alt/caption/SEO intent.

WordPress attachment chỉ là storage/projection mapping. Nó không phải semantic
Media authority. Checksum, filename, attachment URL và upload timestamp chỉ là
binary/duplicate/presentation signals; chúng không tự merge hoặc mint canonical
Media identity.

## Reconcile before Media create

Mọi ingest/adoption trước tiên phải resolve/reconcile reusable canonical Media.
Nếu một Media phù hợp đã tồn tại, reuse canonical UUID/stable key/revision,
asset đủ điều kiện và contextual Usage thay vì tạo Media mới vì cùng binary được
cần ở Post/Product/Specimen/Entity khác.

Checksum equality có thể tạo duplicate candidate nhưng không chứng minh semantic
identity. Ambiguous identity phải review/defer; không auto-merge.

## Current governed ingest boundary

New image bytes enter the shared governed Media application boundary:

`validate actual binary → normalize/orient/resize/encode as required →
create-or-resolve one canonical Media → retain source-original PRIVATE/protected
MediaAsset → create eligible optimized/public derivatives under the same Media
→ create/reconcile contextual MediaUsage → WordPress attachment/projection
read-back → canonical Media/Asset/Usage read-back → cleanup temporary artifacts →
idempotency check`.

Corrupt, fake, unreadable, out-of-root or incomplete bytes fail closed. Failure
must not leave orphan Media, Asset, Usage, attachment mapping or temporary file.
Derivative WebP/thumbnail/responsive representations never create a second
semantic Media.

The current MCP binary/metadata entry remains `nhk.media.ingest`; direct file
transport is multipart, not base64/data URL. Admin/Article/WordPress adapters
reuse the same application boundary and do not write semantic Media tables as a
second writer.

## Semantic role, view/detail and subject context

`MediaUsage` describes placement/context. View/detail intent describes what part
or view of the subject the image represents. Typical operator concepts may
include:

`front`, `back`, `movement`, `dial`, `hands`, `pendulum`, `gong`, `hammer`,
`plate`, `marking`, `logo`, `case_detail`.

These examples are not permission to invent registry values. The executable
Media usage/detail registries decide the accepted identifiers; unknown values
fail closed. A view/detail label is not an Authority type, Knowledge claim,
Evidence relation or Graph predicate.

The semantic chain is:

`binary/storage → canonical Media → MediaAsset(s) → MediaUsage / controlled
role/detail context → resolved canonical subject → registered Graph/projection
only when that separate relation contract requires it`.

Usage does not automatically create `depicts`, `about`, Knowledge, Source or
Evidence. If a semantic subject relation is required, resolve canonical target
first and use the registered Graph/Governance boundary.

## Representative versus evidence/detail

`representative`, `evidence` and `technical_detail` are distinct intent classes.
Evidence/detail imagery does not silently replace an existing representative.
Representative selection follows deterministic contract precedence, not upload
recency. Evidence imagery is not automatically canonical Evidence; Source/
Evidence remains its own factual provenance model.

Contextual alt/caption belongs to usage/editorial projection and may differ by
surface. OCR/recognition/EXIF/filename/caption/alt may feed lexical/research
candidates only; none is semantic truth by itself.

## Article and Product/Specimen reuse

WordPress owns featured/inline editorial ordering. MediaUsage indexes the
context; it does not copy Article body truth. Product and Specimen may reuse one
Media through separate usages; deleting a Product must not delete shared Media
or Specimen Media use.

An image appearing in a listing does not prove it depicts a canonical Specimen.
Such a fact needs the applicable registered relation/evidence contract.

## Public/Admin boundary

Admin Hình ảnh is the current canonical Media management workspace. It can show
Media identity, assets, usages/roles, provenance and frontend state without
making WordPress attachment the owner. Normal guided forms use canonical
selection/user-facing fields and do not require proposal UUID, Evidence UUID,
fingerprint, expected revision or raw JSON.

This does not authorize a standalone indexable Image/Media detail page. Public
asset delivery/projection remains governed by Media visibility/readiness/route
policy. A future standalone route needs explicit architectural approval.

## Physical filename integrity

Semantic rekey/namespace changes are separate from physical attachment filename
normalization. A semantic identity operation must not silently rename
`_wp_attached_file`, derivatives or inline URLs. Basename-sensitive work requires
a separately governed Media operation with pre/post inventory, collision/checksum
and HTTP/read-back verification.

The September 2026 Odo media incident remains historical evidence for this
separation; it is not permission to run unsolicited repair/backfill.
