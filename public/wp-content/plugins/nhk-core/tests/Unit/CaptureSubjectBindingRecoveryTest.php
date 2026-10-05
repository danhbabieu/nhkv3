<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\CaptureSubjectBindingRecovery;
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Capture\CaptureRecord;
use PHPUnit\Framework\TestCase;

final class CaptureSubjectBindingRecoveryTest extends TestCase
{
    public function test_historical_mixed_capture_resolves_from_existing_subject_context_before_persisting(): void
    {
        $repository = new CaptureSubjectBindingRecoveryRepository();
        $capture = new CaptureRecord(
            '01a10bdb-918d-7c6d-a94e-0dd4baa5f391',
            'mixed-odo-context',
            hash('sha256', 'mixed-odo-context'),
            'AUTHORITY_PLANNED',
            'PLANNED',
            753,
            'article-token',
            [],
            ['purpose' => 'MIXED', 'subject_hints' => ['d2af7739-3d1b-4666-ad0a-aeda0758f4d8']],
        );
        $repository->create($capture);

        $recovery = new CaptureSubjectBindingRecovery($repository);
        $packet = $recovery->resolve($capture, [], [], static function (array $hints): array {
            self::assertSame(['d2af7739-3d1b-4666-ad0a-aeda0758f4d8'], $hints);
            return ['status' => 'resolved', 'primary' => ['id' => $hints[0], 'type' => 'brand', 'stable_key' => 'nhk:brand:odo', 'name' => 'Odo', 'revision' => 1, 'match' => 'uuid_exact'], 'primary_source' => 'canonical_uuid'];
        });

        self::assertNotNull($packet);
        self::assertSame('d2af7739-3d1b-4666-ad0a-aeda0758f4d8', $packet->canonicalSubjectId);
        $repaired = $recovery->persist($capture, 753, $packet->toResolution());
        self::assertSame('d2af7739-3d1b-4666-ad0a-aeda0758f4d8', $repository->findById($capture->captureId)?->context['subject_resolution_packet']['canonical_subject_id']);
        self::assertSame(2, $repaired->revision);
    }

    public function test_mixed_capture_owned_article_persists_exact_resolved_subject_binding_and_reads_it_back(): void
    {
        $repository = new CaptureSubjectBindingRecoveryRepository();
        $capture = new CaptureRecord(
            '01a10bdb-918d-7c6d-a94e-0dd4baa5f391',
            'mixed-odo',
            hash('sha256', 'mixed-odo'),
            'AUTHORITY_PLANNED',
            'PLANNED',
            753,
            'article-token',
            [],
            ['purpose' => 'MIXED'],
        );
        $repository->create($capture);
        $resolution = [
            'status' => 'resolved',
            'primary' => [
                'id' => 'd2af7739-3d1b-4666-ad0a-aeda0758f4d8',
                'type' => 'brand',
                'stable_key' => 'nhk:brand:odo',
                'name' => 'Odo',
                'revision' => 1,
                'match' => 'uuid_exact',
            ],
            'primary_source' => 'canonical_uuid',
        ];

        $recovery = new CaptureSubjectBindingRecovery($repository);
        $repaired = $recovery->persist($capture, 753, $resolution);

        self::assertSame(753, $repaired->articleId);
        self::assertSame('d2af7739-3d1b-4666-ad0a-aeda0758f4d8', $repaired->context['subject_resolution_packet']['canonical_subject_id']);
        self::assertSame('uuid_exact', $repaired->context['subject_resolution_packet']['match_reason']);
        self::assertSame('d2af7739-3d1b-4666-ad0a-aeda0758f4d8', $repaired->diagnostics['subjects']['primary']['id']);
        self::assertSame(2, $repaired->revision);

        $readBack = $repository->findById($capture->captureId);
        self::assertNotNull($readBack);
        self::assertSame($repaired->context['subject_resolution_packet'], $readBack->context['subject_resolution_packet']);

        $replayed = $recovery->persist($readBack, 753, $resolution);
        self::assertSame($repaired->revision, $replayed->revision);
    }
}

final class CaptureSubjectBindingRecoveryRepository implements CaptureRepository
{
    /** @var array<string,CaptureRecord> */
    private array $records = [];

    public function findByIdempotencyKey(string $key): ?CaptureRecord
    {
        foreach ($this->records as $record) if ($record->idempotencyKey === $key) return $record;
        return null;
    }

    public function findById(string $captureId): ?CaptureRecord
    {
        return $this->records[$captureId] ?? null;
    }

    public function create(CaptureRecord $record): CaptureRecord
    {
        return $this->records[$record->captureId] = $record;
    }

    public function save(CaptureRecord $record): CaptureRecord
    {
        return $this->records[$record->captureId] = $record;
    }
}
