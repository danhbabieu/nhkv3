# Video Semantic Ingest Contract

> Non-normative implementation contract under the sole Constitution. The
> Constitution controls if any text conflicts.

Workflow: `YouTube URL + user hint → source resolution → snapshot → transcript
policy → NHK lookup → relation candidates → optional Knowledge enrichment
planning → Hub classification → editorial package → SEO projection →
completeness → governed Video Proposal`.

The public MCP entry point is the existing governed `nhk.video.ingest`. It may
return a single preview packet with source, editorial, Hub, relation, SEO,
warning and ambiguity information. It never approves, applies or publishes.

Input is intentionally small: `url`, optional `user_hint`, optional
`intended_category`, optional already-resolved `intended_relations`, optional
`editorial_instruction` and optional `idempotency_key`. User hints are retained
as `USER_HINT`; they are high-value context, not Authority truth.

The source adapter is the only boundary allowed to call YouTube. The preferred
client is the official YouTube Data API with an environment-provided key. A
missing key is an explicit configuration/unavailable warning. No HTML scraping,
SSRF, arbitrary host or transcript workaround is permitted.

The Proposal payload reuses the dedicated Video metadata boundary for the
normalized source snapshot, transcript policy, editorial package, Hub result,
relation candidates, SEO package, provenance and source-rights state. It is not
WordPress post meta and does not create a second semantic store.

When configured, `VideoIntakeService` invokes an optional read-only Knowledge
enrichment seam after canonical semantic target resolution. The seam selects
one narrowest confidently supported subject per observation in the order
`specimen > variant > model > movement/brand`; it never copies an observation
upward or sideways. Equal candidates are ambiguous and produce no
proposal-ready candidate. Brand-only context does not infer a Variant.

Its output is the bounded `knowledge_enrichment` packet with `status`,
`subject`, `candidates`, `diagnostics`, `proposal_ready` and
`unresolved_reasons`. Each candidate exposes `classification`, `subject_id`,
`facet`, `scope`, `observation`, provenance summary and `proposal_ready`.
`same_claim` and ambiguous/unresolved candidates are never proposal-ready.
`new_claim` is proposal-ready only after the shared planner has resolved its
dependencies; `add_evidence` additionally requires canonical `source_id` and
`source_revision`.

Transcript text is source material, not an atomic Knowledge claim. An approved
read-only factual-observation extractor must return bounded observations with
provenance/locator. If no extractor is configured, the packet emits
`TRANSCRIPT_FACT_EXTRACTION_UNAVAILABLE` and creates no transcript candidate;
extractor failure is diagnostic and does not fail Video intake. Generated
editorial text is never passed to the Knowledge planner or represented as
Evidence.

At this phase Video does not resolve or create a canonical NHK Source entity.
The intake therefore passes no invented Source ID. If a future caller supplies
canonical `source_id` plus `source_revision`, the shared planner may produce a
proposal-ready `add_evidence` candidate. Without that canonical binding,
existing-claim evidence remains `same_claim`/review-only and the packet records
`SOURCE_RESOLUTION_NEEDED`; Video intake does not create Source or Evidence.

The seam is planning-only: it does not call Knowledge/Evidence repositories,
submit or approve proposals, apply mutations, or create Graph predicates. A
planner failure is diagnostic and fail-closed for enrichment while preserving
the complete Video intake preview and its existing `about` relation/proposal
flow. Same-claim and add-Evidence idempotency remain governed by the shared
Knowledge planner/factory; Video intake does not apply either result.

Same external identity plus same intent is idempotent. Existing identity means
reconcile/update candidate, never duplicate Video. Source changes require a
new governed review packet; NHK fields are not overwritten by source metadata.
