# Brand Backbone Structural Acceptance Matrix

This is the executable acceptance contract for later implementation. Every row
is read-only until implementation and the separately governed data gate are
approved.

| ID | Acceptance criterion | Evidence/test shape | Expected |
|---|---|---|---|
| BB-01 | Registry exposes exact definitions | Unit lookup and `all()` assertions | Two approved predicates only; exact endpoints/cardinality |
| BB-02 | Model→Brand accepted | Graph fixture | One active `model_of` edge via Graph service |
| BB-03 | Variant→Model accepted | Graph fixture | One active `variant_of` edge via Graph service |
| BB-04 | Invalid shapes fail closed | Data-provider endpoint tests | Typed rejection; no repository mutation |
| BB-05 | Model has one active parent | Two Brand parents | Cardinality violation; first edge unchanged |
| BB-06 | Variant has one active parent | Two Model parents | Cardinality violation; first edge unchanged |
| BB-07 | Reverse navigation derived | Brand incoming / Model incoming queries | Children resolve without reverse edge rows |
| BB-08 | Variant Brand context is two-hop | Variant structural context query | Only Variant→Model→Brand resolves Brand |
| BB-09 | No shortcut edge required | Fixture edge inventory | No variant→brand, brand→model, model→variant structural edges |
| BB-10 | Shared entities need no Brand parent | Read Movement/Music/Component/Classification without one | Valid unless own contract says otherwise |
| BB-11 | Orphan Model incomplete | Model without active `model_of` | Not structurally complete/public; no guess |
| BB-12 | Orphan Variant incomplete | Variant without active `variant_of` | Not structurally complete/public; no guess |
| BB-13 | Retired parent incomplete | Retire direct edge, query | Unavailable/incomplete; no resurrection |
| BB-14 | Exact triple idempotent | Create same active triple twice | Same edge identity, no duplicate |
| BB-15 | Governance preserved | Mutation boundary integration test | No direct SQL shortcut |
| BB-16 | Payload is not canonical Graph structure | Payload parent but no Graph parent | Conflict/gap observable; not Graph-complete |
| BB-17 | Ambiguity fails closed | Two valid parent candidates | Review candidates; no auto-attach |
| BB-18 | Contract checkpoint is non-mutating | Before/after counts and command log | Zero semantic mutations |
| BB-19 | No article-body migration | Static scope review | No body import/parser/population path |
| BB-20 | Quality gates pass | Unit, lint, diff check, secret review | Pass before checkpoint commit |

Tests should use isolated/in-memory fixtures or guarded `nhk_v3_test`; destructive
operations are forbidden on `nhk_v3`.
