# Universal Structured Semantic Intake & Synthesis — Design Spec

Status: DESIGN SPEC FOR REVIEW
Date: 2026-09-30
Baseline: `0ce9fe2a`

## Intent

NHK V3 needs one documented, reusable planning law for interpreting linguistic
input before semantic resolution, enrichment, governed delta planning, or
editorial synthesis. The law applies to written text, spoken-language
transcripts, Articles, Knowledge text, Video metadata/transcripts, Media
context, and human hints.

The law is an application/planning boundary. It does not create a persisted
semantic object, canonical identity, Graph endpoint, second Dictionary owner,
second Knowledge store, Article semantic owner, or write bypass.

## Constraints

- `nhk.capture.ingest` remains the canonical entry point for new submissions.
- Existing Authority, Graph, Knowledge, Source/Evidence, Dictionary, Media,
  Video, Article, Governance and Content Intent ownership remains unchanged.
- No schema, migration, backfill, data mutation, deployment, URL change or
  MCP runtime behavior change is part of this documentation change.
- Production/runtime data remains regression evidence, never implementation
  vocabulary or authorization.
- Current implementation status must not be upgraded by documentation alone.

## Recommended architecture

The canonical conceptual sequence is:

```text
RAW INPUT
  → INTERPRET
  → STRUCTURED INTERPRETATION PACKET
  → RESOLVE
  → REUSE / SEARCH
  → EVALUATE SCOPE, PROVENANCE, EVIDENCE AND APPLICABILITY
  → DELTA PLANNING
  → GOVERNANCE IF MUTATION
  → SYNTHESIS / PUBLIC PROJECTION IF READ PATH
```

The packet is ephemeral and read/planning-only. It may carry source context,
raw-input lineage/reference, locale, Content Intent context, lexical spans,
proper names, identifiers, configurations, technical terms, resolved and
unresolved references, ambiguity, subject/scope/provenance/evidence signals,
claim/relation/attribute candidates, reuse matches, delta candidates and
diagnostics. It does not carry a new canonical semantic identity.

The three interpretation layers remain separate:

1. Language — observed wording, aliases, colloquial forms, technical terms,
   names, shorthand and normalized lookup forms.
2. Semantic structure — subject, attribute, configuration, relation and claim
   candidates resolved through existing domain owners.
3. Trust and scope — source lineage, observation type, provenance, evidence,
   applicability and uncertainty.

Layer A cannot decide Layer C. A detected or resolved span is not automatically
truth, a relation, evidence or approval.

## Normative invariants to consolidate

The contract and constitutional amendment will state these as explicit laws:

- `DETECTED ≠ TRUE`
- `RESOLVED ≠ RELATED`
- `RELATED ≠ FACT`
- `FACT ≠ UNIVERSAL`
- `MENTION ≠ EVIDENCE`
- `GENERATED PROSE ≠ KNOWLEDGE`
- `GENERATED PROSE ≠ EVIDENCE`
- `LEXICAL MATCH ≠ SEMANTIC IDENTITY`
- `GRAPH REACHABILITY ≠ APPLICABILITY`
- `FREQUENCY ≠ AUTHORITY`
- `CONFIDENCE ≠ APPROVAL`
- `UNKNOWN ≠ FALSE`
- `AMBIGUOUS → FAIL CLOSED`
- narrow scope must not silently widen
- reuse before create; search before propose

Existing equivalent laws will be cross-referenced rather than duplicated.

## Shared lexical and structural rules

The central contract will elevate the current Dictionary boundaries to shared
law without adding production vocabulary:

- choose the longest reusable valid span while cutting clause continuation and
  editorial tails;
- require independent eligibility for every structural unit in a numeric
  configuration;
- require code/reference morphology or bounded context for identifier spans;
- preserve complete proper-name continuations;
- suppress weaker interior spans when they exist only inside a stronger span;
- preserve atomic spans when an independent detector reason exists;
- permit numeric designations only with independent context, hint or approved
  lexical reason;
- retain ambiguity rather than selecting an owner.

Production corpus examples remain regression evidence. Synthetic and unseen
fixtures are the evidence for generic behavior.

## Transcript and spoken-language law

Transcript and ASR input are observations with retained source lineage. Missing
subjects, shorthand, repetitions, repairs, incomplete spoken numbers,
community terms and transcription errors are interpreted into candidates, not
silently corrected into identity or truth. ASR confidence is not semantic
confidence. A human correction may strengthen a lexical hint but does not bypass
scope, provenance, evidence or Governance.

## Enrichment order

After interpretation, all domains follow the conceptual reuse-first order:

```text
resolve subject
  → Dictionary lookup
  → canonical search
  → bounded Graph neighborhood
  → Knowledge search
  → exact/reuse analysis
  → Source/Evidence validation
  → determine actual gap
  → classify delta
```

Delta classes are semantic planning outcomes only. The contract may describe
`NO_CHANGE`, `REUSE_EXISTING`, Dictionary/Knowledge/Relation candidates,
evidence addition, `AMBIGUOUS`, `EDITORIAL_ONLY`, `NOISE` and
`UNRESOLVED` without creating storage enums or runtime vocabulary.

## Knowledge, Dictionary and relation boundaries

Knowledge input is atomized into subject, designation, feature/component,
proposition, provenance signal and scope signal. A whole sentence is not a
claim by default. Existing applicable Knowledge is reused before a new claim
candidate is proposed; duplicate wording does not create duplicate truth.

Dictionary may propose labels, aliases or lexical concepts from Article,
Knowledge, Video, Media, transcript or human input, but remains lexical
curation. It cannot determine factual truth, prove identity/relation or become
Evidence. Approval remains human/governed.

Relation discovery requires resolved source and target identities, a registered
predicate, valid direction and scope, and sufficient provenance/evidence. Same
sentence, co-occurrence, lexical proximity and Graph reachability are not
relations or evidence. Mutation remains Proposal → Approval → Eligibility →
Controlled Apply → read-back.

## Synthesis and feedback-contamination law

Self-writing follows:

```text
EDITORIAL INTENT
  → interpret topic
  → resolve canonical subjects
  → Dictionary language expansion
  → bounded Graph discovery
  → retrieve Knowledge
  → validate scope/provenance/evidence
  → select applicable Claims
  → synthesize
  → compliance
  → public projection
```

Writers cannot use Graph reachability alone, dump all related claims, promote
unreviewed candidates, widen specimen observations, or copy raw Knowledge into
a second store. Article composition retains the existing body-free Claim
ID/revision trace where required. Generated Article prose, summaries, SEO copy
and derived lexical observations retain lineage and cannot become independent
Evidence or canonical Knowledge. Derived copies must not inflate frequency or
corroboration and must not feed an automatic self-training loop.

## Documentation changes after approval

The implementation/documentation plan will update only these documentation
boundaries:

1. `docs/constitution/NHK_V3_CONSTITUTION.md` — one amendment establishing
   the universal interpretation law and direct-write/generated-text barriers.
2. `docs/architecture/UNIVERSAL_STRUCTURED_SEMANTIC_INTAKE_CONTRACT.md` — the
   canonical subordinate contract containing the shared packet and rules.
3. `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md` — current-law
   routing and explicit LAW/DOCUMENTED versus runtime status.
4. `docs/constitution/READ_FIRST.md` — only if the new ACTIVE contract must be
   added to the Dictionary/Article/Knowledge cross-reference map.
5. `docs/architecture/ARTICLE_INGEST_CONTRACT.md`,
   `docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md`,
   `docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md` and
   `docs/architecture/GOVERNED_LIVING_KNOWLEDGE_DESIGN.md` — concise
   cross-references and clarification only; no duplicated universal law.
6. `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpDocumentationRegistry.php`
   — only the existing documentation allowlist, if required to expose the new
   contract as ACTIVE. No MCP handler, schema or transport behavior changes.

No implementation code or generated runtime snapshot is changed by this spec.

## Status and verification

This design intentionally distinguishes:

- LAW APPROVED / DOCUMENTED — the constitutional and subordinate-contract
  rules exist and are discoverable;
- RUNTIME IMPLEMENTED — executable shared packet/interpreter coverage exists
  and is tested.

At design time, Dictionary provides an early structured lexical slice and
Capture/Living Knowledge already implement parts of the shared semantic stages.
That evidence does not prove a complete universal packet/interpreter runtime.

Future implementation acceptance must cover lexical spans, subject resolution,
claim atomization, scope, relation validation, reuse/deduplication, trust
boundaries, derived lineage and synthesis safety using synthetic/unseen inputs.

