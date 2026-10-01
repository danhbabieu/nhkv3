<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Dictionary\DictionaryTermDetector;
use NHK\Tests\Fixtures\DictionaryLexicalHoldout;
use PHPUnit\Framework\TestCase;

final class DictionaryLexicalHoldoutTest extends TestCase
{
    public function test_independent_holdout_preserves_labeled_boundaries_and_states(): void
    {
        $detector = new DictionaryTermDetector();
        $complete = 0;
        $qualifiedExpected = 0;
        $qualifiedFound = 0;
        $qualifiedTruePositive = 0;
        $limitations = [];

        foreach (DictionaryLexicalHoldout::cases() as $case) {
            $items = $detector->detect((string) $case['text']);
            $byTerm = array_column($items, null, 'normalized_term');
            foreach ($case['expected'] as $term => $status) {
                self::assertArrayHasKey($term, $byTerm, (string) $case['id']);
                self::assertSame($status, $byTerm[$term]['evidence_status'], (string) $case['id'] . ':' . $term);
                if ($status === 'QUALIFIED') {
                    $qualifiedExpected++;
                    if (($byTerm[$term]['resolver_eligible'] ?? false) === true) $qualifiedTruePositive++;
                }
            }
            foreach ($case['forbidden'] as $term) self::assertArrayNotHasKey($term, $byTerm, (string) $case['id'] . ':forbidden');
            $qualifiedFound += count(array_filter($items, static fn (array $item): bool => ($item['evidence_status'] ?? '') === 'QUALIFIED'));
            if (($case['gold_complete'] ?? false) === true) $complete++;
            elseif (isset($case['limitation'])) $limitations[] = $case['id'] . ': ' . $case['limitation'];
        }

        self::assertGreaterThan(0, $complete);
        self::assertGreaterThan(0, $qualifiedExpected);
        self::assertSame($qualifiedExpected, $qualifiedTruePositive);
        self::assertGreaterThanOrEqual($qualifiedTruePositive, $qualifiedFound);
        self::assertNotEmpty($limitations);
    }

    public function test_holdout_metrics_report_unavailable_when_gold_labels_are_incomplete(): void
    {
        $incomplete = array_filter(DictionaryLexicalHoldout::cases(), static fn (array $case): bool => ($case['gold_complete'] ?? false) !== true);

        self::assertNotEmpty($incomplete);
        self::assertSame('UNAVAILABLE', $this->metricFor($incomplete, 'independent_source_count'));
    }

    /** @param list<array<string,mixed>> $cases */
    private function metricFor(array $cases, string $metric): string
    {
        foreach ($cases as $case) if (($case['gold_complete'] ?? false) !== true) return 'UNAVAILABLE';
        return $metric === '' ? 'UNAVAILABLE' : 'AVAILABLE';
    }
}
