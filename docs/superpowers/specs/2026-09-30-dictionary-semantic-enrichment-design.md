# Dictionary Semantic Enrichment Design

## Status

Approved in conversation on 2026-09-30. This design is subordinate to the
NHK V3 Constitution and the active semantic-intake, Dictionary, Article,
Capture, Graph, Media and Video contracts.

## Goal

Complete the Dictionary planning slice so natural language can be interpreted
once by the shared `StructuredSemanticInterpreter`, converted into bounded
semantic query seeds, resolved against existing canonical data, and consumed
by a read-only Dictionary Seed v1/enrichment planner without creating semantic
truth or mutating storage.

## Non-goals

- No second interpreter or consumer-specific parser.
- No persistent DTO, semantic owner, taxonomy, endpoint, predicate or schema.
- No Knowledge, Graph, Evidence, Dictionary approval or Authority mutation.
- No legacy article-body migration, corpus backfill, staging or production
  mutation.
- No production Chat MCP, Article, Video or Media ingestion implementation.

## Architecture

All adapters construct the same ephemeral input envelope:

```text
source adapter
  → UniversalInputEnvelope
  → StructuredSemanticInterpreter
  → StructuredInterpretationPacket
  → Dictionary / retrieval / planner consumers
```

The input envelope carries text, locale, source kind and identifier, raw or
derived lineage, content intent, optional canonical target hint, provenance,
observation strength and bounded hints. It does not assert identity or truth.

The packet carries lexical/proper-name/identifier/configuration/technical
spans, normalized forms, resolution state, semantic query seeds, candidate
claims/relations, scope/provenance/evidence/editorial signals, reuse matches
and diagnostics. Query seeds are lookup hints only; they are not Knowledge,
Evidence, Entity or Graph relations.

`DictionaryPlanningService` consumes the packet and applies:

```text
search first → resolve → reuse → candidate only if unresolved
```

Ambiguous identity or owner fails closed. Unknown valid terms remain private
planning candidates. Derived prose retains lineage and cannot corroborate
itself as independent Knowledge or Evidence.

## Generic lexical behavior

The existing Dictionary lexical owner remains the detector boundary, but
domain examples are test corpus only. Detection must use generic lexical and
structural rules already required by the active contract:

- preserve meaningful multi-token phrases, names and unknown valid terms;
- preserve identifiers and independently eligible number/unit configurations;
- trim conjunctions, pronouns, questions and prose/clause tails;
- suppress weaker interior spans when they exist only inside a stronger span;
- retain real ambiguity instead of auto-linking;
- classify editorial/observational language without promoting it to fact.

## Dictionary Seed v1

Add a read-only planner/audit boundary that deduplicates normalized lexical
observations while retaining raw forms, occurrence/source-family lineage and
resolution diagnostics. It reports existing reuse, aliases, proper names,
identifiers, configurations, technical/colloquial/phonetic terms, ambiguity,
new lexical candidates, editorial/noise and suppressed observations using
existing runtime vocabulary where available. Frequency is never authority;
derived copies are not independent observations.

The planner must support bounded pagination/filtering/aggregation and
privacy-safe serialization. It must not approve, attach, create labels or
concepts, write Knowledge or Graph, or convert a candidate into evidence.

## Enrichment seam

Provide a consumer/orchestrator seam outside the core:

```text
natural text
  → query seeds
  → Dictionary resolution
  → canonical owner resolution
  → bounded Graph discovery
  → Knowledge retrieval
  → scope/provenance/evidence/applicability validation
  → read-only enrichment result
```

The seam rejects wrong scope, missing required evidence, and merely reachable
but inapplicable claims. Core interpretation remains independent of retrieval.

## Compatibility and tests

Existing `TextInputInterpreter`, Capture, Article research and shared
enrichment consumers continue to use the shared core. Adapter tests prove that
the same text across human chat, Article, video transcript and media caption
has compatible lexical normalization/query seeds while source metadata differs.

Focused tests cover natural-language filtering, longest/strongest spans,
ambiguity, Dictionary reuse and suppression, enrichment applicability,
derived lineage and zero-write behavior. Full corpus verification is reported
only when the canonical WordPress/MySQL runtime is available.

## Documentation and checkpoint

Update the active universal-intake and Dictionary contracts, add a
non-normative inheritance/enrichment plan only if needed, and record the
implementation/readiness state in `V3_EXECUTION_STATE.md`. No documentation
may compete with the Constitution or owning contracts.
