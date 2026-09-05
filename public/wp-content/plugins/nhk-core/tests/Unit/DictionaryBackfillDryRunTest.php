<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryBackfillDryRun;
use PHPUnit\Framework\TestCase;

final class DictionaryBackfillDryRunTest extends TestCase
{
    public function test_dry_run_aggregates_all_source_kinds_and_never_requests_persistence(): void
    {
        $calls = [];
        $preview = static function (string $text, string $kind, array $context, array $hints) use (&$calls): array {
            $calls[] = compact('text', 'kind', 'context', 'hints');
            return match ($kind) {
                'ARTICLE' => ['status' => 'AVAILABLE', 'mode' => 'PREVIEW', 'resolved_terms' => [['normalized_term' => 'westminster']], 'candidate_terms' => [], 'ambiguous_terms' => [], 'warnings' => []],
                'KNOWLEDGE' => ['status' => 'AVAILABLE', 'mode' => 'PREVIEW', 'resolved_terms' => [], 'candidate_terms' => [['normalized_term' => 'côn lòng máng']], 'ambiguous_terms' => [], 'warnings' => []],
                'MEDIA' => ['status' => 'AVAILABLE', 'mode' => 'PREVIEW', 'resolved_terms' => [], 'candidate_terms' => [], 'ambiguous_terms' => [['normalized_term' => 'côn']], 'warnings' => []],
                'VIDEO' => ['status' => 'AVAILABLE', 'mode' => 'PREVIEW', 'resolved_terms' => [], 'candidate_terms' => [], 'ambiguous_terms' => [], 'warnings' => ['DICTIONARY_TERM_SUPPRESSED']],
                default => ['status' => 'UNAVAILABLE'],
            };
        };
        $service = new DictionaryBackfillDryRun($preview);
        $report = $service->scan([
            ['kind' => 'ARTICLE', 'id' => '1', 'text' => 'Westminster'],
            ['kind' => 'KNOWLEDGE', 'id' => 'k1', 'text' => 'côn lòng máng'],
            ['kind' => 'MEDIA', 'id' => 'm1', 'text' => 'côn'],
            ['kind' => 'VIDEO', 'id' => 'v1', 'text' => 'máy đẹp'],
        ]);

        self::assertTrue($report['no_write']);
        self::assertSame('DRY_RUN', $report['mode']);
        self::assertSame(['ARTICLE' => 1, 'KNOWLEDGE' => 1, 'MEDIA' => 1, 'VIDEO' => 1], $report['source_counts']);
        self::assertSame(1, $report['totals']['resolved_existing']);
        self::assertSame(1, $report['totals']['candidate_new']);
        self::assertSame(1, $report['totals']['ambiguous']);
        self::assertSame(1, $report['totals']['suppressed']);
        self::assertCount(4, $calls);
        foreach ($calls as $call) self::assertContains($call['kind'], ['ARTICLE', 'KNOWLEDGE', 'MEDIA', 'VIDEO']);
    }

    public function test_unavailable_source_is_reported_not_counted_as_empty_success(): void
    {
        $service = new DictionaryBackfillDryRun(static fn (): array => ['status' => 'UNAVAILABLE']);
        $report = $service->scan([['kind' => 'ARTICLE', 'id' => '1', 'text' => 'x']]);

        self::assertSame(1, $report['totals']['unavailable']);
        self::assertSame('UNAVAILABLE', $report['items'][0]['status']);
        self::assertTrue($report['no_write']);
    }
}
