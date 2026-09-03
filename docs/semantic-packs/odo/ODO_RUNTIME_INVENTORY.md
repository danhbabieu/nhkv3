# Odo Runtime Inventory — Read-Only Checkpoint

**Date:** 2026-09-03 — demo MCP/API read-only inventory recheck
**Status:** `BLOCKED_AUTHENTICATED_GRAPH_READ` — demo public/read surface evidence exists; administrator Graph/revision inventory unavailable; no runtime data mutation performed
**Requested continuation point:** `bbf6f12147d8ea015485fb756fd4d46357d10fcb`
**Observed current HEAD:** `1d598c8` (worktree contains unrelated uncommitted MCP/Media changes)
**Pack checkpoint:** `6fd6cc3` (`docs: add Odo semantic reference pack`)

## Scope and evidence boundary

This is the required read-only inventory artifact. The Odo reference pack and
manifest are design inputs, not runtime observations. Runtime inventory could
not be completed because the read-only deployment preflight failed at
`WORDPRESS_BOOTSTRAP_FAILED`; the direct bootstrap probe returned WordPress's
database-connection error. Therefore all rows below sourced from the pack are
marked `DESIGN_INPUT`, not observed facts.

Evidence vocabulary used here is deliberately closed:

- `RUNTIME_UNAVAILABLE`: the application read boundary could not be reached.
- `WORDPRESS_BOOTSTRAP_FAILED`: WordPress could not establish its configured
  database connection.
- `RUNTIME_OBSERVED`: read from the live application read boundary after a
  successful WordPress bootstrap; this is the only class that can establish
  Odo runtime counts/references.
- `DESIGN_INPUT`: copied from the approved Odo pack/manifest only; never runtime
  evidence.
- `COLLISION`: a runtime-observed canonical target collision, or a design-input
  collision explicitly labelled as such.
- `NON-COLLIDING`: a runtime-observed target with no active collision found.
- `MERGE_CANDIDATE`: a possible identity merge that is not confirmed.
- `CONFIRMED_MERGE`: an owner-confirmed same-identity merge decision; it is
  still not permission to apply a mutation.
- `RESEARCH_REQUIRED`: evidence is insufficient or the contract/registry is
  unresolved; no inference is allowed.
- `CODE_OBSERVED`: read from the current worktree's runtime code/contracts;
  this is not a runtime data fact.
- `RUNTIME_UNVERIFIED`: a runtime record, count, revision, reference or
  read-back that still requires a restored WordPress/database runtime.

No direct SQL, WordPress write, semantic write, Graph mutation, migration,
seed, repair, merge, rekey, retirement, Media/Video creation or Post mutation
was performed.

## Demo runtime read evidence

`TARGET_RUNTIME=demo.1945.vn`.

The deployed read path is `POST /wp-json/nhk/v1/mcp` using MCP Streamable HTTP
protocol `2026-07-28`; `initialize` returned server `nhk-v3` version `3.0.0`.
No credential or token was printed or committed. The deployed catalog contains
21 tools and is older than the current reviewed worktree, whose catalog and
Governance executor advertise generic `rekey` and `merge`. The deployed
instance has no authenticated Graph read path available to this run. `GET /wp-json/nhk/v1/health` returned database,
migration, graph, authority, governance, media, video, knowledge, article,
runtime, hydration, application and REST checks all healthy.

Read-only `nhk.search` for `odo` returned `post_total=8`, semantic totals
`entities=76`, `media=0`, `videos=0`, `knowledge=18`. It observed the Odo Brand,
Odo 24/30/35/36/39/20 models, Odo variants, Odo movements, Odo components,
Music and Knowledge records, including the confirmed pinned-dial pair:
`32f43d4b-d6c8-4223-a89b-cc47f30cda77` →
`48311ccd-9d45-4985-a620-ca579499f02c`, and the applied-glued candidate
`01bead27-1308-48c1-af99-c68318e2b577` →
`e326a326-ae8c-447f-a2a4-a83a3cf168d4`. Odo 35 model and movement plus the
`odo.36.35` variant were observed and remain untouched.

The public `entity/model` and `entity/variant` collection endpoints returned
`total=0` while `nhk.search` returned their records. This is a deployed
projection inconsistency, not evidence that those records do not exist.
`entity` collection responses omit UUID, stable key, revision and lifecycle;
`nhk.entity.get` by known UUID returns UUID/stable key/name/payload but not
revision or lifecycle. The deployed Graph REST read returned `401` for both
incoming and outgoing requests. Consequently inbound/outbound relations,
active triple uniqueness, full collision resolution and reference closure
remain `RUNTIME_UNVERIFIED` pending a demo WordPress administrator credential
with `manage_options` or an equivalent authenticated project-standard API
session. This is the exact stop code
`DEMO_ADMIN_SEMANTIC_CREDENTIAL_REQUIRED`.

Native WP read-only metadata for Posts 38, 39, 40 and 55 was reachable. IDs,
titles, slugs, status, permalink, dates and excerpts were observed; Post 55
was `publish`, ID `55`, slug
`dong-ho-24-may-tron-ten-goi-54-thi-truong-viet-nam`, and its permalink was
stable at the time of read. Body preservation was not asserted because this
inventory does not parse legacy article bodies.

## Fresh local recovery recheck

The exact Homebrew service was resolved before considering a restart:
`mysql (homebrew.mxcl.mysql)`, loaded from
`~/Library/LaunchAgents/homebrew.mxcl.mysql.plist`, with
`/opt/homebrew/opt/mysql/bin/mysqld_safe --datadir=/opt/homebrew/var/mysql`.
Homebrew reports `Running: true`; `lsof` observed `mysqld` PID `95616` listening
on `127.0.0.1:3306`; `/tmp/mysql.sock` exists; and the MySQL error log records
`ready for connections` with the same port and socket. The configured database
directory `/opt/homebrew/var/mysql/nhk_v3` exists with NHK V3 tablespace files.

No restart was performed because the exact service is already running and the
server/listener/log show no service-down condition. A restart would not be a
safe explanation for the observed failure: the agent sandbox rejects local TCP
and Unix-socket connects with `Operation not permitted`. `mysqladmin` therefore
could not complete a handshake, and Computer Use was also denied access to the
Terminal app. This leaves authentication and server-catalog database existence
`RUNTIME_UNVERIFIED`, not `PASS`; the WordPress/application read boundary
remains unavailable.

## Runtime preflight

| Check | Result | Evidence |
|---|---|---|
| Git HEAD | PASS | Current observed HEAD `e356d53...`; requested `bbf6f12...` is an ancestor |
| Homebrew MySQL service | PASS | Exact service `mysql (homebrew.mxcl.mysql)`; `Running: true`, loaded |
| `mysqld` process | PASS | `lsof` observed PID `95616` on the active listener; `brew services info` wrapper PID `95516` |
| TCP `127.0.0.1:3306` listener | PASS | OS-level `lsof` shows `TCP 127.0.0.1:3306 (LISTEN)` |
| TCP client handshake | UNVERIFIED | Sandbox returns `Operation not permitted`; no authentication result claimed |
| `/tmp/mysql.sock` | PASS | Socket exists with mode `srwxrwxrwx` |
| Socket client handshake | UNVERIFIED | `mysqladmin` cannot open the local socket from the sandbox |
| Configured `nhk_v3` database | UNVERIFIED | `/opt/homebrew/var/mysql/nhk_v3` exists on disk; server catalog query is blocked |
| Authentication | UNVERIFIED | No server handshake was permitted; credentials were not changed |
| Composer lock/autoload/runtime classes | PASS | `php tools/deployment-preflight.php` |
| WordPress bootstrap | FAIL | `WORDPRESS_BOOTSTRAP_FAILED`; direct probe rendered `Error establishing a database connection` |
| NHK Core bootstrap | FAIL | dependent on WordPress bootstrap; `RUNTIME_UNAVAILABLE` |
| Schema migration | FAIL | dependent on WordPress bootstrap; `RUNTIME_UNAVAILABLE` |
| Authority hydration | FAIL | dependent on WordPress bootstrap; `RUNTIME_UNAVAILABLE` |
| REST bootstrap | FAIL | dependent on WordPress bootstrap; `RUNTIME_UNAVAILABLE` |

## `WORDPRESS_BOOTSTRAP_FAILED` root-cause evidence

Investigation was read-only. No SQL statement, write request, migration,
service restart or data repair was executed.

| Layer | Evidence | Finding |
|---|---|---|
| MySQL service | `brew services info mysql`: `Running: true`, `Loaded: true`, service `mysql (homebrew.mxcl.mysql)` | Exact service is healthy at launchd/service layer; no restart justified |
| MySQL process/listener | `lsof`: `mysqld` PID `95616`, TCP `127.0.0.1:3306 (LISTEN)` | Process and listener are observed at the OS layer |
| MySQL server log | `/opt/homebrew/var/mysql/iMac-cua-Imac.local.err`: `ready for connections`, socket `/tmp/mysql.sock`, port `3306` | Server startup completed according to its log |
| WordPress config resolution | `public/wp-load.php` found the parent `wp-config.php`; `wp-config.php:2-5` defines the values below | Root config is the active config path for this install |
| Database configured | `wp-config.php` resolves `DB_NAME = nhk_v3` when `NHK_WP_TEST_DB` is unset; `/opt/homebrew/var/mysql/nhk_v3` exists | Development target is present on disk; server-catalog existence remains unverified because client handshake is blocked |
| TCP/socket config | `DB_HOST = 127.0.0.1`; MySQL log and socket stat show `/tmp/mysql.sock` | WordPress is configured for TCP; socket exists as a secondary diagnostic path |
| Credentials/config resolution | `DB_USER = root`, `DB_PASSWORD = EMPTY`; no credential file or secret lookup is used by `wp-config.php` | Resolution is deterministic; authentication is not proven because the client did not reach a handshake |
| Bootstrap result | `/opt/homebrew/bin/php -d display_errors=1 -r 'require .../public/wp-load.php'` rendered `Database Error` / `Error establishing a database connection` | WordPress bootstrap fails at `wpdb` connection initialization |
| Probe environment | In-sandbox TCP probe returned `Operation not permitted`; `mysqladmin` returned client error `(1)` for TCP and socket; unrestricted escalation was rejected; Terminal Computer Use was denied | Current agent runner cannot complete a valid DB handshake; this is an environment/connectivity failure, not evidence of a MySQL service-down condition |

Conclusion: the confirmed failure is at the WordPress-to-MySQL connection
boundary as seen by this execution environment. The daemon/listener/log indicate
that MySQL itself has started and the configured data directory exists, while
the current execution environment cannot establish the client connection. A
credentials rejection, missing server-catalog `nhk_v3` database, schema failure
or NHK Core application failure is **not** proven by the available probes. Treat
this as `RUNTIME_UNAVAILABLE` until a local probe with permission to complete
the handshake and WordPress bootstrap succeeds.

Runtime status is fail-closed. Counts, revisions, inbound/outbound edges,
Source/Evidence references, Media/Video references and Post references remain
`RUNTIME_UNVERIFIED` until the existing local WordPress/database runtime is
restored.

## Demo target preflight — 2026-09-03

`TARGET_RUNTIME=demo.1945.vn`. The public HTTPS homepage was reachable in the
authenticated browser session and rendered the NHK editorial surface, including
the public Odo brand link and Odo-related posts. This is only public UI evidence;
it does not expose the required Authority/Graph/Knowledge/Media/Video/Post
inventory or revisions. A focused read-only navigation to
`https://demo.1945.vn/wp-json/` was blocked by the browser client before an API
response could be inspected. No login, form submission, MCP call, REST write,
or semantic mutation was performed. Therefore the demo inventory remains
`RUNTIME_UNAVAILABLE`/`RUNTIME_UNVERIFIED`, and no apply is authorized by this
checkpoint.

## Static canonical identity map

The following is the complete explicit identity map in the approved pack. It
is not a claim that these records currently exist or have these revisions in
the unavailable runtime.

| Type | UUID | Old key | Canonical key | Decision | Evidence status |
|---|---|---|---|---|---|
| brand | `d2af7739-3d1b-4666-ad0a-aeda0758f4d8` | `nhk:brand:o-do` | `nhk:brand:odo` | REKEY | DESIGN_INPUT |
| model | `984658bf-19a6-4daa-a220-2a6c13af81ed` | `nhk:model:o-do.24` | `nhk:model:odo.24` | REKEY | DESIGN_INPUT |
| model | `fdf5bfd5-d3f4-4281-a39e-77c9271bcf4a` | `nhk:model:o-do.30` | `nhk:model:odo.30` | REKEY | DESIGN_INPUT |
| model | `c01c109c-5d39-401e-a16e-6d61a0a52f50` | `nhk:model:o-do.36` | `nhk:model:odo.36` | REKEY | DESIGN_INPUT |
| model | `d39bfeae-40c4-47c2-a050-94ca56c8c93b` | `nhk:model:o-do.39` | `nhk:model:odo.39` | REKEY | DESIGN_INPUT |
| model | `dd76ee46-2f76-4c65-a70b-73aae8a7e698` | `nhk:model:o-do.20` | `nhk:model:odo.20` | REVIEW/KEEP | DESIGN_INPUT |
| model | `fc86a551-06eb-48da-a765-5578e70bf4c9` | `nhk:model:o-do.35` | — | RETIREMENT_REVIEW | DESIGN_INPUT |
| movement | `f6342492-729e-4d01-aa67-8fa19c60c619` | `nhk:movement:o-do.24` | `nhk:movement:odo.24` | REKEY | DESIGN_INPUT |
| movement | `200ac862-e7c3-4434-aa01-10edc47d31b7` | `nhk:movement:o-do.30` | `nhk:movement:odo.30` | REKEY | DESIGN_INPUT |
| movement | `08fea152-2faf-47f6-a8af-d58c0324e04a` | `nhk:movement:o-do.36` | `nhk:movement:odo.36` | REKEY | DESIGN_INPUT |
| movement | `1f66321f-9940-4359-a47b-7d68734da41e` | `nhk:movement:o-do.39` | `nhk:movement:odo.39` | REKEY | DESIGN_INPUT |
| movement | `d11c546f-5c9d-4399-a04b-ddb2a121bcd7` | `nhk:movement:o-do.20` | `nhk:movement:odo.20` | REVIEW/KEEP | DESIGN_INPUT |
| movement | `63eb0f6d-4b38-4a34-aa27-d02f5dbe76f5` | `nhk:movement:o-do.35` | — | RETIREMENT_REVIEW | DESIGN_INPUT |
| variant | `72c1ed8a-3626-465e-ae1d-af12c0fae68f` | `nhk:variant:o-do.24.54` | `nhk:variant:odo.24.54` | REKEY | DESIGN_INPUT |
| variant | `7301f50c-ef0d-4e95-a581-39e5063d4648` | `nhk:variant:o-do.24.57` | `nhk:variant:odo.24.57` | REKEY | DESIGN_INPUT |
| variant | `8d6da0b0-28e7-49d6-a73c-b1f30e13879d` | `nhk:variant:o-do.24.62` | `nhk:variant:odo.24.62` | REKEY | DESIGN_INPUT |
| variant | `11f8c058-e3bd-416b-adb0-f9bbb2854ad8` | `nhk:variant:o-do.24.58` | `nhk:variant:odo.24.58` | REKEY | DESIGN_INPUT |
| variant | `e2d0ab8b-761e-4c8a-a3db-978ce508670a` | `nhk:variant:o-do.24.20` | `nhk:variant:odo.24.20` | REVIEW | DESIGN_INPUT |
| variant | `9d21258b-e99f-44d2-a0a0-dec050b45338` | `nhk:variant:o-do.24.54.8-8-westminster` | `nhk:variant:odo.24.54.8-8-westminster` | REKEY | DESIGN_INPUT |
| variant | `3290b880-a449-4056-a890-13701d7bc5e0` | `nhk:variant:o-do.24.54.6-10-two-tune` | `nhk:variant:odo.24.54.6-10-two-tune` | REKEY | DESIGN_INPUT |
| variant | `e1452027-e2a4-4222-aeb5-fb45e6916b3c` | `nhk:variant:o-do.24.54.10-10-ave` | `nhk:variant:odo.24.54.10-10-ave` | REKEY | DESIGN_INPUT |
| variant | `f1f08304-19b2-45e5-aa9d-3a9ec460b366` | `nhk:variant:o-do.24.54.10-11-two-tune` | `nhk:variant:odo.24.54.10-11-two-tune` | REKEY | DESIGN_INPUT |
| variant | `5812357b-7a66-4f3d-aec5-d50d65d7f8f6` | `nhk:variant:o-do.24.54.10-10-two-tune` | `nhk:variant:odo.24.54.10-10-two-tune` | REKEY | DESIGN_INPUT |
| variant | `c2435242-6836-4feb-ac88-93799e27390c` | `nhk:variant:o-do.30.8` | `nhk:variant:odo.30.8` | REKEY | DESIGN_INPUT |
| variant | `fb58a9cf-f8ac-45aa-aced-98b1b403c43d` | `nhk:variant:o-do.30.10` | `nhk:variant:odo.30.10` | REKEY | DESIGN_INPUT |
| variant | `852da54d-457a-4397-a16d-52d9452ba766` | `nhk:variant:o-do.36.8` | `nhk:variant:odo.36.8` | REKEY | DESIGN_INPUT |
| variant | `dc874471-5554-48c0-a4da-8f9b81e2e283` | `nhk:variant:o-do.36.8.westminster` | `nhk:variant:odo.36.8.westminster` | REKEY | DESIGN_INPUT |
| variant | `79be7459-3fad-4ae3-acfe-6833cbd076c8` | `nhk:variant:o-do.36.8.two-tune` | `nhk:variant:odo.36.8.two-tune` | REKEY | DESIGN_INPUT |
| variant | `95873bfe-d978-4eda-a5a2-ce9ba79625df` | `nhk:variant:o-do.36.10` | `nhk:variant:odo.36.10` | REKEY | DESIGN_INPUT |
| variant | `1108f512-1250-472e-a0bf-8edf6a93dd94` | `nhk:variant:o-do.36.10.ave-maria` | `nhk:variant:odo.36.10.ave-maria` | REKEY | DESIGN_INPUT |
| variant | `5f6c98ca-869a-4418-a8a4-1a32eb931c5e` | `nhk:variant:o-do.36.10.two-tune` | `nhk:variant:odo.36.10.two-tune` | REKEY | DESIGN_INPUT |
| variant | `28b4a74e-5d9b-4de5-acf0-8d5f6df4ae6e` | `nhk:variant:o-do.36.39` | — | REVIEW | DESIGN_INPUT |
| variant | `f60febc6-0e81-460e-a7f2-1addbedcace4` | `nhk:variant:o-do.36.35` | — | RETIREMENT_REVIEW | DESIGN_INPUT |

## Confirmed and candidate duplicate records

| Source UUID/key | Target UUID/key | Decision | Runtime result |
|---|---|---|---|
| `32f43d4b-d6c8-4223-a89b-cc47f30cda77` / `nhk:component:o-do.dial.applied-pinned` | `48311ccd-9d45-4985-a620-ca579499f02c` / `nhk:component:odo.dial.applied-pinned` | `CONFIRMED_MERGE` (`DESIGN_INPUT`); owner-confirmed same identity; merge required | `RUNTIME_UNVERIFIED`; no merge operation exists in current runtime |
| `01bead27-1308-48c1-af99-c68318e2b577` / `nhk:component:o-do.dial.applied-glued` | `e326a326-ae8c-447f-a2a4-a83a3cf168d4` / `nhk:component:odo.dial.applied-glued` | `MERGE_CANDIDATE` (`DESIGN_INPUT`); do not merge | `RUNTIME_UNVERIFIED`; intentionally untouched |

## Read-only inventory gate result

Because WordPress bootstrap did not pass, none of the following runtime fields
may be populated from the manifest, filesystem names or static code. Each item
is explicitly retained as `RUNTIME_UNVERIFIED`; rows that require an identity or
contract decision also remain `RESEARCH_REQUIRED`.

| Required inventory | Current evidence status | Result |
|---|---|---|
| All active Odo entities: UUID, stable key, revision, state | `RUNTIME_UNAVAILABLE` | `RUNTIME_UNVERIFIED` |
| Every key containing `o-do` and every key containing `odo` | `RUNTIME_UNAVAILABLE` | `RUNTIME_UNVERIFIED` |
| Target collision matrix (`o-do` → `odo`) | Pack map only | `DESIGN_INPUT`; runtime `COLLISION`/`NON-COLLIDING` unresolved |
| Inbound and outbound relations | `RUNTIME_UNAVAILABLE` | `RUNTIME_UNVERIFIED` |
| Source references and Evidence references | `RUNTIME_UNAVAILABLE` | `RUNTIME_UNVERIFIED` |
| Knowledge references | `RUNTIME_UNAVAILABLE` | `RUNTIME_UNVERIFIED` |
| Media and MediaUsage references | `RUNTIME_UNAVAILABLE` | `RUNTIME_UNVERIFIED` |
| Video references | `RUNTIME_UNAVAILABLE` | `RUNTIME_UNVERIFIED` |
| WordPress Post references | `RUNTIME_UNAVAILABLE` | `RUNTIME_UNVERIFIED` |
| Odo 35 references | `RUNTIME_UNAVAILABLE` | `RUNTIME_UNVERIFIED` / `RESEARCH_REQUIRED` |
| Pinned-dial duplicate references | Owner statement in pack only | `CONFIRMED_MERGE` (`DESIGN_INPUT`); runtime refs unverified |
| Glued-dial candidate references | Candidate statement in pack only | `MERGE_CANDIDATE` (`DESIGN_INPUT`); `RESEARCH_REQUIRED` |

No complete runtime collision matrix, entity count or reference inventory is
claimed in this checkpoint. The static map and duplicate rows below remain
design evidence only.

## Reference inventory required before mutation

The following must be collected from the Graph/domain read boundaries after
runtime restoration. No inference from names or pack prose is allowed.

| Scope | Required read | Current result |
|---|---|---|
| All Odo Authority records | active/retired UUID, type, stable key, name, revision, state, payload | `RUNTIME_UNAVAILABLE` / `RUNTIME_UNVERIFIED` |
| All Odo UUIDs | active inbound and outbound Graph edges with predicate/revision | `RUNTIME_UNAVAILABLE` / `RUNTIME_UNVERIFIED` |
| Source/Evidence | claims and evidence that reference Odo subjects or related proposals | `RUNTIME_UNAVAILABLE` / `RUNTIME_UNVERIFIED` |
| Media/MediaUsage | canonical Media and usage references for Odo subjects | `RUNTIME_UNAVAILABLE` / `RUNTIME_UNVERIFIED` |
| Video | Video references/attachments related to Odo subjects | `RUNTIME_UNAVAILABLE` / `RUNTIME_UNVERIFIED` |
| WordPress Posts | `wp_post:<blog_id>:38`, `39`, `40`, `55` Graph references and editorial fingerprints | `RUNTIME_UNAVAILABLE` / `RUNTIME_UNVERIFIED` |
| Odo 35 | model, movement and variant inbound/outbound references | `RUNTIME_UNAVAILABLE` / `RUNTIME_UNVERIFIED` |

## Predicate resolution status

The current code registry contains `about`, `depicts`, `model_of`, `variant_of`,
`uses_movement`, `supports_music`, `configured_with_music` and
`observed_playing_music` (`CODE_OBSERVED`). No mutation was attempted. The
presence of a predicate in code does not prove that an Odo endpoint row or
triple exists in runtime.

The following intents remain unresolved until actual endpoint rows, evidence
and directionality are reviewed:

- Model → Movement: no registered specific predicate.
- Variant → Component and Variant → Classification: no registered specific
  predicate; broad `about` cannot be silently treated as a domain contract.
- Knowledge/Source/Evidence and Post associations: only use `about` where the
  governed proposal explicitly establishes that association and the endpoint
  contract permits it.
- Product–Specimen: explicitly `REGISTRY_GAP`; do not use Product payload or
  broad `about` as an ownership relation.

## Runtime capability gaps and required stop codes

| Required phase | Current capability | Required outcome |
|---|---|---|
| Namespace rekey preserving UUID | `CODE_OBSERVED`: generic Authority `rekey`, revision/collision checks and proposal executor dispatch | Runtime proposal/apply still requires authenticated preflight and human gate |
| Confirmed component merge | `CODE_OBSERVED`: generic same-type merge, Graph inbound/outbound adapter, verify and durable receipt | Runtime proposal/apply still requires authenticated preflight and human gate |
| Odo 35 retirement | `CODE_OBSERVED`: ordinary retirement exists, but reference audit and runtime are unavailable | No retirement mutation; keep `RETIREMENT_REVIEW` in the Odo pack |
| Media placeholders | `CODE_OBSERVED`: Article coordinator has internal system-placeholder handling; no Odo-specific governed placeholder operation | Not a blocker; keep requirements only; no fake Media/file/URL |
| Video placeholders | `CODE_OBSERVED`: no governed Video placeholder operation | Not a blocker; keep requirements only; no Video entity |
| Post reconciliation | `CODE_OBSERVED`: Article path is reconcile-only; WordPress is unavailable | Do not create proposal/apply or alter Posts |

## Next safe action after runtime restoration

1. Restore/validate the local WordPress-to-MySQL connection, then re-run
   read-only preflight and capture runtime counts/revisions.
2. Export all inbound/outbound relations and Source/Evidence/Media/Video/Post
   references through application read boundaries.
3. Resolve target collisions before any proposal.
4. Obtain approval for the generic V3 rekey and merge/reference-movement
   contract in [ODO_NAMESPACE_AND_IDENTITY_MERGE_RUNTIME_DESIGN.md](ODO_NAMESPACE_AND_IDENTITY_MERGE_RUNTIME_DESIGN.md);
   until then remain fail-closed.
5. Create proposals only after capability and reference audit are complete;
   stop at the separate Human Approval/Controlled Apply boundary.
