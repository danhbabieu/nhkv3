# Dictionary Entry/Sense Materialization Runbook

Status: active operational guidance, 2026-10-03. This runbook is subordinate
to the Constitution and the Dictionary Entry/Sense contracts.

## Non-negotiable distinction

`SCHEMA MIGRATION != SEMANTIC MATERIALIZATION`.

Migration024 creates only additive Entry, Form and Entry→Concept mapping
tables. It does not populate rows, merge meanings, change Concept UUIDs or
rewrite labels/routes.

## Safe default

The only default materialization shape is:

`1 existing Concept → 1 Entry → 1 existing Concept-as-Sense`.

The existing Concept remains the durable Sense identity. No new Sense UUID is
created for an existing Concept. Definitions, contexts, destination references,
Candidate history and Mention history remain unchanged.

## Read-only workflow

1. Verify documentation bootstrap, runtime/build identity and migration target.
2. Read the Migration015 Concept/Label/Candidate/Mention inventory.
3. Read Migration024 table existence and counts; an unavailable runtime is not
   an empty corpus.
4. Call the materialization profile/plan projection with a bounded Concept ID
   list or limit.
5. Review classifications, warnings, conflicts, grouping candidates and the
   deterministic fingerprint.

The planner may report `UNMAPPED_CONCEPT`, `ALREADY_MAPPED`,
`MAPPING_INCONSISTENT`, `RETIRED`, `DESTINATION_INVALID` and
`PREFERRED_LABEL_DIVERGED`. `GROUP_SENSES_UNDER_ENTRY` is always
`REVIEW_REQUIRED`.

## Apply gate

Apply is internal/admin only and production remains read-only in this task. An
approved plan must contain the exact fingerprint and current Concept revision.
The service applies only independent eligible one-to-one items. It creates the
Entry and preferred Form, maps the existing Concept as Sense, reads back the
canonical rows and records actor, timestamp, IDs, fingerprint and idempotency
receipt.

Retries with the same idempotency key and unchanged fingerprint reuse the
receipt. A changed plan, stale Concept revision, existing mapping, retired
Concept, invalid destination or review-required warning fails closed with no
implicit repair.

## Forbidden behavior

- no `materialize_all_and_merge=true` operation;
- no grouping by equal label, normalized label, slug, destination or similar
  definition;
- no direct SQL/WordPress writer bypassing Dictionary and Governance;
- no Knowledge, Source/Evidence, Graph, Media or Video mutation;
- no production/staging backfill without a separately approved exact packet;
- no redirect, sitemap or public-route mutation as a side effect.

## Evidence vocabulary

Report these facts separately: `SOURCE IMPLEMENTED`, `DEPLOYED/DISCOVERED`,
`INVOCABLE`, `MUTATION NOT EXERCISED IN PRODUCTION`, and
`INTEGRATION_BLOCKED`. Never infer deployed success from local source or
tests alone.
