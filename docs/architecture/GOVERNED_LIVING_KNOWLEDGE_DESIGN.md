# Governed Living Knowledge Design

**Status:** owner-approved incremental design, reconciled to current runtime on 2026-09-07.

## Ownership

Authority owns canonical identity/lifecycle. Graph owns typed semantic
relations. Knowledge owns atomic claims. Source/Evidence owns canonical
provenance and support. WordPress native `wp_posts` owns Article title/body,
author, dates, categories and editorial URLs. Media/MediaAsset/MediaUsage and
Video retain their separate domains. Public Projection is read-only
presentation. Governance owns durable semantic mutation.

Dictionary lexical state is a separate curation boundary. Concept/Label/
Candidate/Mention records never become Authority, Knowledge, Evidence or Graph
truth merely by existing.

No Article, FAQ, Cluster, Projection, Note or KnowledgeCell Authority type is
added by this design. No Graph predicate is added by this design.

## Reconcile before create

Before a new factual claim is proposed, current canonical Authority/Knowledge
must be resolved and the candidate reconciled against existing subject/facet/
scope truth.

Required outcomes are:

- exact existing claim → reuse or add valid Evidence;
- merge/update/replacement candidate → governed lifecycle decision;
- related but distinct claim → keep both with explicit scope/relation where
  contract allows;
- genuinely new claim → create only after subject and dependencies resolve;
- uncertain → defer/research; do not mint a temporary claim.

Exact normalized text is only an exact-match signal inside the same canonical
subject/facet/scope. Fuzzy, lexical or AI similarity cannot decide identity.
Dictionary labels may assist lookup but never establish semantic equivalence.

## No orphan Knowledge

The intended canonical subject/context must be resolved before Knowledge
creation. If the correct subject is a genuinely missing Authority node, create,
apply and read that Authority node back first. If subject resolution or node
creation cannot complete safely, the Knowledge candidate is deferred.

A detached claim created with the intention to “attach a node later” is not a
valid completed ingest. A claim is also not complete merely because its Proposal
is approved; canonical Knowledge plus intended Graph/evidence read-back must
match the operation contract.

## Living Knowledge behavior

Semantic meaning is never silently rewritten. Existing truth is classified
conservatively as same claim/new Evidence, compatible distinct claim,
qualification, contradiction, replacement/lifecycle decision or retirement.
Historical claims remain readable.

`supports`, `contradicts` and `qualifies` remain Evidence relations. Scope stays
at the narrowest evidence-supported level: entity, Brand, Model, Variant,
Movement or Specimen observation. Specimen observation never promotes
automatically to Variant/Model/Brand.

Commercial Product copy, Article prose, Video source/editorial text, Media
caption/alt/OCR and generated synthesis are not canonical claims or Evidence by
themselves.

## Required governed factual lifecycle

For new semantic truth:

`Source/Evidence research → canonical subject resolution → reconcile current
canonical claims → create/update/merge Authority if genuinely needed → Authority
read-back → Knowledge ingest → submit → review → approval binding → eligibility
→ Controlled Apply → Knowledge read-back → governed Graph attachment →
Source/Evidence attachment → Graph/Knowledge/Evidence read-back → idempotency
verification`.

Depending on operation composition, Source may be prepared earlier than the
claim, but canonical Claim/Source/Evidence dependency rules must still hold.
Second-run acceptance requires no duplicate claim, Source, Evidence, Authority
node or active relation.

## Read model pipeline

`Canonical Knowledge → Current Truth Resolver → Knowledge Cluster/Facet Read
Model → synthesis port → SEO Stability Guard → Public Projection`.

Cluster/facet is a read concept, never a persisted semantic entity/Graph edge.
Resolver output can include compatible claims, qualifiers, contradictions,
unresolved conflicts, scope and evidence coverage; it never persists aggregate
truth or lets synthesis choose a winner for an unresolved conflict.

Projection fragments retain dependency fingerprints over the canonical subject,
claim/evidence/source identities and revisions, policy/profile versions and
projection contract. Unchanged fingerprints may reuse last-known-good output.
Synthesis cannot introduce a fact absent from the current-truth packet.

## Article and Note boundary

Knowledge changes can create an Article enrichment/update suggestion only; they
never directly write Article body. WordPress editorial notes, research notes and
workspace annotations are planning context, not canonical semantic truth. A note
fact is promoted only by re-entering canonical subject resolution,
Source/Evidence/Knowledge and Governance.

Article research/preflight is therefore a reconciliation input. New semantic
claims found during editorial research are not “completed” until the semantic
owner lifecycle/read-back has finished.

## Video boundary — generic planner versus guided relation workflow

Generic Video factual extraction remains a planning seam. `USER_HINT` and
bounded observations from an authorized transcript preserve provenance and use
the narrowest confidently supported subject. Equal candidates are ambiguous.
Whole transcript text and generated editorial copy are never canonical Knowledge
or Evidence.

The older statement that Video cannot resolve/create any canonical Source is
**historical for the isolated generic enrichment preview**. It must not be used
to describe the current guided Video relation workflow.

Current guided relation orchestration, after canonical Video + canonical target
resolution, can deterministically resolve/reuse or create and read back:

`private YouTube Source → provenance-scoped Claim → private Evidence`.

It then creates the governed `Video → about → target` proposal using canonical
Evidence references and stable fingerprints/idempotency. Existing dependencies
are reused only when their provenance binding matches; mismatches fail closed.
Normal operators do not manually type Video proposal/Evidence UUIDs.

This path is bounded to relation provenance. It is not permission to auto-write
arbitrary transcript facts or to make PRIVATE Source/Evidence public.

## Media / Image boundary

Media annotations, MediaUsage, `depicts`, OCR, recognition, filename, caption and
alt remain candidate/research inputs at most. They are not Evidence merely by
existing in storage. A future Media→Living Knowledge mutation must use the same
canonical subject, reconcile, Source/Evidence and Governance boundaries.

Dictionary observation can remain non-blocking lexical state after an owning
content write; it never changes semantic truth.

## Governed apply boundary

Use only operations present in the current executable catalog/domain owner.
Proposal translation fails closed with `REGISTRY_GAP`/`UNSUPPORTED` when the
required operation or relation vocabulary does not exist.

Evidence candidates are proposal-eligible only after canonical Claim and Source
resolution and revision closure. Existing-target lifecycle changes use the
current owner revision. Canonical ordering binds content, dependency and
idempotency fingerprints.

Semantic lifecycle is:

`proposal/create-or-ingest → submit → review → approval with binding fingerprint
→ eligibility → Controlled Apply → canonical owner read-back → idempotency
verification`.

The translation/planning layer itself performs no secret parallel repository
write.

## Deferred and retry boundary

Unresolved candidates preserve provenance, proposed subject/type/relation,
evidence, canonical IDs already resolved, registry/runtime blocker, existing
proposal ID and deterministic rerun instruction. Use states such as
`PENDING_RESEARCH`, `EVIDENCE_GAP`, `REGISTRY_GAP`, `RELATION_GAP`,
`LEXICAL_GAP`, `RUNTIME_BLOCKED`, `NEEDS_REVIEW` and final outcomes
`COMPLETED`, `DEFERRED_WITH_REASON`, `BLOCKED_WITH_OWNER_ACTION`.

A rate-limit/runtime interruption does not justify a new proposal. Reuse the
existing proposal/idempotency binding when the intent is unchanged.

## E2E acceptance

Acceptance for a governed semantic candidate requires the complete proposal →
submit/review/binding → approve → eligibility → Controlled Apply → canonical
owner read-back → audit chain, plus replay/idempotency. Tests/runtime evidence
must cover dependency drift and failure atomicity. Exact integration/runtime
proof is reported separately and cannot be inferred from unit coverage.

Reference corpora remain test/reference data only and do not authorize demo or
production mutation.
