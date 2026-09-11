# NHK V3 Deploy Documentation Verification Design

## Goal

Provide one safe operator command that builds the canonical documentation
snapshot before transfer, deploys the exact NHK Core artifact through the
existing allowlisted SSH/rsync adapter, and verifies the target MCP runtime
against the release documentation and package identities.

## Scope and boundaries

- The only deployment target is `demo.1945.vn`, matching the existing adapter
  allowlist.
- The wrapper operates on the current committed checkout. An optional fast-
  forward pull may update only a clean checkout; no pull may run with local
  changes.
- Canonical documentation remains repository-owned. The generated
  `resources/canonical-docs/` directory is a release artifact, not an editable
  source.
- The existing `RemoteDeploymentAdapter` remains the only file transfer
  boundary. The wrapper does not add GitHub, CI/CD, database or WordPress data
  transport.
- Verification uses the target's direct `/wp-json/nhk/v1/mcp` Streamable HTTP
  endpoint and calls only `initialize`, `tools/list`,
  `nhk.documentation.bootstrap` and `nhk.documentation.list`.
- Cache invalidation and PHP-FPM/OPcache restart remain host-specific manual
  operations. The wrapper never guesses or runs them.

## Release flow

```text
clean checkout → optional ff-only pull → Composer install
→ composer generate:mcp-docs → local manifest validation
→ rsync plugin artifact → direct MCP bootstrap/list
→ compare documentation_version, manifest_hash, build_identity and file hashes
```

Any failed stage stops the command. A successful transfer without matching MCP
read-back is not success.

## Failure contract

- Documentation generation/validation failure: `DOC_BUILD_FAILED`.
- Transfer failure: preserve the existing adapter reason code.
- Target MCP unavailable or malformed: `MCP_BOOTSTRAP_UNAVAILABLE`.
- Documentation identity or any per-file hash mismatch:
  `DOC_MANIFEST_MISMATCH`.
- Runtime package identity mismatch: `DEPLOYMENT_NOT_ACTIVE`.

The final output is JSON when requested and never includes credentials, SSH
arguments, request headers or response bodies beyond bounded safe identity
fields.

## Verification contract

The local expected manifest is loaded only after the canonical Composer
generator exits successfully. The expected package identity is the fingerprint
returned by `RemoteDeploymentAdapter::deploy()`, which uses the same exclusion
and hashing rules as runtime `build_identity`.

The target MCP probe validates HTTP success, JSON-RPC success, the advertised
modern protocol response, and the structured documentation bootstrap/list
payloads. It compares all manifest file entries by path and SHA-256, so a
changed canonical document cannot be hidden behind matching top-level hashes.
