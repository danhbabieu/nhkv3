<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Video\YouTubeUrlNormalizer;

/**
 * Shared read-only preflight vocabulary for every Capture adapter.
 *
 * This report is diagnostic state only. It does not authorize a write and it
 * deliberately keeps missing, pending, ambiguous and blocked dependencies
 * distinct so adapters cannot turn an incomplete plan into CONTENT_COMPLETE.
 */
final class CaptureSemanticPreflight
{
    /** @param array<string,mixed> $input @param array<string,mixed> $interpretation @param array<string,mixed> $intent @param array<string,mixed> $resolution @param array<string,mixed> $context */
    public function evaluate(array $input, array $interpretation, array $intent, array $resolution, array $context = []): array
    {
        $checks = [
            'intent' => $this->intent($intent, $input),
            'source' => $this->source($input, $intent),
            'canonical_identity' => $this->identity($resolution),
            'duplicate_reuse' => $this->decision($context['duplicate_reuse'] ?? $input['duplicate_reuse'] ?? null, 'DUPLICATE_REUSE_NOT_EVALUATED'),
            'subject_compatibility' => $this->compatibility($resolution, $context),
            'provenance' => $this->provenance($input, $context, $intent),
            'scope' => $this->scope($resolution, $context),
            'evidence' => $this->evidence($input, $context),
            'graph_eligibility' => $this->graph($input, $context),
            'content_relevance' => $this->decision($context['content_relevance'] ?? $input['content_relevance'] ?? null, 'CONTENT_RELEVANCE_PENDING'),
        ];

        $statuses = array_map(static fn (array $check): string => (string) ($check['status'] ?? 'INCOMPLETE'), $checks);
        $overall = in_array('BLOCKED', $statuses, true)
            ? 'BLOCKED'
            : (in_array('UNAVAILABLE', $statuses, true) ? 'UNAVAILABLE' : (in_array('INCOMPLETE', $statuses, true) ? 'INCOMPLETE' : 'READY'));

        return ['status' => $overall, 'checks' => $checks, 'diagnostics' => ['vocabulary' => 'capture-semantic-preflight-v1', 'interpretation_available' => $interpretation !== []]];
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $input */
    private function intent(array $intent, array $input): array
    {
        $status = strtolower(trim((string) ($intent['status'] ?? '')));
        if ($status === 'resolved' && trim((string) ($intent['intent'] ?? '')) !== '') return $this->check('READY', ['INTENT_RESOLVED'], ['intent' => strtoupper((string) $intent['intent'])]);
        if ($status === 'ambiguous') return $this->check('INCOMPLETE', ['CONTENT_INTENT_AMBIGUOUS']);
        return $this->check('BLOCKED', ['CONTENT_INTENT_INVALID_OR_MISSING']);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $intent */
    private function source(array $input, array $intent): array
    {
        $kind = strtoupper(trim((string) ($intent['intent'] ?? $input['intent'] ?? '')));
        $url = trim((string) ($input['video']['url'] ?? ''));
        if ($kind === 'VIDEO' && $url !== '') {
            try { YouTubeUrlNormalizer::normalize($url); return $this->check('READY', ['SOURCE_IDENTITY_VALID']); } catch (\Throwable) { return $this->check('BLOCKED', ['SOURCE_IDENTITY_INVALID']); }
        }
        foreach (['source', 'source_identity', 'provenance_packets'] as $key) if (($input[$key] ?? null) !== null && $input[$key] !== [] && $input[$key] !== '') return $this->check('READY', ['SOURCE_CONTEXT_PRESENT']);
        return $this->check('INCOMPLETE', ['SOURCE_CONTEXT_MISSING']);
    }

    /** @param array<string,mixed> $resolution */
    private function identity(array $resolution): array
    {
        return match (strtolower(trim((string) ($resolution['status'] ?? 'unresolved')))) {
            'resolved' => $this->check('READY', ['CANONICAL_IDENTITY_RESOLVED']),
            'ambiguous' => $this->check('INCOMPLETE', ['CANONICAL_IDENTITY_AMBIGUOUS']),
            'conflict' => $this->check('BLOCKED', ['CANONICAL_IDENTITY_CONFLICT']),
            default => $this->check('INCOMPLETE', ['PRIMARY_SUBJECT_NOT_RESOLVED']),
        };
    }

    /** @param array<string,mixed> $resolution @param array<string,mixed> $context */
    private function compatibility(array $resolution, array $context): array
    {
        if (($resolution['status'] ?? '') === 'conflict') return $this->check('BLOCKED', ['SUBJECT_COMPATIBILITY_CONFLICT']);
        if (($context['subject_compatibility']['status'] ?? '') === 'BLOCKED') return $this->check('BLOCKED', [(string) ($context['subject_compatibility']['reason'] ?? 'SUBJECT_COMPATIBILITY_CONFLICT')]);
        return ($resolution['status'] ?? '') === 'resolved' ? $this->check('READY', ['SUBJECT_COMPATIBLE']) : $this->check('INCOMPLETE', ['SUBJECT_COMPATIBILITY_PENDING']);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $context @param array<string,mixed> $intent */
    private function provenance(array $input, array $context, array $intent): array
    {
        if (($context['provenance']['status'] ?? '') !== '') return $this->decision($context['provenance'], 'PROVENANCE_PENDING');
        if (strtoupper((string) ($intent['intent'] ?? $input['intent'] ?? '')) === 'VIDEO' && ($input['relations'] ?? []) === []) return $this->check('NOT_APPLICABLE', ['STANDALONE_VIDEO_SOURCE_DEFERRED']);
        return $this->check('INCOMPLETE', ['PROVENANCE_PENDING']);
    }

    /** @param array<string,mixed> $resolution @param array<string,mixed> $context */
    private function scope(array $resolution, array $context): array
    {
        if (($context['scope']['status'] ?? '') !== '') return $this->decision($context['scope'], 'SCOPE_PENDING');
        return ($resolution['status'] ?? '') === 'resolved' ? $this->check('READY', ['SUBJECT_SCOPE_BOUNDED']) : $this->check('INCOMPLETE', ['SCOPE_PENDING']);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $context */
    private function evidence(array $input, array $context): array
    {
        if (($context['evidence']['status'] ?? '') !== '') return $this->decision($context['evidence'], 'EVIDENCE_PENDING');
        $refs = (array) ($input['evidence_refs'] ?? $input['evidence'] ?? []);
        return $refs === [] ? $this->check('INCOMPLETE', ['EVIDENCE_NOT_VERIFIED']) : $this->check('READY', ['EVIDENCE_REFERENCES_PRESENT']);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $context */
    private function graph(array $input, array $context): array
    {
        if (($input['relations'] ?? []) === [] && ($context['graph_eligibility'] ?? null) === null) return $this->check('NOT_APPLICABLE', ['NO_RELATION_REQUESTED']);
        return $this->decision($context['graph_eligibility'] ?? null, 'GRAPH_ELIGIBILITY_PENDING');
    }

    /** @param mixed $value */
    private function decision(mixed $value, string $fallbackReason): array
    {
        if (is_array($value) && trim((string) ($value['status'] ?? '')) !== '') {
            $status = strtoupper(trim((string) $value['status']));
            return $this->check($status, array_values(array_map('strval', (array) ($value['reason_codes'] ?? $value['reasons'] ?? [$fallbackReason]))));
        }
        return $this->check('INCOMPLETE', [$fallbackReason]);
    }

    /** @param list<string> $reasonCodes @param array<string,mixed> $extra */
    private function check(string $status, array $reasonCodes, array $extra = []): array
    {
        return ['status' => $status, 'reason_codes' => array_values(array_unique($reasonCodes))] + $extra;
    }
}
