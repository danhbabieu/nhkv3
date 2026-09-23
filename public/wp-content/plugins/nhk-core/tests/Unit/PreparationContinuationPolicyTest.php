<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Capture\PreparationContinuationPolicy;
use NHK\Core\Application\Capture\PreparationDependencyRequirements;
use PHPUnit\Framework\TestCase;

final class PreparationContinuationPolicyTest extends TestCase
{
    public function test_optional_enrichment_is_deferred_but_safe_to_continue(): void
    {
        $decision = (new PreparationContinuationPolicy())->decide([
            [
                'code' => 'RELATED_VARIANT_CONTEXT',
                'dependency_class' => 'OPTIONAL_ENRICHMENT',
                'readiness' => 'INCOMPLETE',
                'escalation' => null,
            ],
        ]);

        self::assertTrue($decision->mayContinue);
        self::assertSame([], $decision->blockingFindings);
        self::assertSame(['RELATED_VARIANT_CONTEXT'], $decision->deferredFindings);
    }

    public function test_critical_identity_denies_continuation(): void
    {
        $decision = (new PreparationContinuationPolicy())->decide([
            [
                'code' => 'PRIMARY_SUBJECT_AMBIGUOUS',
                'dependency_class' => 'CRITICAL_IDENTITY',
                'readiness' => 'BLOCKED',
                'escalation' => 'REVIEW_REQUIRED',
            ],
        ]);

        self::assertFalse($decision->mayContinue);
        self::assertSame(['PRIMARY_SUBJECT_AMBIGUOUS'], $decision->blockingFindings);
    }

    public function test_publication_only_blocks_publication_phase_but_not_working_phase(): void
    {
        $finding = [[
            'code' => 'PUBLIC_ROUTE_NOT_READY',
            'dependency_class' => 'PUBLICATION_ONLY',
            'readiness' => 'INCOMPLETE',
            'escalation' => null,
        ]];

        $working = (new PreparationContinuationPolicy())->decide($finding, ['phase' => 'EDITORIAL_WORKING']);
        $publication = (new PreparationContinuationPolicy())->decide($finding, ['phase' => 'PUBLICATION']);

        self::assertTrue($working->mayContinue);
        self::assertFalse($publication->mayContinue);
        self::assertSame(['PUBLIC_ROUTE_NOT_READY'], $publication->blockingFindings);
    }

    public function test_server_requirement_wins_over_untrusted_optional_downgrade(): void
    {
        $requirements = (new PreparationDependencyRequirements())->derive(
            ['intent' => 'TEXT_ARTICLE', 'content_preparation' => ['dependency_requirements' => [['code' => 'FACT', 'kind' => 'OPTIONAL_ENRICHMENT']]]],
            [],
            [
                'content_intent' => ['intent' => 'KNOWLEDGE_DELTA'],
                'server_dependency_requirements' => [[
                    'code' => 'FACT',
                    'kind' => 'REQUIRED_FACTUAL_DEPENDENCY',
                    'readiness' => 'INCOMPLETE',
                ]],
            ],
        );

        self::assertSame('REQUIRED_FACTUAL_DEPENDENCY', $requirements[0]['kind']);
    }

    public function test_knowledge_delta_required_evidence_remains_denied(): void
    {
        $decision = (new PreparationContinuationPolicy())->decide([
            [
                'code' => 'KNOWLEDGE_EVIDENCE_REQUIRED',
                'dependency_class' => 'REQUIRED_FACTUAL_DEPENDENCY',
                'readiness' => 'UNAVAILABLE',
                'escalation' => 'REVIEW_REQUIRED',
            ],
        ], ['intent' => 'KNOWLEDGE_DELTA']);

        self::assertFalse($decision->mayContinue);
        self::assertSame(['KNOWLEDGE_EVIDENCE_REQUIRED'], $decision->blockingFindings);
    }

    public function test_repeated_evaluation_is_deterministic(): void
    {
        $findings = [[
            'code' => 'SPARSE_KNOWLEDGE_OPTIONAL',
            'dependency_class' => 'OPTIONAL_ENRICHMENT',
            'readiness' => 'INCOMPLETE',
            'escalation' => null,
        ]];

        $policy = new PreparationContinuationPolicy();
        self::assertSame($policy->decide($findings)->toArray(), $policy->decide($findings)->toArray());
    }

    public function test_later_exact_subject_consumer_still_denies_without_a_packet(): void
    {
        $workingDecision = (new PreparationContinuationPolicy())->decide([
            [
                'code' => 'SPARSE_KNOWLEDGE_OPTIONAL',
                'dependency_class' => 'OPTIONAL_ENRICHMENT',
                'readiness' => 'INCOMPLETE',
                'escalation' => null,
            ],
        ], ['phase' => 'EDITORIAL_WORKING']);
        $exactDecision = (new PreparationContinuationPolicy())->decide([
            [
                'code' => 'PRIMARY_SUBJECT_AMBIGUOUS',
                'dependency_class' => 'CRITICAL_IDENTITY',
                'readiness' => 'BLOCKED',
                'escalation' => 'REVIEW_REQUIRED',
            ],
        ], ['phase' => 'EXACT_SUBJECT_CONSUMER']);

        self::assertTrue($workingDecision->mayContinue);
        self::assertFalse($exactDecision->mayContinue);
    }
}
