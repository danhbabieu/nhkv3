<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/**
 * Deterministic transient classification for editorial Claim reuse.
 *
 * This policy never changes canonical Claim state. It only annotates a
 * retrieval read model with applicability and public-composability decisions.
 */
final class EditorialSemanticRolePolicy
{
    public const GROUNDING = 'GROUNDING';
    public const PROVENANCE_ONLY = 'PROVENANCE_ONLY';
    public const READER_FACT = 'READER_FACT';
    public const SUPPORTING_CONTEXT = 'SUPPORTING_CONTEXT';
    public const SPECIMEN_CONTEXT = 'SPECIMEN_CONTEXT';
    public const CONTROL_ONLY = 'CONTROL_ONLY';

    public const DISCOVERED = 'DISCOVERED';
    public const ELIGIBLE = 'ELIGIBLE';
    public const APPLICABLE = 'APPLICABLE';
    public const SELECTED = 'SELECTED';
    public const PUBLICLY_COMPOSABLE = 'PUBLICLY_COMPOSABLE';

    /** @param array<string,mixed> $candidate @param array<string,mixed> $subject @param array<string,mixed> $context @return array<string,mixed> */
    public function classify(array $candidate, array $subject, array $context = []): array
    {
        $role = $this->role($candidate);
        $applicability = $this->applicability($candidate, $subject);
        $eligible = ($candidate['eligibility'] ?? '') === 'eligible';
        $publiclyComposable = $eligible && $applicability === 'applicable' && in_array($role, [self::READER_FACT, self::SUPPORTING_CONTEXT, self::SPECIMEN_CONTEXT], true);
        $state = !$eligible
            ? self::DISCOVERED
            : ($applicability !== 'applicable' ? 'INAPPLICABLE' : self::APPLICABLE);
        $reason = $this->reason($role, $applicability, $eligible, $candidate);
        $utility = is_array($candidate['utility'] ?? null) ? $candidate['utility'] : [];
        $utility['editorial'] = $this->editorialUtility($candidate, $role, $applicability, $context);

        return $candidate + [
            'semantic_role' => $role,
            'applicability' => $applicability,
            'state' => $state,
            'publicly_composable' => $publiclyComposable,
            'editorial_utility' => $utility['editorial'],
            'utility' => $utility,
            'reason' => $reason,
        ];
    }

    /** @param array<string,mixed> $candidate */
    private function role(array $candidate): string
    {
        $type = strtolower(trim((string) ($candidate['claim_type'] ?? $candidate['type'] ?? '')));
        $provenance = strtoupper(trim((string) ($candidate['provenance'] ?? '')));
        $scope = strtolower(trim((string) ($candidate['scope'] ?? '')));

        if (in_array($type, ['control', 'diagnostic', 'lifecycle', 'governance'], true)) return self::CONTROL_ONLY;
        if (in_array($type, ['provenance', 'source', 'identity_provenance'], true) || in_array($provenance, ['SYSTEM_INFERENCE', 'EXTERNAL_RESEARCH'], true) && $this->provenanceOnlyShape($candidate)) return self::PROVENANCE_ONLY;
        if ($type === 'identity' || $scope === 'identity-only') return self::GROUNDING;
        if ($scope === 'specimen-only' || strtolower((string) ($candidate['subject_type'] ?? '')) === 'specimen') return self::SPECIMEN_CONTEXT;
        if (($candidate['retrieval_origin'] ?? '') === 'neighborhood') return self::SUPPORTING_CONTEXT;
        return self::READER_FACT;
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $subject */
    private function applicability(array $candidate, array $subject): string
    {
        $subjectId = trim((string) ($subject['id'] ?? ''));
        $candidateId = trim((string) ($candidate['subject_id'] ?? $candidate['original_subject']['id'] ?? ''));
        if ($subjectId === '' || $candidateId === '') return 'inapplicable';
        if ($candidateId === $subjectId) {
            if (($candidate['scope'] ?? '') === 'specimen-only' && strtolower((string) ($subject['type'] ?? '')) !== 'specimen') return 'inapplicable';
            return 'applicable';
        }

        $path = array_values(array_filter((array) ($candidate['graph_path'] ?? $candidate['relation_path'] ?? []), 'is_array'));
        if ($path === []) return 'inapplicable';
        $firstSource = (string) ($path[0]['source'] ?? '');
        if ($firstSource !== strtolower((string) ($subject['type'] ?? '')) . ':' . $subjectId) return 'inapplicable';
        if (($candidate['scope'] ?? '') === 'specimen-only') return 'inapplicable';
        if (in_array('SEMANTIC_SCOPE_NOT_APPLICABLE', (array) ($candidate['warnings'] ?? []), true) || in_array('SPECIMEN_SCOPE_LIMIT', (array) ($candidate['warnings'] ?? []), true)) return 'inapplicable';
        return ($candidate['scope_compatibility'] ?? 'compatible') === 'compatible' ? 'applicable' : 'inapplicable';
    }

    /** @param array<string,mixed> $candidate */
    private function provenanceOnlyShape(array $candidate): bool
    {
        $text = strtolower(trim((string) ($candidate['text'] ?? $candidate['claim_text'] ?? '')));
        $subjectName = strtolower(trim((string) ($candidate['resolved_primary_subject']['name'] ?? '')));
        return $subjectName === '' || $text === '' || ($candidate['claim_type'] ?? '') === 'provenance' || count(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [], static fn (string $word): bool => mb_strlen($word) >= 4)) <= 14;
    }

    /** @param array<string,mixed> $candidate */
    private function editorialUtility(array $candidate, string $role, string $applicability, array $context): float
    {
        if ($applicability !== 'applicable' || in_array($role, [self::GROUNDING, self::PROVENANCE_ONLY, self::CONTROL_ONLY], true)) return 0.0;
        $utility = is_array($candidate['utility'] ?? null) ? $candidate['utility'] : [];
        $base = (float) ($utility['total'] ?? $candidate['score'] ?? 0.0);
        $topic = strtolower(trim((string) ($context['topic'] ?? '')));
        $text = strtolower((string) ($candidate['text'] ?? ''));
        if ($topic !== '' && $text !== '') {
            $topicWords = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $topic) ?: []));
            $textWords = array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [])));
            $base += count(array_intersect($topicWords, $textWords)) / max(1, count(array_unique($topicWords)));
        }
        return round($base, 6);
    }

    /** @param array<string,mixed> $candidate */
    private function reason(string $role, string $applicability, bool $eligible, array $candidate): string
    {
        if (!$eligible) return 'candidate was discovered but did not pass Claim eligibility';
        if ($applicability !== 'applicable') return 'candidate path or semantic scope does not apply to the resolved subject';
        return match ($role) {
            self::PROVENANCE_ONLY => 'candidate supports identity or provenance trace but is not reader knowledge',
            self::GROUNDING => 'candidate grounds canonical identity but is not reader knowledge',
            self::CONTROL_ONLY => 'candidate is control metadata and cannot become public prose',
            self::SPECIMEN_CONTEXT => 'candidate is public-composable only within its exact specimen scope',
            self::SUPPORTING_CONTEXT => 'applicable neighbor provides bounded supporting context',
            default => 'applicable Claim provides reader-useful domain knowledge',
        };
    }
}
