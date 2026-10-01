<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Dictionary\{DictionaryResolver, DictionarySeedPlanner, DictionaryTermDetector};
use NHK\Core\Application\Semantic\StructuredSemanticInterpreter;
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

        self::assertNotSame('UNAVAILABLE', $metrics['false_positive_lookup_rate']);
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
        $lookupCount = 0;
        $falsePositiveLookups = 0;
        $falseNegativeNewTerms = 0;

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

            $lookupTerms = [];
            $resolver = new DictionaryResolver(
                static fn (): array => [],
                static function (string $term) use (&$lookupTerms): array { $lookupTerms[] = $term; return []; },
                static fn (): array => [],
                static fn (): array => [],
                static fn (): bool => false,
            );
            $plan = (new DictionarySeedPlanner($resolver))->plan(
                (new StructuredSemanticInterpreter())->interpret(['raw_text' => (string) $case['text'], 'source_kind' => 'HOLDOUT', 'source_identity' => ['source_id' => 'holdout:' . $case['id']]]),
            );
            $expectedQualifiedTerms = array_keys(array_filter($case['expected'], static fn (string $status): bool => $status === 'QUALIFIED'));
            $expectedQualifiedSet = array_fill_keys($expectedQualifiedTerms, true);
            foreach ($lookupTerms as $term) {
                $lookupCount++;
                if (!isset($expectedQualifiedSet[$this->normalize($term)])) $falsePositiveLookups++;
            }
            $plannedTerms = array_fill_keys(array_column($plan['items'], 'normalized_form'), true);
            foreach ($expectedQualifiedTerms as $term) if (!isset($plannedTerms[$term])) $falseNegativeNewTerms++;
        }

        return [
            'candidate_precision' => $foundQualified > 0 ? $truePositive / $foundQualified : 'UNAVAILABLE',
            'valid_term_recall' => $expectedQualified > 0 ? $truePositive / $expectedQualified : 'UNAVAILABLE',
            'boundary_accuracy' => $expectedSpans > 0 ? $matchedSpans / $expectedSpans : 'UNAVAILABLE',
            'false_positive_lookup_rate' => $lookupCount > 0 ? $falsePositiveLookups / $lookupCount : 'UNAVAILABLE',
            'false_negative_new_term_rate' => $expectedQualified > 0 ? $falseNegativeNewTerms / $expectedQualified : 'UNAVAILABLE',
            'independent_source_count' => 'UNAVAILABLE',
        ];
    }

    private function normalize(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }
}
