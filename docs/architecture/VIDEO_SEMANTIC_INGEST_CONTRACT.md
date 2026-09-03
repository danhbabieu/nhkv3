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
enrichment seam after canonical semantic target resolution. Its output is the
`knowledge_enrichment` packet with `status`, `candidates` and `diagnostics`.
Only resolved Authority targets may become candidates; ambiguity, unavailable
resolution or planner failure never creates a candidate. `USER_HINT` and an
authorized transcript retain their own provenance. YouTube metadata is source
input only, and generated editorial text is never passed to the Knowledge
planner or represented as Evidence.

The seam is planning-only: it does not call Knowledge/Evidence repositories,
submit or approve proposals, apply mutations, or create Graph predicates. A
planner failure is diagnostic and fail-closed for enrichment while preserving
the complete Video intake preview and its existing `about` relation/proposal
flow. Same-claim and add-Evidence idempotency remain governed by the shared
Knowledge planner/factory; Video intake does not apply either result.

Same external identity plus same intent is idempotent. Existing identity means
reconcile/update candidate, never duplicate Video. Source changes require a
new governed review packet; NHK fields are not overwritten by source metadata.
