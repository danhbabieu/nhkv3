<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\CaptureSubjectBindingRecovery;
use NHK\Core\Application\Semantic\SubjectResolutionService;
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

    public function test_conflicting_existing_binding_is_rejected_instead_of_being_treated_as_an_idempotent_replay(): void
    {
        $repository = new CaptureSubjectBindingRecoveryRepository();
        $capture = new CaptureRecord(
            '01a10bdb-918d-7c6d-a94e-0dd4baa5f391',
            'binding-conflict',
            hash('sha256', 'binding-conflict'),
            'AUTHORITY_APPLIED',
            'APPLIED',
            753,
            'article-token',
            [],
            ['purpose' => 'MIXED', 'subject_resolution_packet' => [
                'status' => 'resolved',
                'canonical_subject_id' => 'd2af7739-3d1b-4666-ad0a-aeda0758f4d8',
                'entity_type' => 'brand',
                'stable_key' => 'nhk:brand:odo',
                'canonical_name' => 'Odo',
                'revision' => 1,
                'primary_source' => 'canonical_uuid',
            ]],
        );
        $repository->create($capture);

        $this->expectExceptionObject(new \RuntimeException('CAPTURE_SUBJECT_BINDING_CONFLICT'));
        (new CaptureSubjectBindingRecovery($repository))->persist($capture, 753, [
            'status' => 'resolved',
            'primary' => [
                'id' => '4cbe5aa1-4222-46bd-a140-6ab66d2da199',
                'type' => 'model',
                'stable_key' => 'nhk:model:vedette.37',
                'name' => 'Vedette 37',
                'revision' => 1,
                'match' => 'uuid_exact',
            ],
            'primary_source' => 'canonical_uuid',
        ]);
    }

    public function test_capture_subject_binding_readback_failure_is_fail_closed(): void
    {
        $repository = new CaptureSubjectBindingRecoveryRepository();
        $repository->suppressReadBack = true;
        $capture = new CaptureRecord(
            '01a10bdb-918d-7c6d-a94e-0dd4baa5f391',
            'binding-readback-failure',
            hash('sha256', 'binding-readback-failure'),
            'AUTHORITY_APPLIED',
            'APPLIED',
            753,
            'article-token',
            [],
            ['purpose' => 'MIXED'],
        );
        $repository->create($capture);

        $this->expectExceptionObject(new \RuntimeException('CAPTURE_SUBJECT_BINDING_READBACK_UNAVAILABLE'));
        (new CaptureSubjectBindingRecovery($repository))->persist($capture, 753, [
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
        ]);
    }

    public function test_binding_persistence_requires_registered_canonical_subject_readback(): void
    {
        $repository = new CaptureSubjectBindingRecoveryRepository();
        $capture = new CaptureRecord(
            '01a10bdb-918d-7c6d-a94e-0dd4baa5f391',
            'binding-canonical-validation',
            hash('sha256', 'binding-canonical-validation'),
            'SEMANTICS_RECONCILED',
            'REVIEW_REQUIRED',
            null,
            null,
            [],
            ['purpose' => 'MIXED'],
        );
        $repository->create($capture);
        $subjects = new SubjectResolutionService(static fn (string $hint): array => []);

        $this->expectExceptionObject(new \RuntimeException('CAPTURE_SUBJECT_BINDING_UNAVAILABLE'));
        (new CaptureSubjectBindingRecovery($repository, $subjects))->persist($capture, 0, [
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
        ]);
    }

    public function test_stale_capture_revision_is_rejected_by_the_binding_write_boundary(): void
    {
        $repository = new CaptureSubjectBindingRecoveryRepository();
        $repository->enforceCas = true;
        $capture = new CaptureRecord(
            '01a10bdb-918d-7c6d-a94e-0dd4baa5f391',
            'binding-stale-revision',
            hash('sha256', 'binding-stale-revision'),
            'SEMANTICS_RECONCILED',
            'REVIEW_REQUIRED',
            null,
            null,
            [],
            ['purpose' => 'MIXED'],
        );
        $repository->create($capture);
        $repository->save(new CaptureRecord(
            $capture->captureId,
            $capture->idempotencyKey,
            $capture->requestFingerprint,
            $capture->stage,
            $capture->status,
            $capture->articleId,
            $capture->articleStateToken,
            $capture->assets,
            $capture->context,
            $capture->diagnostics,
            $capture->phaseReceipts,
            2,
            $capture->createdAt,
            $capture->updatedAt,
        ));

        $this->expectExceptionObject(new \RuntimeException('CAPTURE_STALE_REVISION'));
        (new CaptureSubjectBindingRecovery($repository))->persist($capture, 0, [
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
        ]);
    }

    public function test_ambiguous_canonical_resolver_result_is_not_promoted_to_a_binding(): void
    {
        $repository = new CaptureSubjectBindingRecoveryRepository();
        $capture = new CaptureRecord(
            '01a10bdb-918d-7c6d-a94e-0dd4baa5f391',
            'binding-ambiguous',
            hash('sha256', 'binding-ambiguous'),
            'SEMANTICS_RECONCILED',
            'REVIEW_REQUIRED',
            null,
            null,
            [],
            ['purpose' => 'MIXED'],
        );
        $repository->create($capture);
        $subjects = new SubjectResolutionService(static fn (string $hint): array => [
            ['id' => $hint, 'type' => 'brand', 'stable_key' => 'nhk:brand:one', 'name' => 'One', 'revision' => 1],
            ['id' => $hint, 'type' => 'model', 'stable_key' => 'nhk:model:one', 'name' => 'One', 'revision' => 1],
        ]);

        $this->expectExceptionObject(new \RuntimeException('CAPTURE_SUBJECT_BINDING_AMBIGUOUS'));
        (new CaptureSubjectBindingRecovery($repository, $subjects))->persist($capture, 0, [
            'status' => 'resolved',
            'primary' => [
                'id' => 'd2af7739-3d1b-4666-ad0a-aeda0758f4d8',
                'type' => 'brand',
                'stable_key' => 'nhk:brand:one',
                'name' => 'One',
                'revision' => 1,
                'match' => 'uuid_exact',
            ],
            'primary_source' => 'canonical_uuid',
        ]);
    }
}

final class CaptureSubjectBindingRecoveryRepository implements CaptureRepository
{
    /** @var array<string,CaptureRecord> */
    private array $records = [];
    public bool $suppressReadBack = false;
    public bool $enforceCas = false;

    public function findByIdempotencyKey(string $key): ?CaptureRecord
    {
        foreach ($this->records as $record) if ($record->idempotencyKey === $key) return $record;
        return null;
    }

    public function findById(string $captureId): ?CaptureRecord
    {
        if ($this->suppressReadBack) return null;
        return $this->records[$captureId] ?? null;
    }

    public function create(CaptureRecord $record): CaptureRecord
    {
        return $this->records[$record->captureId] = $record;
    }

    public function save(CaptureRecord $record): CaptureRecord
    {
        $existing = $this->records[$record->captureId] ?? null;
        if ($this->enforceCas && $existing instanceof CaptureRecord && $record->revision !== $existing->revision + 1) throw new \RuntimeException('CAPTURE_STALE_REVISION');
        return $this->records[$record->captureId] = $record;
    }
}
