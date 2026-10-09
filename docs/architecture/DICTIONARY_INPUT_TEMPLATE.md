# NHK V3 — Dictionary Input Template for Music Terms

**Trạng thái:** transient lexical-authoring worksheet — không phải schema hay writer  
**Owner:** Dictionary owns Entry/Form/Sense; Authority, Knowledge, Source/Evidence,
Graph, Media, Video and WordPress retain their existing owners.

## 1. Boundary

This template prepares lexical content that may be mentioned while researching
Music. It does not turn a Music name or alias into a Dictionary Entry
automatically. A Dictionary Mention is not Evidence, a Knowledge claim, a
semantic relation or proof of identity.

The normal sequence is:

`SEARCH FIRST → RESOLVE → REUSE → CREATE PRIVATE CANDIDATE ONLY IF UNRESOLVED`

Any semantic owner mutation or Graph relation still follows its own registered
contract and Governance lifecycle. The worksheet is transient and
`EXAMPLE_ONLY / NO_LIVE_WRITE`.

## 2. Entry/Form/Sense worksheet

| Layer | Required preparation | Result |
|---|---|---|
| Entry | preferred form, locale, lifecycle, owner/public mode | |
| Form | text, kind, locale, context, public visibility | |
| Sense | one lexical meaning, definition, domain, usage scope, register | |
| Attestation | exact observed text, source kind, locator, observed date | |
| Owner reference | registered type, canonical ID/read-back, revision, route | |
| Review | duplicate audit, ambiguity, reviewer, idempotency/read-back | |
| Public | delegated/dedicated/blocked, canonical URL, indexability reason | |

A definition explains lexical meaning. If it contains a factual claim about a
brand, date, configuration, relation or capability, split that proposition into
the owning Knowledge/Authority workflow instead of hiding it in Dictionary prose.

## 3. Forms and senses

~~~yaml
# transient worksheet; not an Entry/Form/Sense runtime payload
entry:
  preferred_form: ""
  locale: ""
  lifecycle: DRAFT | APPROVED | RETIRED
forms:
  - text: ""
    kind: PREFERRED | ALTERNATE | COLLOQUIAL | TECHNICAL | PHONETIC | HIDDEN
    locale: ""
    context: ""
    public: false
senses:
  - sense_key: ""
    definition: null
    definition_status: DRAFT | REVIEW_REQUIRED | APPROVED | BLOCKED
    context:
      domain: ""
      usage_scope: []
      register: ""
      examples: []
    ambiguity_notes: ""
    semantic_reference:
      type: null
      canonical_id: null
      stable_key: null
      observed_revision: null
      current_route: null
      status: ABSENT | VALIDATED | PENDING_REVALIDATION | AMBIGUOUS | INVALID
~~~

Forms with multiple meanings need a Sense/context or remain `AMBIGUOUS`.
Authority Alias and Dictionary Form are distinct: a Form has lexical/editorial
value and never rekeys the Authority owner.

## 4. Lexical observation and attestation

Record lexical attestation separately from semantic Evidence.

| Input | Keep | Do not promote automatically |
|---|---|---|
| Music title/alias | exact wording, locale, source context | Dictionary Entry, Knowledge or relation |
| technical term | form, domain, register, attestation | canonical Component/Classification |
| definition candidate | definition text, review state, provenance | Evidence for factual claim |
| URL/archive | locator, accessed date, scope | approved Evidence |
| OCR/caption/transcript | extractor, segment, confidence, lineage | verified terminology or identity |
| user hint | exact user wording and provenance | `VERIFIED` fact or owner |

Candidate terminology such as `chime`, `quarter chime`,
`Westminster Quarters`, `carillon`, `gong`, `bell`, `hammer`,
`score`, `arrangement`, `transcription`, `melody` and `movement` is
research fixture input only. It is not a hardcoded record list or creation
instruction. Vietnamese, English, French, German and historical forms require
locale/source evidence.

## 5. Duplicate, ambiguity and owner review

- Search approved labels/forms, hidden resolver forms, existing Senses and
  suppressed/rejected candidates first.
- Reuse an existing Entry/Sense when meaning, locale and scope match.
- Preserve competing owners or meanings as `AMBIGUOUS`; do not choose by
  string equality or confidence alone.
- Revalidate owner type, canonical identity, active state, revision, route and
  public eligibility before delegated linking.
- Lexical attestation is not Knowledge Evidence.
- No Dictionary page may compete with an owner-delegated Music canonical page.

## 6. Public projection checklist

| Gate | State |
|---|---|
| lexical value and definition reviewed | `MISSING` / `UNKNOWN` / `CANDIDATE` / `DISPUTED` / `VERIFIED` |
| owner reference revalidated | `ABSENT` / `VALIDATED` / `AMBIGUOUS` / `BLOCKED` |
| canonical mode | `DELEGATED` / `DEDICATED` / `BLOCKED` |
| public eligibility/read-back | `MISSING` / `VERIFIED` / `PUBLIC_READY` |
| runtime availability | `AVAILABLE` / `TEMPORARILY_UNAVAILABLE` |

Draft, ambiguous, rejected, ignored, suppressed, duplicate and blocked content
is not indexable. Unknown or runtime-unavailable state must be shown as
`UNKNOWN` or unavailable, not as empty success.

The shared intake status vocabulary remains explicit: `MISSING`, `UNKNOWN`,
`NOT_APPLICABLE`, `DISPUTED`, `BLOCKED`, `CANDIDATE`, `VERIFIED` and
`PUBLIC_READY`. A disputed lexical form or sense is retained for review and
must not be silently selected as the canonical public wording.
