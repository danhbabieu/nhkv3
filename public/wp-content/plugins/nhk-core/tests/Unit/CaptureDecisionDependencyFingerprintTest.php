<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\CaptureDecisionDependencyFingerprint;
use NHK\Core\Application\Semantic\SemanticClaimCandidateGuard;
use NHK\Core\Domain\Governance\CommandCanonicalizer;
use PHPUnit\Framework\TestCase;

final class CaptureDecisionDependencyFingerprintTest extends TestCase
{
    public function test_semantic_admission_policy_version_is_part_of_the_canonical_fingerprint_payload(): void
    {
        $requestFingerprint = hash('sha256', 'semantic-policy-fingerprint');
        $context = [
            'raw_input' => 'Người dùng nêu một dữ kiện.',
            'content_intent' => ['intent' => 'KNOWLEDGE_DELTA'],
        ];
        $diagnostics = [];

        self::assertSame('capture-decision-dependencies-4', CaptureDecisionDependencyFingerprint::VERSION);
        self::assertSame('semantic-admission-policy-1', SemanticClaimCandidateGuard::POLICY_VERSION);

        $expected = hash('sha256', CommandCanonicalizer::canonicalize([
            'version' => 'capture-decision-dependencies-4',
            'policy_version' => CaptureDecisionDependencyFingerprint::POLICY_VERSION,
            'semantic_admission_policy_version' => 'semantic-admission-policy-1',
            'request_fingerprint' => $requestFingerprint,
            'subject' => [],
            'dependencies' => [
                'canonical_context' => [],
                'evidence_context' => [],
                'visual_context' => [],
                'enrichment' => [],
            ],
            'input_context' => [
                'raw_input' => 'Người dùng nêu một dữ kiện.',
                'subject_hints' => [],
                'content_intent' => ['intent' => 'KNOWLEDGE_DELTA'],
                'original_request' => [],
                'subject_reconciliation' => [],
                'decision_trace' => [],
                'constraint_findings' => [],
                'quality_decision' => '',
            ],
        ]));

        self::assertSame($expected, CaptureDecisionDependencyFingerprint::forState($requestFingerprint, $context, $diagnostics));
    }
}
