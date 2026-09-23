<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Semantic\SubjectResolutionService;
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
    ) {
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $interpretation @param list<array<string,mixed>> $assets @param array<string,mixed> $context */
    public function prepare(array $input, array $interpretation, array $assets = [], array $context = []): ContentPreparationResult
    {
        $fingerprint = $this->fingerprint($input, $interpretation, $assets, $context);
        $sources = $this->sources($input, $interpretation);
        $candidates = $this->candidateList($sources, $interpretation);
        $resolution = $this->subjects->resolveSources($sources);
        $gaps = $this->gaps($resolution, $candidates);
        $diagnostics = [
            'phase' => 'DISCOVER',
            'source_precedence' => ['canonical_uuid', 'stable_key', 'explicit_subject_hint', 'title_subject', 'body_mention'],
            'media_video' => $this->mediaVideoDiagnostics($input, $assets),
        ];
        $enrichment = ['status' => 'NOT_REQUESTED', 'items' => []];
        $reviewReasons = [];
        $blockers = [];

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
                $resolution = $this->subjects->resolveSources($sources);
                $gaps = $this->gaps($resolution, $candidates);
            }
        }

        $inventory = $this->inventory($resolution, $input, $interpretation);
        if (($inventory['status'] ?? 'available') === 'unavailable') $blockers[] = 'CANONICAL_INVENTORY_UNAVAILABLE';
        $related = array_values(array_merge($gaps['related_entities'], (array) ($inventory['candidates'] ?? [])));
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
            return new ContentPreparationResult('BLOCKED', $fingerprint, null, $candidates, $gaps, $plan, $enrichment, $diagnostics, $blockers, $reviewReasons);
        }
        if ($reviewReasons !== []) {
            return new ContentPreparationResult('REVIEW_REQUIRED', $fingerprint, null, $candidates, $gaps, $plan, $enrichment, $diagnostics, [], $reviewReasons);
        }

        $packet = SubjectResolutionPacket::fromResolution($resolution);
        if ($packet === null || $packet->status !== 'resolved') {
            return new ContentPreparationResult('REVIEW_REQUIRED', $fingerprint, null, $candidates, $gaps, $plan, $enrichment, $diagnostics, [], ['FINAL_SUBJECT_PACKET_INVALID']);
        }
        $diagnostics['phase'] = 'PREPARED';
        return new ContentPreparationResult('PREPARED', $fingerprint, $packet, $candidates, $gaps, $plan, $enrichment, $diagnostics);
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
