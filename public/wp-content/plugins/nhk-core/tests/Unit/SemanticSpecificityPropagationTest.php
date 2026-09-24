<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{EditorialKnowledgeSelector, EditorialQualityGate, ReaderJourneyPlanner, SemanticSeoPlanner, SharedEditorialComposer};
use PHPUnit\Framework\TestCase;

final class SemanticSpecificityPropagationTest extends TestCase
{
    public function test_broader_context_remains_contextual_through_unit_journey_composer_and_seo(): void
    {
        $claim = [
            'claim_id' => 'model-property', 'claim_revision' => 4, 'text' => 'Model-X có property P.',
            'eligibility' => 'eligible', 'publicly_composable' => true, 'subject_id' => 'model-x', 'subject_type' => 'model',
            'original_subject' => ['id' => 'model-x', 'type' => 'model'],
            'resolved_primary_subject' => ['id' => 'variant-x', 'type' => 'variant'],
            'scope' => 'model', 'facet' => 'configuration', 'retrieval_origin' => 'neighborhood',
            'graph_path' => [['source' => 'variant:variant-x', 'predicate' => 'variant_of', 'target' => 'model:model-x']],
            'evidence' => ['status' => 'eligible'], 'provenance' => 'CATALOG_SUPPORTED',
            'retrieval_tier' => 'BACKGROUND_CONTEXT', 'coverage_kind' => 'contextual',
            'editorial_treatment' => 'BACKGROUND_CONTEXT',
        ];
        $pack = (new EditorialKnowledgeSelector())->select(['status' => 'available', 'items' => [$claim], 'eligible_claims' => [$claim]], 'Variant-X', ['id' => 'variant-x', 'type' => 'variant'], ['profile' => 'article']);
        $selected = $pack->selectedClaims[0];
        self::assertSame('BACKGROUND_CONTEXT', $selected['editorial_treatment']);
        self::assertSame('contextual', $selected['coverage_kind']);
        self::assertTrue($selected['semantic_context_only']);
        self::assertSame('PARTIAL', $pack->diagnostics['coverage_status']);
        self::assertSame([], $pack->diagnostics['coverage_achieved']);
        self::assertSame(['contextual'], $pack->diagnostics['coverage_kinds']);

        $plan = (new ReaderJourneyPlanner())->plan($pack);
        self::assertSame('BACKGROUND_CONTEXT', $plan->sections[1]['claim_refs'][0]['editorial_treatment']);
        self::assertTrue($plan->sections[1]['claim_refs'][0]['semantic_context_only']);

        $draft = (new SharedEditorialComposer())->compose($plan);
        self::assertSame('BACKGROUND_CONTEXT', $draft->claimTrace[0]['editorial_treatment']);
        self::assertTrue(str_contains($draft->body, 'Trong bối cảnh này') || str_contains($draft->body, 'Ngoài thông tin chính'));
        self::assertStringNotContainsString('Variant-X có property P', $draft->body);

        $seo = (new SemanticSeoPlanner())->plan($pack, $plan, $draft, ['structured_data' => ['type' => 'Article']]);
        self::assertSame('BACKGROUND_CONTEXT', $seo->claimTrace[0]['editorial_treatment']);
        self::assertStringNotContainsString('Model-X có property P', $seo->metaDescription);

        $quality = (new EditorialQualityGate())->evaluate($pack, $plan, $draft, $seo);
        self::assertContains('INSUFFICIENT_READER_COVERAGE', $quality->warnings);
    }
}
