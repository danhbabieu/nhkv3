# Westminster Content & Audio Readiness v2

Status: RESEARCH_ONLY / GOVERNANCE_CANDIDATES / NON_CANONICAL / REFERENCE_AUDIO_RENDERED_LOCALLY

Implementation follow-up (2026-10-08): the existing v2 read path now treats
mixed valid/invalid reference packets as `PARTIAL`, resolves playable audio
only through the existing Media owner and `PublicMediaAssetDelivery`, and
renders direct/derived relation context without graph diagnostics. This does
not create a Westminster score, MediaAsset, Source, Evidence or canonical
mutation; the route remains blocked until a governed reference packet exists.

This is a readiness report and candidate register for the existing Westminster
Music entity. It is not a new template, an Authority record, a Knowledge
record, Source/Evidence record, Graph packet, score asset, Media asset, or
runtime authorization. No canonical record, file, relation, or production
data was changed while preparing it.

Execution follow-up (2026-10-08): the Grove D-major notation witness is now
transcribed into a versioned non-canonical JSON event list and machine-checked
against the Grove-derived LilyPond representation and Starmer's Cambridge
Quarters discussion. Original local `PIANO_REFERENCE` and `BELL_SIMULATION`
WAVs exist as non-public research outputs. They use the same 41-event stream,
but their octave, A4 reference, tempo, phrase gaps and synthesis models are
editorial assumptions. They are not a canonical score, historical recording,
Big Ben tuning model or public MediaAsset. See the score JSON, audio manifest
and Media candidate packet under `docs/research/westminster/`.

## Current read-back

The read-only public route `/ban-nhac/westminster/` currently resolves to the
existing Music entity and uses the universal Music dossier surface. The page
shows:

- an existing Westminster Music identity;
- many Variant cards, including Westminster-labelled clock variants and
  unrelated-looking two-melody/Ave Maria variants;
- five clock-application Movement cards: Odo 24, Vedette 42, FFR 6, FFR 8 and
  Odo 36;
- Brand context for Odo, Vedette and Junghans, and Model context for FFR 6,
  Odo 36, Odo 24, Vedette 42, FFR 8 and Junghans W64;
- one related Video;
- an empty “Tri thức về Westminster” state;
- no score event list, no audio card, no playable `<audio>` element and no
  public MediaAsset delivery.

This is a public projection read-back, not proof that every displayed relation
has passed a fresh Source/Evidence review. The dated unresolved relation
inventory contains Westminster-related records whose `source_refs` or
`evidence_refs` are empty and whose status is `UNRESEARCHED`/
`NO_CANONICAL_TARGET`; they must not be reused as verified evidence.

## Research verification

| Proposition | Evidence | Result | Scope decision |
|---|---|---|---|
| The original Cambridge Quarters were composed in 1793 and are commonly called Westminster Chimes | [Great St Mary's — Bells](https://www.greatstmarys.org/bells) | Verified as an institutional historical statement | Scope to the Cambridge origin/name relationship; do not turn it into a universal clock-brand fact |
| Westminster has four quarter bells and a separate Great Bell; the quarter chimes run every 15 minutes | [UK Parliament Virtual Tour](https://virtualtours-assets.parliament.uk/elizabeth-tower/index.html), Belfry/Westminster Chimes and Mechanism Room sections | Verified as an institutional description | Scope to the Elizabeth Tower/Westminster mechanism |
| The Westminster installation is tied to the 1859 mechanism and Denison/Dent clock design | [UK Parliament Virtual Tour](https://virtualtours-assets.parliament.uk/elizabeth-tower/index.html) | Verified as historical/mechanical context | Do not infer that every later commercial movement has the same mechanism |
| The detailed Parliament bell table gives Big Ben E and quarter bells G♯, F♯, E, B | [The Great Bell and the quarter bells](https://www.parliament.uk/about/living-heritage/building/palace/big-ben/facts-figures/great-bell/) | Verified as one current official table | Use as a Westminster-installation pitch-class candidate, not as octave/tuning proof |
| Another official Parliament facts page gives the first quarter bell as G natural | [Facts and figures](https://www.parliament.uk/about/living-heritage/building/palace/big-ben/facts-figures/) | Conflicts with the detailed table's G♯ | Requires source-owner adjudication before canonical score verification |
| Cambridge Quarters require the ten-bell form for the historical hour relationship; six/eight-bell forms are reduced variants | [W. W. Starmer, Chimes and Chime Tunes, pp. 8–9](https://whitingsociety.org.uk/old-ringing-books/starmer-chimes-and-chime-tunes-file-01.pdf) | Verified specialist historical statement | Preserve the ten-bell/original versus reduced commercial arrangement distinction |
| Royal Exchange copied the four-note groups but altered their sequence; Parliament copied Cambridge chimes in 1859–60 | [Starmer, pp. 8–9](https://whitingsociety.org.uk/old-ringing-books/starmer-chimes-and-chime-tunes-file-01.pdf) | Verified specialist historical statement | Do not collapse Cambridge, Royal Exchange and Westminster arrangements into one edition |
| The historical notation article prints the Cambridge/Westminster sequence in D major | [Grove-derived Cambridge Quarters article](https://en.wikisource.org/wiki/A_Dictionary_of_Music_and_Musicians/Cambridge_Quarters) | Usable notation witness; page transcription/image provenance still needs edition-level review | Candidate score source only; no final VERIFIED packet yet |
| The Great Bell is described as E below middle C and slightly flat in Parliament's transcript | [UK Parliament audio transcript](https://www.parliament.uk/visiting/online-tours/virtualtours/bigben-tour/big-ben-podcast/) | Verifies an approximate sounding reference, not a frequency/cents tuning table | Do not substitute A4=440 or an exact octave without a specialist decision |
| Five chime phrases are a musical structure and historical pitch relationships vary by installation | [Daniel Harrison, “Tolling Time,” Music Theory Online](https://www.mtosmt.org/issues/mto.00.6.4/mto.00.6.4.harrison.html) and [Worcester clock-chimes notes](https://worcesterbells.org.uk/wp-content/uploads/CLOCKCHIMES-Operation.pdf) | Specialist corroboration and variation warning | Use for review questions and arrangement separation, not as the sole canonical score source |

## Score readiness

### Defensible edition candidate

The current best candidate is the historical Cambridge Quarters notation
printed in the Grove-derived “Cambridge Quarters” article and cross-checked
against Starmer's discussion. The Grove witness explicitly encodes D major,
four quarter-note events per written group and a whole-note hour event; the
article also records the Royal Exchange sequence alteration. Starmer's
contemporary specialist scan places the Cambridge Quarters engraving on pp.
8–9, identifies the 1793–94 Cambridge installation and the ten-bell/hour-bell
relationship, and describes Parliament's 1859–60 copying. These facts make
the witness suitable for a notation-edition candidate, not yet a verified NHK
performance score: exact scan/edition identity, sounding octave, temperament,
unprinted inter-phrase gaps, mechanical timing and the Westminster installation
mapping still require adjudication.

A non-canonical versioned research artifact records this boundary at
`docs/research/westminster/score-editions/grove-cambridge-quarters-d-major-v1.md`:
`NOTATION_WITNESS_VERIFIED / PERFORMANCE_UNVERIFIED`. It uses pitch classes
only, preserves the written quarter/whole durations and adds no inferred rests,
octaves or milliseconds. It is not accepted by the runtime `VERIFIED` score
contract and is not an audio source yet.

### Source-derived phrase candidate

The Grove notation encodes quarter-note events in D major and a final whole-note
hour event. Reading the printed LilyPond transcription, the candidate phrase
order is:

```text
Q1: F# E D A
Q2: D F# E A | D E F# D
Q3: F# D E A | A E F# D | F# E D A
Q4: D F# E A | D E F# D | F# D E A | A E F# D
Hour: D (whole-note notation witness)
```

This is deliberately recorded as a source-derived candidate, not as a
canonical score. At notation-witness level it supports the phrase order,
note spelling, written quarter/whole durations and bar grouping. It does not
add rests or timing that are not printed. It does not verify:

- the sounding octave of each quarter bell or the Great Bell;
- A4 reference, cents deviation, tempering, or the current post-restoration
  bell tuning;
- real bell attack/decay or the mechanical inter-strike interval;
- whether the public Westminster installation should be represented by the
  D-major historical witness or by a transposed E-major pitch-class mapping;
- the first-quarter G♯/G conflict in official Parliament pages;
- the Royal Exchange permutation or six/eight-bell commercial reductions.

A simple +2-semitone transposition maps the D-major witness to the detailed
Parliament pitch classes G♯, F♯, E, B and maps the hour D to E. That is an
analytical comparison only; it is not permission to publish the transposition
as the verified score.

### Required score acceptance evidence

Before a score can be marked `VERIFIED`, the governed packet must contain the
exact edition/page or figure locator, event sequence, note spelling, octave
policy, tuning reference, rhythm/duration policy, phrase boundaries, hourly
strike separation policy, arrangement/transposition label, reviewer decision,
and a deterministic score checksum. A source text field and a
`verification_status=VERIFIED` string alone are insufficient.

## Atomic candidate register for Capture/Governance

The following are human-review candidates only. They intentionally have no
synthetic canonical UUID, no fabricated Evidence ID, and no apply payload.

### Source candidates

1. Great St Mary's, “Bells” — institutional source for the 1793 Cambridge
   origin and the Cambridge/Westminster naming distinction. Public display is
   eligible in principle under the international-institutional source policy;
   exact Source metadata and locator still require governed intake.
2. UK Parliament, Elizabeth Tower Virtual Tour — institutional source for the
   four quarter bells, 15-minute cadence, mechanism, and Westminster historical
   context. Use separate locators for belfry, Westminster Chimes and mechanism
   statements.
3. UK Parliament, “The Great Bell and the quarter bells” — institutional pitch
   table. Store the G♯ spelling as a source-scoped statement and retain the
   conflicting official facts-page statement for review; do not silently merge.
4. W. W. Starmer, *Chimes and Chime Tunes* — specialist historical notation
   and arrangement source, especially pp. 8–9. Rights and edition metadata must
   be checked before derivative publication.
5. Grove-derived “Cambridge Quarters” article — historical notation witness
   for the phrase sequence. Treat Wikisource as a transcription/access layer;
   verify against the underlying scan/edition before score acceptance.
6. Daniel Harrison, “Tolling Time” — specialist analytical corroboration;
   cite for analysis and variation, not as a substitute for the historical
   notation edition.

### Knowledge candidates

Each candidate below is atomic and subject-scoped. It must be duplicate-searched
against the existing Westminster Music entity before proposal.

| Candidate proposition | Subject/scope | Provenance | Evidence to attach | State |
|---|---|---|---|---|
| Cambridge Quarters were composed at Great St Mary's, Cambridge, in 1793 | Music: Westminster/Cambridge Quarters historical origin | EXTERNAL_RESEARCH | Great St Mary's page, Bells section | Candidate; not applied |
| The Westminster installation uses four quarter bells plus a separate Great Bell and plays the quarter melody every 15 minutes | Music: Westminster installation / Elizabeth Tower, not all commercial clocks | EXTERNAL_RESEARCH | Parliament Virtual Tour locators | Candidate; not applied |
| The detailed Parliament pitch table reports quarter-bell classes G♯, F♯, E, B and Great Bell E | Music: Westminster installation, pitch-class statement only | EXTERNAL_RESEARCH | Parliament Great Bell page | Candidate; conflict review required |
| The historical Cambridge form is a ten-bell arrangement whose hour bell has the stated octave relationship to the third quarter bell | Music: Cambridge Quarters historical arrangement | EXTERNAL_RESEARCH | Starmer p. 8 and cross-check | Candidate; arrangement-scoped |
| Royal Exchange preserved four-note groups but altered their sequence | Music: Royal Exchange variant | EXTERNAL_RESEARCH | Starmer p. 9 and Grove article | Candidate; must not be attached to Westminster without arrangement scope |
| The Grove notation witness contains the Q1–Q4 and hour event sequence recorded above | Music: notation edition candidate | EXTERNAL_RESEARCH | Grove article score locator plus page-image review | Notation witness verified; runtime score not canonical |

### Evidence candidates

Evidence must be created only after the claim and Source resolve in the
Capture → Proposal → review → eligibility → Controlled Apply lifecycle. Each
Evidence record should carry the exact URL, page/section or score locator,
retrieval date, source revision, support type (`SUPPORTS`/`QUALIFIES` where
appropriate), and visibility decision. No Evidence was synthesized from the
public route, this report, or a generated summary.

## Piano reference

Status: `RENDERED_LOCALLY / NON_PUBLIC / MEDIA_INGEST_BLOCKED`.

The local WAV is an original synthesis render from the edition-specific event
list, labelled `PIANO_REFERENCE`. Its score linkage, tempo, tuning reference,
render method, checksum, size, duration and event count are recorded in
`reference-audio/manifest.json`. It is not a sampled piano, performer
recording or historical Westminster audio. The governed MediaAsset/public
delivery step remains pending.

It must not be called a historical Westminster recording and must not imply
that equal-tempered piano reproduces bell inharmonicity or the current Great
Bell's slightly-flat behavior.

## Bell simulation

Status: `RENDERED_LOCALLY / NON_PUBLIC / MEDIA_INGEST_BLOCKED`.

The local WAV is an original additive inharmonic synthesis render from the same
event list, labelled `BELL_SIMULATION`. The manifest identifies its synthetic
partial model and render method. It is not a tower-bell acoustic reconstruction,
measured Great Bell sample, Cambridge bell arrangement or historical recording.
The governed MediaAsset/public delivery step remains pending.

## Historical recording and media rights

Status: NOT READY.

No historical recording was reused. The Parliament audio page states that its
Big Ben material is Parliamentary copyright and provides a licence with
conditions; it also says the licence does not guarantee continuous
availability/quality and restricts commercial promotion uses. That is not an
NHK asset authorization for copying, hosting, transforming or redistributing a
particular recording. The exact recording, chain of custody, checksum, licence
scope and delivery decision must be retained before any
`HISTORICAL_RECORDING` item is proposed.

The governed path remains the existing Media workflow: binary upload/attachment
read-back, governed `media-ingest`, canonical MediaAsset → Media → MediaUsage,
public eligibility and final delivery read-back. No new audio owner and no URL
shortcut are permitted.

## Relation readiness

Current route relations are useful discovery context, not permission to make
brand-wide claims:

- Variant → Music must remain a documented variant configuration, not a claim
  that every Odo, Vedette, FFR or Junghans product uses Westminster.
- Movement → Music must remain a documented Movement capability and must not be
  promoted to Brand or Model truth.
- Brand/Model cards need a direct-versus-derived origin and evidence path. The
  current Music template renders type/title cards but does not visibly render
  relation origin or `via_types`, so a reader cannot tell whether a Brand or
  Model association is direct, derived, or merely contextual.
- Article, Video and Media remain their own owners. The current route shows one
  Video but no audio MediaAsset and no Knowledge claims; this is not evidence
  that the Video or any clock variant proves Westminster notation.
- The dated unresolved inventory records Westminster-related V2/legacy
  knowledge with empty evidence or unresolved targets. Those rows remain
  review inputs only and must not be reattached by name matching.

## Validator and runtime defects relevant to readiness

The current metadata validator is useful but is not an audio/score verifier:

- It accepts only event JSON with pitch classes A–G plus `#`/`b`, integer
  octaves 0–9, integer millisecond timing, and positive durations. It does not
  ingest or validate MIDI, MusicXML/MEI, LilyPond/ABC, PDF, SVG or engraved
  notation files.
- It rejects overlapping events, so it cannot represent chords/polyphony. That
  is acceptable for a monophonic chime reference only if the score policy says
  so explicitly.
- It accepts arbitrary non-empty `source`, `tuning`, `phrase`, `instrument`,
  `rights` and `render_method` strings; it does not verify the source, tuning,
  rights, instrument license, checksum, physical file or playback.
- It does not enforce a meaningful tempo range, maximum duration, required
  quarter/hour phrase set, note count, phrase order, or a non-overlapping
  segment sequence beyond basic bounds.
- `status=AVAILABLE` means at least one score/audio component normalized; it
  does not mean every submitted component passed. A valid score plus invalid
  audio can therefore return `AVAILABLE` with errors.
- Mixed packets now normalize as `PARTIAL`; valid score/audio components are
  retained while invalid components are omitted and a generic public warning
  is emitted. `VERIFIED`/`AVAILABLE` remains metadata/schema validity, not
  proof of score authenticity or playback.
- The projection now accepts an internal governed `media_asset_id` and emits
  only an owner-generated `/am-thanh/<opaque-id>/` delivery reference after
  Media active/readiness, PUBLIC visibility, safe MIME, path, size, checksum
  and supported audio magic checks. It never emits the internal asset id as a
  packet field or accepts a raw audio URL. Without that MediaAsset, the UI
  stays non-playable.
- Public audio delivery supports only `audio/mpeg`, `audio/ogg` and
  `audio/wav`, with MP3/OGG/WAV signature checks. AAC/M4A, FLAC and WebM audio
  remain unsupported and are rejected by the existing allowlist.
- Public delivery currently allowlists `audio/mpeg`, `audio/ogg` and
  `audio/wav` (plus image/video types); AAC/M4A, FLAC and WebM audio are not
  accepted by that boundary.

These are implementation/readiness findings, not a request to bypass the
contracts or to add a second template. The delivery and relation findings
were fixed locally in the existing v2 boundaries; score and asset readiness
remain separate governed decisions.

## Test and browser evidence

- Focused Music/Media/frontend contract run after the implementation: **210
  tests, 1,572 assertions, 5 warnings**, exit 0.
- Contract suite: **6 tests, 48 assertions**, exit 0.
- Full Unit at 512M: **3,587 tests, 22,173 assertions, 14 errors, 5
  failures, 28 warnings, 58 deprecations and 66 PHPUnit deprecations**. The
  errors are concentrated in existing Knowledge identity and one Article
  receipt test; the failures include existing Knowledge writer/audit and
  documentation snapshot expectations. No Westminster audio accuracy test
  exists, and no audio binary was available to test.
- Integration: **147 tests; 22 environment failures and 125 skipped** because
  `NHK_WP_TEST_PATH=public` and the authorized NHK V3 test database identity
  were not available. No integration mutation was attempted.
- Read-only browser QA on the deployed Westminster route succeeded for
  desktop, 390×844 mobile and 768×1024 tablet viewport checks: the page had a
  main landmark, H1, skip link, no horizontal overflow and the Music dossier.
  Audio count was zero at all sizes. The local checkout was not separately
  served, so local-build playback/SEO read-back remains unverified.
- Current route SEO contains a canonical/OG URL, description, robots policy and
  JSON-LD `CollectionPage` plus `BreadcrumbList`; it does not contain a score,
  `MusicComposition`, or audio delivery node because those data are absent.
  The deployed build also has zero `<audio>` elements and no score/audio
  sections; the local delivery/relation changes were not deployed.

## Blockers

1. Official sources disagree on at least the first quarter pitch spelling
   (G♯ versus G); octave, cents/tuning and mechanical timing are unresolved.
2. The historical notation witness is not yet edition/page-image adjudicated
   into a canonical score version.
3. No accepted Knowledge/Source/Evidence records exist for Westminster's
   research claims; current public “Tri thức” is empty.
4. No governed Piano, Bell Simulation or Historical Recording MediaAsset exists.
5. No governed Westminster reference packet or MediaAsset exists yet. The
   local projection now distinguishes partial metadata from public playback,
   and the local template renders direct/derived relation context; deployed
   read-back still cannot verify those changes.
6. Authorized Integration runtime is unavailable; no Governance acceptance or
   canonical read-back for candidates can be performed in this environment.

## Next safe action

1. Have a bell/clock music specialist adjudicate the exact historical edition,
   the G♯/G discrepancy, sounding octave, tuning convention, duration policy,
   and the Cambridge/Royal Exchange/Westminster arrangement label.
2. Submit only the atomic candidates above through the existing Capture and
   Governance workflow after duplicate search and exact Source/Evidence
   locator capture. Do not reuse unresolved V2 rows as evidence.
3. Once the score reaches governed `VERIFIED`, generate original Piano and
   Bell Simulation renders from the same event list, with separate mode labels,
   checksums, instrument/render/rights metadata and audio QA.
4. Ingest those binaries through the existing Media owner and public delivery
   path, then perform canonical read-back before exposing controls.
5. Reconcile each Variant/Movement/Model/Brand/Article/Video/Media relation
   with direct/derived origin and evidence scope; suppress any edge that cannot
   prove the exact subject. Repeat desktop/mobile/tablet and SEO/accessibility
   QA only after the matching build is deployed through the approved path.

No deployment, push, migration, import, seed, direct canonical mutation or
audio reuse was performed for this report.
