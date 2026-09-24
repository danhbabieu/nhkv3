<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{EditorialContextPack, EditorialDraft, EditorialPlan, EditorialQualityGate, SemanticSeoPlan};
use PHPUnit\Framework\TestCase;

final class AdaptiveKnowledgeQualityDiagnosticsTest extends TestCase
{
    public function test_selector_diagnostics_are_exposed_as_quality_findings_without_claim_count_scoring(): void
    {
        $pack = new EditorialContextPack('available', ['id' => 'subject', 'type' => 'model'], 'subject', ['profile' => 'video'], 'available', [[
            'claim_id' => 'fact', 'claim_revision' => 1, 'text' => 'Subject có cấu hình.', 'eligibility' => 'eligible', 'publicly_composable' => true, 'editorial_role' => 'CORE', 'original_subject' => ['id' => 'subject'],
        ]], [], [], [], [], [
            'coverage_status' => 'PARTIAL',
            'stop_reason' => 'marginal_gain_low',
            'knowledge_unit_count' => 1,
            'selected_unit_count' => 1,
            'candidate_count' => 1000,
            'provenance_dominated' => true,
            'duplicate_dominated' => true,
            'quality_requires_coverage' => true,
        ]);
        $plan = new EditorialPlan('available', 'video', ['id' => 'subject', 'type' => 'model'], 'subject', [['id' => 'opening', 'claims' => []]]);
        $draft = new EditorialDraft('available', 'video', 'subject', 'Subject', 'Subject có cấu hình.', [['claim_id' => 'fact', 'claim_revision' => 1]]);
        $seo = new SemanticSeoPlan('NOT_APPLICABLE', 'video', 'overview', ['id' => 'subject'], 'subject', [], 'subject', 'subject', 'subject', null, [], [], [], [], [], []);

        $report = (new EditorialQualityGate())->evaluate($pack, $plan, $draft, $seo);

        self::assertContains('INSUFFICIENT_READER_COVERAGE', $report->warnings);
        self::assertContains('LOW_MARGINAL_INFORMATION_GAIN', $report->warnings);
        self::assertContains('PROVENANCE_DOMINATED_SELECTION', $report->warnings);
        self::assertContains('DUPLICATE_KNOWLEDGE_DOMINATION', $report->warnings);
    }
}
