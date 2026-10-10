# Facebook Content Audit CLI — Technical Specification

**Status:** Approved for fixture-first implementation

**Date:** 2026-10-10

## Goal

Provide a read-only CLI that inventories exactly one Facebook Page and the
group-post evidence that can be accessed through permitted Meta surfaces. The
first release is fixture-first: it must be useful and fully testable without
Meta credentials, while real collection remains fail-closed until the target
Page ID and access are verified.

## Scope lock

The only permitted target is the canonical URL
`https://www.facebook.com/donghonhakho.vn`.

The runtime may inspect another Facebook object only when it is returned as
identity/access evidence for this target Page or as a group/post record whose
relationship to the target Page is explicitly reported. It must never enumerate
or collect an unrelated Page.

The scope lock stores the verified Page ID only after successful identity
verification. A supplied Page ID is not trusted as verified input. A URL
mismatch, Page ID mismatch, missing verification, or ambiguous identity stops
collection with a typed failure.

## Non-goals and safety boundary

- No Facebook write API, delete, hide, edit, publish, permission change or
  mutation-capable adapter method exists.
- No NHK semantic owner, database table, migration, Governance operation,
  Admin UI, MCP endpoint or business pipeline is changed.
- No credentials, access tokens, app secrets or authorization headers are
  persisted in checkpoints, fixtures, logs or workbooks.
- No live collection is attempted in fixture mode, and Meta mode refuses to
  collect until identity and required access checks pass.
- `DELETE_CANDIDATE` is a report classification only and never invokes a
  delete-capable API.

## Components

The implementation lives under `NHK\\Core\\Application\\FacebookAudit` and
uses a small contract boundary under `NHK\\Core\\Contracts\\FacebookAudit`.

1. **Scope and identity** — validates the exact target URL, carries the
   server-verified Page ID, and produces `IDENTITY_NOT_VERIFIED` or
   `SCOPE_MISMATCH` diagnostics without guessing.
2. **Read adapter** — exposes only identity, access, page-post, and permitted
   group-discovery reads. The fixture adapter implements the same interface as
   the Meta adapter.
3. **Normalizer** — converts external/fixture rows into stable audit rows while
   preserving `NULL`, `UNAVAILABLE`, `INACCESSIBLE`, and numeric zero.
4. **Collection runner** — performs bounded cursor pagination, checkpointed
   incremental sync, retry of transient failures, and monotonic cursor checks.
5. **Classification and duplicate detection** — deterministic, explainable,
   report-only decisions with no legal conclusion from a trademark string.
6. **Workbook writer** — creates an Excel-compatible `.xlsx` using native
   `ZipArchive`; it writes direct post URLs as hyperlinks and neutralizes Excel
   formula-injection prefixes.
7. **CLI** — fixture mode is explicit and safe for tests; Meta mode requires
   environment-provided credentials and verified identity, and redacts secrets.

## Data states and access report

Every requested capability is reported as one of `GRANTED`, `DENIED`,
`UNSUPPORTED`, `INACCESSIBLE`, or `NOT_CHECKED`.

Missing field values are represented as a typed cell state, not silently
coerced:

- `NULL` — the source returned an explicit null or the field is not applicable.
- `UNAVAILABLE` — the source/adapter could not determine the value.
- `INACCESSIBLE` — the object or surface exists in scope but permission blocks
  access.
- `0` — the source authoritatively returned numeric zero.

The access report covers Page metadata, Page posts, engagement metrics, photos
and videos, Reels, Insights, known Facebook Groups, and read-only verification
of delete capability. No write permission is requested.

## Collection contract

The adapter contract is read-only and cursor-based:

```php
interface FacebookAuditReadAdapter
{
    public function verifyIdentity(FacebookAuditScope $scope): IdentityVerification;
    public function inspectAccess(FacebookAuditScope $scope): AccessReport;
    public function pagePosts(FacebookAuditScope $scope, ?string $after, int $limit): ReadPage;
    public function groupPosts(FacebookAuditScope $scope, ?string $after, int $limit): ReadPage;
}
```

`ReadPage` carries rows, the next cursor, source status, and a retryable/error
diagnostic. The runner refuses a repeated cursor, caps attempts, stores the
last successful cursor and collection timestamp, and can resume from a
checkpoint without duplicating rows. Checkpoints are local JSON files chosen
by the caller and contain only scope, verified Page ID, cursors, row keys,
status and timestamps.

Group rows must explicitly distinguish `PAGE_AUTHORED`, `PAGE_SHARED`, and
`THIRD_PARTY_SHARED`. If group identity, post identity, Page author identity,
or the relevant permission cannot be verified, the row is retained as
`INACCESSIBLE`/`INSUFFICIENT_DATA`; it is never treated as “no post”.

## Classification rules

Classification is deterministic and carries reason codes. The priority order is
`INSUFFICIENT_DATA` (when required evidence is missing), `DUPLICATE`,
`TRADEMARK_REVIEW`, `DELETE_CANDIDATE`, `LOW_ENGAGEMENT`, then `KEEP`.

- `LOW_ENGAGEMENT` is only calculated when engagement values are known. It is
  the bottom quartile within the same content-type and age bucket; otherwise
  the row remains `INSUFFICIENT_DATA`.
- `DUPLICATE` requires the same normalized text and media-reference
  fingerprint. Similar wording alone is not a duplicate.
- `TRADEMARK_REVIEW` is a review flag from an explicit configured review lexicon
  or fixture marker. It is not a finding of legal infringement.
- `DELETE_CANDIDATE` requires complete read access, stale age, low engagement,
  no detected commercial/customer-interest signal, and no unresolved duplicate
  or trademark review. It remains a suggestion only.
- `KEEP` is used only when no higher-priority review flag applies and the
  required fields are sufficiently available.

## Workbook contract

The workbook contains these sheets, with a direct URL column in every post
sheet:

1. `Tổng quan`
2. `Danh sách bài Fanpage`
3. `Danh sách bài hội nhóm`
4. `Bài tương tác thấp`
5. `Bài có rủi ro nhãn hiệu`
6. `Bài trùng lặp`
7. `Đề xuất xóa`
8. `Thiếu quyền truy cập`
9. `Chờ phê duyệt`

The overview includes the required machine-readable result keys:
`PAGE_ID_VERIFIED`, `PAGE_ACCESS_STATUS`, `PAGE_POSTS_FOUND`,
`GROUPS_DISCOVERED`, `GROUP_POSTS_VERIFIED`, `INACCESSIBLE_GROUPS`,
`LOW_ENGAGEMENT_COUNT`, `TRADEMARK_REVIEW_COUNT`,
`DELETE_CANDIDATES_COUNT`, `REPORT_LOCATION`, `BLOCKERS`, and `NEXT_ACTION`.

The writer must support Vietnamese Unicode, preserve hyperlinks as hyperlink
relationships (never as formulas), and prefix dangerous text beginning with
`=`, `+`, `-`, `@`, tab, CR, or LF with a single apostrophe before placing it
in a cell. Null/state values must remain visibly distinct from `0`.

## CLI modes

Fixture mode accepts a fixture JSON path, checkpoint path, workbook output path,
and optional retry/page-size settings. Meta mode accepts only environment
variable names for the access token and optional app configuration; it never
accepts a plaintext token as a command-line argument. Meta mode stops before
collection when the exact scope is not verified, the Graph identity response
does not match the target URL, or the required access is missing.

## Verification boundary

Verification must include focused PHPUnit tests for every component, a fixture
end-to-end run, workbook ZIP/XML inspection, full PHPUnit, PHP lint, and
`git diff --check`. No live Meta collection, deployment, migration or push is
part of this change.

