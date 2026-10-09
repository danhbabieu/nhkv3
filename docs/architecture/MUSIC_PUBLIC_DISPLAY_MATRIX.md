# NHK V3 — Music Public Display Matrix

This is a read-only mapping from collection input to canonical owner,
readiness and presentation. It does not create fields, routes, Graph edges or
public assets.

Every row records the evidence/readiness decision before presentation.
The frontend component is a consumer of the projection, never a semantic owner.

| Music field/group | Canonical owner | Evidence/readiness gate | Public projection | Frontend component | Verification |
|---|---|---|---|---|---|
| Name, preferred title, alias, language | Authority / Public Identity | canonical identity, scope and route revalidated; alias is not a new owner | public-safe name, accepted aliases and canonical route | Music dossier identity | Authority/Public Identity read-back + `MusicDossierProjectionTest` |
| Introduction and editorial summary | WordPress / public projection | editorial source and public copy policy; not Knowledge by copy | reader-safe summary | Music dossier introduction | WordPress/public read model; no body copied to Knowledge |
| Origin, history, authorship, chronology | Knowledge + Source/Evidence | atomic subject-scoped claims with eligible support; disputes retained | approved claims and allowed citations | history/research sections | Music coverage + public Evidence policy |
| Form, phrase structure, melody features | Knowledge / score edition boundary | source/edition scope; candidate vs verified separated | public claim or score context when eligible | structure section | coverage status + source locator |
| Score edition and notation witness | Source/Evidence + existing Music reference/Media boundary | edition, locator, integrity, rights and verification; no guessed octave/tuning | allowlisted score metadata/events only | score section | `MusicReferenceContract`, dossier projection tests |
| Piano reference | Media / MediaAsset / MediaUsage | score lineage, classification, rights and governed delivery | labelled reference audio only | audio/player section when delivery exists | Media read-back + public delivery |
| Bell simulation | Media / MediaAsset / MediaUsage | simulation disclosure, score lineage, rights and delivery | labelled `BELL_SIMULATION`; never historical Big Ben audio | audio/player section when eligible | reference contract + frontend QA |
| Historical recording | Media + Source/Evidence | exact recording identity, provenance, authenticity, rights, checksum and delivery | public player only after all gates | audio/player section | governed Media read-back |
| Clock mechanism context | Knowledge + Graph | exact clock/mechanism scope and supporting evidence | scoped context, not universal Music capability | clock application section | registered relation/claim read-back |
| Brand/Model/Variant/Movement relation | Graph + Knowledge + Source/Evidence | registered endpoint/predicate, direction, scope, provenance and evidence | direct/derived reader-safe relation with path kind | related entities / clock application | Graph public projection tests |
| Specimen observation | Authority/Specimen + Knowledge/Evidence | one physical object and specimen-scoped support | explicitly specimen-scoped relation/observation | related entities | scope and identity read-back |
| Image/diagram/gallery | Media / MediaAsset / MediaUsage | exact subject/use, public eligibility and asset delivery | public-safe image/alt/usage | library/media gallery | Media binding and dossier read-back |
| Video | Video + Graph/Source | canonical Video owner, first-party route, eligible relation and public-safe metadata | first-party Video link; external URL remains source/reference | library/video link | Video public projection and route checks |
| Dictionary terms | Dictionary Entry/Form/Sense | lexical review, attestation, owner revalidation and canonical mode | term/definition or delegated link; no competing Music page | research/dictionary section | Dictionary resolver/public projection |
| Source citations | Source/Evidence public policy | public source eligibility and exact support | reader-safe title, locator and allowed URL only | sources section | dossier safe-evidence allowlist |
| Research gaps and uncertainty | Coverage/read-model diagnostics | explicit `MISSING`, `UNKNOWN`, `DISPUTED`, `BLOCKED`, `CANDIDATE` | honest gap/review/unavailable copy; no fabricated value | research/coverage section | `MusicCoverageAssessment` |
| SEO and route | Public Identity / SEO / WordPress | persisted identity, eligibility, canonical route and indexability | public-safe title/meta/canonical URL | page head and route | URL/SEO contract + browser/read-back |

## State interpretation

| State | Meaning | Display rule |
|---|---|---|
| canonical data available | owner read model contains data | still check public eligibility |
| public eligible | owner policy, rights and readiness pass | may enter projection |
| rendered frontend data | projection was read and component rendered | does not imply live mutation |
| missing feature | component/capability is not implemented | report honest gap |
| missing evidence | claim/source support is absent or not public | omit unsupported citation; show gap/warning |
| missing rights | asset rights are unresolved/blocked | no public delivery |
| temporarily unavailable | runtime/dependency/read-back failed | show unavailable, not empty success |

Internal UUIDs, stable keys, revisions, private evidence, raw storage metadata
and draft diagnostics are never public fields. A URL exists only when the
canonical owner and public route policy support it; URL existence is not dossier
completion.
