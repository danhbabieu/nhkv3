<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver;
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Mcp\McpGovernanceHandler;
use NHK\Core\Contracts\Governance\AutomationPolicyStorage;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryProposalRepository;
use PHPUnit\Framework\TestCase;

final class McpGovernanceAutomationTest extends TestCase
{
    public function test_mcp_ingest_uses_shared_policy_and_returns_manual_review_status(): void
    {
        $handler = new McpGovernanceHandler(
            new GovernanceService(new InMemoryProposalRepository()),
            null,
            null,
            new GovernanceAutomationPolicyResolver(['video'], new class implements AutomationPolicyStorage {
                public function read(): array { return []; }
                public function write(array $policies): void {}
            }),
        );

        $videoId = UuidCodec::newV7();
        $result = $handler->ingestFromArguments([
            'operation' => 'ingest',
            'entity_type' => 'video',
            'subject_id' => $videoId,
            'payload' => ['canonical_id' => $videoId, 'url' => 'https://example.test/video'],
            'idempotency_key' => 'mcp-policy-review',
        ]);

        self::assertSame('awaiting_review', $result['status']);
        self::assertSame('REVIEW_REQUIRED', $result['mode']);
        self::assertSame('submitted', $result['proposal_state']);
    }
}
