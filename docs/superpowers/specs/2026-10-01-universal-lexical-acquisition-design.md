# Universal Lexical Acquisition Design

## Status

Approved in conversation on 2026-10-01. This design is subordinate to the
NHK V3 Constitution and the active Universal Structured Semantic Intake and
Dictionary contracts.

## Goal

Complete the shared, read-only lexical interpretation path so Article,
Knowledge, Video, Media and free-text inputs can discover, qualify, preserve
and audit lexical candidates without creating a source-specific parser,
semantic owner, canonical identity or data mutation.

## Non-goals

- No Article-, Knowledge-, Video- or Media-specific lexical pipeline.
- No new Dictionary owner, Authority type, Graph predicate, schema or
  persistence boundary.
- No resolver redesign unless a failing test proves an existing resolver
  boundary is incorrect.
- No Knowledge, Authority, Graph, Evidence, Dictionary, Article or WordPress
  writes; no migration, seed, backfill, staging operation or deployment.
- No full Article or Knowledge corpus scan during development.
- No blacklist expansion as the primary quality mechanism.

## Existing path and target boundary

The implementation preserves the current path:

```text
UniversalInputEnvelope
  → StructuredSemanticInterpreter
  → DictionaryTermDetector
  → DictionaryLexicalQualityGate
  → lexical evidence
  → semantic_query_seeds
  → DictionarySeedPlanner
  → existing resolver
  → read-only audit output
```

The six responsibilities are made explicit within these existing boundaries:

1. Segmentation identifies sentence/clause boundaries, lexical units, proper
   names, identifiers and technical configurations.
2. Candidate discovery finds reusable lexical spans, including unknown valid
   terms and single-word terms when context supports them.
3. Evidence assessment combines span origin, structural context, provenance,
   approved labels and observation type. Frequency is never authority.
4. Overlap arbitration selects spans by evidence and structure, not length
   alone, and removes only weaker interior spans.
5. Resolver eligibility sends only `QUALIFIED` spans to lookup while retaining
   `OBSERVATION_ONLY` spans with bounded provenance and diagnostics.
6. Canonical resolution reuses the existing Dictionary resolver and fails
   closed on ambiguity; unresolved terms remain private review candidates.

No new service is required for each responsibility. Existing classes may be
refined or split only when a focused failing test demonstrates that the current
boundary cannot express the responsibility safely.

## Lexical states and evidence

Every discovered span that reaches the shared packet has an explicit lexical
classification:

- `QUALIFIED`: sufficient lexical/structural evidence; eligible for a
  semantic query seed and resolver lookup.
- `OBSERVATION_ONLY`: plausible lexical observation with insufficient proof;
  retains normalized/raw forms, source identity, locator/context, lineage,
  observation strength and diagnostics, but never consumes resolver budget.
- Noise/editorial/discourse fragment: excluded from Dictionary candidates and
  resolver lookup, with only bounded diagnostics where needed for auditability.

An unknown term is not noise merely because it is absent from approved labels.
Conversely, repeated occurrence in one source does not promote an observation
to `QUALIFIED`.

## Segmentation and overlap policy

The detector remains locale-aware and generic. It must:

- preserve meaningful Vietnamese compounds, multilingual names, identifiers,
  number/unit configurations and approved labels;
- cut predicate, conjunction, pronoun, question, discourse and clause tails;
- treat function words as contextual signals, not absolute rejection rules;
- prevent a long prose span from winning over a shorter span with stronger
  structural or provenance evidence;
- avoid splitting a recognized proper name or identifier into unsupported
  fragments;
- keep adjacent approved or structurally qualified units distinct unless the
  input proves one lexical span.

The implementation must not add Article-18/19/41 exceptions or a growing list
of domain terms. Existing regression examples remain tests, not detection
rules.

## Cross-source aggregation and provenance

Aggregation is keyed by normalized term for reporting, but provenance is never
collapsed into the aggregate row. Each observation retains:

- source kind and stable source identifier;
- source family and field/locator;
- raw and normalized forms;
- occurrence count within that source;
- derived/generated lineage and parent source;
- context, observation strength and diagnostics.

The audit distinguishes occurrence count from independent-source count. A
replayed source or derived copy cannot become independent corroboration merely
because it has a new row or appears more often. Existing pagination and cursor
semantics remain deterministic, privacy-safe and bounded; no seed is lost or
duplicated across batches.

If the current ephemeral packet or audit DTO cannot carry a required distinction
without persistence changes, the implementation must stop at a design-level
report and identify the required contract/schema/governance gate. It must not
invent storage or silently discard provenance.

## Independent evaluation set

Add a source-independent, gold-labeled holdout beside the existing regression
sentinels. It must include:

- technical, historical and specialist descriptions;
- narrative, advertising, dialogue and speech-to-text samples;
- Vietnamese and foreign proper names;
- multilingual compounds, new/unknown terms and overlapping phrases;
- identifiers, URLs, symbols and technical configurations;
- multiple-term and ambiguity cases;
- weak-context observations and derived/repeated copies.

Each case records expected span boundaries, lexical state, resolver eligibility,
source/provenance expectations and any intentionally unresolved ambiguity.
Reports calculate only metrics supported by the labels: candidate precision,
valid-term recall, boundary accuracy, name/identifier preservation,
false-positive lookup rate and valid-term-to-noise rate. Missing or incomplete
gold labels are reported as measurement limits, never estimated.

Article 18, 19 and 41, plus the established escape/overlap/cursor tests, remain
regression sentinels and are not the holdout design source.

## Error and privacy policy

Ambiguous resolution, unsupported scope, invalid source encoding, malformed
resolver fields and source-local planning failures remain fail-closed or
source-local diagnostics according to the existing audit boundary. Diagnostics
are bounded and must not serialize source bodies, private evidence, raw
Article/Knowledge text or credentials.

All lexical audit paths must return `read_only=true` and `mutated=false`.

## Acceptance criteria

- Regression sentinels listed in the request remain unchanged.
- Observation-only terms remain visible for review but never reach resolver
  lookup or seed output.
- Unknown valid terms survive as private candidates.
- Approved labels, aliases, proper names, identifiers and configurations are
  not overrun by generic spans.
- Cross-source aggregation distinguishes within-source occurrences from
  independent sources and preserves lineage.
- Cursor pagination processes every eligible seed without loss or duplication.
- Independent holdout results are reported with honest label limitations.
- Targeted PHPUnit, PHP lint, `git diff --check` and secret review pass, with
  unrelated pre-existing failures explicitly reported.
- `V3_EXECUTION_STATE.md` records the result and confirms
  `read_only=true`, `mutated=false`, no mutation and no deployment.

## Likely implementation surface

The first implementation pass is expected to stay within existing boundaries:

- `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryTermDetector.php`
- `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryLexicalQualityGate.php`
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/StructuredSemanticInterpreter.php`
- `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionarySeedPlanner.php`
- `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionarySeedCorpusAuditCoordinator.php`
- focused Dictionary/Semantic corpus tests and a new independent holdout fixture

This list is a boundary hypothesis, not permission to modify every file. Any
resolver, persistence, contract or schema change requires separate evidence
and an explicit gate before implementation.

