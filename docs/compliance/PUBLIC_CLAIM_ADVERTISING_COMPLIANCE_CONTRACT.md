# NHK V3 Public Claim & Advertising Compliance Contract

> **SUBORDINATE TO THE CONSTITUTION.** This contract implements the public-claim
> law approved by `docs/constitution/NHK_V3_CONSTITUTION.md`. If any provision
> conflicts with the Constitution, mark `CONSTITUTION_CONFLICT` and stop at the
> applicable human gate.

## Purpose

This contract governs public-facing promotional or commercial claims produced,
assembled or projected by NHK V3. It applies across WordPress Article content,
Product listing copy, MediaUsage alt/caption and text overlays, image/banner or
thumbnail text, Video title/description/editorial package, SEO title/meta copy,
Open Graph text, structured promotional copy, public cards and other visitor-
facing promotional projections.

It does not create a new Authority type, Graph predicate, Knowledge type,
Media role, Video type or semantic owner. It is a cross-cutting publication
constraint over existing owners and projections.

## Legal basis and policy intent

NHK V3 must comply with applicable Vietnamese advertising law. In particular,
public advertising must not use claims equivalent in meaning to leadership,
uniqueness or absolute superiority unless the claim is supported by legally
valid evidence that matches the exact subject, comparison scope, time scope and
meaning asserted.

The system must evaluate meaning, not merely a banned-word list. Replacing a
restricted expression with a synonym, euphemism, foreign-language phrase or
creative spelling does not make an unsupported superiority/uniqueness/absolute
claim compliant.

## Claim classes

### 1. Descriptive / editorial claim

A statement describing observable, editorial or aesthetic characteristics
without asserting market leadership, uniqueness or absolute superiority.
Examples include case form, dimensions, material, mechanism, documented music,
condition observations, sound character, provenance-backed history or an
editorial opinion clearly framed as opinion.

This class may publish when it remains truthful, appropriately scoped and does
not disguise an objective superiority claim.

### 2. Objective promotional claim

A verifiable statement about performance, origin, age, rarity, material,
configuration, provenance, condition, ranking, award, market position or other
objective property.

It may publish only when the owner/source/evidence chain supports the exact
claim at the exact scope. Product listing copy, source text, user input, AI
inference, visual similarity or SEO intent alone is not proof.

### 3. Superiority / uniqueness / absolute claim

A claim asserting or clearly implying leadership, sole status, unmatched
quality, absolute superiority, market ranking or equivalent meaning.

This class is **EVIDENCE_REQUIRED**. Without legally valid supporting material,
the public projection must not publish the claim and must either:

- rewrite it into a truthful descriptive statement that removes the unsupported
  leadership/uniqueness/absolute meaning; or
- return a compliance blocker for human review.

A rewrite must change the meaning, not only substitute vocabulary.

## Evidence rule

Evidence must be specific to the claim. The system must not broaden evidence
beyond what the source actually proves.

For a superiority/uniqueness/absolute advertising claim, evidence must also
satisfy the applicable legal requirements in force at publication time. Where
law requires a particular lawful document, issuer, survey, award, validity
period or disclosure, the publication gate must preserve those constraints.

A certificate or survey supporting one award, category, geography, period or
comparison set does not authorize an unrestricted claim about all products,
all competitors or all time.

Source/Evidence remains the provenance owner. Public Projection may render a
reader-safe disclosure or attribution when legally or contractually required;
it must not fabricate or summarize evidence more strongly than the source.

## Scope law

Claims inherit the NHK V3 fact-scope law. Evidence about one Specimen does not
authorize a Variant-, Model-, Brand- or market-wide promotional claim. Evidence
about one Variant does not authorize a whole-Brand claim. A Product listing
statement does not become evidence merely because it is already public.

## Channel parity

The same claim meaning must receive the same compliance result regardless of
surface. This contract therefore applies to at least:

- WordPress title, excerpt and body when used promotionally;
- Product listing title and commercial copy;
- MediaUsage caption, alt when promotional, text overlay, banner and thumbnail;
- Video title, summary, description and thumbnail text;
- SEO title, meta description, Open Graph text and structured promotional copy;
- public cards, hub copy, comparison copy and generated summaries.

No channel may be used to bypass a blocker that would apply in another channel.
Text embedded in an image or thumbnail is still public advertising copy for the
purpose of this policy.

## Publication gate

Before public publication or projection of promotional copy:

1. extract or identify material promotional claims;
2. classify each claim as descriptive/editorial, objective promotional, or
   superiority/uniqueness/absolute;
3. resolve the canonical subject and scope;
4. bind applicable Source/Evidence when required;
5. check legal/compliance constraints current at publication time;
6. rewrite unsupported language only when the resulting meaning is genuinely
   narrower and truthful;
7. block publication when a required claim cannot be supported or safely
   rewritten;
8. preserve reader-safe disclosure/attribution when required;
9. verify the rendered public surface, including image/thumbnail text and SEO
   projection, not only the source draft.

## AI and generated copy

Generated copy is never evidence. AI may propose compliant wording, but it may
not manufacture rankings, awards, market leadership, uniqueness, provenance or
legal support. A confidence score is not evidence.

The preferred writing strategy is evidence-led specificity: describe the
actual form, mechanism, material, documented configuration, provenance,
condition, sound character, craftsmanship or collector context instead of
using unsupported superlative positioning.

## Ownership

- WordPress remains owner of Article editorial copy.
- Product remains owner of commercial listing copy.
- MediaUsage remains owner of contextual alt/caption/placement metadata.
- Video remains owner of its NHK editorial video package.
- Source/Evidence owns provenance/support.
- Knowledge owns canonical atomic facts where applicable.
- Public Projection/SEO renders compliant public copy.

This contract does not duplicate those records into a new compliance database
or semantic entity by default.

## Failure and diagnostics

Until a dedicated runtime vocabulary is approved, implementations must not
invent a closed status enum. They may use bounded diagnostics that preserve at
least these meanings:

- unsupported objective claim;
- superiority/uniqueness/absolute claim requires evidence;
- evidence does not match claim scope;
- evidence validity/disclosure requirement unresolved;
- compliant rewrite required;
- compliance dependency unavailable.

Infrastructure or legal-rule lookup failure must not be silently treated as a
passing compliance result.

## Legal-source maintenance

The legal basis is time-sensitive. Implementations that automate this gate must
use a maintained legal-source/reference layer or human-reviewed policy version,
record the policy version/date used for the decision, and re-review the contract
when Vietnamese advertising law changes.

As of the 2026-09-03 approval, the design is grounded in Article 8(11) of the
Vietnamese Law on Advertising and Circular 12/2026/TT-BVHTTDL, effective
2026-07-05, which clarifies equivalent expressions and lawful supporting
materials. This date/reference is evidence for the policy version, not a claim
that legislation can never change.

## Explicit exclusions

This contract does not authorize:

- semantic data migration or backfill;
- automatic promotion of Product or editorial copy into Knowledge;
- automatic creation of Source/Evidence from generated text;
- a new entity type, endpoint, predicate, field or Graph edge;
- legal conclusions beyond the maintained policy/evidence;
- silent publication when the compliance decision is ambiguous.
