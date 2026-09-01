<?php
declare(strict_types=1);

namespace NHK\Core\Application\Migration;

final class V2DomainPostClassifier
{
    /** @var array<string,string> */
    private const CLASSIFICATIONS = [
        'nhk_brand' => ['classification' => 'STRUCTURE_REFERENCE', 'reason_code' => 'DOMAIN_IDENTITY_REQUIRES_EXPLICIT_MAPPING'],
        'nhk_model' => ['classification' => 'STRUCTURE_REFERENCE', 'reason_code' => 'DOMAIN_IDENTITY_REQUIRES_EXPLICIT_MAPPING'],
        'nhk_variant' => ['classification' => 'STRUCTURE_REFERENCE', 'reason_code' => 'DOMAIN_IDENTITY_REQUIRES_EXPLICIT_MAPPING'],
        'nhk_movement' => ['classification' => 'STRUCTURE_REFERENCE', 'reason_code' => 'DOMAIN_IDENTITY_REQUIRES_EXPLICIT_MAPPING'],
        'nhk_music' => ['classification' => 'STRUCTURE_REFERENCE', 'reason_code' => 'DOMAIN_IDENTITY_REQUIRES_EXPLICIT_MAPPING'],
        'nhk_component' => ['classification' => 'STRUCTURE_REFERENCE', 'reason_code' => 'DOMAIN_IDENTITY_REQUIRES_EXPLICIT_MAPPING'],
        'nhk_classification' => ['classification' => 'STRUCTURE_REFERENCE', 'reason_code' => 'DOMAIN_IDENTITY_REQUIRES_EXPLICIT_MAPPING'],
        'nhk_knowledge' => ['classification' => 'STRUCTURE_REFERENCE', 'reason_code' => 'KNOWLEDGE_IDENTITY_REQUIRES_EXPLICIT_MAPPING'],
        'attachment' => ['classification' => 'REQUIRES_REVIEW', 'reason_code' => 'MEDIA_SOURCE_RECOVERY_OR_RETIREMENT_REQUIRED'],
        'wp_global_styles' => ['classification' => 'RETIRE', 'reason_code' => 'NON_EDITORIAL_IMPLEMENTATION_RECORD'],
        'nhk_article' => ['classification' => 'EDITORIAL_DEFERRED', 'reason_code' => 'EDITORIAL_POST_REQUIRES_SEPARATE_REVIEW'],
        'post' => ['classification' => 'EDITORIAL_DEFERRED', 'reason_code' => 'EDITORIAL_POST_REQUIRES_SEPARATE_REVIEW'],
        'page' => ['classification' => 'EDITORIAL_DEFERRED', 'reason_code' => 'EDITORIAL_POST_REQUIRES_SEPARATE_REVIEW'],
    ];

    /** @param list<array<string,mixed>> $records */
    public function run(array $records): array
    {
        $counts = [];
        $items = [];
        foreach ($records as $record) {
            $legacyType = (string) ($record['legacy_type'] ?? '');
            $definition = self::CLASSIFICATIONS[$legacyType] ?? ['classification' => 'REQUIRES_REVIEW', 'reason_code' => 'UNSUPPORTED_LEGACY_RECORD_REQUIRES_REVIEW'];
            $classification = $definition['classification'];
            $counts[$classification] = ($counts[$classification] ?? 0) + 1;
            $items[] = [
                'legacy_id' => (string) ($record['legacy_id'] ?? ''),
                'legacy_type' => $legacyType,
                'post_name' => (string) ($record['post_name'] ?? ''),
                'post_title' => (string) ($record['post_title'] ?? ''),
                'classification' => $classification,
                'reason_code' => $definition['reason_code'],
                'editorial_import_forbidden' => $classification !== 'EDITORIAL_DEFERRED',
                'mapping_applied' => false,
            ];
        }
        ksort($counts);
        return ['source_count' => count($records), 'counts' => $counts, 'items' => $items];
    }
}
