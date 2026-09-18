<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryObservationRegistry;
use PHPUnit\Framework\TestCase;

final class DictionaryMediaObservationBoundaryTest extends TestCase
{
    public function test_attachment_title_and_alt_are_not_promoted_to_explicit_dictionary_hints(): void
    {
        $observed = null;
        DictionaryObservationRegistry::register(
            static function (string $kind, string $id, string $text, array $context, array $hints) use (&$observed): array {
                $observed = compact('kind', 'id', 'text', 'context', 'hints');
                return ['status' => 'PREVIEW', 'resolved_terms' => [], 'candidate_terms' => [], 'ambiguous_terms' => [], 'warnings' => []];
            },
            static fn (): array => ['status' => 'PREVIEW', 'resolved_terms' => [], 'candidate_terms' => [], 'ambiguous_terms' => [], 'warnings' => []],
        );

        $result = DictionaryObservationRegistry::observe('MEDIA', 'attachment-42', 'Côn 111', ['attachment_id' => 42, 'weak_sources' => ['title', 'alt', 'filename']]);

        self::assertSame('PREVIEW', $result['status']);
        self::assertSame('MEDIA', $observed['kind']);
        self::assertSame('attachment-42', $observed['id']);
        self::assertSame(['attachment_id' => 42, 'weak_sources' => ['title', 'alt', 'filename']], $observed['context']);
        self::assertSame([], $observed['hints']);
    }

    public function test_dictionary_illustration_metadata_remains_usage_context_and_never_becomes_media_or_evidence_truth(): void
    {
        $concept = new \NHK\Core\Domain\Dictionary\DictionaryConcept('concept-cuon-111', 'Côn 111', 'Tên gọi lexical.', \NHK\Core\Domain\Dictionary\DictionaryConcept::APPROVED, null, null, null, ['public_slug' => 'con-111'], 2);
        $fixture = (new DictionaryCurationServiceTest('media fixture'))->mediaFixtureForBoundary($concept);
        $before = $fixture->stateSnapshot();

        $fixture->service->selectPreferredIllustration($concept->conceptId, $concept->revision, '01a0ab0c-fde0-7c01-a89d-fc5eef832c89', 'Côn 111', 'Côn 111 nhìn toàn cảnh', 'Minh họa riêng cho mục từ.');

        self::assertSame($before['media_ids'], $fixture->stateSnapshot()['media_ids']);
        self::assertSame($before['asset_ids'], $fixture->stateSnapshot()['asset_ids']);
        self::assertSame([], $fixture->evidenceRows);
        self::assertSame([], $fixture->graphRows);
        self::assertSame([], $fixture->attachmentMetadata);
        self::assertSame([], $fixture->binaryCopies);
    }
}
