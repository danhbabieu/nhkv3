<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Entity\EntityMediaProjection;
use NHK\Core\Application\Media\MediaEnrichmentFrontendReadbackVerifier;
use NHK\Core\Application\Mcp\{McpAbilityRegistration, McpReadHandler, McpToolCatalog};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaBindingOperationRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Authority\{AuthorityEntity, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Media\{MediaBindingOperation, MediaUsage, MediaUsageRoleRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MediaBindingReadbackContractTest extends TestCase
{
    public function test_media_binding_read_is_registered_described_and_dispatchable(): void
    {
        self::assertTrue(McpToolCatalog::has('nhk.media.binding.get'));
        self::assertTrue(McpToolCatalog::hasExecutableDispatchHandler('nhk.media.binding.get'));
        self::assertSame('nhk-v3/media-binding-get', McpAbilityRegistration::abilityNameForTool('nhk.media.binding.get'));
    }

    public function test_durable_binding_receipt_reads_by_operation_and_idempotency_key(): void
    {
        $operationId = UuidCodec::newV7();
        $mediaId = UuidCodec::newV7();
        $targetId = UuidCodec::newV7();
        $operation = new MediaBindingOperation($operationId, 'capture:media-binding:0', hash('sha256', 'receipt'), $mediaId, 'classification', $targetId, MediaUsageRoleRegistry::REPRESENTATIVE, 'USER_EXPLICIT', 'PINNED', MediaBindingOperation::COMPLETE, 'COMPLETE');
        $repository = $this->createMock(MediaBindingOperationRepository::class);
        $repository->method('findByOperationId')->with($operationId)->willReturn($operation);
        $repository->method('findByIdempotencyKey')->with($operation->idempotencyKey)->willReturn($operation);
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $handler = new McpReadHandler($this->createMock(AuthorityRepository::class), $types, $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class), $this->createMock(\NHK\Core\Contracts\Video\VideoRepository::class), $this->createMock(\NHK\Core\Contracts\Knowledge\KnowledgeRepository::class), $this->createMock(\NHK\Core\Contracts\Knowledge\EvidenceRepository::class), mediaBindingOperations: $repository);

        self::assertSame($operation->toArray(), $handler->mediaBindingGet($operationId));
        self::assertSame($operation->toArray(), $handler->mediaBindingGet('', $operation->idempotencyKey));
    }

    public function test_public_classification_projection_readback_verifies_exact_ready_representative(): void
    {
        [$verifier, $targetId, $mediaId] = $this->verifier();
        $result = $verifier->verify('classification', $targetId, $mediaId);
        self::assertSame('verified', $result['status']);
        self::assertSame($mediaId, $result['media_id']);
    }

    /** @dataProvider failedReadbackProvider */
    public function test_public_readback_fails_closed_for_projection_or_canonical_mismatch(string $case): void
    {
        [$verifier, $targetId, $mediaId, $projection, $usages] = $this->verifier(true, $case === 'projection' ? UuidCodec::newV7() : null, $case !== 'no_usage');
        if ($case === 'not_public') $verifier = $this->verifier(false)[0];
        $result = $verifier->verify('classification', $targetId, $mediaId);
        self::assertSame('unavailable', $result['status']);
    }

    public static function failedReadbackProvider(): array
    {
        return [['projection'], ['no_usage'], ['not_public']];
    }

    /** @return array{0:MediaEnrichmentFrontendReadbackVerifier,1:string,2:string,3:object,4:object} */
    private function verifier(bool $eligible = true, ?string $projectionMediaId = null, bool $hasUsage = true): array
    {
        $targetId = UuidCodec::newV7();
        $mediaId = UuidCodec::newV7();
        $authority = $this->createMock(AuthorityRepository::class);
        $authority->method('findByCanonicalId')->willReturn(new AuthorityEntity($targetId, 'classification', 'nhk:classification:test', 'Test classification', 1, []));
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $eligibility = static fn (?object $entity): bool => $eligible && $entity !== null;
        $projectionResult = ['representative' => ['media_id' => $projectionMediaId ?? $mediaId, 'asset_id' => UuidCodec::newV7(), 'url' => '/media.webp']];
        $projection = static fn (string $type, string $id): array => $projectionResult;
        $usage = new MediaUsage(UuidCodec::newV7(), $mediaId, 'classification', $targetId, MediaUsageRoleRegistry::REPRESENTATIVE, activeSlot: 'representative', selectionSource: 'USER_EXPLICIT', selectionPolicy: 'PINNED');
        $usages = $this->createMock(MediaUsageRepository::class);
        $usages->method('listByEndpoint')->willReturn($hasUsage ? [$usage] : []);
        return [new MediaEnrichmentFrontendReadbackVerifier($authority, $types, $eligibility, $projection, $usages), $targetId, $mediaId, $projectionResult, $usages];
    }
}
