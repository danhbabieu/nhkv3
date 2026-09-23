# Canonical Hierarchical Subject Resolution Design

**Status:** Approved architectural design; implementation follows the existing NHK V3 Constitution and ACTIVE contracts.

**Scope:** Repair the shared `nhk.capture.ingest` subject-resolution path so a submission containing compatible parent and child hints selects the narrowest existing canonical Authority identity, while preserving fail-closed ambiguity, contradiction, Evidence, Graph and Governance boundaries.

## 1. Problem and outcome

The current shared resolver resolves each hint independently and can let the first explicit match become primary. A Brand match can therefore compete with an exact Variant match from the same canonical hierarchy and produce `PRIMARY_SUBJECT_AMBIGUOUS`, or retain the Brand as primary because the input order is preserved. The existing `contradictions()` helper and compatibility fields are not connected to the primary-selection decision.

The outcome is one generic hierarchical resolver for Capture and all registered content-intake consumers:

- an active, existing Variant wins over compatible Model/Brand context;
- a Model wins when no narrower compatible identity is resolved;
- a Brand remains primary when it is the only canonical identity;
- two canonical identities with no unique compatible narrowest node remain review/ambiguous;
- contradictory canonical context fails closed;
- unresolved lexical fragments never displace an exact canonical identity;
- no Authority, Evidence, Graph relation or Governance mutation is created by resolution.

The concrete failing Video is a regression fixture only. Production behavior contains no fixture name, UUID, URL, brand or model special case.

## 2. Canonical boundaries

### 2.1 `CanonicalAuthoritySubjectResolver`

This class is a read-only Authority lookup adapter. It owns only identity lookup and normalization against registered Authority types:

1. exact canonical UUID;
2. scoped `(entity_type, stable_key)`;
3. exact canonical name;
4. registered alias;
5. bounded registered identity forms such as a Variant reference.

It returns canonical candidate records containing UUID, type, stable key, name, revision, match source and persisted compatibility metadata. It does not decide which candidate is primary, traverse arbitrary Graph paths, create identities or classify ambiguity.

### 2.2 `SubjectResolutionService`

This class is the shared policy/decision boundary. It receives all explicit sources together, requests Authority candidates from the lookup adapter, obtains bounded structural context through an injected read-only structural-context port, and returns one normalized resolution result.

It owns:

- source precedence;
- composite explicit-hint lookup;
- candidate normalization/deduplication;
- hierarchy compatibility;
- specificity selection;
- contradiction classification;
- lexical quality filtering;
- machine-actionable diagnostics and state.

It never writes Authority, Graph, Knowledge, Source, Evidence, Video, Article or Media state.

The structural port may be backed by the existing registered Graph context query and Authority read-back. It must use registered endpoint/predicate semantics (`variant_of`, `model_of` and their canonical context) and must not infer or persist shortcut relations from names or stable-key prefixes.

## 3. Resolution algorithm

### 3.1 Gather before locking

The service gathers all sources before selecting a primary subject:

- canonical UUIDs;
- scoped stable keys;
- explicit subject hints;
- title/topic subjects;
- bounded body mentions;
- structured parent/model/brand context when supplied by the registered adapter.

Source precedence remains `canonical_uuid > stable_key > explicit_subject_hint > title_subject > body_mention`, but precedence controls trust and contradiction checks, not an early return that hides narrower compatible identities.

### 3.2 Identity lookup precedence

For each identity-bearing hint, lookup uses:

`exact canonical UUID → scoped stable key → exact canonical name → registered alias → normalized/composite explicit hint → bounded lexical/body candidate`.

Composite lookup is a read-only search for an already existing canonical identity. It may combine compatible explicit values such as Brand context plus designation, or Model context plus Variant reference. It must return exactly one active canonical identity to resolve automatically. Zero results remain unresolved; multiple structurally compatible results remain ambiguous. The service never creates a new Authority entity from a composite string.

### 3.3 Compatibility classification

Candidates are classified against one another and the canonical structural context:

- `compatible_ancestor`: a Brand/Model that is an active ancestor of the narrower candidate;
- `compatible_descendant`: a narrower candidate supported by a resolved parent context;
- `unrelated`: no canonical structural path or registered compatible context;
- `contradictory`: an explicit identity or structural assertion conflicts with the selected hierarchy;
- `unverified`: the required structural context is unavailable, stale or malformed.

Compatible ancestors are retained as context and never compete horizontally with the narrower candidate. Unverified context cannot be treated as compatibility; the result remains review/unresolved as required by the contract.

### 3.4 Specificity selection

Specificity applies only after identity and compatibility validation. The ordered types are:

`specimen > variant > model > movement/brand`.

This is not a blind type preference. A narrower type may be selected only when its canonical identity is active, registered, structurally compatible, unique at the narrowest level, revision-valid and free of contradiction. A same-type competing exact identity always remains ambiguous, even if another candidate has a higher lexical score.

The result records the selected primary, compatible ancestors/context, rejected candidates and the reason for every rejection.

## 4. Composite and lexical-noise policy

Composite matching is limited to explicit semantic hints and registered canonical fields. It can normalize whitespace, slash variants and Unicode forms already supported by the lookup boundary. It cannot promote arbitrary body text into identity.

Body extraction is a bounded research signal. A body fragment is eligible for primary consideration only when it resolves to an active canonical identity and passes entity-shape/lexical-quality validation. Empty fragments, stopword-only fragments, sentence residue, instructions and unresolved lexical pieces are diagnostics, not competing candidates. Exact canonical identity from explicit input or title remains authoritative over such noise.

The policy is generic: it uses canonical resolvability, source precedence, entity shape, match strength and structural compatibility. It does not maintain a fixture-specific Vietnamese stopword list or a brand/model exception.

## 5. Result state and diagnostics

Internal semantic states are normalized to:

- `SUBJECT_RESOLVED` — one unique narrowest canonical identity;
- `SUBJECT_UNRESOLVED` — no active canonical identity can be selected;
- `SUBJECT_AMBIGUOUS` — multiple canonical identities remain after compatibility and specificity analysis;
- `SUBJECT_CONFLICT` — explicit identities or structural context contradict;
- `SUBJECT_REVIEW_REQUIRED` — a bounded dependency such as structural context or current revision is unavailable and the operation cannot safely proceed.

Compatibility API codes such as `PRIMARY_SUBJECT_AMBIGUOUS` may remain in projections, but they map to the normalized state and never become an independent source of truth.

Every non-resolved result contains machine-actionable diagnostics:

```text
reason
candidate IDs/types/revisions
match source and match class
normalized hints
compatibility/context result
rejected candidates with rejection reason
allowed continuation action
capture/request binding when available
```

`SUBJECT_AMBIGUOUS` exposes the bounded candidate identities that an operator may choose. `SUBJECT_CONFLICT` exposes the conflicting canonical identities and requires correction or explicit governed reconciliation; it does not offer an arbitrary replacement.

## 6. Immutable Subject Resolution Packet

After a resolved decision, Capture creates one immutable `SubjectResolutionPacket` containing:

- canonical UUID;
- entity type;
- scoped stable key;
- canonical name;
- current Authority revision;
- resolution source and match class/confidence;
- compatible ancestor/context identities and their revisions;
- diagnostics and decision fingerprint.

The packet is bound to the Capture/request fingerprint and current subject revision. A current canonical read-back must confirm UUID, type, active state and revision before reuse. Retired, missing, changed or stale subjects reopen resolution and never silently downgrade to a parent.

Downstream consumers receive the packet or its canonical read-only projection. They must not call a shadow resolver to replace the primary subject.

## 7. Downstream handoff

The packet is propagated unchanged through:

`Capture → ContentPreparation → Video/Article/Knowledge adapter → enrichment/research → Source/Claim/Evidence planning → registered relation planning → Governance proposal/read-back → public projection when applicable`.

Video semantic target selection uses the packet primary. A Variant remains the `about` target when canonical context confirms it; Brand and Model are context only. Knowledge enrichment retains the same subject and scope. Article and Media flows may use the packet for contextual enrichment but cannot invent semantic ownership. Public/SEO projection consumes canonical read-back and never creates a route or relation from the packet alone.

## 8. Reconciliation state machine

An ambiguous or review-required Capture remains the same Capture and idempotency identity. Continuation is accepted only when:

1. the request names the existing Capture and exact idempotency key;
2. the selected UUID is in the persisted bounded candidate set;
3. the Authority entity is active, registered, current and revision-valid;
4. the selected identity passes the same compatibility/contradiction gate against all persisted explicit hints;
5. a new packet is persisted with the Capture revision/fingerprint;
6. the normal Capture path resumes using that packet.

An arbitrary UUID, inactive/retired candidate, stale revision, changed payload or cross-Capture candidate fails closed. A successful reconciliation must not produce a `NOT_AMBIGUOUS` pseudo-state; it transitions to `SUBJECT_RESOLVED` and records the prior review receipt plus the selected canonical identity.

## 9. Evidence, Graph and Governance boundaries

Resolution is not semantic evidence and never authorizes a write. The existing sequence remains:

`Source → Claim → Evidence → Video/Article/Knowledge → registered Graph relation → Governance → Controlled Apply → canonical read-back`.

The implementation must preserve:

- current Evidence references and provenance requirements;
- `PredicateRegistry` endpoint/predicate/cardinality rules;
- Graph as the only relation store;
- Proposal/Approval/Eligibility/Controlled Apply ownership;
- optimistic revisions and idempotency;
- duplicate external Video reuse by platform plus external ID;
- A→B relation correction through governed reconciliation;
- final canonical read-back before completion/public projection.

No user hint, subject selection, transcript, generated prose, thumbnail or lexical match becomes Evidence. No relation is created merely because two subjects are structurally compatible.

## 10. Backward compatibility and failure modes

Existing packet wire aliases remain readable, while typed packet fields are canonical. Existing Capture review codes remain available as compatibility projections. Existing exact UUID/stable-key/name/alias behavior remains valid unless a contradiction or stale canonical revision is proven.

Failure modes remain distinguishable:

- missing identity: `SUBJECT_UNRESOLVED`;
- multiple unrelated or same-type exact identities: `SUBJECT_AMBIGUOUS`;
- parent/child from different canonical trees: `SUBJECT_CONFLICT` / `SUBJECT_CONFLICT_REVIEW_REQUIRED`;
- unavailable structural read: `SUBJECT_REVIEW_REQUIRED` or infrastructure blocker;
- inactive/retired/stale canonical entity: fail closed and reopen resolution;
- unavailable Evidence/Governance/Graph runtime: preserve review/blocker and do not write.

No generic WordPress writer, post meta or direct SQL is a semantic fallback.

## 11. Regression matrix

The implementation must cover all cases below with generated or existing canonical fixtures. Fixture identities are test data only.

| Case | Input/condition | Expected |
|---|---|---|
| A | Brand + exact Variant name + designation | `SUBJECT_RESOLVED`; Variant primary; no ambiguity |
| B | Brand + exact Model, no Variant signal | Model primary |
| C | Brand only | Brand primary |
| D | Exact Variant canonical name | Variant primary |
| E | Brand + designation only, one compatible existing Variant | Variant primary |
| F | Designation exists under multiple Brands without context | `SUBJECT_AMBIGUOUS` |
| G | Brand A + exact Variant under Brand B | `SUBJECT_CONFLICT_REVIEW_REQUIRED` |
| H | Exact Variant + correct canonical parent | Variant primary; parent is context |
| I | Exact Variant + unresolved body fragments | Variant primary; noise excluded |
| J | Explicit UUID + compatible hints | UUID subject retained |
| K | Explicit UUID + contradictory hints | conflict review; UUID not replaced |
| L | Canonical identity missing | unresolved/review; no Authority creation |
| M | Same external Video platform/ID | existing Video UUID reused |
| N | Capture resolves Variant | Video adapter, Knowledge and relation planner receive same Variant UUID/type/revision |
| O | Ambiguous Capture + valid bounded operator selection | same Capture resumes with locked packet |
| P | Reconciliation UUID outside candidate set | fail closed |

The failing real-world pattern is included as a generic fixture shape such as `Brand`, `Brand designation`, `designation`; no concrete production special case is allowed.

## 12. Cross-domain acceptance

Tests must prove shared behavior for `VIDEO`, `TEXT_ARTICLE`, `IMAGE_ARTICLE`, `KNOWLEDGE_DELTA`, `MEDIA_ENRICHMENT`, and Authority/Mixed Capture where the registered subject path applies. Non-Video intents must not receive Video blockers or run Video-only semantic callbacks. The resolver change must not alter Article ownership, MediaUsage ownership, Knowledge scope, Evidence requirements, Video external identity or public URL ownership.

## 13. Observability and verification

Internal diagnostics must make the selected identity explainable without exposing private Evidence or internal identifiers in public copy. The checkpoint must record root cause, changed boundaries, tests, remaining gaps and runtime verification. Local tests do not prove deployment; absent a fresh deployed build/read-back, `RUNTIME_VERIFICATION=PENDING_DEPLOYMENT` is required.

