# Governed Living Knowledge Design

**Status:** owner-approved incremental design, 2026-09-03.

## Ownership

Authority owns canonical identity and lifecycle. Graph owns typed relations.
Knowledge owns atomic claims. Source/Evidence owns provenance and support.
WordPress native `wp_posts` owns Article title, body, author, dates, categories
and editorial URLs. Media, MediaAsset, MediaUsage and Video keep their existing
bounded contexts. Public Projection is a presentation/read model. Governance
owns durable semantic mutation.

No Article, FAQ, Cluster, Projection or KnowledgeCell Authority type is added.
No new Graph predicate is added by this design.

## Living Knowledge behavior

Semantic meaning is never silently rewritten in an existing claim. Editorial
typos that do not change meaning may use the existing correction contract;
otherwise the system conservatively classifies structured input as one of:

- same claim with new Evidence;
- new compatible claim;
- qualifying Evidence/claim;
- contradicting Evidence/claim;
- semantic replacement requiring a lifecycle decision for the old claim;
- retirement of obsolete claim through Governance.

Historical claims remain readable. `supports`, `contradicts` and `qualifies`
remain the only Evidence relations. Scope is bounded at the narrowest supported
level: entity, brand, model, variant, movement or specimen observation. A
specimen observation never promotes automatically to Variant, Model or Brand.
Exact normalized text equality is only a deterministic exact-match signal for
an active claim in the same subject/facet/scope. It is not broad semantic
equivalence. Fuzzy or AI similarity cannot decide canonical identity. Explicit
structured relation context is required for add-Evidence, qualification and
contradiction; ambiguous or unsupported input fails closed.

## Read model pipeline

`Canonical Knowledge → Current Truth Resolver → Knowledge Cluster/Facet Read
Model → AI Natural Synthesis port → SEO Stability Guard → Public Projection`.

Facet/profile metadata is stored in the existing provenance/metadata JSON
boundary, under validated namespaced keys. Unknown values fail closed. A
cluster is an application/read concept and never a persisted semantic entity or
Graph edge.

The resolver is read-only and returns compatible claims, qualifiers,
contradictions, unresolved conflicts, scope, evidence coverage and internal
trace references. It never persists aggregate truth and never lets AI choose a
winner for an unresolved conflict.

## Projection and provenance

Entity pages are living projections rebuilt per affected facet. Fragments are
`overview`, `recognition`, `configuration`, `movement`, `music`, `history`,
`domestic_cultural`, `evidence_media` and `related`. Every fragment carries a
dependency fingerprint made from subject, facet/profile version, fragment key,
claim IDs/revisions/lifecycle/public state, Evidence IDs/revisions/relation/
state, eligible Source revisions, projection contract version and
generator/policy version.
Unchanged fingerprints are reused; unrelated fragments remain unchanged.

AI synthesis accepts a current-truth packet, presentation context, previous
fragment and SEO constraints, and returns candidate copy plus diagnostics. It
cannot introduce a fact absent from the packet. The default implementation is
deterministic and Vietnamese-first. If synthesis is unavailable, the system
retains last-known-good eligible content or deterministic reader-safe content;
it never emits fabricated prose or turns unavailable into empty.

## Article, Video and Media boundaries

Knowledge changes affecting an Article create an enrichment/update suggestion
packet only. They never write an Article body or bypass the Article workflow.
Video `user_hint`, Media annotations and future observations enter the same
read-only planner and can produce governed candidates, but they do not create a
second writer or hard Graph truth. `MediaUsage`/`depicts` alone is not Evidence.

Semantic apply remains `Proposal → Human Approval → Eligibility → Controlled
Apply → canonical repository → audit → read-back`. Same-intent repeats are
idempotent and produce no duplicate claim, Evidence or relation.

## Governed apply boundary

The effective operation vocabulary is read from the current runtime catalog
(`McpToolCatalog::governedOperations()`); this slice does not introduce an
operation registry or an adapter. Knowledge claim and Evidence creation use a
registered `create`/`ingest` operation selected from that vocabulary. If no
corresponding operation is registered, proposal translation returns typed
`REGISTRY_GAP`; unsupported candidate classifications return `UNSUPPORTED`.

Evidence candidates are proposal-eligible only after canonical `claim_id` and
`source_id` resolution. Their structured contract carries relation,
excerpt/observation, optional locator, metadata and claim/source revision
closure. Unresolved source input remains an `ambiguous` review candidate and
cannot be translated into an Evidence proposal.

Create proposals carry no existing-target revision (`expected_revision=null`).
Existing-target lifecycle proposals must carry the repository revision read at
the binding boundary; eligibility rejects a changed revision. Canonical
structured ordering binds content, dependency and idempotency fingerprints.
The factory is translation-only and performs no KnowledgeService or repository
write.

## Acceptance and non-goals

The Odo corpus is acceptance/reference data only. Tests cover Odo 62 white pegs,
Sonodo/Movement 24 scope, 54/57/62 configuration parity, Odo 30 non-cloning,
Odo 39 evidence-only enrichment, stable `/odo/` and `/o-do/` routes, and all
scope/contradiction/idempotency rules. No Odo production/demo data is mutated.

End-to-end `Proposal → Approval → Eligibility → Controlled Apply → read-back`
for living Knowledge enrichment remains an explicit `CODE_GAP`; this slice
does not claim runtime completion.

Durable public identity remains a separately reported
`PUBLIC_IDENTITY_STORAGE_GAP` unless additive storage can be implemented without
bulk migration. No slug migration is part of this feature.
