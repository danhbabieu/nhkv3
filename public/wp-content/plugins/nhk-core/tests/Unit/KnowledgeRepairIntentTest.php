<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\KnowledgeRepairIntent;
use PHPUnit\Framework\TestCase;

final class KnowledgeRepairIntentTest extends TestCase
{
    private const ID = '01a09786-dd67-70e7-9d30-9b8d3931766d';

    public function testUpdateIsTypedAndBounded(): void
    {
        $intent = KnowledgeRepairIntent::fromArray(['canonical_knowledge_uuid' => self::ID, 'expected_revision' => 3, 'operation' => 'update', 'delta' => ['text' => 'Clean canonical wording.', 'claim_type' => 'fact'], 'reason' => 'Remove workflow contamination.', 'provenance' => ['origin' => 'AUDIT'], 'cleanup_class' => 'PROCESS_CONTAMINATION']);
        self::assertSame(self::ID, $intent->targetUuid);
        self::assertSame('update', $intent->operation);
        self::assertSame(3, $intent->expectedRevision);
    }

    public function testTargetStableKeyAndSubjectInferenceAreNotAcceptedAsRepairFields(): void
    {
        $this->expectExceptionMessage('KNOWLEDGE_REPAIR_STABLE_KEY_FORBIDDEN');
        KnowledgeRepairIntent::fromArray(['canonical_knowledge_uuid' => self::ID, 'expected_revision' => 1, 'operation' => 'retire', 'reason' => 'retire', 'provenance' => ['origin' => 'AUDIT'], 'cleanup_class' => 'INTERNAL_WORKFLOW_KNOWLEDGE', 'stable_key' => 'forbidden']);
    }

    public function testRetireDoesNotRequireAContentDelta(): void
    {
        $intent = KnowledgeRepairIntent::fromArray(['canonical_knowledge_uuid' => self::ID, 'expected_revision' => 1, 'operation' => 'retire', 'reason' => 'Retire internal workflow residue.', 'provenance' => ['origin' => 'AUDIT'], 'cleanup_class' => 'INTERNAL_WORKFLOW_KNOWLEDGE']);
        self::assertSame('retire', $intent->operation);
        self::assertNull($intent->text);
    }
}
