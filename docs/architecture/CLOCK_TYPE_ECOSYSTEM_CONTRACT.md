# Clock-Type Ecosystem Contract

> **ACTIVE application/projection contract — 2026-09-13.** This document is
> subordinate to `docs/constitution/NHK_V3_CONSTITUTION.md` and the owning
> Authority, Graph, Article, Knowledge, Media, Video, Capture, Public Identity
> and Governance contracts. It organizes existing vocabulary; it does not add
> an entity type, predicate, taxonomy or persistence owner.

## 1. Identity and profile boundary

Clock Type is the `clock_type` Entity Profile over the existing Authority
shape:

```text
entity_type = classification
family      = clock_type
profile     = clock_type
```

`family` is persisted payload data and is resolved by `EntityProfileRegistry`.
It is never inferred from stable key, name, title, slug or UI label. The
compatibility spelling `clock-type` may be read with a diagnostic, but is not
canonical and is never rewritten by this contract. No separate Clock Type
entity type, combined facet identity or stable-key normalization is introduced.

Brand remains an independent profile:

```text
entity_type = brand
profile     = brand
```

Brand is optional for Model, Variant, Specimen and Product. No Unknown Brand or
Brand filler identity is created.

## 2. Relation / content matrix

| Context | Existing vocabulary | Canonical owner | Write boundary | Read projection |
|---|---|---|---|---|
| Clock Type → child Clock Type | `subtype_of` | Graph | `nhk.capture.ingest` → Proposal → Approval → Eligibility → Controlled Apply | bounded hierarchy projection |
| Model → Clock Type | `classified_as` | Graph | governed relation proposal with exact revisions | direct membership |
| Variant → Clock Type | `classified_as` | Graph | governed relation proposal with exact revisions | direct membership |
| Specimen → Clock Type | `classified_as` | Graph | governed relation proposal with exact revisions | direct membership |
| Product → Clock Type | `classified_as` | Graph | governed relation proposal with exact revisions | direct membership |
| Brand ↔ Clock Type | no persisted relation | profile-specific Graph read recipe | no write; no shortcut | bounded derived projection |
| Article → Clock Type | `about` where explicit Article subject is the type | WordPress Article + Graph | Article/Governance lifecycle | direct Article relation |
| Article object → Clock Type | object subject plus `classified_as` context | WordPress Article + Graph | object-specific governed relation | derived Article context |
| Knowledge → Clock Type | exact claim subject/scope/provenance | Knowledge + Source/Evidence | Knowledge Governance | exact-subject claims |
| Media → Clock Type | `depicts` only when explicit conceptual attachment is sanctioned; usage remains separate | Media + Graph | Media/Governance boundary | `DIRECT_MEDIA` or `DERIVED_MEDIA` |
| Video → Clock Type | explicit `about` target only for a video about the whole type | Video + Graph | Video/Governance boundary | direct or derived Video context |

Graph là relation owner duy nhất. The registered vocabulary is limited to
`subtype_of`, `classified_as`, `model_of`, `variant_of`, `about` and `depicts`
for the contexts above; no new predicate is created here.

## 3. Hierarchy

`subtype_of` is legal only for two ACTIVE `classification` entities whose
resolved profile is `clock_type`, with distinct UUIDs, no active duplicate edge
and no cycle. Outbound cardinality is one and inbound cardinality is many.
Cycle detection is bounded and fail-closed. Origin, material, case form,
feature, music, rod count and dial form remain separate facets and never use
`subtype_of`.

Retired edges are not revived implicitly. A hierarchy write packet binds source
and target UUIDs, revisions, predicate, dependency closure and provenance;
Controlled Apply performs canonical Graph read-back.

## 4. Object membership

Only these sources may use `classified_as` to a Clock Type:

```text
Model / Variant / Specimen / Product → classified_as → Classification(family=clock_type)
```

Brand and Movement are rejected. Membership planning searches/reuses the exact
Clock Type first, binds source and target revisions, retains provenance and
scope, rejects retired-edge resurrection, and enters the existing Governance
lifecycle. A membership edge does not create Brand, Model, Variant, Specimen or
Product identities.

## 5. Derived Brand association

Brand ↔ Clock Type is projection-only. The approved bounded paths are:

```text
Brand ← model_of — Model — classified_as → Clock Type
Brand ← model_of — Model ← variant_of — Variant — classified_as → Clock Type
```

The reader returns deterministic `DIRECT`/`DERIVED` origin, hop count, ordered
predicates, explainable path and alternative paths where available. Product or
Specimen to Brand paths are `CONTRACT_GAP` when no registered structural path
proves them; they are not inferred. Không tạo Brand↔Clock Type shortcut edge;
no parallel relation field is persisted.

## 6. Article and Knowledge

An Article may use an explicit `about` relation to the Clock Type when its
semantic subject is the whole type. An Article about a concrete Model, Variant,
Specimen or Product keeps that object as its primary subject; Clock Type is
derived context only. WordPress remains the owner of title, body, author, dates,
categories and editorial URL.

Knowledge claims are selected by exact canonical subject, active/public state,
scope, provenance and Evidence/Source support. A Clock Type claim may directly
name the Clock Type as subject when it truly describes the whole type. A claim
owned by a child object never becomes a Clock Type claim merely because Graph
can reach the type. No Claim body is copied into Article, Graph or dossier
storage.

## 7. Media and Video

MediaUsage remains a contextual placement record; it does not create `depicts`,
Evidence or Claim truth. Media directly depicting a concrete object remains
object-scoped and can appear in a Clock-Type dossier only as `DERIVED_MEDIA`.
Direct conceptual `depicts` attachment is accepted only when the current Media
contract and evidence permit it; otherwise the result is `CONTRACT_GAP`, not an
invented relation. PRIVATE, review, placeholder and non-ready Media is omitted
from public projection.

Video `about` remains the exact existing target. A video about a concrete object
is not rewritten to Clock Type; a video about the whole type may explicitly use
the Clock Type as target if the Video attachment contract permits it. Video
identity, external source, SEO, VideoObject, thumbnail, readiness and
`/video/{slug}/` ownership are unchanged.

## 8. Dossier and Admin projection

The Clock-Type dossier composes read-only owner output in this order:

```text
Identity → Hierarchy → direct Knowledge → Articles → Media → Videos
         → Models → Variants → Specimens → Products → derived Brands
         → diagnostics/readiness
```

It preserves exact subject and relation origin. Media distinguishes
`DIRECT_MEDIA` from `DERIVED_MEDIA`; Article, Video and entity relations retain
`DIRECT`/`DERIVED`, hop count and explainable predicates. Admin projection may
show UUID, stable key, revision and lifecycle state; public profile projection
must omit internal identity fields.

Every section preserves these states:

```text
AVAILABLE_WITH_ITEMS
AVAILABLE_EMPTY
UNAVAILABLE_IMPLEMENTATION_GAP
BLOCKED
```

Unavailable is not converted to empty. Dossier assembly never writes Authority,
Graph, Article, Knowledge, Media, Video or Public Identity data and never
allocates/reprojects a route.

## 9. Governance and route boundary

All semantic relation changes use the existing Capture-owned
Proposal → Submit → Approval policy → Eligibility → Controlled Apply →
canonical read-back lifecycle with exact revision/dependency bindings. Direct
Graph/Authority/Article/Knowledge/Media/Video writers are not substitutes.

Root Public Identity and route allocation/reprojection remain outside this
contract. Không cấp Public Identity/root route trong lượt này. Existing route owners and `/video/`, `/anh/`, `/thuong-hieu/` and
`/loai-dong-ho/` boundaries are read-only inputs; no `/dong-ho-cong-cong/` or
other root route is allocated here.

## 10. Current gaps

- `CONTRACT_GAP`: no sanctioned Product/Specimen → Brand structural path exists,
  so Brand derivation stops instead of guessing.
- `CONTRACT_GAP`: direct conceptual Media → Clock Type is available only when
  the current Media semantic attachment contract and evidence authorize it;
  object-scoped Media continues through derived Graph context.
- A missing Graph, Knowledge, Media, Video or route owner is an unavailable or
  blocked section, never a fabricated empty semantic result.
