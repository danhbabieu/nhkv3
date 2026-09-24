<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

require_once __DIR__ . '/KnowledgeWriterPreviewServiceTest.php';

use PHPUnit\Framework\TestCase;

final class KnowledgeWriterPreviewSafetyTest extends TestCase
{
    use KnowledgeWriterPreviewFixture;

    public function test_preview_is_deterministic_and_does_not_mutate_authority_or_claim_fixtures(): void
    {
        $before = serialize([$this->authority, $this->rows]);
        $service = $this->service();
        $first = $service->preview($this->request());
        $second = $service->preview($this->request());
        self::assertSame(json_encode($first), json_encode($second));
        self::assertSame($before, serialize([$this->authority, $this->rows]));
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
