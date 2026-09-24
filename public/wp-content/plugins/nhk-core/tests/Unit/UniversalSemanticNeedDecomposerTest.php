<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{SemanticNeedDecomposer, TextInputInterpreter, UniversalInputEnvelope};
use PHPUnit\Framework\TestCase;

final class UniversalSemanticNeedDecomposerTest extends TestCase
{
    public function test_five_registered_components_become_deduplicated_subject_bound_needs(): void
    {
        $input = UniversalInputEnvelope::fromArray([
            'body' => 'five facets',
            'subject_resolution' => ['primary' => ['id' => 'subject-1', 'type' => 'variant', 'revision' => 3]],
            'components' => [
                ['facet_key' => 'dial', 'concept_key' => 'form', 'origin' => 'USER_EXPLICIT'],
                ['facet_key' => 'case', 'concept_key' => 'style', 'origin' => 'USER_EXPLICIT'],
                ['facet_key' => 'movement', 'concept_key' => 'mechanism', 'origin' => 'SOURCE_EXPLICIT'],
                ['facet_key' => 'finish', 'concept_key' => 'treatment', 'origin' => 'MACHINE_DERIVED'],
                ['facet_key' => 'configuration', 'concept_key' => 'layout', 'origin' => 'USER_EXPLICIT'],
                ['facet_key' => 'dial', 'concept_key' => 'form', 'origin' => 'USER_EXPLICIT'],
            ],
        ]);

        $result = (new SemanticNeedDecomposer(new TextInputInterpreter(), $this->vocabulary()))->decompose($input)->toArray();

        self::assertCount(5, $result['needs']);
        self::assertContains('DUPLICATE_NEED_COLLAPSED', $result['diagnostics']);
        self::assertSame(['subject-1', 'subject-1', 'subject-1', 'subject-1', 'subject-1'], array_column(array_column($result['needs'], 'canonical_subject'), 'id'));
    }

    public function test_missing_subject_is_fail_closed_and_conflicting_hint_cannot_replace_authority(): void
    {
        $missing = UniversalInputEnvelope::fromArray(['components' => [['facet_key' => 'form', 'concept_key' => 'dial_form']]]);
        $missingResult = (new SemanticNeedDecomposer(new TextInputInterpreter(), $this->vocabulary()))->decompose($missing)->toArray();
        self::assertSame([], $missingResult['needs']);
        self::assertContains('CANONICAL_SUBJECT_REQUIRED', $missingResult['diagnostics']);

        $resolved = UniversalInputEnvelope::fromArray([
            'subject_resolution' => ['primary' => ['id' => 'authoritative-1', 'type' => 'variant']],
            'subject_hints' => ['weaker-1'],
            'components' => [['facet_key' => 'appearance', 'concept_key' => 'surface', 'origin' => 'SPECIMEN_OBSERVATION', 'scope' => 'specimen']],
        ]);
        $result = (new SemanticNeedDecomposer(new TextInputInterpreter(), $this->vocabulary()))->decompose($resolved)->toArray();
        self::assertSame('authoritative-1', $result['needs'][0]['canonical_subject']['id']);
        self::assertSame('variant', $result['needs'][0]['canonical_subject']['type']);
    }

    private function vocabulary(): object
    {
        return new class {
            public function isRegistered(string $facet, string $concept): bool
            {
                return in_array($facet . ':' . $concept, ['dial:form', 'case:style', 'movement:mechanism', 'finish:treatment', 'configuration:layout', 'appearance:surface'], true);
            }
        };
    }
}
