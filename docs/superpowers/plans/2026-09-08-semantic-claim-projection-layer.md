# Semantic Claim Projection Layer implementation plan

This plan follows the approved design and uses the existing Knowledge,
Graph, Authority, Public Identity, SEO and frontend boundaries. Each task is
TDD-first: write a failing focused test, verify RED, implement the smallest
slice, run focused/regression tests, then record a checkpoint. No task writes
canonical semantic data.

1. Add immutable projection value objects and controlled vocabulary tests.
2. Add deterministic ClaimClassifier and node profile registry.
3. Add centralized GraphProjectionPolicy with bounded structural rules.
4. Add ClaimScopeResolver using GraphService and KnowledgeRepository only.
5. Add deterministic ClaimRanker and ClaimClusterer.
6. Add LiveLedgerProjectionBuilder with public Source/Evidence summaries,
   counts, context, filters and pagination metadata.
7. Add derived projection repository contracts, in-memory store and additive
   WPDB migration/repository with input-hash idempotency and CAS publication.
8. Add dependency index and invalidation service for claims, edges and
   visibility changes.
9. Add deterministic SeoProjectionBuilder and section-level dirty tracking.
10. Add ProjectionRevisionStore state machine and publication validation gate.
11. Add unified ClaimProjectionService for ledger, published SEO and status.
12. Wire entity detail reads through the service without changing URL/H1 or
   canonical ownership; add shared Vietnamese frontend Ledger rendering,
   escaping, accessibility and bounded pagination.
13. Add capability/nonce-protected projection status and maintenance actions
   through the existing Admin control-plane conventions.
14. Integrate canonical mutation event boundaries; never hook raw SQL writes.
15. Add resumable dry-run `projection rebuild --all` service/command with
   batch size, cursor, progress and failure isolation.
16. Extend existing semantic search only through its current query boundary;
   canonical node URLs remain the only result URLs.
17. Add security, performance, frontend compatibility and Odo/Brand–Model–
   Variant acceptance coverage.
18. Reconcile current documentation and execution status, then run focused,
   full unit, applicable integration, lint, JS syntax, Composer, migration,
   diff and secret reviews. Do not claim runtime acceptance where the target
   WordPress environment is unavailable.

## Checkpoint evidence

Every checkpoint records changed files, focused tests, regression result,
environment blockers, and whether any migration was run. Local commits use
the task's requested semantic message. No push, pull, deployment or
production/staging mutation is part of this plan.
