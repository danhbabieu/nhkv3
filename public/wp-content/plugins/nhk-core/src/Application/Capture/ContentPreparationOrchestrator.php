<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Semantic\SubjectResolutionService;
use NHK\Core\Application\Video\VideoStatementDecisionEngine;
use NHK\Core\Domain\Capture\SubjectResolutionPacket;

/**
 * Prepares canonical semantic context before a new Article is created.
 * Domain owners remain responsible for all durable semantic mutations.
 */
final class ContentPreparationOrchestrator
{
    /** @param callable(array<string,mixed>):array|null $canonicalInventory @param callable(array<string,mixed>):array|null $governedEnrichment */
    public function __construct(
        private SubjectResolutionService $subjects,
        private $canonicalInventory = null,
        private $governedEnrichment = null,
        private ?VideoStatementDecisionEngine $statementDecisions = null,
    ) {
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $interpretation @param list<array<string,mixed>> $assets @param array<string,mixed> $context */
    public function prepare(array $input, array $interpretation, array $assets = [], array $context = []): ContentPreparationResult
    {
        $fingerprint = $this->fingerprint($input, $interpretation, $assets, $context);
        [$authorityPacket, $subjectPrecedence] = $this->authoritativePacket($context);
        if ($authorityPacket !== null) {
            $sources = ['canonical_uuid' => [$authorityPacket->canonicalSubjectId], 'stable_key' => $authorityPacket->stableKey];
            $candidates = [];
            $resolution = $authorityPacket->toResolution();
        } else {
            $sources = $this->sources($input, $interpretation);
            $candidates = $this->candidateList($sources, $interpretation);
            $resolution = $this->subjects->resolveSources($sources);
        }
        $gaps = $this->gaps($resolution, $candidates);
        $diagnostics = [
            'phase' => 'DISCOVER',
            'source_precedence' => ['canonical_uuid', 'stable_key', 'explicit_subject_hint', 'title_subject', 'body_mention'],
            'media_video' => $this->mediaVideoDiagnostics($input, $assets),
            'subject_precedence' => $subjectPrecedence,
        ];
        $statementDecision = $this->statementDecisions ??= new VideoStatementDecisionEngine();
        $statementResult = $statementDecision->evaluate(
            is_array($interpretation['statements'] ?? null) ? $interpretation['statements'] : [],
            is_array($context['canonical_context'] ?? null) ? $context['canonical_context'] : [],
            is_array($context['evidence_context'] ?? null) ? $context['evidence_context'] : [],
            is_array($context['visual_context'] ?? null) ? $context['visual_context'] : [],
        );
        $decisionTrace = $statementResult->items();
        $constraintFindings = $statementResult->findings();
        $qualityDecision = $this->decisionQuality($constraintFindings);
        $repairRounds = max(0, min(3, (int) ($context['repair_rounds'] ?? 0)));
        $diagnostics['decision_pipeline'] = 'interpret_resolve_compare_classify_treat';
        $diagnostics['decision_trace_count'] = count($decisionTrace);
        $enrichment = ['status' => 'NOT_REQUESTED', 'items' => []];
        $reviewReasons = [];
        $blockers = [];
        if ($qualityDecision === 'HARD_BLOCK') $blockers[] = 'VIDEO_DECISION_HARD_BLOCK';
        elseif ($qualityDecision === 'REVIEW_REQUIRED') $reviewReasons[] = 'VIDEO_DECISION_REVIEW_REQUIRED';

        if (($resolution['status'] ?? '') === 'ambiguous' || ($resolution['conflicts'] ?? []) !== []) {
            $reviewReasons[] = 'PRIMARY_SUBJECT_AMBIGUOUS';
        }

        $requests = is_array($context['enrichment_requests'] ?? null) ? $context['enrichment_requests'] : [];
        if ($reviewReasons === [] && $requests !== []) {
            $enrichment = $this->enrich($requests, $input, $resolution, $fingerprint, $context);
            if (($enrichment['status'] ?? '') === 'BLOCKED') $blockers = array_values(array_unique(array_merge($blockers, array_map('strval', (array) ($enrichment['blockers'] ?? [])))));
            if (($enrichment['status'] ?? '') === 'REVIEW_REQUIRED') $reviewReasons = array_values(array_unique(array_merge($reviewReasons, array_map('strval', (array) ($enrichment['review_reasons'] ?? [])))));
            if (($enrichment['status'] ?? '') === 'VERIFIED') {
                $diagnostics['phase'] = 'RE_RESOLVE';
                if ($authorityPacket === null) $resolution = $this->subjects->resolveSources($sources);
                $gaps = $this->gaps($resolution, $candidates);
            }
        }

        $inventory = $this->inventory($resolution, $input, $interpretation);
        if (($inventory['status'] ?? 'available') === 'unavailable') $blockers[] = 'CANONICAL_INVENTORY_UNAVAILABLE';
        $related = $this->relatedEntities($gaps['related_entities'], $inventory['candidates'] ?? [], $resolution, $enrichment);
        $plan = [
            'primary_subject_candidate' => $resolution['primary'] ?? null,
            'related_entities' => $related,
            'semantic_owner_relations' => $this->semanticOwnerRelations($enrichment),
            'article_owned_relations' => [],
        ];
        $diagnostics['phase'] = 'LOCK_FINAL_SUBJECT_PACKET';
        $diagnostics['candidate_count'] = count($candidates);

        if (($resolution['status'] ?? '') !== 'resolved' && $reviewReasons === [] && $blockers === []) {
            $reviewReasons[] = 'PRIMARY_SUBJECT_NOT_RESOLVED';
        }
        if ($blockers !== []) {
            return new ContentPreparationResult('BLOCKED', $fingerprint, null, $candidates, $gaps, $plan, $enrichment, $diagnostics, $blockers, $reviewReasons, [], $decisionTrace, $constraintFindings, $qualityDecision, $repairRounds);
        }
        if ($reviewReasons !== []) {
            return new ContentPreparationResult('REVIEW_REQUIRED', $fingerprint, null, $candidates, $gaps, $plan, $enrichment, $diagnostics, [], $reviewReasons, [], $decisionTrace, $constraintFindings, $qualityDecision, $repairRounds);
        }

        $packet = SubjectResolutionPacket::fromResolution($resolution);
        if ($packet === null || $packet->status !== 'resolved') {
            return new ContentPreparationResult('REVIEW_REQUIRED', $fingerprint, null, $candidates, $gaps, $plan, $enrichment, $diagnostics, [], ['FINAL_SUBJECT_PACKET_INVALID'], [], $decisionTrace, $constraintFindings, $qualityDecision, $repairRounds);
        }
        $diagnostics['phase'] = 'PREPARED';
        return new ContentPreparationResult('PREPARED', $fingerprint, $packet, $candidates, $gaps, $plan, $enrichment, $diagnostics, [], [], [], $decisionTrace, $constraintFindings, $qualityDecision, $repairRounds);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $interpretation @param list<array<string,mixed>> $assets @param array<string,mixed> $context */
    private function fingerprint(array $input, array $interpretation, array $assets, array $context): string
    {
        $provided = trim((string) ($context['preparation_fingerprint'] ?? $input['preparation_fingerprint'] ?? ''));
        if ($provided !== '') return $provided;
        return hash('sha256', json_encode([
            'input' => $this->withoutBodies($input),
            'interpretation' => $this->withoutBodies($interpretation),
            'assets' => $this->withoutBodies($assets),
            'enrichment_requests' => $this->withoutBodies($context['enrichment_requests'] ?? []),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $interpretation @return array<string,mixed> */
    private function sources(array $input, array $interpretation): array
    {
        $mentions = array_values(array_unique(array_map('strval', (array) ($interpretation['entity_mentions'] ?? []))));
        return [
            'canonical_uuid' => $input['canonical_uuid'] ?? $input['subject_uuid'] ?? [],
            'stable_key' => $input['stable_key'] ?? $input['subject_stable_key'] ?? [],
            'subject_hints' => $input['subject_hints'] ?? $interpretation['primary_subject_hints'] ?? [],
            'title_subject' => $input['title_subject'] ?? $input['topic_subject'] ?? [],
            'body_mentions' => $mentions,
        ];
    }

    /** @param array<string,mixed> $sources @param array<string,mixed> $interpretation @return list<array<string,mixed>> */
    private function candidateList(array $sources, array $interpretation): array
    {
        $candidates = [];
        foreach ([
            'canonical_uuid' => $sources['canonical_uuid'] ?? [],
            'stable_key' => $sources['stable_key'] ?? [],
            'explicit_subject_hint' => $sources['subject_hints'] ?? [],
            'title_subject' => $sources['title_subject'] ?? [],
            'body_mention' => $sources['body_mentions'] ?? [],
        ] as $source => $values) {
            foreach ((array) $values as $value) {
                $value = trim((string) $value);
                if ($value !== '') $candidates[] = ['value' => $value, 'source' => $source, 'status' => 'CANDIDATE'];
            }
        }
        foreach ((array) ($interpretation['relation_hints'] ?? []) as $hint) {
            if (is_array($hint) && trim((string) ($hint['target'] ?? '')) !== '') $candidates[] = ['value' => trim((string) $hint['target']), 'source' => 'relation_mention', 'status' => 'CANDIDATE'];
        }
        $unique = [];
        foreach ($candidates as $candidate) $unique[$candidate['source'] . ':' . $candidate['value']] = $candidate;
        return array_values($unique);
    }

    /** @param array<string,mixed> $resolution @param list<array<string,mixed>> $candidates @return array<string,mixed> */
    private function gaps(array $resolution, array $candidates): array
    {
        $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : null;
        $resolvedIds = array_fill_keys(array_map(static fn (array $item): string => (string) ($item['id'] ?? ''), array_values(array_filter((array) ($resolution['resolved'] ?? []), 'is_array'))), true);
        $related = [];
        foreach ($candidates as $candidate) {
            $related[] = [
                'value' => $candidate['value'],
                'source' => $candidate['source'],
                'status' => $primary !== null && isset($resolvedIds[(string) ($primary['id'] ?? '')]) && $candidate['value'] === (string) ($primary['name'] ?? '') ? 'EXISTING' : 'CANDIDATE',
            ];
        }
        return [
            'primary_subject' => $primary === null ? 'MISSING' : (($resolution['status'] ?? '') === 'ambiguous' ? 'AMBIGUOUS' : 'EXISTING'),
            'related_entities' => $related,
            'missing_entities' => $primary === null ? array_values(array_map(static fn (array $item): string => (string) $item['value'], $candidates)) : [],
            'missing_knowledge' => [],
            'missing_relations' => [],
            'claims_needing_review' => [],
        ];
    }

    /** @param list<array<string,mixed>> $requests @param array<string,mixed> $input @param array<string,mixed> $resolution */
    private function enrich(array $requests, array $input, array $resolution, string $fingerprint, array $context): array
    {
        if (!is_callable($this->governedEnrichment)) return ['status' => 'BLOCKED', 'items' => [], 'blockers' => ['GOVERNED_ENRICHMENT_UNAVAILABLE']];
        $items = [];
        foreach ($requests as $request) {
            if (!is_array($request)) continue;
            $locator = trim((string) ($request['locator'] ?? $request['stable_key'] ?? $request['canonical_uuid'] ?? ''));
            if ($locator !== '') {
                $existing = $this->subjects->resolve([$locator]);
                $existingPrimary = is_array($existing['primary'] ?? null) ? $existing['primary'] : null;
                if (($existing['status'] ?? '') === 'resolved' && $existingPrimary !== null) {
                    $items[] = [
                        'request' => $this->withoutBodies($request),
                        'canonical_readback' => [
                            'canonical_id' => (string) ($existingPrimary['id'] ?? ''),
                            'revision' => max(1, (int) ($existingPrimary['revision'] ?? 1)),
                            'status' => 'VERIFIED',
                        ],
                        'result' => ['status' => 'REUSED'],
                    ];
                    continue;
                }
            }
            if (($request['evidence_supported'] ?? false) !== true) return ['status' => 'REVIEW_REQUIRED', 'items' => $items, 'review_reasons' => ['INSUFFICIENT_ENRICHMENT_EVIDENCE']];
            $readback = ($this->governedEnrichment)([
                'input' => $this->withoutBodies($input),
                'request' => $request,
                'current_resolution' => $resolution,
                'preparation_fingerprint' => $fingerprint,
                'capture_id' => (string) ($context['capture_id'] ?? ''),
                'governance' => is_array($context['governance'] ?? null) ? $context['governance'] : [],
            ]);
            if (!is_array($readback) || !in_array(strtoupper((string) ($readback['status'] ?? '')), ['APPLIED', 'VERIFIED', 'REUSED', 'IDEMPOTENT'], true)) {
                return ['status' => 'BLOCKED', 'items' => $items, 'blockers' => ['GOVERNED_ENRICHMENT_READBACK_UNAVAILABLE']];
            }
            $items[] = ['request' => $this->withoutBodies($request), 'canonical_readback' => $readback['canonical_readback'] ?? [], 'result' => $this->withoutBodies($readback)];
        }
        return ['status' => 'VERIFIED', 'items' => $items];
    }

    /** @param array<string,mixed> $resolution @param array<string,mixed> $input @param array<string,mixed> $interpretation @return array<string,mixed> */
    private function inventory(array $resolution, array $input, array $interpretation): array
    {
        if (!is_callable($this->canonicalInventory)) return ['status' => 'available', 'candidates' => []];
        $result = ($this->canonicalInventory)(['resolution' => $resolution, 'input' => $this->withoutBodies($input), 'interpretation' => $this->withoutBodies($interpretation)]);
        return is_array($result) ? $result : ['status' => 'unavailable', 'candidates' => []];
    }

    /** @param list<array<string,mixed>> $gaps @param mixed $inventoryCandidates @param array<string,mixed> $resolution @param array<string,mixed> $enrichment @return list<array<string,mixed>> */
    private function relatedEntities(array $gaps, mixed $inventoryCandidates, array $resolution, array $enrichment): array
    {
        $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        $primaryKeys = array_values(array_filter([
            (string) ($primary['id'] ?? ''),
            (string) ($primary['stable_key'] ?? ''),
            (string) ($primary['name'] ?? ''),
        ]));
        $related = [];
        foreach ($gaps as $candidate) {
            if (!is_array($candidate)) continue;
            $value = trim((string) ($candidate['value'] ?? $candidate['name'] ?? ''));
            if ($value === '' || in_array($value, $primaryKeys, true)) continue;
            $related[] = $candidate;
        }
        $allowed = array_fill_keys(array_values(array_filter(array_map(
            static fn (array $candidate): string => trim((string) ($candidate['value'] ?? $candidate['name'] ?? $candidate['id'] ?? '')),
            $related,
        ))), true);
        foreach ((array) ($resolution['resolved'] ?? []) as $resolved) {
            if (!is_array($resolved)) continue;
            if ((string) ($resolved['id'] ?? '') === (string) ($primary['id'] ?? '')) continue;
            foreach ([(string) ($resolved['id'] ?? ''), (string) ($resolved['stable_key'] ?? ''), (string) ($resolved['name'] ?? '')] as $key) {
                if ($key !== '') $allowed[$key] = true;
            }
        }
        foreach ((array) $inventoryCandidates as $candidate) {
            if (!is_array($candidate)) continue;
            $keys = array_values(array_filter([
                (string) ($candidate['id'] ?? ''),
                (string) ($candidate['stable_key'] ?? ''),
                (string) ($candidate['name'] ?? $candidate['value'] ?? ''),
            ]));
            if (array_intersect($keys, array_keys($allowed)) === []) continue;
            $related[] = $candidate;
        }
        foreach ((array) ($enrichment['items'] ?? []) as $item) {
            foreach ((array) (($item['result']['related_entities'] ?? [])) as $candidate) {
                if (is_array($candidate)) $related[] = $candidate;
            }
        }
        $unique = [];
        foreach ($related as $candidate) {
            $identity = trim((string) ($candidate['name'] ?? $candidate['value'] ?? ''));
            $key = $identity !== '' ? $identity : ((string) ($candidate['id'] ?? '') . '|' . (string) ($candidate['stable_key'] ?? ''));
            if ($key !== '|') $unique[$key] = $candidate;
        }
        return array_values($unique);
    }

    /** @param array<string,mixed> $enrichment @return list<array<string,mixed>> */
    private function semanticOwnerRelations(array $enrichment): array
    {
        $relations = [];
        foreach ((array) ($enrichment['items'] ?? []) as $item) foreach ((array) (($item['result']['semantic_owner_relations'] ?? [])) as $relation) if (is_array($relation)) $relations[] = $relation;
        return $relations;
    }

    /** @param array<string,mixed> $input @param list<array<string,mixed>> $assets @return array<string,mixed> */
    private function mediaVideoDiagnostics(array $input, array $assets): array
    {
        return [
            'status' => ($assets !== [] || is_array($input['video'] ?? null)) ? 'candidate_input' : 'not_present',
            'media_count' => count($assets),
            'video_present' => is_array($input['video'] ?? null),
            'semantic_promotion' => false,
        ];
    }

    private function decisionQuality(array $findings): string
    {
        foreach ($findings as $finding) if (($finding['severity'] ?? '') === 'HARD_BLOCK') return 'HARD_BLOCK';
        foreach ($findings as $finding) if (($finding['severity'] ?? '') === 'REVIEW_REQUIRED') return 'REVIEW_REQUIRED';
        return 'READY';
    }

    /** @return array{0:?SubjectResolutionPacket,1:list<string>} */
    private function authoritativePacket(array $context): array
    {
        $precedence = [];
        $reconciliation = is_array($context['subject_reconciliation'] ?? null) ? $context['subject_reconciliation'] : [];
        $authority = strtoupper(trim((string) ($reconciliation['authority'] ?? '')));
        $explicit = $authority !== '' && in_array($authority, ['USER_CONFIRMED_SUBJECT_RECONCILIATION', 'GOVERNED_SUBJECT_RECONCILIATION'], true)
            ? (is_array($reconciliation['packet'] ?? null) ? SubjectResolutionPacket::fromArray($reconciliation['packet']) : null)
            : null;
        if ($explicit !== null && $explicit->status === 'resolved') {
            return [$explicit, ['EXPLICIT_SUBJECT_RECONCILIATION_APPLIED']];
        }
        if ($authority !== '') $precedence[] = 'EXPLICIT_SUBJECT_RECONCILIATION_INVALID';

        $persisted = is_array($context['persisted_subject_resolution_packet'] ?? null)
            ? SubjectResolutionPacket::fromArray($context['persisted_subject_resolution_packet'])
            : null;
        if ($persisted !== null && $persisted->status === 'resolved') {
            if ($this->packetIsCurrent($persisted, $context)) return [$persisted, ['PERSISTED_RESOLVED_SUBJECT_REUSED']];
            $precedence[] = 'PERSISTED_SUBJECT_REOPENED';
        }
        return [null, $precedence];
    }

    private function packetIsCurrent(SubjectResolutionPacket $packet, array $context): bool
    {
        if (($context['canonical_subject_retired'] ?? false) === true || ($context['subject_resolution_revision_valid'] ?? true) !== true) return false;
        $readback = is_array($context['canonical_subject_readback'] ?? null) ? $context['canonical_subject_readback'] : [];
        if ($readback === []) return true;
        $status = strtolower(trim((string) ($readback['status'] ?? 'active')));
        if (in_array($status, ['retired', 'missing', 'inactive'], true)) return false;
        if (isset($readback['canonical_id']) && strtolower((string) $readback['canonical_id']) !== strtolower($packet->canonicalSubjectId)) return false;
        if (isset($readback['entity_type']) && strtolower((string) $readback['entity_type']) !== strtolower($packet->entityType)) return false;
        if (isset($readback['revision']) && (int) $readback['revision'] !== $packet->revision) return false;
        return true;
    }

    private function withoutBodies(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) $result[$key] = in_array((string) $key, ['raw_input', 'content', 'body', 'post_content'], true) ? '[omitted]' : $this->withoutBodies($item);
            return $result;
        }
        return $value;
    }
}
