# Editorial Enrichment and Semantic SEO Design

**Date:** 2026-09-24  
**Status:** Design approved in conversation; implementation pending spec review  
**Scope:** Shared Article/Video editorial enrichment and Semantic SEO only

## Goal

Ensure canonical Knowledge is reused as useful reader-facing editorial material
without allowing grounding, provenance or control metadata to become public
prose, while preserving canonical Claim identity, evidence, scope and traceability.

## Non-goals

- No canonical Knowledge schema or database migration.
- No new persistent semantic role, Claim type or Graph predicate.
- No frontend projection work.
- No LLM, network classifier or new external dependency.
- No change to Governance, Capture identity, idempotency or canonical ownership.
- No Video/Odo/YouTube-specific behavior.
- No staging, production or database mutation.

## Architectural invariants

1. Claim eligibility is distinct from editorial usefulness.
2. Grounding and provenance are not automatically reader-facing content.
3. Graph discovery never authorizes truth or public prose.
4. Truth/evidence confidence is distinct from editorial utility.
5. SEO is derived from the final validated reader-facing package.
6. Generated prose never becomes Knowledge or Evidence.
7. Article and Video share editorial intelligence; composition remains surface-specific.

## Current failure boundary

`EditorialKnowledgeSelector` currently promotes the first highest-ranked eligible
candidate to `CORE`. Its utility rewards topic overlap, direct subject, evidence
and lexical novelty but has no provenance-only semantic role. The composer then
realizes selected Claim text almost verbatim.

Separately, the Video owner adapters discard selected shared knowledge by writing
`facts=[]` and `related_knowledge=[]` on both initial and resume paths.

## Design

### 1. Transient semantic editorial role

Every retrieved candidate remains a canonical Claim read model and receives a
transient editorial classification. The minimum role vocabulary is:

```text
GROUNDING
PROVENANCE_ONLY
READER_FACT
SUPPORTING_CONTEXT
SPECIMEN_CONTEXT
CONTROL_ONLY
```

Classification is deterministic and based on available semantic metadata:
original subject, scope, applicability, evidence/provenance purpose, Claim type,
directness and editorial context. It must not match exact phrases, languages,
UUIDs, product names or source-specific wording.

`GROUNDING`, `PROVENANCE_ONLY` and `CONTROL_ONLY` remain trace/diagnostic data.
Only reader-useful roles can become public prose.

### 2. Transient state model

The read model preserves the following decisions without persisting new semantic
state:

```text
DISCOVERED → ELIGIBLE → APPLICABLE → SELECTED → PUBLICLY_COMPOSABLE
```

Each candidate carries the relevant state/decision, reason, Claim ID/revision,
original subject, scope, graph path, evidence and provenance. Applicability is
explicitly evaluated rather than inferred from graph reachability alone.

### 3. Applicability

Direct Claims must match the resolved subject and scope. Neighbor Claims may be
applicable only when the registered path and semantic propagation rules preserve
the Claim's scope. Specimen observations cannot broaden to Variant, Model or
Brand. A neighboring Variant/Model Claim is discovered for consideration but is
not automatically an applicable reader fact.

### 4. Selector

`EditorialKnowledgeSelector` will:

- classify candidates before ranking;
- prevent grounding/provenance/control roles from becoming `CORE`;
- rank reader-useful Claims independently of evidence confidence;
- allow supporting context to enrich a reader journey without dominating it;
- retain excluded candidates and reasons;
- expose `semantic_role`, applicability, editorial utility,
  `publicly_composable` and selection reason.

If no reader-useful Claim is available, the selector returns a sparse pack. The
composer may produce bounded subject/source framing, but the quality result must
remain semantically thin and must not claim unsupported facts.

### 5. Context pack

`EditorialContextPack` remains transient and gains explicit read-model buckets:

```text
grounding
reader_facts
supporting_context
specimen_context
control_provenance
```

All buckets preserve Claim identity, revision, subject, scope, path, evidence,
provenance, decision and diagnostics. Only the reader-facing buckets are
available as factual composition material.

### 6. Reader journey

`ReaderJourneyPlanner` consumes reader facts and supporting context. It creates
sections for useful coverage, not one section for every selected candidate. It
must omit provenance-only/control candidates, avoid canonical-title repetition,
and expose a coverage/gap diagnostic when the available reader knowledge is
insufficient.

### 7. Composer

`SharedEditorialComposer` may use grounding and provenance internally for
traceability and scope checks, but it only realizes `PUBLICLY_COMPOSABLE`
material. It must not print internal identity/provenance language merely because
that material is present in the context pack. It must not fabricate missing facts.

### 8. Video owner mapping

`VideoIntakeService` and `VideoEditorialResumePlanner` map selected reader-safe
Claims from the shared enrichment result into `facts` and
`related_knowledge`. Grounding/provenance/control entries remain traceable in
dependencies/context but are not copied into reader facts. Initial and resume
paths use the same mapping helper and preserve idempotency and Claim dependency
identity.

### 9. SEO lifecycle

SEO planning consumes the final reader-facing editorial context/package. The
planner excludes non-public roles from title-support, meta description, Open
Graph and structured descriptions. When bounded repair changes editorial copy,
the pipeline recomposes and regenerates SEO from the repaired package before
quality revalidation.

No SEO field may be reconstructed from raw provenance or an obsolete pre-repair
SEO plan.

### 10. Quality gate

Structural/internal-language leaks remain hard blockers. Semantic diagnostics
are added for:

- provenance-dominated public material;
- available reader Claims discarded before package output;
- excessive identity/source-title repetition;
- low reader information gain despite high evidence confidence;
- SEO using non-public semantic roles.

Machine-repairable copy defects remain bounded repair candidates. `CONTENT_COMPLETE`
is not valid when usable reader knowledge exists but the final package discards
all of it.

## Repair lifecycle

```text
compose → quality → bounded repair → recompose → regenerate SEO → revalidate
```

Repair is transient editorial work and never mutates canonical Knowledge.

## Surface parity

Article and Video use the same role classification, applicability, selection,
public-composability and quality rules. They may differ in prose length,
section arrangement, schema type and owner persistence.

## Test obligations

The implementation must include red-before-green tests proving:

1. A direct, hop-0, supported provenance Claim cannot become CORE over a useful
   domain Claim.
2. Selected shared Knowledge survives Video initial and resume package mapping.
3. Provenance-only material cannot enter body, SEO description, OG or VideoObject.
4. Repair causes SEO regeneration from final content.
5. Neighbor reachability does not broaden Claim applicability.
6. Article and Video share role semantics.
7. No reader-useful Knowledge produces bounded sparse output without fabrication.
8. The canonical Claim/Knowledge objects remain unchanged.

Synthetic fixtures must include different wording and at least one non-English
provenance-style Claim so tests cannot pass through phrase blacklists.

## Verification and delivery

Run focused tests, relevant Video/Article tests, Unit/Contract/Integration tests
where available, PHP lint/static checks, `git diff --check`, and a production
diff scan for subject-specific strings, UUIDs, source IDs and phrase blacklists.

The implementation will be delivered as one focused local commit with no push,
deploy or server mutation.
