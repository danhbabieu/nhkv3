# Odo Canonical Token Migration Receipt — 2026-09-03

Target: `demo.1945.vn` only. No production/V2 operation was performed.

## Runtime and safety

- Remote repository: `/home/erourxcg/apps/nhkv3`
- WordPress document root: `/home/erourxcg/apps/nhkv3/public`
- Backup: `/home/erourxcg/nhkv3-odo-backup.4NMUmb.sql`
- Backup SHA-256: `22da7df9538c2dc909ff21a054581687c2932b65cfca98715b33952a57024dad`
- Remote HEAD before apply: `54c9a26a5297c41d6212b842b66351d6e0c3c949`
- Remote HEAD after verification: `6d624650075293cfa8de4be21908697be05cc73f`
- Remote tracked changes: none; pre-existing untracked logs/uploads/plugin directories were preserved.

## Dry-run and apply

Dry-run found 195 mutable rows containing `o-do`: 71 Authority, 112
Knowledge, 1 Media, 2 MediaAsset, 3 Posts, 4 postmeta and 2 options.
There were two Authority collisions, so 193 non-collision mutable rows were
updated transactionally. The two collision sources remain active and were not
retired or merged.

Collisions:

| Source UUID | Source key | Canonical UUID | Canonical key |
|---|---|---|---|
| `01bead27-1308-48c1-af99-c68318e2b577` | `nhk:component:o-do.dial.applied-glued` | `e326a326-ae8c-447f-a2a4-a83a3cf168d4` | `nhk:component:odo.dial.applied-glued` |
| `32f43d4b-d6c8-4223-a89b-cc47f30cda77` | `nhk:component:o-do.dial.applied-pinned` | `48311ccd-9d45-4985-a620-ca579499f02c` | `nhk:component:odo.dial.applied-pinned` |

No GUID was rewritten. Evidence excerpts and immutable audit values were not
rewritten.

## Read-back

- Mutable `o-do`: 2 Authority collision rows remain.
- Immutable audit rows containing `o-do`: 1.
- Evidence rows containing `o-do`: 0.
- WordPress GUID rows containing `o-do`: 2 (intentionally kept).
- Posts, postmeta, termmeta, options, taxonomy, Knowledge, Source, Media,
  MediaAsset, MediaUsage, Video and Graph mutable fields: 0 remaining.
- `/odo/`: HTTP 200.
- `/o-do/`: HTTP 301 to `/odo/`.
- Postmeta serialization check: 0 invalid rows.

The final mutable inventory is therefore **2 unresolved Authority collision
rows**, not zero, because automatic merge would conflate two distinct names,
UUIDs and semantic records.
