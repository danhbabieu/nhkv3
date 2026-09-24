<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

require_once __DIR__ . '/KnowledgeWriterPreviewServiceTest.php';

use PHPUnit\Framework\TestCase;

final class ConnectedReadOnlyOwnerProbe
{
    public int $readCount = 0;

    /** @param array<string,array{records:list<array<string,mixed>>,write_calls:int,read_calls:int}> $owners */
    public function __construct(private array &$owners, private array &$claimRows)
    {
    }

    /** @return array<string,array{records:list<array<string,mixed>>,write_calls:int}> */
    public function read(): array
    {
        $this->readCount++;
        foreach ($this->owners as &$owner) $owner['read_calls']++;
        unset($owner);
        $this->owners['KnowledgeClaim']['records'] = $this->claimRows;
        $snapshot = [];
        foreach ($this->owners as $name => $owner) {
            $snapshot[$name] = [
                'records' => $owner['records'],
                'write_calls' => $owner['write_calls'],
            ];
        }
        return $snapshot;
    }
}

final class KnowledgeWriterPreviewSafetyTest extends TestCase
{
    use KnowledgeWriterPreviewFixture;

    /** @var array<string,array{records:list<array<string,mixed>>,write_calls:int,read_calls:int}> */
    private array $connectedOwners = [];

    public function test_preview_is_deterministic_and_does_not_mutate_authority_or_claim_fixtures(): void
    {
        $owners = [];
        foreach (['Capture', 'Post', 'Media', 'MediaUsage', 'Video', 'Source', 'Evidence', 'Graph',
            'Proposal', 'Governance', 'PublicIdentity', 'SeoProjection'] as $name) {
            $owners[$name] = ['records' => [['id' => strtolower($name) . ':existing', 'revision' => 3, 'payload' => ['state' => 'active']]], 'write_calls' => 0, 'read_calls' => 0];
        }
        $owners['KnowledgeClaim'] = ['records' => $this->rows, 'write_calls' => 0, 'read_calls' => 0];
        $this->rows[0]['source_ids'] = [$owners['Source']['records'][0]['id']];
        $this->rows[0]['evidence_ids'] = [$owners['Evidence']['records'][0]['id']];
        $probe = new ConnectedReadOnlyOwnerProbe($owners, $this->rows);
        $this->ownerProbe = $probe;
        $snapshot = static fn (): array => $probe->read();
        $before = ['authority' => serialize($this->authority), 'stores' => $snapshot()];
        $service = $this->service();
        $first = $service->preview($this->request());
        $second = $service->preview($this->request());
        self::assertSame(json_encode($first), json_encode($second));
        self::assertSame(3, $probe->readCount);
        self::assertSame($before, ['authority' => serialize($this->authority), 'stores' => $snapshot()]);
        foreach ($snapshot() as $store) self::assertSame(0, $store['write_calls']);
    }

    public function test_facade_constructor_has_only_resolution_and_transient_read_dependencies(): void
    {
        $parameters = (new \ReflectionClass(\NHK\Core\Application\Semantic\KnowledgeWriterPreviewService::class))->getConstructor()->getParameters();
        $types = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getType()?->getName() ?? '', $parameters);
        self::assertSame([
            \NHK\Core\Application\Mcp\McpSemanticContextResolver::class,
            \NHK\Core\Application\Semantic\SubjectResolutionService::class,
            \NHK\Core\Application\Semantic\SharedEnrichmentBoundary::class,
            \NHK\Core\Application\Semantic\ReaderJourneyPlanner::class,
            \NHK\Core\Application\Semantic\SharedEditorialComposer::class,
            \NHK\Core\Application\Semantic\EditorialQualityGate::class,
        ], $types);
    }
}
