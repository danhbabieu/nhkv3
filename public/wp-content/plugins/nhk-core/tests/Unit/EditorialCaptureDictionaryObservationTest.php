<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Capture\EditorialCaptureCoordinator;
use NHK\Core\Application\Dictionary\DictionaryObservationRegistry;
use NHK\Core\Application\Semantic\{ArticleComposer, ClaimRetrievalEngine, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Capture\CaptureRecord;
use PHPUnit\Framework\TestCase;

final class EditorialCaptureDictionaryObservationTest extends TestCase
{
    protected function tearDown(): void
    {
        DictionaryObservationRegistry::register(
            static fn (): array => ['status' => 'NOT_CONFIGURED', 'blocking' => false],
            static fn (): array => ['status' => 'NOT_CONFIGURED', 'blocking' => false],
        );
    }

    public function test_lexical_observation_is_persisted_before_subject_conflict_blocks_capture(): void
    {
        $observations = [];
        DictionaryObservationRegistry::register(
            static function (string $kind, string $sourceId, string $text, array $context = [], array $hints = []) use (&$observations): array {
                $observations[] = compact('kind', 'sourceId', 'text', 'context', 'hints');
                return ['status' => 'AVAILABLE', 'blocking' => false];
            },
            static fn (): array => ['status' => 'AVAILABLE', 'blocking' => false],
        );

        $coordinator = new EditorialCaptureCoordinator(
            new InMemoryCaptureRepositoryForDictionaryTest(),
            static fn (array $input): array => ['items' => []],
            static fn (array $input): array => ['post_id' => 0],
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => match ($hint) {
                'Subject A' => [['id' => 'subject-a', 'type' => 'variant', 'name' => 'Subject A', 'revision' => 1]],
                'Subject B' => [['id' => 'subject-b', 'type' => 'variant', 'name' => 'Subject B', 'revision' => 1]],
                default => [],
            }),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static fn (array $context): array => ['status' => 'PLANNED', 'writes' => []],
            new ArticleComposer(),
            static fn (array $context): array => ['status' => 'RECONCILED'],
            static fn (array $context): array => ['eligible' => false, 'blockers' => []],
            static fn (array $context): array => ['status' => 'verified'],
        );

        $result = $coordinator->execute([
            'idempotency_key' => 'capture-dictionary-before-subject-blocker',
            'text' => 'Côn hoa thị là bộ có đặc trưng của dòng đồng hồ Junghans.',
            'subject_hints' => ['Subject A', 'Subject B'],
        ]);

        self::assertSame('REVIEW_REQUIRED', $result->status);
        self::assertSame('ambiguous', $result->diagnostics['subjects']['status'] ?? null);
        self::assertCount(1, $observations);
        self::assertSame('CAPTURE', $observations[0]['kind']);
        self::assertSame($result->captureId, $observations[0]['sourceId']);
        self::assertSame('Côn hoa thị là bộ có đặc trưng của dòng đồng hồ Junghans.', $observations[0]['text']);
    }
}

final class InMemoryCaptureRepositoryForDictionaryTest implements CaptureRepository
{
    /** @var array<string,CaptureRecord> */
    private array $records = [];

    public function findByIdempotencyKey(string $key): ?CaptureRecord { return $this->records[$key] ?? null; }
    public function findById(string $captureId): ?CaptureRecord { foreach ($this->records as $record) if ($record->captureId === $captureId) return $record; return null; }
    public function create(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] ??= $record; }
    public function save(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] = $record; }
}
