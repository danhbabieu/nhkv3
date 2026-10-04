# Dictionary architecture closure review — 2026-10-04

This is a read-only code/test review record for the `main` implementation. No
Migration024 materialization, semantic enrichment apply, Graph mutation,
staging/production write or deployment was performed.

| Requirement | Implementation | Test evidence | Status |
| --- | --- | --- | --- |
| Entry/Form/Sense remains Dictionary lexical ownership | Existing Entry/Form repository and Concept-as-Sense lifecycle retained | Entry/Sense, materialization and public-query suites | DONE |
| Mapping semantic reference is authoritative; invalid mapping does not fallback | Detail/Public queries preserve non-ABSENT mapping state and only use legacy fallback when absent | `DictionaryDetailQueryTest`, `DictionaryPublicQueryTest` | DONE |
| Canonical dossier reaches Dictionary detail | Empty dossier seed no longer bypasses `SemanticDossierQuery`; bootstrap hydrates when no real status exists | `DictionaryRuntimeContractTest`; focused dossier suite | PARTIAL — production WordPress wiring still needs live runtime read-back |
| Knowledge consumes canonical facets and is bounded | Facets flatten to public items, cap at 6 and expose `has_more` | `DictionaryDetailQueryTest` | DONE |
| Media precedence and bounded gallery | Primary media, gallery, then relation fallback; dedupe and cap at 8 | `DictionaryDetailQueryTest` | DONE |
| Video/Article semantic sections | Relation projection is consumed with canonical URLs supplied by owner projections | Dictionary detail and dossier suites | PARTIAL — live canonical owner acceptance pending |
| Clock-Type derived Brand/Model/Specimen | Existing Clock-Type dossier adapter remains the only derived path | Clock-Type dossier/hierarchy suites | DONE (read-only code-side) |
| Related Dictionary terms | Same-owner plus bounded dossier/Graph-discovered owners, direct-first deterministic dedupe, max 12 | `DictionaryRelatedTermProjectionTest` | PARTIAL — live registry/path read-back pending |
| Reverse Mentions | Article, approved Media, and eligible Video resolve through canonical readers; unknown sources omit | Existing detail suite; runtime source review | PARTIAL — dedicated source resolver fixture coverage remains to add |
| Cross-section dedupe | Existing source-key dedupe retained in detail composition | `DictionaryDetailQueryTest` | DONE (code-side) |
| Shared Dictionary SEO/sitemap decision | Dedicated decision distinguishes INDEXABLE/NOINDEX/REDIRECT/BLOCKED; hub excludes owner-backed entries | `DictionaryDetailQueryTest`, SEO/sitemap suites | PARTIAL — active theme head/HTTP acceptance pending |
| Public frontend order and safe links | Current theme already renders owner/semantic/related sections; lexical mentions remain last | Existing frontend contract tests | PARTIAL — active theme needs a targeted visual/HTTP regression for breadcrumbs and mention links |

## Verdict

`CODE_SIDE_REPAIR_READY / RUNTIME_ACCEPTANCE_PENDING`.

The remaining gaps are environment/read-back and frontend-specific regression
coverage, not permission to mutate semantic data or run deployment.
