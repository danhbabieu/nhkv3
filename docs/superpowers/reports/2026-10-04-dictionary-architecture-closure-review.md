# Dictionary architecture closure review — 2026-10-04

This is a read-only code/test review record for the `main` implementation. No
Migration024 materialization, semantic enrichment apply, Graph mutation,
staging/production write or deployment was performed.

| Requirement | Implementation | Test evidence | Status |
| --- | --- | --- | --- |
| Entry/Form/Sense remains Dictionary lexical ownership | Existing Entry/Form repository and Concept-as-Sense lifecycle retained | Entry/Sense, materialization and public-query suites | DONE |
| Mapping semantic reference is authoritative; invalid mapping does not fallback | Detail/Public queries preserve non-ABSENT mapping state and only use legacy fallback when absent | `DictionaryDetailQueryTest`, `DictionaryPublicQueryTest` | DONE |
| Canonical dossier reaches Dictionary detail | Empty dossier seed no longer bypasses `SemanticDossierQuery`; bootstrap hydrates when no real status exists | `DictionaryRuntimeContractTest`; focused dossier suite | DONE / DEFERRED_RUNTIME_ACCEPTANCE |
| Knowledge consumes canonical facets and is bounded | Facets flatten to public items, cap at 6 and expose `has_more` | `DictionaryDetailQueryTest` | DONE |
| Media precedence and bounded gallery | Primary media, gallery, then relation fallback; dedupe and cap at 8 | `DictionaryDetailQueryTest` | DONE |
| Video/Article semantic sections | Relation projection is consumed with canonical URLs supplied by owner projections | Dictionary detail and dossier suites | DONE / DEFERRED_RUNTIME_ACCEPTANCE |
| Clock-Type derived Brand/Model/Specimen | Existing Clock-Type dossier adapter remains the only derived path | Clock-Type dossier/hierarchy suites | DONE (read-only code-side) |
| Related Dictionary terms | Same-owner plus bounded dossier/Graph-discovered owners, direct-first deterministic dedupe, max 12 | `DictionaryRelatedTermProjectionTest` | DONE / DEFERRED_RUNTIME_ACCEPTANCE |
| Reverse Mentions | Article, approved Media, and eligible Video resolve through canonical readers; unknown sources omit | `DictionaryMentionPublicProjectionTest`, detail dedupe test | DONE / DEFERRED_RUNTIME_ACCEPTANCE |
| Cross-section dedupe | Existing source-key dedupe retained in detail composition | `DictionaryDetailQueryTest` | DONE (code-side) |
| Shared Dictionary SEO/sitemap decision | Dedicated decision distinguishes INDEXABLE/NOINDEX/REDIRECT/BLOCKED; hub excludes owner-backed entries; invalid mapping is fail-closed | `DictionarySeoDecisionTest`, `DictionaryDetailQueryTest`, frontend contract | DONE / DEFERRED_RUNTIME_ACCEPTANCE |
| Public frontend order and safe links | Detail breadcrumb, per-Sense semantic links, technical relations, final weak Mention links and no duplicate Sense definition | `FrontendContractTest`, Dictionary detail/frontend suite | DONE / DEFERRED_RUNTIME_ACCEPTANCE |

## Verdict

`DICTIONARY_ARCHITECTURE_CODE_COMPLETE / RUNTIME_ACCEPTANCE_PENDING`.

Remaining items are runtime-only acceptance: deployed build identity, live
HTTP/read-back, runtime dossier/data mapping, semantic apply and browser
acceptance. They do not block the code-complete verdict and do not authorize
semantic mutation or deployment.
