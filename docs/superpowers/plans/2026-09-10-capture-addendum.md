# Capture addendum implementation plan — 2026-09-10

1. Add RED unit coverage for same Capture/Post continuation, addendum replay
   and changed-payload conflict; prove no files are re-adopted and the semantic
   callback receives the merged scoped input.
2. Add the additive Capture addendum domain/repository/migration boundary and
   extend the Capture repository with canonical Capture-ID read-back.
3. Add the continuation application service and route it through the existing
   `nhk.capture.ingest` dispatch/schema without adding a second normal writer.
4. Run focused, contract and relevant integration tests; update canonical MCP,
   Article, Knowledge and execution-state evidence to match the runtime.
5. Run PHP lint, Composer validation, `git diff --check`, secret review and
   fresh final verification. DEMO deployment/read-back remains gated by the
   configured deployment contract and authenticated runtime.
