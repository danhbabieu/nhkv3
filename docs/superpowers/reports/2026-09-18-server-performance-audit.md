# Server Performance Audit — NHK V3 Homepage

**Date:** 2026-09-18  
**Scope:** read-only staging/browser inspection and local code inspection  
**Mutation status:** no semantic mutation, database write, cache purge, deployment, source sync, push, pull or reset

## Executive result

The audit is **not complete**. The available surfaces do not expose PHP
generation timing, WordPress query count/time, application stage timings,
OPcache status, live cache-hit evidence or reliable DNS/TLS/TTFB metrics.
Therefore this report does not claim a measured primary bottleneck.

The strongest code-level lead is the homepage latest-feed assembly. It is a
static hotspot, not yet a runtime finding: the current implementation lists
all Media, Video, Knowledge and Authority records, performs per-item
projection/detail work, sorts the merged collection and only then slices the
first 12 items. The next authorized step should be staging-only instrumentation
and a bounded read-model measurement, not another visual change.

## Evidence identity

| Field | Result |
|---|---|
| Local HEAD | `f67685c8385b9c65802ed2606679c7f6a494aab0` |
| Worktree | clean; `main...origin/main` |
| Last previously verified staging source/build revision | `c236e537f230b849d297e6b881415ad1e442fbe7` |
| Runtime freshness | **STALE/UNVERIFIED** relative to current local HEAD |
| Previously observed web worker | LiteSpeed LSAPI, PHP 8.4.11 (`/opt/alt/php84/usr/bin/lsphp`) |
| Fresh health/identity read-back | blocked by Chrome client policy for REST endpoint; shell DNS cannot resolve host |

Because the deployed revision does not match the current checkout, live timing
must not be used to conclude application performance for the current code.

## Homepage browser snapshot

Read-only Chrome inspection of `https://demo.1945.vn/` succeeded. The browser
session was authenticated as `nhk_admin`, so these are not anonymous-public
baseline numbers.

| Metric | Observed |
|---|---:|
| HTML serialized size | 73,757 bytes |
| DOM elements | 631 |
| Images | 38 |
| Scripts | 9 |
| Linked stylesheets | 7 |
| Latest-feed visible items | 5 |

The page still exposed two 1280×720 `maxresdefault.jpg` YouTube thumbnails in
the latest area during this session. This is visual evidence only and is not a
server bottleneck measurement.

## Required metrics

| Metric family | Result | Reason |
|---|---|---|
| PHP cold/warm render | `UNVERIFIED` | no server-side profiler, Server-Timing or hosting log surface |
| Hero/latest/gallery/video/entity/navigation timing | `UNVERIFIED` | no staging instrumentation surface |
| DB query count/time and slow-query list | `UNVERIFIED` | no safe query collector or server log access |
| Source/read-model counts | `UNVERIFIED` | no runtime counters/read trace exposed |
| OPcache flags, memory, hit rate | `UNVERIFIED` | only worker identity was observable previously |
| Page-cache state/hit/miss | `UNVERIFIED` | no trustworthy live cache headers or cache logs available |
| Object-cache backend/hit rate | `UNVERIFIED` | local drop-ins are absent; that does not prove live state |
| LiteSpeed/server cache | `UNVERIFIED` | LiteSpeed/LSAPI worker was observed, cache behavior was not |
| DNS/connect/TLS/TTFB/total | `UNVERIFIED` | shell DNS failed; Chrome loaded the site but exposed no timing API here |

The shell/browser discrepancy is explicit: `curl` could not resolve
`demo.1945.vn`, while Chrome rendered the homepage. The shell failure is not
classified as a site DNS performance root cause.

## Static application finding

`HomeSemanticQuery::latestFeed()` currently:

1. walks the complete Video list;
2. walks the complete Media list and calls the gallery projection per item;
3. walks the complete Knowledge claim list;
4. walks every registered Authority type and calls `detailForEntity()` per entity;
5. sorts the merged result and slices to 12.

The underlying Media, Video, Knowledge and Authority list repositories use
unbounded `SELECT * ... ORDER BY id` reads for these paths. Request-scope
memoization removes repeated list/card/detail calls within one request, but it
does not bound the initial reads or the merge-before-limit work.

This supports:

```text
STATIC_HOTSPOT=LATEST_FEED_UNBOUNDED_ASSEMBLY
RUNTIME_SEVERITY=UNVERIFIED
```

## Classification and action

```text
ROOT_CAUSE_CLASS=NOT_PROVEN (static hotspot points to C: LATEST_FEED)
ROOT_CAUSE=No measured server-side root cause yet
PRIMARY_BOTTLENECK=UNVERIFIED
SECONDARY_BOTTLENECK=Runtime/build parity and observability gap
APPLICATION_CHANGE_REQUIRED=NO (not justified by current evidence)
INFRA_CHANGE_REQUIRED=NO (recommendations only; no authority to change host)
```

Recommended next measurement package:

- deploy/verify the exact current build identity through the canonical read-only
  deployment gate;
- add temporary staging-only timing and query counters outside public output;
- record cold-ish and warm PHP render, query count/time, and per-module timings;
- record page/object/LiteSpeed cache evidence from response headers or hosting
  logs;
- only if the measurement confirms the hotspot, replace merge-before-limit
  assembly with a bounded latest-feed projection and batch/prefetch path;
- define cache owner, key, TTL/version and invalidation before proposing any
  persistent cache.

`SERVER_PERFORMANCE_AUDIT_COMPLETE=NO`
