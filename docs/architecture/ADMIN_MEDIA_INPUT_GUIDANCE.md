# NHK V3 Admin Media Input Guidance

> **NON-NORMATIVE.** This is implementation guidance. If it conflicts with
> `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution controls.

## Purpose

Admin and other operator-facing inputs must preserve the Media, MediaAsset and
MediaUsage boundaries while allowing an editor to describe image intent. The
Admin surface is an input adapter; it is not a second semantic write path.

## Required flow

Admin composes a governed Media proposal and submits it through the existing
Governance and Controlled Apply path. The proposal may carry asset metadata,
contextual usage fields, the controlled usage role, SEO Blueprint context and
batch context. The application layer then delegates Media persistence through
`MediaIngestGateway` and `MediaService`.

Admin must not write `nhk_media`, `nhk_media_assets` or `nhk_media_usages`
directly, create a Graph edge from an upload, promote OCR/recognition to an
Authority identity, or use keyword groups as meta keywords or Knowledge.
Every file adoption/upload must validate actual image bytes before persistence;
corrupt/fake/unreadable payloads fail closed and partial attachment/mapping or
semantic records are cleaned up so no orphan remains. The source-original is
retained PRIVATE and eligible optimized derivatives are PUBLIC under one
canonical Media identity.

## Controlled input vocabulary

Use `MediaUsageRoleRegistry` for roles, `MediaDetailTypeRegistry` for detail
types and `SeoKeywordGroupRegistry` for bounded keyword groups. Use
`MediaDiagnosticCodeRegistry` and `MediaSeoStateRegistry` when displaying
missing, placeholder, low-resolution, metadata or rights diagnostics. Unknown
values fail closed.

For an Article, the input adapter may select one Media for
`featured_primary`, one distinct Media for `inline_primary`, and zero or more
supporting Media. The Article coordinator reuses suitable existing Media
before creating a placeholder. WordPress remains the owner of editorial image
selection and content ordering.
Entity projection must expose the selected representative separately from
evidence and `technical_detail`; evidence/detail never silently substitutes an
existing representative. Selection follows deterministic precedence, never
upload recency.

## Dictionary lexical observation — 2026-09-05

When `DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md` is enabled, Media/Image input may
feed the lexical detector only after the normal Media/attachment write boundary
has completed, or through an explicitly read-only preview.

Allowed lexical observation inputs include editor-supplied caption/alt/context
and weak signals such as filename, OCR, EXIF or visual recognition. The
observation must preserve its source and strength. Weak signals never become an
approved alias, semantic identity, Knowledge/Evidence or Graph relation by
themselves.

The Dictionary resolver must search approved labels and current canonical
owners before creating a private Candidate. Ambiguous matches remain unlinked.
A newly detected image term must never cause a new Media, Authority, Knowledge,
Evidence or `depicts` relation automatically.

An approved Dictionary concept may reuse an existing eligible Media as an
illustration through the existing MediaUsage/projection boundary. The binary is
not copied into a Dictionary-specific store. Representative, evidence and
technical-detail precedence remains governed by the Media contracts.

## SEO and upload expectations

Alt text and caption are usage-context fields. Subject, view and filename
intent belong to the Blueprint and asset metadata; they do not change Media
identity. Camera-style filenames are normalized by the application boundary
when enough context exists. Public preferred-image and sitemap projections
exclude placeholders and non-public assets.

Every new NHK-managed image byte upload enters one governed Media identity
boundary. The adapter validates and auto-orients the source, creates the
contract-required public normalized derivative (currently WebP where supported),
applies contextual naming only when trustworthy context exists, reads back the
WordPress attachment/projection and cleans temporary workfiles. The uploaded
source-original bytes are retained as a private/protected `MediaAsset` under the
same canonical Media identity according to the Constitution; they are not the
public filename/URL identity and are not discarded merely because a derivative
was produced.

Derivative WebP/thumbnail/responsive sizes never become a second semantic Media.
A WordPress attachment is a storage/projection mapping for the same Media, not a
semantic owner. Existing public URLs remain stable after publication; a later
SEO filename preference does not authorize silent rename/rewrite of an existing
public attachment.

A missing trustworthy naming context fails closed; the adapter must not invent
a descriptive filename. Upload, OCR, recognition, EXIF or filename context does
not infer Authority, Knowledge, Evidence, Graph, `about` or `depicts`.

The direct multipart MCP path and Admin path must reuse the same governed Media
application boundary. If a suitable canonical Media already exists, downstream
systems should reuse its UUID/stable key, eligible asset and contextual Usage
rather than creating a duplicate Media solely because the same image is needed
in another Article, Product, Specimen or projection.

Existing legacy attachments are read-only unless a separately governed repair
or migration task explicitly authorizes changes.
