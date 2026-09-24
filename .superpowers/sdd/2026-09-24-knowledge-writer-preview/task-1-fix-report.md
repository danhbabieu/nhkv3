# Task 1 fix report — Knowledge Writer Preview

Status: FIXED locally. Implementation commit: `a3106d4004a233c516e9745e58ee58a6857bc5a4` (descendant of `bf082edd`). No deployment, data mutation or push.

## Independent review findings addressed

1. **Critical — reader answer leak:** the preview now rejects the entire answer when it contains control keys, UUIDs, stable keys, HTML control payloads, or known Claim/Source/Evidence IDs, including non-UUID IDs. Suppression clears `used_knowledge` and yields `PUBLIC_COPY_UNSAFE`.
2. **Important — locator conflicts:** every supplied UUID, stable key and name/query is resolved independently; contradictory, missing or ambiguous explicit locators cannot silently fall through to the UUID. Retrieval does not run on a conflict.
3. **Important — natural Vietnamese facet prose:** a registered exact facet can satisfy a facet-only need without repeating its English key in the claim text. Existing subject applicability, scope, evidence and provenance checks still run; concept-specific needs retain text relevance.
4. **Important — used knowledge:** the preview filters the shared composer trace against realized answer prose and deduplicates by factual text. A suppressed answer has an empty trace, and coverage follows the resulting used facts.
5. **Important — depth:** an incompatible depth is blocked before enrichment. A compatible depth is reported as effective only after the existing journey planner confirms it.
6. **Important — mutation safety:** the fixture now snapshots counts, revisions, serialized state and write-call counters for Capture, Post, Media, MediaUsage, Video, Knowledge/Claim, Source, Evidence, Graph, Proposal, Governance, Public Identity and SEO projection. An observation hook checks the same state during retrieval, and the constructor dependency test excludes writer services.
7. **Important — bounds:** locator fields, query/name, observation shape/count/value, semantic needs and reason-code lists are bounded. Raw coverage-aspect packets are not projected. Diagnostics and exclusion reasons remain code-only.
8. **Minor — coverage:** `complete`, `partial` and `sparse` now reflect rendered eligible facts and requested facets.

## Deviations and scope

- Incompatible depth is rejected rather than remapped to another profile; the existing purpose-to-profile policy stays authoritative.
- The shared composer remains unchanged because its duplicate trace is consumed by other owners. Preview-specific rendered-fact filtering preserves those contracts.
- Review finding 9 concerns unrelated CompletionCoordinator changes in the starting history; neither that file nor UniversalOwnerLifecycleAcceptance was edited.

## Test evidence

- Red first: focused PHPUnit reported 6 failures covering the facet, depth, metadata, locator, observation and duplicate-trace cases. A second red run exposed an additional `source:` control-payload leak.
- Green focused matrix: 53 tests / 248 assertions across preview, retrieval, scope and composer tests; preview plus Video adapter: 39 tests / 193 assertions after preserving shared composer behavior.
- Full Unit: 2,539 tests / 14,536 assertions, 18 warnings, 41 deprecations and 30 PHPUnit deprecations (pre-existing non-failing diagnostics). Contract: 6 tests / 48 assertions.
- Four changed PHP files lint clean; `git diff --check` passed. Secret review found no credentials or private keys; the literal `secret=value` is a rejection fixture only.

## Remaining concerns

- This is local read-only service evidence. No staging/runtime exposure or data-bearing acceptance was attempted.
