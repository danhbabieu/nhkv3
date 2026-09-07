# NHK V3 Admin Media Input Guidance

> **NON-NORMATIVE CURRENT GUIDANCE.** If it conflicts with
> `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution controls.

## Purpose

Admin is a guided control-plane/input adapter over the canonical Media and
Governance boundaries. It is not a second semantic writer.

Media, MediaAsset and MediaUsage remain separate. WordPress attachment is
storage/projection only. Admin must not write semantic Media tables directly,
infer an Authority identity from an upload, or turn OCR/recognition/filename/
caption into Knowledge/Evidence/Graph truth.

## Required create-or-reuse flow

Before creating Media, Admin/application code must search current canonical
Media and reconcile the incoming asset/context. Reuse an existing suitable Media
when canonical identity is already established. Checksum/filename/URL equality
may flag a duplicate candidate but is not semantic merge proof.

For new bytes the shared flow is:

`binary validation → canonical Media create-or-resolve → source-original
PRIVATE/protected MediaAsset → eligible optimized derivative(s) → contextual
MediaUsage → WordPress attachment/projection → canonical Media/Asset/Usage
read-back → temporary/orphan cleanup → idempotency check`.

Corrupt/fake/unreadable input fails closed. A partial attachment, mapping, asset,
usage or temporary file must not remain after failure.

The current direct MCP image transport is multipart `nhk.media.ingest`; Admin
must converge on the same governed application boundary. No base64/data URL or
parallel semantic writer is authorized.

## Controlled usage and view/detail context

Use the executable Media usage/detail registries; unknown values fail closed.
Operator-facing view/detail concepts can include `front`, `back`, `movement`,
`dial`, `hands`, `pendulum`, `gong`, `hammer`, `plate`, `marking`, `logo`,
`case_detail` where a corresponding current registry value exists.

Do not invent a runtime identifier because the English concept is useful in UI.
Role/detail context is metadata/usage intent, not a Graph predicate or Knowledge
claim.

For Article use, WordPress remains owner of featured/inline ordering. Current
mandatory Article roles such as `featured_primary`, `inline_primary` and
supporting usages are selected through the Media coordinator and must reuse
canonical Media where possible. `representative`, `evidence` and
`technical_detail` remain distinct presentation/usage intents; evidence/detail
never silently replaces representative.

## Canonical subject relation

An image usage may point at or be used near an Authority/Post/Product/Specimen,
but placement does not prove a semantic relation. If the operation needs
`depicts` or another relation, first resolve the canonical subject and use the
registered Graph/Governance lifecycle. Do not derive the relation from file
name, OCR, proximity, WP attachment parent or upload form selection alone.

## Dictionary / Living Knowledge observation

Media/Image input may feed read-only lexical/research observation or a
post-write private Dictionary candidate when that contract is enabled. Weak
signals include caption, alt, filename, OCR, EXIF and recognition. They must
retain source/strength and never create Authority, Knowledge, Evidence or Graph
truth automatically.

If a real fact is discovered, hand it to the normal semantic workflow:
canonical subject resolution → reconcile existing Knowledge → Source/Evidence →
Governance → canonical read-back. MediaUsage/`depicts` alone is not Evidence.

## SEO and public projection

Alt/caption are contextual usage/editorial fields. Public filename naming uses
trustworthy context only and does not change canonical Media identity. Public
asset URLs remain stable after publication unless a separately governed media
operation authorizes a change.

Public preferred-image/sitemap projections exclude placeholders, private or
ineligible assets. Source-original bytes remain private/protected while eligible
derivatives may be public under the same Media.

## Unified Workbench guidance — current

The standard Admin menu is Tổng quan, Nội dung, Media, Tri thức, Duyệt, Hệ
thống and Nâng cao. Media contains `Tất cả`, `Hình ảnh` and `Video` workspaces
with domain-specific list/detail adapters.

Normal guided workflows resolve canonical selections and user-facing fields.
They do not ask operators for proposal UUID, Evidence UUID, fingerprint,
expected revision or raw JSON; those belong under Kỹ thuật/Nâng cao.

For Video, “Xem trên web” opens the canonical `/video/{slug}/` route only after
public projection eligibility; “Mở nguồn gốc” opens the external source. The
same principle applies to Media: storage/source actions are distinct from
canonical semantic identity and public projection state.

Existing legacy attachments remain read-only unless a separately governed
repair/migration explicitly authorizes changes.
