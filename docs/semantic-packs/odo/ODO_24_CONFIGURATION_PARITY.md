# ODO 24 CONFIGURATION PARITY — OWNER CONFIRMED

> **Status:** OWNER-CONFIRMED DATA DESIGN SUPPLEMENT  
> **Date:** 2026-09-03  
> **Scope:** Model 24 community branches 54 / 57 / 62  
> **Precedence:** Constitution → runtime registries/contracts → ODO Semantic Reference Pack → this supplement for the specific 54/57/62 configuration-parity decision.

## 1. Owner decision

The owner confirms that the community/market branches **54, 57 and 62** sit on the same Model 24 / Movement 24 basis and share the same family configuration set.

Use the currently documented 54 configuration shapes as the template for 57 and 62.

Configuration parity applies to the physical/configuration family, not automatically to every Music relation.

## 2. Shared configuration family

For each of 54, 57 and 62, the semantic plan must support these configuration shapes:

- 8 côn / 8 búa
- 6 côn / 10 búa
- 10 côn / 10 búa
- 10 côn / 11 búa

The 10 côn / 10 búa shape may have more than one Music-specific semantic variant when Evidence supports different musical configurations. Do not collapse distinct Music configurations merely because the rod/hammer count is the same.

## 3. Graph modeling rule

Do **not** model Variant → Variant.

The current registry allows:

- `variant_of`: Variant → Model
- `uses_movement`: Variant → Movement
- `configured_with_music`: Variant → Music

Therefore configuration variants for 54 / 57 / 62 must remain direct variants of Model 24 and may use Movement 24 where supported.

Conceptual grouping:

```text
Model 24
├── 54 family
│   ├── 8/8
│   ├── 6/10
│   ├── 10/10
│   └── 10/11
├── 57 family
│   ├── 8/8
│   ├── 6/10
│   ├── 10/10
│   └── 10/11
└── 62 family
    ├── 8/8
    ├── 6/10
    ├── 10/10
    └── 10/11
```

The conceptual family grouping above is for data design / projection. It does not authorize an unsupported `variant_of Variant` edge.

## 4. Runtime reconciliation rule

Before creating any missing 57/62 configuration identity:

1. resolve by UUID/stable key/name;
2. reuse an existing entity when present;
3. detect legacy `o-do` / canonical `odo` collisions;
4. preserve UUID on rekey/merge where contract permits;
5. create only a governed proposal for genuinely missing identities/relations;
6. read back Authority + Graph after apply;
7. never use WordPress taxonomy/postmeta as semantic storage.

Canonical namespace for all new keys is `odo`.

## 5. Music is evidence-scoped

Do not infer Music from côn/búa count alone.

Existing 54 Music/configuration records remain useful evidence/candidates, but parity of the physical configuration set does not automatically assert that every 57 or 62 example carries the same Music package.

`configured_with_music` must be created only from owner-supplied fact, Source/Evidence, or another accepted governed evidence path for the exact configuration.

## 6. Operational intent

A future governed reconciliation should produce a matrix:

| Family | Configuration | Variant UUID | Model | Movement | Music | Evidence | Runtime status |
|---|---|---|---|---|---|---|---|
| 54 | 8/8, 6/10, 10/10, 10/11 | resolve/reuse | Model 24 | Movement 24 | evidence-scoped | required for Music | existing/review |
| 57 | 8/8, 6/10, 10/10, 10/11 | resolve/create candidate | Model 24 | Movement 24 | evidence-scoped | required for Music | reconcile |
| 62 | 8/8, 6/10, 10/10, 10/11 | resolve/create candidate | Model 24 | Movement 24 | evidence-scoped | required for Music | reconcile |

No runtime mutation is authorized by this supplement alone; mutation remains governed by the current Proposal → approval → eligibility → Controlled Apply flow.
