# Owner Publication Override Design

**Status:** Design-only proposal for owner review

**Date:** 2026-09-03

**Normative basis:** `docs/constitution/NHK_V3_CONSTITUTION.md` is the sole
normative authority. This document does not amend it, create an Article
Authority type, create a Graph Article endpoint, or authorize a data
migration.

## Problem

NHK V3 already has publication checks spanning WordPress editorial state,
semantic reconciliation, MediaUsage, SEO, public routes, rendered
verification and public-claim compliance. The current operation-level gate
can distinguish blockers from warnings, but it does not yet define a durable,
generic decision boundary for the case where a safe editorial exception is
acceptable to the project owner.

Treating every failed rule as an automatic prohibition makes eligible
editorial incompleteness indistinguishable from identity, security and
infrastructure failure. Treating every failure as overridable is unsafe and
would violate the Constitution. The missing boundary is a reusable
publication-governance mechanism that preserves the truth of failed checks
while allowing explicit owner approval for eligible exceptions.

## Goals

This design will:

- Preserve existing website laws and run the normal publication gate first.
- Classify the gate result into exactly three practical outcomes:
  `PASS`, `OWNER_REVIEW_REQUIRED` or `SYSTEM_BLOCKED`.
- Let the project owner explicitly approve eligible exceptions in the
  ChatGPT/MCP flow.
- Bind approval to the exact WordPress Post, current state token, gate result,
  diagnostics and policy revision evaluated at the time of approval.
- Durably record the owner decision, overridden diagnostics and final result.
- Keep WordPress `wp_posts` as the source of truth for editorial publication.
- Keep Authority, Knowledge, Source/Evidence, Graph and Media responsibilities
  unchanged, with semantic mutations still routed through Governance.
- Support current and future Article, Media, SEO, Knowledge/Evidence and
  semantic-publication constraints without a Post-87 or image-specific path.

## Non-goals

This design does not:

- Implement code, schema, migrations, database writes or runtime changes.
- Amend the Constitution or redefine its publication, identity, security,
  provenance or Governance laws.
- Provide a blanket publish bypass or allow an owner to override
  authentication, authorization, identity ambiguity, route collision,
  corrupted state or unreliable execution.
- Fabricate Evidence, turn an unverified claim into verified truth, suppress a
  diagnostic, or rewrite a failed check as `PASS`.
- Move editorial ownership from WordPress or create a second Article body.
- Mutate Authority, Knowledge, Source/Evidence, Graph, Media or MediaUsage
  outside their existing contracts.
- Publish V2, staging or production data, repair legacy content, change public
  identity or perform cutover.

## Authority model

The project owner is the highest editorial publication approval authority for
this project. The owner may accept an eligible editorial or quality exception
after the system presents concise, truthful diagnostics.

The owner is not a security principal by implication. The request must first
be authenticated and authorized by the existing project and operation
boundary. Owner approval cannot authorize an operation that the caller is not
allowed to perform, cannot resolve an ambiguous Post identity, and cannot
make an unsafe system state reliable.

The responsibilities remain separated:

| Responsibility | Owner |
|---|---|
| Editorial title, body, status, dates, category and public URL | Native WordPress `wp_posts` and taxonomy boundaries |
| Canonical semantic entities | Authority |
| Atomic claims | Knowledge |
| Provenance and support | Source/Evidence |
| Typed relations | Graph |
| Semantic proposal, approval, eligibility, apply and audit | Governance |
| Publication decision and eligible editorial exception | Publication decision boundary, with explicit project-owner approval |

The decision boundary coordinates these owners; it does not become a new
semantic owner.

## Publication outcomes

The runtime must expose exactly these three practical outcomes. A decision
result may also contain detailed diagnostics, warnings, audit identifiers and
the native publication result, but no fourth practical outcome may be used as a
substitute for one of these states.

### `PASS`

All required publication rules pass at the evaluated revision and token. If
the owner already requested publication, the system may publish without an
additional confirmation. Warnings that do not prevent publication remain
visible and are not relabeled as passes.

### `OWNER_REVIEW_REQUIRED`

At least one rule failed, and every blocking diagnostic is explicitly marked
eligible for owner override by the applicable policy. The system returns a
concise explanation of the failed rules and asks the project owner whether to
publish with exceptions. An explicit affirmative owner instruction creates a
durable decision and permits controlled native publication, subject to a
fresh authorization, Post identity and state-token check.

The failed rules remain failed in the gate result and audit record. The final
publication result is therefore `published_with_exceptions` or its equivalent
operation result, never `PASS`.

### `SYSTEM_BLOCKED`

At least one failure makes publication identity, authorization, security or
execution unsafe or unreliable. No owner override path is offered. The
response reports the exact blocker code and a safe root-cause summary, while
preserving retryability or escalation information when available.

Examples include authentication or authorization failure, inability to
identify the Post being mutated, a confirmed unresolved public identity or
route collision, CAS conflict, corrupted or inconsistent state, missing
required policy resolution, and infrastructure failure that prevents a
reliable publication result. Semantic mutation may remain blocked even if the
WordPress Article itself appears publishable.

## Blocker classification contract

Every publication diagnostic must be a registered, stable code with a
human-readable explanation, a severity, an applicability/policy revision and
one disposition:

```text
OWNER_REVIEW_REQUIRED | SYSTEM_BLOCKED | WARNING
```

`WARNING` does not produce `OWNER_REVIEW_REQUIRED` by itself. A failed rule
must not be classified by its prose, UI label or caller guess. Unknown,
malformed, unregistered or policy-version-incompatible diagnostics fail closed
as `SYSTEM_BLOCKED`.

The classifier evaluates all diagnostics, not only the first one:

1. Any `SYSTEM_BLOCKED` diagnostic produces `SYSTEM_BLOCKED`.
2. Otherwise, any `OWNER_REVIEW_REQUIRED` diagnostic produces
   `OWNER_REVIEW_REQUIRED`.
3. Otherwise, the result is `PASS`.

The normal gate still runs before classification. Classification changes what
may happen after a truthful failure; it never changes the gate's evidence or
the rule's result.

Typical eligible diagnostics may include missing or low-resolution real
imagery, incomplete MediaUsage, incomplete SEO or structured-data enrichment,
FAQ or internal-link quality gaps, pending semantic reconciliation, unverified
semantic read-back where the applicable policy explicitly permits owner
review, and Knowledge/Evidence completeness warnings. The exact code registry
and policy decide eligibility. Invalid structured state, unresolved identity,
route conflict, CAS conflict, authorization failure and unreliable
infrastructure remain system-blocked. Public-claim compliance must preserve
the evidence-led compliance contract; uncertainty about the applicable legal
policy is system-blocked, while a policy-approved editorial review path may
remain owner-reviewable without fabricating support.

## Owner approval flow

The flow is a two-stage interaction with no implicit consent:

1. The owner requests publication, for example `Đăng bài.`
2. The system authenticates and authorizes the request, resolves one exact
   WordPress Post, reads the current draft and state token, and runs all
   publication gates.
3. For `PASS`, the system proceeds to controlled native publication.
4. For `OWNER_REVIEW_REQUIRED`, the system returns a concise question such as
   `Bài còn 2 điểm chưa đạt: ảnh inline thiếu, SEO chưa hoàn tất. Vẫn đăng
   không?` It includes machine-readable diagnostic codes behind the adapter
   response and does not claim that the rules passed.
5. The owner replies with a clear affirmative instruction such as `Đăng.`,
   `Vẫn đăng.` or `Publish.` A vague acknowledgement, silence, unrelated
   message or approval for another Post is not sufficient.
6. The system re-authenticates the operation, re-reads the Post and verifies
   the approval's Post identity, state token, request binding, policy revision
   and diagnostic set. If any has changed, it returns a new gate result and
   requires a new decision when appropriate.
7. The system records the decision before or atomically with the controlled
   publication attempt according to the implementation's transaction
   boundary. It then invokes the existing native WordPress publication writer.
8. The system reads the Post back, records the final publication result and
   returns the public URL only after the native transition is verified.

`SYSTEM_BLOCKED` stops at step 3 and reports the blocker. It never asks the
owner to override.

## Durable decision and audit model

The implementation should use the existing durable operation-receipt/audit
boundaries where their contracts permit, with a dedicated decision payload
rather than an untracked chat message. It must not store article body content
in a decision record.

At minimum, one immutable decision record contains:

| Field | Requirement |
|---|---|
| `wp_post_id` | Exact native Post identifier |
| `decision` | Prefer `APPROVED_WITH_EXCEPTIONS`; otherwise an explicit non-approval decision |
| `publication_gate_result` | Snapshot of the outcome and all diagnostics/warnings at decision time |
| `overridden_rule_codes` | Exact eligible codes accepted by the owner |
| `non_overridden_blockers` | Exact remaining blockers, normally empty for a successful approval |
| `policy_version` | Constitution/law revision or equivalent registered policy version |
| `evaluated_state_token` | CAS token bound to the Post state that was reviewed |
| `timestamp` | Durable UTC decision time |
| `approval_provenance` | Authenticated project-owner identity, channel, request/turn reference and affirmative instruction |
| `final_publication_result` | Native result, verified read-back state, URL if available, or explicit failure/uncertainty |

The record is append-only. A later retry or changed draft creates a new
decision record or a linked superseding decision; it does not rewrite the
historical fact that a prior rule was overridden under an earlier policy.

## ChatGPT/MCP interaction contract

The typed publication operation remains the only V3 publication writer. MCP
and Admin adapters consume the same application boundary and diagnostic
registry. The response must distinguish:

- `PASS`: publication succeeded or is in progress under the existing native
  operation, with the verified public URL when complete.
- `OWNER_REVIEW_REQUIRED`: a structured result containing the Post identity
  reference, concise blockers, diagnostic codes, policy version, expiration or
  freshness information, and an explicit confirmation question.
- `SYSTEM_BLOCKED`: exact blocker/root cause, retryability or remediation
  information, and no override affordance.

The adapter must not infer owner identity merely from a text string. It must
use the existing authenticated project context and record the clear
affirmative instruction as provenance. Public responses expose reader-safe
information and do not expose credentials, private Evidence, internal tokens
or unsupported semantic details.

## `ArticlePublicationGate` integration boundary

`ArticlePublicationGate` remains a read-oriented coordinator. It consumes the
current draft and verified evidence, checks the expected state token and
returns the truthful rule results. It must not ask the owner, write a Post,
write semantic records or decide that a failed rule passed.

The future integration adds a classification/decision layer around the gate:

```text
current Post + expected token + verified evidence
        → ArticlePublicationGate
        → registered diagnostic classifier
        → PASS / OWNER_REVIEW_REQUIRED / SYSTEM_BLOCKED
        → owner decision boundary when eligible
        → native WordPress publish + read-back
        → durable receipt/audit
```

Existing codes such as `CANONICAL_PUBLIC_IDENTITY_INVALID`,
`EDITORIAL_CAS_REQUIRED`, `SEMANTIC_READBACK_UNVERIFIED`,
`MEDIAUSAGE_INCOMPLETE`, `SEO_PROJECTION_INVALID`,
`STRUCTURED_DATA_INCOMPLETE` and `PUBLIC_ROUTE_NOT_READY` must be mapped by a
reviewed registry/policy contract. No implementation may silently infer that
all current `blockers` are owner-reviewable merely because an owner asked.

## Semantic Governance separation

Owner publication approval does not approve or apply semantic mutations. The
Article sequence remains semantic preflight → WordPress draft → Proposal →
Human Approval → Eligibility → Controlled Apply → repository/audit →
read-back → publication gate.

If semantic Governance approval, eligibility, application or read-back is
missing, the publication classifier applies the registered disposition. An
owner may accept a permitted publication-quality exception only where the
policy says so; the system must not create a claim, Evidence link, Authority
record or Graph edge to make the Article appear complete. A published Post can
therefore have an audited publication exception while its semantic workflow
remains incomplete or blocked.

## Future-law extensibility

Each new publication rule registers its diagnostic code, evidence input,
evaluation owner, policy revision, public explanation and explicit failure
disposition. The rule author must choose `OWNER_REVIEW_REQUIRED` or
`SYSTEM_BLOCKED` for failure; warnings are reserved for non-blocking
incompleteness.

The classifier is data-driven over registered diagnostics, so future Article,
Media, SEO, Knowledge/Evidence, compliance and semantic-publication rules use
the same owner-decision boundary. Historical decisions retain their original
codes and policy version and are never reinterpreted as having overridden a
rule introduced later.

## Concurrency, idempotency and state tokens

Publication approval is valid only for the exact Post identity, operation
intent, evaluated state token, gate evidence fingerprint, diagnostic set and
policy version that were presented to the owner.

The implementation must:

- Require a current draft state token for both the initial gate and the
  approval execution.
- Bind the idempotency fingerprint to Post ID, intent, token, evidence/gate
  fingerprint, decision and policy version.
- Return an idempotency conflict when the same key is reused for a different
  request or diagnostic set.
- Re-read after any uncertain native transition before retrying, preserving
  the existing uncertain-result behavior.
- Treat a changed Post revision, token, route identity, diagnostic set or
  policy revision as stale and require re-evaluation.
- Make repeated delivery of the same approved request return the existing
  durable result rather than publish twice or create contradictory decisions.
- Never use a stale owner approval to publish a later draft revision.

## Failure behavior

Failures must remain distinguishable:

- A safe eligible exception returns `OWNER_REVIEW_REQUIRED` until explicit
  approval; a denial or expiration leaves the Post unpublished with a
  durable non-approval outcome.
- A system-blocked condition returns `SYSTEM_BLOCKED` with its exact code and
  no fake override path.
- A state-token or identity change returns a stale/conflict result and starts
  a fresh evaluation.
- A native transport failure after the transition is uncertain until a
  WordPress read-back proves the final state. The system records
  `PUBLICATION_RESULT_UNCERTAIN` or the registered equivalent and does not
  blindly retry.
- A decision-record persistence failure prevents claiming a successful
  exception publication unless the approved transaction boundary proves both
  the decision and native transition durably.
- Missing, malformed or unavailable required evidence/policy is never treated
  as an empty successful packet.

## Migration and backward compatibility

This is an additive design. It requires no migration of legacy article bodies,
no repair of existing semantic records, no V2/staging/production mutation and
no rewrite of existing public URLs or WordPress identity.

Existing generic WordPress publication remains governed by its existing native
rules. Existing V3 publication callers that receive a legacy boolean gate
result must be adapted through an explicit compatibility mapper; they must
not silently gain override behavior. Until the durable decision boundary is
implemented and verified, a failed publication gate remains blocked under the
current runtime behavior.

Existing receipts remain truthful historical records. New decision records
must identify the policy/version and gate shape used; no backfill is required
or permitted merely to make older receipts look like owner decisions.

## Testing requirements

Before implementation is accepted, focused tests must prove:

- All required checks run before classification, including multiple simultaneous
  diagnostics.
- `PASS` publishes without an extra prompt when the owner requested publish.
- Eligible failures produce `OWNER_REVIEW_REQUIRED` with concise and complete
  machine-readable diagnostics.
- Explicit affirmative owner instructions approve only the exact Post and
  reviewed token; vague, unauthenticated or cross-Post instructions do not.
- System-blocked failures never expose an override path.
- Failed rules remain failed in gate output and audit records after an
  `APPROVED_WITH_EXCEPTIONS` decision.
- Authorization, identity ambiguity, route collision, CAS conflict,
  corruption, policy uncertainty and infrastructure failures are
  `SYSTEM_BLOCKED`.
- Unknown diagnostic codes or dispositions fail closed.
- Decision records contain every required field, omit body content and are
  append-only.
- Idempotent retries, mismatched idempotency keys, stale tokens, changed
  evidence and uncertain WordPress transitions are safe and truthful.
- Semantic proposals, Governance approvals/apply, Graph edges, Authority,
  Knowledge, Evidence, Media and MediaUsage remain untouched by owner
  publication approval.
- Future policy revisions preserve historical decision meaning.
- MCP and Admin adapters return the same outcome and diagnostics for the same
  application-service result.
- No Post-specific or image-specific special case exists.

## Acceptance criteria

The design is accepted when:

1. A reviewer can identify the three and only three practical outcomes and the
   precedence rule among diagnostics.
2. The owner is explicitly identified as the highest editorial publication
   authority without being granted security or semantic powers.
3. Eligible exceptions require an affirmative, authenticated, durable owner
   decision bound to a fresh Post state token.
4. System-blocked failures cannot be overridden, and failed rules are never
   relabeled as passed.
5. The decision/audit minimum fields and final-result behavior are explicit.
6. The ArticlePublicationGate, native WordPress writer and semantic Governance
   boundaries are clear and non-overlapping.
7. Future rules have an explicit registration and classification path.
8. Concurrency, idempotency, stale approvals and uncertain native results are
   addressed.
9. Backward compatibility requires no legacy-data mutation and preserves
   existing generic publication semantics.
10. The current Sonodo scenario is explained as an ordinary example, not a
    special-case implementation.

## Example: current Sonodo article scenario

Assume the current Sonodo Article is WordPress Post 87, resolved by its exact
native Post identity and carrying a current draft state token. The normal
ArticlePublicationGate runs with the already verified research, semantic
read-back, MediaUsage, SEO, structured-data, public-route and rendered
evidence packet.

If the only failures are registered eligible quality constraints—for example,
an absent inline real image and incomplete optional structured-data
enhancement—the classifier returns:

```text
outcome: OWNER_REVIEW_REQUIRED
diagnostics:
  - REAL_IMAGE_INCOMPLETE
  - STRUCTURED_DATA_INCOMPLETE
```

ChatGPT/MCP says, in concise Vietnamese, that two points remain incomplete and
asks `Vẫn đăng không?` The owner replies `Đăng.` The system verifies that Post
87 is still the same draft with the same state token, records
`APPROVED_WITH_EXCEPTIONS` with both codes and the applicable policy version,
publishes through the native WordPress writer, reads the Post back and returns
its verified public URL. The audit continues to show both failed rules.

If the same scenario also has an unresolved route collision, ambiguous Post
identity, CAS conflict, missing authorization or unreliable WordPress
read-back, the combined result is `SYSTEM_BLOCKED`. The owner is shown the
root cause and is not offered a blanket override.

If all rules pass, the result is `PASS` and no second confirmation is needed.
Nothing in this example creates a Sonodo-specific rule, changes semantic
records or makes Post 87 a special publication target.

## Open architecture questions

The following implementation choices remain for a later approved plan; they
do not weaken this design:

- Whether the decision record is a new append-only repository abstraction or
  an extension of the existing Article operation receipt with a versioned
  publication-decision payload.
- The exact authenticated owner/principal representation and how a chat turn
  is bound to that principal in each MCP/Admin adapter.
- The transaction/outbox boundary that makes decision persistence and native
  WordPress transition observable without claiming atomicity WordPress cannot
  provide.
- The concrete registry location and schema for diagnostic disposition,
  policy version and localized concise explanations.
- The expiry interval for a pending owner-review token, once operational
  requirements establish an explicit value.

These questions are deliberately implementation-level. They do not authorize
an override for system-blocked failures or any semantic mutation outside
Governance.
