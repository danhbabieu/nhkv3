# Universal Structured Semantic Intake Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add one bounded, ephemeral interpretation boundary that reuses the existing Dictionary detector and semantic planning services across Capture, Dictionary, Knowledge, Article, transcript, Video and Media text inputs.

**Architecture:** `StructuredSemanticInterpreter` owns no truth and returns an immutable `StructuredInterpretationPacket`. `TextInputInterpreter` remains a compatibility adapter for the existing Capture contract. Existing subject, Knowledge, Graph, Dictionary, Article and Governance services remain owners; consumers apply policy only after the shared packet.

**Tech Stack:** PHP 8.x, PHPUnit, existing NHK V3 PSR-4 plugin runtime, no new dependencies, no migration.

**Spec:** `docs/architecture/UNIVERSAL_STRUCTURED_SEMANTIC_INTAKE_CONTRACT.md`

## Global Constraints

- `nhk.capture.ingest` remains the canonical new-submission boundary.
- The packet is ephemeral and has no canonical identity or persistence path.
- Dictionary detection is lexical curation only; it is not identity, Knowledge, Evidence or Graph truth.
- Ambiguity fails closed; unknown valid lexical observations survive as review candidates.
- Scope, provenance and derived lineage are never widened or treated as corroboration.
- No schema/data/migration/deploy/backfill/Graph bulk write is allowed.

## Review Focus

- Existing Dictionary lexical segmentation must remain byte-for-byte compatible at the public result level.
- Numeric configurations and identifiers must not be recognized from punctuation or arbitrary prose alone.
- Generated Article/summary/SEO lineage must not become independent Knowledge/Evidence.
- Subject ambiguity and unregistered relation predicates must remain review-only.
- Transcript, Media and Video contexts must preserve source kind and observation provenance.

## Tasks

- [ ] Add red tests for packet shape, lexical parity, structural/identifier invariants, semantic candidates and lineage guards.
- [ ] Implement immutable packet and shared interpreter using the existing Dictionary detector.
- [ ] Make `TextInputInterpreter` a compatibility adapter without changing existing Capture output.
- [ ] Extend universal input context with source kind, lineage, raw reference and controlled trust/scope signals.
- [ ] Integrate shared interpretation into the existing enrichment/Capture seams and preserve relation Governance handoff.
- [ ] Add deterministic diagnostics, reuse/duplicate classifications and derived-output feedback protection.
- [ ] Run focused tests, all unit tests, lint, diff check, secret review and read-only regression probes where available.
- [ ] Update implementation-status documentation and commit the verified changes.
