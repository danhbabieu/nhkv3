<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryMcpResolveProjection;
use PHPUnit\Framework\TestCase;

final class DictionaryMcpResolveProjectionTest extends TestCase
{
    public function test_standalone_entry_resolution_replaces_legacy_concept_destination_with_entry_route(): void
    {
        $result = (new DictionaryMcpResolveProjection())->project(
            $this->preview([[
                'term' => 'Kính rào',
                'normalized_term' => 'kính rào',
                'concept_id' => 'sense-1',
                'destination_type' => 'dictionary',
                'destination_id' => 'concept-1',
                'destination_url' => null,
            ]]),
            ['status' => 'RESOLVED', 'term' => 'Kính rào', 'normalized_term' => 'kính rào', 'entry_id' => 'entry-1', 'sense_id' => 'sense-1', 'preferred_label' => 'Kính rào', 'destination_type' => 'dictionary', 'destination_id' => 'entry-1', 'destination_url' => '/tu-dien/kinh-rao/'],
        );

        self::assertSame('entry-1', $result['resolved_terms'][0]['destination_id']);
        self::assertSame('/tu-dien/kinh-rao/', $result['resolved_terms'][0]['destination_url']);
    }

    public function test_delegated_resolution_keeps_owner_destination_and_missing_public_identity_is_removed(): void
    {
        $delegated = (new DictionaryMcpResolveProjection())->project(
            $this->preview([['term' => 'Côn hoa thị', 'normalized_term' => 'côn hoa thị', 'concept_id' => 'sense-2', 'destination_type' => 'dictionary', 'destination_id' => 'concept-2', 'destination_url' => null]]),
            ['status' => 'RESOLVED', 'term' => 'Côn hoa thị', 'normalized_term' => 'côn hoa thị', 'entry_id' => 'entry-2', 'sense_id' => 'sense-2', 'preferred_label' => 'Côn hoa thị', 'destination_type' => 'component', 'destination_id' => 'component-1', 'destination_url' => '/linh-kien/con-hoa-thi/'],
        );
        self::assertSame('component', $delegated['resolved_terms'][0]['destination_type']);
        self::assertSame('/linh-kien/con-hoa-thi/', $delegated['resolved_terms'][0]['destination_url']);

        $blocked = (new DictionaryMcpResolveProjection())->project(
            $this->preview([['term' => 'Không còn công khai', 'normalized_term' => 'không còn công khai', 'concept_id' => 'concept-3', 'destination_type' => 'dictionary', 'destination_id' => 'concept-3', 'destination_url' => null]]),
            ['status' => 'UNKNOWN', 'term' => 'Không còn công khai', 'normalized_term' => 'không còn công khai', 'entry_id' => 'entry-3', 'reason' => 'DICTIONARY_ENTRY_PUBLIC_IDENTITY_MISSING'],
        );
        self::assertSame([], $blocked['resolved_terms']);
    }

    /** @param list<array<string,mixed>> $rows */
    private function preview(array $rows): array
    {
        return ['status' => 'AVAILABLE', 'mode' => 'PREVIEW', 'resolved_terms' => $rows, 'candidate_terms' => [], 'ambiguous_terms' => [], 'internal_link_candidates' => [], 'warnings' => [], 'blocking' => false];
    }
}
