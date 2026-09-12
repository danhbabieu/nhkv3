<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Domain\Media\MediaDetailTypeRegistry;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Detects a small, deterministic set of useful visual opportunities. This is
 * a planner only: it never creates Media, claims, relations or requirements.
 */
final class VisualOpportunityDetector
{
    /** @var list<array{phrases:list<string>,feature_key:string,facet:string,visual_intent:string,view:string,priority:int}> */
    private const FEATURES = [
        ['phrases' => ['mặt số'], 'feature_key' => 'DIAL', 'facet' => 'recognition', 'visual_intent' => 'technical_detail', 'view' => 'DIAL', 'priority' => 1],
        ['phrases' => ['bộ búa'], 'feature_key' => 'HAMMER_BANK', 'facet' => 'configuration', 'visual_intent' => 'technical_detail', 'view' => 'HAMMER_BANK', 'priority' => 2],
        ['phrases' => ['bộ côn'], 'feature_key' => 'ROD_BANK', 'facet' => 'configuration', 'visual_intent' => 'technical_detail', 'view' => 'ROD_BANK', 'priority' => 3],
        ['phrases' => ['bộ máy'], 'feature_key' => 'MOVEMENT_FRONT', 'facet' => 'movement', 'visual_intent' => 'technical_detail', 'view' => 'MOVEMENT_FRONT', 'priority' => 4],
        ['phrases' => ['phong vũ biểu'], 'feature_key' => 'COMPONENT_DETAIL', 'facet' => 'component', 'visual_intent' => 'contextual_illustration', 'view' => 'COMPONENT_DETAIL', 'priority' => 5],
        ['phrases' => ['tay lắc'], 'feature_key' => 'HANDS', 'facet' => 'component', 'visual_intent' => 'technical_detail', 'view' => 'HANDS', 'priority' => 6],
        ['phrases' => ['cần ngắt chuông'], 'feature_key' => 'COMPONENT_DETAIL', 'facet' => 'component', 'visual_intent' => 'contextual_illustration', 'view' => 'COMPONENT_DETAIL', 'priority' => 7],
        ['phrases' => ['chi tiết thùng', 'thùng'], 'feature_key' => 'CASE', 'facet' => 'component', 'visual_intent' => 'technical_detail', 'view' => 'CASE', 'priority' => 8],
    ];

    /** @return list<array<string,mixed>> */
    public function detect(string $text, array $interpretation, array $resolution, int $max = 3): array
    {
        $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        $subjectId = trim((string) ($primary['id'] ?? ''));
        $subjectType = trim((string) ($primary['type'] ?? ''));
        if (($resolution['status'] ?? '') !== 'resolved' || !UuidCodec::isValid($subjectId) || $subjectType === '' || $max < 1) return [];

        $parts = [$text];
        foreach (['user_claim_candidates', 'media_observations'] as $key) {
            foreach ((array) ($interpretation[$key] ?? []) as $candidate) if (is_array($candidate)) $parts[] = (string) ($candidate['text'] ?? $candidate['value'] ?? '');
        }
        $haystack = $this->lower(implode("\n", $parts));
        $scope = $subjectType === 'specimen' ? 'specimen_observation' : $subjectType;
        $found = [];
        foreach (self::FEATURES as $feature) {
            if (!in_array($feature['feature_key'], MediaDetailTypeRegistry::all(), true)) continue;
            foreach ($feature['phrases'] as $phrase) {
                if (!str_contains($haystack, $this->lower($phrase))) continue;
                $found[$feature['feature_key']] = [
                    'subject' => ['id' => $subjectId, 'type' => $subjectType, 'name' => (string) ($primary['name'] ?? '')],
                    'scope' => $scope,
                    'facet' => $feature['facet'],
                    'feature_key' => $feature['feature_key'],
                    'feature_label' => $phrase,
                    'visual_intent' => $feature['visual_intent'],
                    'recommended_view' => $feature['view'],
                    'reason' => 'Ảnh cận ' . $phrase . ' sẽ giúp người đọc nhận diện và hiểu rõ chi tiết được nhắc đến.',
                    'priority' => $feature['priority'],
                    'potential_reuse' => ['media' => true, 'knowledge' => true, 'article' => true],
                ];
                break;
            }
        }
        $opportunities = array_values($found);
        usort($opportunities, static fn (array $left, array $right): int => ((int) $left['priority'] <=> (int) $right['priority']) ?: strcmp((string) $left['feature_key'], (string) $right['feature_key']));
        return array_slice($opportunities, 0, min(3, $max));
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }
}
