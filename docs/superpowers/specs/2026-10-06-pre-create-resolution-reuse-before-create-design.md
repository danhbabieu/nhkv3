# NHK V3 — Pre-Create Resolution / Reuse-Before-Create Hardening

**Date:** 2026-10-06
**Status:** Design approved in conversation; implementation not started
**Scope:** Dictionary first, then bounded cross-owner audit and Capture orchestration enforcement

## 1. Intent and outcome

NHK V3 must resolve an incoming semantic observation against existing canonical
state before creating a new semantic object. The invariant is:

> `RESOLVE → REUSE / ENRICH → REVIEW IF AMBIGUOUS → CREATE ONLY IF PROVEN NEW`

This design hardens the Dictionary create paths first, adds a Capture-owned
precondition so orchestration cannot bypass the owner resolver, and audits the
other semantic owners for equivalent gaps. It does not attempt to make all
owners share a global fuzzy deduplication mechanism.

The implementation must preserve these Constitution boundaries:

- WordPress editorial posts remain the sole editorial source of truth.
- Authority owns semantic entities; Knowledge owns claims; Source/Evidence owns
  provenance; Graph owns relations; Governance owns durable semantic mutation.
- Canonical UUID/stable-key, revision, typed relation, provenance, readiness,
  idempotency and fail-closed invariants remain mandatory.
- No legacy article migration, V2/production mutation, canary repair, merge,
  retire, rekey, hard delete or frontend duplicate suppression is included.
- No global `UNIQUE(normalized_form)` constraint is introduced.

## 2. Evidence and root cause

### 2.1 Dictionary create path

`DictionaryMutationService::createEntryWithSense()` currently trims and
normalizes the requested form, creates a fresh `DictionaryConcept` UUID and a
fresh `LexicalEntry` UUID, then delegates to the repository. There is no
owner-specific read-before-create decision before those IDs are allocated.

`WpdbDictionaryEntryRepository::createWithSense()` is transactional and checks
duplicate entry UUIDs. It can reuse a supplied concept UUID, but it does not
resolve an equivalent active form, entry/sense context or semantic owner before
inserting a new entry. This is the primary prevention gap.

The adjacent direct paths are also relevant:

- `DictionaryCurationService::createDraftFromCandidate()` creates a concept and
  labels from a reviewed candidate.
- `DictionaryMutationService::createDraft()` can create a draft concept from
  an input label.
- `DictionaryMutationService::addForm()` and `addSense()` can enrich existing
  entries but need duplicate-aware preconditions so enrichment cannot create a
  second lexical object or map one sense to conflicting entries.

### 2.2 Existing planning and read capabilities

The materialization planner already groups normalized collisions and can emit
`GROUP_SENSES_UNDER_ENTRY` or `REVIEW_REQUIRED`. The materialization service
applies only reviewed one-to-one existing Concept→Entry decisions and reuses
the Concept UUID. Those capabilities are useful evidence but are not yet a
runtime guard for direct creates.

`DictionaryEntrySenseResolver` provides a read path from normalized Form to
Entry and approved Sense, including context filtering, ambiguity detection,
semantic-reference validation and owner-route validation. It must remain a
read/resolution service; it must not silently mutate state.

`DictionaryResolver` supports exact approved-label and owner lookups with
ambiguity handling. It is a planning/read primitive, not a substitute for a
create-time, revision-bound decision.

### 2.3 Historical duplicate evidence

The supplied read-only evidence identifies two existing entries for the same
`Côn hoa thị` observation:

- Entry `01a106b0-2b77-7caa-8f0b-b79cf62ad1a7`, Sense
  `01a10626-1317-77fc-9504-5800350cd07b`.
- Entry `01a10c9f-4890-712a-b9c1-a326c8767f0c`, Sense
  `01a10c9f-488f-7e0c-b2db-85731024f6f1`.
- Both point at component owner
  `01a10c73-50dd-77bf-ac78-c0c4f66b2208`.

The exact historical caller lineage is not verified. The current source
mechanisms capable of producing this shape are verified. The duplicate remains
read-only evidence and is not a repair target in this work.

## 3. Proposed resolution boundary

Introduce a transient, owner-specific Dictionary pre-create resolution service
and result DTO. The concrete names below are proposed implementation names,
not new registry types or semantic contract values:

- `DictionaryPreCreateResolver`
- `DictionaryPreCreateResolution`

The resolver accepts the owner route, lexical observation, optional context,
optional semantic reference/owner, and the caller's expected dependency
revisions. It performs bounded reads through canonical repositories and returns
a decision without mutation.

The result must carry, at minimum:

- normalized input and owner/context scope;
- exact Form, Entry, Sense and semantic-owner candidates;
- ambiguity and inactive/retired-state indicators;
- recommended action;
- existing target UUIDs and revisions when a target exists;
- dependency revisions used for the decision;
- a deterministic resolution fingerprint;
- diagnostics sufficient to explain why a create, reuse, enrichment or review
  decision was reached.

The action vocabulary is deliberately small:

| Action | Meaning |
| --- | --- |
| `REUSE_EXISTING` | Existing canonical Entry/Sense already represents the observation; no new lexical object. |
| `ADD_FORM_TO_ENTRY` | The observation is a distinct approved form for one existing Entry/Sense. |
| `ADD_SENSE_TO_ENTRY` | The Entry is correct but a distinct governed Sense is required. |
| `ENRICH_EXISTING` | Existing canonical state can be enriched through the registered owner workflow. |
| `REVIEW_REQUIRED` | Candidates conflict, are ambiguous, or need an explicit curator decision. |
| `CREATE_NEW` | No applicable canonical candidate exists and the input is proven new within the bounded scope. |

These are decision values for the resolver/application layer. They do not
create new endpoint types, predicates, relation types or runtime registry
entries.

## 4. Equivalence and fail-closed rules

The resolver must be conservative and owner-scoped:

1. An exact approved Form in an applicable context with one compatible Sense
   resolves to `REUSE_EXISTING`; no new Entry or Sense is created.
2. An alternate, colloquial or technical form that uniquely aligns to an
   existing Sense may resolve to `ADD_FORM_TO_ENTRY` or `ENRICH_EXISTING`,
   depending on the registered owner workflow. It must not create a second
   Entry merely because the surface form differs.
3. The same normalized wording with multiple contexts or Senses is not enough
   to merge. A unique explicit context/semantic reference may permit
   `ADD_SENSE_TO_ENTRY`; otherwise the result is `REVIEW_REQUIRED`.
4. A shared semantic owner is supporting evidence, not an automatic merge
   command. Compatible owner/context evidence may support reuse or enrichment;
   divergent meaning remains review-required.
5. Retired or inactive records are duplicate-prevention candidates. They are
   not silently reactivated or replaced by a new record.
6. No applicable candidate plus no ambiguity permits `CREATE_NEW`. Any
   unresolved conflict, unavailable dependency, invalid owner route or stale
   revision fails closed to review/replan rather than creating.

The resolver must not use a generic fuzzy match across owners. Dictionary
equivalence is lexical and context-sensitive; Authority, Knowledge, Source,
Evidence, Graph, Media and Video retain their own identity contracts.

## 5. Create-time enforcement

Every Dictionary create-capable path must consume a current resolution result
and a matching fingerprint before durable mutation:

- direct `createEntryWithSense`;
- reviewed candidate `CREATE_ENTRY_WITH_SENSE`;
- direct/draft `createDraft`;
- curation `createDraftFromCandidate`;
- `addForm`, with a duplicate-aware target check;
- `addSense`, with a mapping check that prevents one Sense from being
  duplicated or attached to conflicting Entries without an explicit governed
  decision.

The application layer should be the primary guard. The repository should add a
final structural recheck inside its existing transaction for safely knowable
active form/mapping races. If the state no longer matches the resolution
fingerprint or dependency revisions, it must abort with a replan/expected
revision conflict. It must not guess, merge, reactivate, or create a fallback
record.

The idempotency receipt must bind the operation to the resolution fingerprint,
target UUIDs, dependency revisions and requested action. A replay with the same
receipt returns the canonical prior result; a changed input, target, revision or
resolution must not be accepted as the same operation.

If a server-signed bounded packet is used by an existing acceptance workflow,
the Dictionary guard validates the packet's exact Capture/request, operation
family, canonical IDs, revisions, dependency closure, capability, expiry and
HMAC. A missing, stale or unapproved packet remains fail-closed. No
object-specific allowlist is added to repository policy.

## 6. Capture/orchestration enforcement

Current Capture orchestration uses a planning envelope and owner dependency DAG;
lexical observations create candidates/mentions and do not themselves authorize
a new Dictionary Entry. The hardening adds a generic owner-create precondition
to the Capture-owned execution boundary:

- any Capture plan that can create or enrich Dictionary state must carry the
  Dictionary resolution fingerprint, exact operation/action, dependency
  revisions and idempotency binding;
- execution revalidates the packet immediately before the owner workflow;
- a Capture packet cannot bypass the owner resolver or convert an ambiguous
  result into `CREATE_NEW`;
- lexical observation/candidate collection remains independent and private.

This keeps Capture as orchestration and Dictionary as semantic owner. It does
not turn Capture into a second Dictionary resolver.

## 7. Read-only duplicate candidate audit

Add a bounded Dictionary audit command/service after prevention is in place. It
must read canonical Form, Entry, Sense, semantic-reference and owner data and
emit candidate clusters with reasons, confidence/evidence, active state,
context comparison and a review status. It must not write, merge, retire,
rekey, relabel, change public identity or hide frontend cards.

The audit must detect the supplied `Côn hoa thị` cluster and make the two Entry
and Sense identities visible in its evidence output. It must also distinguish:

- exact normalized lexical collisions;
- same owner with compatible context;
- same owner with divergent context;
- same wording with multiple senses;
- inactive/retired candidates;
- unresolved or unavailable dependencies.

The audit output is a reconciliation input only. A future repair plan may be
proposed separately, but no repair is authorized by this design.

## 8. Cross-owner audit findings

The audit is diagnostic and patch-only-where-proven:

- **Authority:** the normal planner/resolver path already uses UUID, stable-key,
  exact name, alias and review semantics. The low-level create primitive is
  intentionally narrow and needs no Dictionary-driven patch. Any future
  bypass must be handled in the Authority owner slice.
- **Knowledge Claim:** stable-key idempotency is present, while the Capture and
  retrieval contracts call for resolve/reuse before a new claim proposal. A
  differently keyed but semantically equivalent claim is a separate
  Knowledge-specific gap; do not invent a cross-owner fuzzy resolver here.
- **Source:** stable-key exact reuse/collision handling is present for Source
  identity.
- **Evidence:** deterministic citation paths exist, but the random-ID `cite()`
  path needs a separate provenance-equivalence review. No patch is included in
  this Dictionary slice.
- **Graph:** active triple reuse is protected by the canonical triple key and
  repository transaction/locking; Governance also binds scope, evidence,
  revisions and idempotency. No patch is included.
- **Media / MediaUsage:** media ingest resolves canonical media identity;
  asset storage and usage reconciliation are deterministic and CAS-bound. No
  patch is included.
- **Video:** URL ingest resolves `(platform, externalVideoId)` before create and
  relation workflows carry subject/Graph/Governance rules. No patch is
  included.
- **Article:** the canonical Capture ingest path creates at most one native
  editorial Post per request and uses idempotency/state tokens. Article wording
  is not a safe identity key, so no text dedupe is added.
- **Capture:** owner dependency and idempotency binding are the relevant shared
  gap; the precondition described in section 6 is the bounded patch.

## 9. Test matrix

Tests must prove both the decision and the absence of unintended writes:

| Case | Expected result |
| --- | --- |
| Exact existing Entry/Sense | `REUSE_EXISTING`; no new Entry/Sense/Form. |
| Alternate form with unique Sense alignment | `ADD_FORM_TO_ENTRY` or registered enrichment; no new Entry. |
| Same owner with compatible context | Reuse/enrich under the owner route. |
| Same owner with divergent context | `REVIEW_REQUIRED`; no blind merge or create. |
| Same normalized wording, distinct governed sense | `ADD_SENSE_TO_ENTRY` only with explicit unique context/reference; otherwise review. |
| Multiple candidates | `REVIEW_REQUIRED`; no mutation. |
| Inactive/retired candidate | Review or governed reactivation path; never silent replacement. |
| Genuinely new observation | `CREATE_NEW`; one canonical create. |
| Idempotent replay | Existing receipt/canonical result; no duplicate. |
| Stale revision/fingerprint | Fail closed; no mutation; replan signal. |
| Capture packet bypass attempt | Rejected before owner mutation. |
| Historical `Côn hoa thị` audit | Both supplied Entry/Sense identities appear in one reviewable cluster. |

Representative existing owner tests from the cross-owner audit should be
included as regression coverage, but the Dictionary slice must not change their
identity semantics.

## 10. Delivery slices and acceptance criteria

1. **Dictionary resolution contract and guard.** Add the read model/resolver,
   decision rules, current-revision/fingerprint binding and direct/candidate
   create integration. Add unit and repository race/idempotency tests.
2. **Dictionary enrichment and Capture precondition.** Wire `addForm`,
   `addSense`, curation/draft paths and the Capture-owned packet validation.
   Add fail-closed orchestration tests.
3. **Read-only duplicate audit.** Implement bounded evidence output and prove
   the historical cluster is reported without mutation.
4. **Cross-owner audit regression.** Record owner-specific findings and patch
   only any proven bypass that is directly in scope and covered by its owner
   contract.

Acceptance requires:

- all applicable tests pass without weakening existing tests;
- PHP lint and migration checks are clean where applicable;
- `git diff --check` and secret review are clean;
- no schema migration is introduced unless a later implementation proves it is
  necessary and Constitution-compliant;
- no staging/production or live semantic mutation occurs;
- no frontend duplicate suppression is added;
- execution state is updated at each checkpoint with evidence;
- the V2/V3 parity matrix is consulted before any parity claim.

## 11. Risks and explicit non-goals

- Lexical equivalence is not universal semantic identity. Conservative review is
  preferable to a false merge or a second silent canonical object.
- A repository-only uniqueness constraint would encode the wrong policy because
  the same spelling can legitimately have multiple contexts or senses.
- Race protection must report stale state rather than retrying with a newly
  inferred target.
- The historical duplicate may require a future governed reconciliation
  workflow; this design intentionally leaves it untouched.
- The proposed class/DTO names are implementation scaffolding and must be
  checked against the runtime registry and existing contracts before coding.

## 12. Self-review checklist

- [x] Dictionary is treated as the semantic owner; Capture remains orchestration.
- [x] Resolve/reuse/enrich precedes create on every identified Dictionary path.
- [x] Ambiguity and stale revision fail closed.
- [x] No global normalized-form uniqueness is proposed.
- [x] No frontend duplicate suppression is proposed.
- [x] The historical duplicate is read-only and explicitly identified.
- [x] No migration, canary mutation, merge, retire, rekey or production action is included.
- [x] Cross-owner findings preserve owner-specific identity semantics.
- [x] Test, verification, execution-state and parity gates are explicit.
