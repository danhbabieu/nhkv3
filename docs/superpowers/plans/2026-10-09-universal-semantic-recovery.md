# Universal Semantic Recovery — Root Cause and Repair Plan

## Root-cause report (pre-implementation)

### Confirmed failure boundary

The failure occurs after the canonical subject is resolved and before the
Governance proposal is created:

`TextInputInterpreter` → `GovernedCaptureContinuationService::plans()` →
`SemanticClaimCandidateGuard::evaluate()` → no Knowledge plan → no Governance
proposal.

The real interpreter emits a `user_statement` candidate with
`EXPLICIT_USER_KNOWLEDGE`, `status=CANDIDATE`, and no structured assertion for
ordinary declarative input such as “Westminster Quarters được dùng cho chuông
đồng hồ.” The guard only admitted text that exactly matched
`structured_interpretation_packet.semantic_assertions`, so the candidate was
converted to `KNOWLEDGE_SEMANTIC_HANDOFF_REQUIRED` before scope resolution,
deduplication, or Governance admission.

This is reproduced locally with the production interpreter and continuation
service. The red regression test is
`test_real_text_interpreter_user_statement_reaches_governed_knowledge_plan`.

### Classification

- Root cause: the semantic admission guard removed the valid raw-user
  admission path while tightening dictionary isolation. Structured
  continuation deltas still require an assertion match; the historical
  unconditional continuation bypass is intentionally not restored because it
  would defeat the existing “no new fact” safety case.
- Contributing diagnostic defect: `CaptureEnrichmentPlanningEnvelope` is
  persisted before semantic write-back, so its Knowledge owner track can remain
  `NOT_APPLICABLE` even when the shared continuation path later produces a
  governed Knowledge write.
- Downstream symptoms: zero Knowledge proposals, `REQUIRED_OWNER_READBACK_UNVERIFIED`,
  terminal blocking, and retry state that advances without creating a new
  semantic candidate.
- Not the root cause: canonical subject resolution, Music scope routing,
  Governance lifecycle, proposal deduplication, or retry revision binding.

### Repair constraints

Admit only candidates that the interpreter has classified as a raw explicit
user statement, while preserving assertion matching for continuation deltas,
the strict dictionary-command boundary, review-required rejection, canonical
registry scope, existing Knowledge reuse, and the existing Proposal →
Governance lifecycle. Refresh the persisted enrichment envelope only after
write-back so owner-track diagnostics describe the current transaction state.

## Implementation sequence

1. Keep the red real-chain regression as the guardrail.
2. Add narrowly scoped semantic admission for raw explicit user statements;
   retain assertion matching for continuation deltas and blocking for
   unclassified prose, dictionary commands without a matching assertion, and
   review-required candidates.
3. Add regression coverage for dictionary isolation, review-required input,
   continuation deltas, duplicate reuse, stale revisions, and all registered
   entity types through the shared planner.
4. Refresh the enrichment planning envelope after semantic write-back and
   cover the ordering with a focused coordinator regression.
5. Run focused, full, lint, diff, and secret checks; update execution state;
   commit only verified local changes. Do not run staging/production retries,
   migrations, deployment, or push.
