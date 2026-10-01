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
        $metrics = $this->metrics();

        self::assertSame('UNAVAILABLE', $metrics['false_positive_lookup_rate']);
        self::assertSame('UNAVAILABLE', $metrics['independent_source_count']);
        self::assertNotSame('UNAVAILABLE', $metrics['candidate_precision']);
        self::assertNotSame('UNAVAILABLE', $metrics['valid_term_recall']);
        self::assertNotSame('UNAVAILABLE', $metrics['boundary_accuracy']);
    }

    /** @return array<string,float|string> */
    private function metrics(): array
    {
        $detector = new DictionaryTermDetector();
        $expectedQualified = 0;
        $foundQualified = 0;
        $truePositive = 0;
        $expectedSpans = 0;
        $matchedSpans = 0;

        foreach (DictionaryLexicalHoldout::cases() as $case) {
            if (($case['gold_complete'] ?? false) !== true) continue;
            $items = array_column($detector->detect((string) $case['text']), null, 'normalized_term');
            $foundQualified += count(array_filter($items, static fn (array $item): bool => ($item['evidence_status'] ?? '') === 'QUALIFIED'));
            foreach ($case['expected'] as $term => $status) {
                $expectedSpans++;
                if (isset($items[$term]) && ($items[$term]['evidence_status'] ?? '') === $status) $matchedSpans++;
                if ($status !== 'QUALIFIED') continue;
                $expectedQualified++;
                if (isset($items[$term]) && ($items[$term]['evidence_status'] ?? '') === 'QUALIFIED') $truePositive++;
            }
        }

        return [
            'candidate_precision' => $foundQualified > 0 ? $truePositive / $foundQualified : 'UNAVAILABLE',
            'valid_term_recall' => $expectedQualified > 0 ? $truePositive / $expectedQualified : 'UNAVAILABLE',
            'boundary_accuracy' => $expectedSpans > 0 ? $matchedSpans / $expectedSpans : 'UNAVAILABLE',
            'false_positive_lookup_rate' => 'UNAVAILABLE',
            'independent_source_count' => 'UNAVAILABLE',
        ];
    }
}
