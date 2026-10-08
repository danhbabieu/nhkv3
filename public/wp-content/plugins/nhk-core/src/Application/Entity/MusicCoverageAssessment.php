<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Domain\Authority\AuthorityEntity;

/** Read-only coverage diagnostics for every canonical Music entity. */
final class MusicCoverageAssessment
{
    public function __construct(private MusicDataCollectionStandard $standard = new MusicDataCollectionStandard())
    {
    }

    /** @return array<string,mixed> */
    public function assess(AuthorityEntity $entity, array $dossier = [], ?array $referencePacket = null): array
    {
        if ($entity->entityType !== 'music') {
            return ['status' => 'UNAVAILABLE', 'reason' => 'MUSIC_ENTITY_REQUIRED', 'categories' => []];
        }

        $categories = [];
        $fieldsByCategory = [];
        foreach ($this->standard->fields() as $field) $fieldsByCategory[$field['category_key']][] = $field;
        foreach ($this->standard->categories() as $letter => $category) {
            $fieldResults = [];
            foreach ($fieldsByCategory[$letter] ?? [] as $field) {
                $fieldResults[] = $this->fieldResult($field, $entity, $dossier, $referencePacket);
            }
            $categories[$letter] = [
                'category_key' => $letter,
                'display_name_vi' => $category['display_name_vi'],
                'applicability' => $category['applicability'],
                'status' => $this->aggregateStatus($fieldResults),
                'fields' => $fieldResults,
            ];
        }

        $summary = ['complete' => 0, 'verified_not_public' => 0, 'research_gaps' => 0, 'rights_blockers' => 0, 'unsupported_relations' => 0];
        foreach ($categories as $category) {
            if (in_array($category['status'], ['VERIFIED', 'PUBLIC_READY', 'NOT_APPLICABLE'], true)) $summary['complete']++;
            if ($category['status'] === 'VERIFIED') $summary['verified_not_public']++;
            if (in_array($category['status'], ['MISSING', 'UNKNOWN', 'CANDIDATE', 'DISPUTED'], true)) $summary['research_gaps']++;
            if ($category['status'] === 'BLOCKED') $summary['rights_blockers']++;
            if ($category['category_key'] === 'V' && in_array($category['status'], ['MISSING', 'CANDIDATE', 'DISPUTED', 'BLOCKED'], true)) $summary['unsupported_relations']++;
        }

        $next = $this->nextTask($categories);
        return [
            'status' => $summary['research_gaps'] === 0 && $summary['rights_blockers'] === 0 ? 'COMPLETE' : 'PARTIAL',
            'subject' => ['type' => 'music', 'name' => $entity->canonicalName],
            'summary' => $summary,
            'categories' => $categories,
            'next_highest_value_task' => $next,
            'reuse' => $this->reuseSignals($dossier),
        ];
    }

    /** @return array<string,mixed> */
    private function fieldResult(array $field, AuthorityEntity $entity, array $dossier, ?array $referencePacket): array
    {
        $status = $this->statusForField((string) $field['field_name'], $entity, $dossier, $referencePacket);
        return [
            'field_name' => $field['field_name'],
            'display_name_vi' => $field['display_name_vi'],
            'applicability' => $field['applicability'] !== '' ? $field['applicability'] : 'RECOMMENDED',
            'status' => $status,
        ];
    }

    private function statusForField(string $field, AuthorityEntity $entity, array $dossier, ?array $packet): string
    {
        $identity = is_array($dossier['identity'] ?? null) ? $dossier['identity'] : [];
        $facets = is_array($dossier['knowledge']['facets'] ?? null) ? $dossier['knowledge']['facets'] : [];
        $relations = is_array($dossier['relation_sections'] ?? null) ? $dossier['relation_sections'] : [];
        $reference = $packet === null ? ['score' => null, 'audio' => []] : (new MusicReferenceContract())->normalize($packet);

        return match ($field) {
            'canonical_name', 'identity_scope', 'preferred_title' => $entity->canonicalName !== '' ? 'VERIFIED' : 'MISSING',
            'alias' => !empty($identity['aliases']) ? 'VERIFIED' : 'MISSING',
            'title_language' => !empty($identity['language']) ? 'VERIFIED' : 'UNKNOWN',
            'origin_place', 'origin_date', 'origin_statement' => $this->claimStatus($facets, ['origin']),
            'composer_attribution', 'attribution_status' => $this->claimStatus($facets, ['authorship', 'composer']),
            'timeline_event' => $this->claimStatus($facets, ['chronology', 'history']),
            'cultural_context' => $this->claimStatus($facets, ['context', 'purpose', 'significance']),
            'form_description', 'phrase_sequence' => $this->claimStatus($facets, ['structure', 'music']),
            'notation_witness' => $this->claimStatus($facets, ['notation', 'structure']),
            'score_edition' => $reference['score'] !== null ? (($reference['score']['verification_status'] ?? '') === 'VERIFIED' ? 'VERIFIED' : 'CANDIDATE') : 'MISSING',
            'arrangement_variant' => $this->claimStatus($facets, ['arrangement', 'variant', 'structure']),
            'pitch_assertion' => $this->claimStatus($facets, ['pitch', 'structure', 'music', 'history']),
            'rhythm_tempo_tuning' => $reference['score'] !== null ? 'CANDIDATE' : 'MISSING',
            'piano_reference' => $this->audioStatus($reference['audio'], 'PIANO'),
            'bell_reference' => $this->audioStatus($reference['audio'], 'BELL_SIMULATION'),
            'historical_recording' => $this->audioStatus($reference['audio'], 'HISTORICAL_RECORDING'),
            'clock_mechanism_context' => !empty($relations['movements']) || $this->hasClaims($facets, ['mechanism', 'history']) ? 'CANDIDATE' : 'MISSING',
            'clock_model_compatibility' => $this->relationStatus($relations, ['brands', 'models', 'variants', 'movements']),
            'documented_specimen' => $this->relationStatus($relations, ['specimens']),
            'image_diagram' => !empty($dossier['media_gallery']) ? 'CANDIDATE' : 'MISSING',
            'video_reference' => $this->relationStatus($relations, ['videos']),
            'catalogue_archive' => $this->claimStatus($facets, ['catalogue', 'archive', 'research']),
            'knowledge_dictionary_entry' => !empty($dossier['dictionary_terms']) || $this->hasClaims($facets, array_keys($facets)) ? 'CANDIDATE' : 'MISSING',
            'registered_relation' => $this->relationStatus($relations, array_keys($relations)),
            'source_evidence_bundle', 'evidence_locator' => $this->claimStatus($facets, array_keys($facets)),
            'rights_license' => $this->rightsStatus($reference['audio'], $dossier),
            'public_presentation' => (($dossier['status'] ?? '') === 'AVAILABLE' && !empty($identity['url'])) ? 'PUBLIC_READY' : 'MISSING',
            'review_coverage' => !empty($dossier['coverage']['reviewed']) ? 'VERIFIED' : 'MISSING',
            default => 'UNKNOWN',
        };
    }

    /** @param list<array<string,mixed>> $fields */
    private function aggregateStatus(array $fields): string
    {
        $statuses = array_values(array_unique(array_map(static fn(array $field): string => (string) ($field['status'] ?? 'UNKNOWN'), $fields)));
        foreach (['BLOCKED', 'DISPUTED', 'MISSING', 'UNKNOWN', 'CANDIDATE'] as $status) {
            if (in_array($status, $statuses, true)) return $status;
        }
        if ($statuses !== [] && count(array_filter($statuses, static fn(string $status): bool => $status === 'NOT_APPLICABLE')) === count($statuses)) return 'NOT_APPLICABLE';
        if ($statuses !== [] && count(array_filter($statuses, static fn(string $status): bool => $status === 'PUBLIC_READY')) === count($statuses)) return 'PUBLIC_READY';
        return 'VERIFIED';
    }

    /** @param list<array<string,mixed>> $items */
    private function claimStatus(array $facets, array $keys): string
    {
        $items = [];
        foreach ($keys as $key) foreach (is_array($facets[$key] ?? null) ? $facets[$key] : [] as $item) if (is_array($item)) $items[] = $item;
        if ($items === []) return 'MISSING';
        $statuses = array_map(static fn(array $item): string => strtoupper(trim((string) ($item['status'] ?? 'CANDIDATE'))), $items);
        foreach (['BLOCKED', 'DISPUTED', 'UNKNOWN'] as $status) if (in_array($status, $statuses, true)) return $status;
        if (in_array('CANDIDATE', $statuses, true)) return 'CANDIDATE';
        if (in_array('PUBLIC_READY', $statuses, true)) return 'PUBLIC_READY';
        return in_array('VERIFIED', $statuses, true) ? 'VERIFIED' : 'CANDIDATE';
    }

    private function hasClaims(array $facets, array $keys): bool
    {
        foreach ($keys as $key) if (!empty($facets[$key])) return true;
        return false;
    }

    /** @param list<array<string,mixed>> $audio */
    private function audioStatus(array $audio, string $mode): string
    {
        foreach ($audio as $item) if (($item['mode'] ?? '') === $mode) return ($item['verification_status'] ?? '') === 'VERIFIED' ? 'VERIFIED' : 'CANDIDATE';
        return 'NOT_APPLICABLE';
    }

    /** @param array<string,mixed> $relations */
    private function relationStatus(array $relations, array $groups): string
    {
        $found = false;
        foreach ($groups as $group) foreach (is_array($relations[$group] ?? null) ? $relations[$group] : [] as $item) {
            if (!is_array($item)) continue;
            $found = true;
            if (($item['status'] ?? '') === 'DISPUTED') return 'DISPUTED';
        }
        return $found ? 'CANDIDATE' : 'MISSING';
    }

    /** @param list<array<string,mixed>> $audio */
    private function rightsStatus(array $audio, array $dossier): string
    {
        $hasAssets = $audio !== [] || !empty($dossier['media_gallery']);
        if (!$hasAssets) return 'NOT_APPLICABLE';
        foreach ($audio as $item) if (str_contains(strtoupper((string) ($item['rights'] ?? '')), 'REVIEW_REQUIRED')) return 'BLOCKED';
        foreach (is_array($dossier['media_gallery'] ?? null) ? $dossier['media_gallery'] : [] as $media) {
            if (!is_array($media)) return 'BLOCKED';
            $rights = strtoupper(trim((string) ($media['rights_status'] ?? $media['license_status'] ?? $media['rights'] ?? '')));
            if ($rights === '' || str_contains($rights, 'REVIEW_REQUIRED') || str_contains($rights, 'BLOCKED') || str_contains($rights, 'PRIVATE') || str_contains($rights, 'UNKNOWN')) return 'BLOCKED';
        }
        return 'VERIFIED';
    }

    /** @param array<string,array<string,mixed>> $categories @return array<string,mixed> */
    private function nextTask(array $categories): array
    {
        $priority = ['BLOCKED' => 1, 'DISPUTED' => 2, 'MISSING' => 3, 'UNKNOWN' => 4, 'CANDIDATE' => 5];
        $best = null;
        foreach ($categories as $category) foreach ($category['fields'] as $field) {
            $rank = $priority[$field['status']] ?? 99;
            if ($rank >= 99 || ($best !== null && $rank >= $best['_rank'])) continue;
            $best = ['field_name' => $field['field_name'], 'display_name_vi' => $field['display_name_vi'], 'status' => $field['status'], '_rank' => $rank];
        }
        if ($best === null) return ['field_name' => '', 'display_name_vi' => '', 'status' => 'COMPLETE'];
        unset($best['_rank']);
        return $best;
    }

    /** @return list<string> */
    private function reuseSignals(array $dossier): array
    {
        $signals = [];
        foreach (['knowledge', 'relation_sections', 'media_gallery', 'dictionary_terms'] as $key) if (!empty($dossier[$key])) $signals[] = $key;
        return $signals;
    }
}
