<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Dictionary\DictionaryLexicalRelationGovernanceAdapter;
use NHK\Core\Application\Dictionary\DictionaryRuntime;
use NHK\Core\Application\Mcp\McpDictionaryHandler;
use NHK\Core\Contracts\Dictionary\DictionaryLexicalRelationRepository;
use NHK\Core\Domain\Dictionary\DictionaryLexicalRelation;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class McpLexicalRelationReadRegressionTest extends TestCase
{
    public function test_apply_then_separate_mcp_read_finds_relation_by_uuid_and_idempotency_key(): void
    {
        $repository = new class implements DictionaryLexicalRelationRepository {
            /** @var array<string,DictionaryLexicalRelation> */
            private array $relations = [];

            public function create(DictionaryLexicalRelation $relation): DictionaryLexicalRelation { return $this->relations[$relation->relationUuid] = $relation; }
            public function findByUuid(string $uuid): ?DictionaryLexicalRelation { return $this->relations[$uuid] ?? null; }
            public function findByIdempotencyKey(string $key): ?DictionaryLexicalRelation
            {
                foreach ($this->relations as $relation) if ($relation->idempotencyKey === $key) return $relation;
                return null;
            }
            public function update(DictionaryLexicalRelation $relation, int $expectedRevision): DictionaryLexicalRelation { return $this->relations[$relation->relationUuid] = $relation; }
            public function retire(DictionaryLexicalRelation $relation, int $expectedRevision): DictionaryLexicalRelation { return $this->relations[$relation->relationUuid] = $relation->retired($expectedRevision); }
            public function reactivate(DictionaryLexicalRelation $relation, int $expectedRevision): DictionaryLexicalRelation { return $this->relations[$relation->relationUuid] = $relation->reactivated($expectedRevision); }
            public function listForEntry(string $entryUuid, int $afterId = 0, int $limit = 100, bool $includeRetired = false): array { return ['items' => [], 'next_cursor' => null]; }
        };
        $source = UuidCodec::newV7();
        $target = UuidCodec::newV7();
        $idempotencyKey = 'mcp-lexical-read-regression';
        $adapter = new DictionaryLexicalRelationGovernanceAdapter(
            $repository,
            static fn (string $id): array => ['active' => true],
            static fn (string $entry, string $sense): bool => true,
        );
        $runtime = (new \ReflectionClass(DictionaryRuntime::class))->newInstanceWithoutConstructor();
        $handler = new McpDictionaryHandler($runtime, null, $adapter);
        $preview = $handler->lexicalRelationPreview([
            'operation' => 'ADD',
            'source_entry_uuid' => $source,
            'target_entry_uuid' => $target,
            'kind' => DictionaryLexicalRelation::RELATED,
            'idempotency_key' => $idempotencyKey,
        ]);
        self::assertSame('READY', $preview['status']);
        $applied = $handler->lexicalRelationApply([
            'plan' => $preview['plan'],
            'approved_plan_fingerprint' => $preview['plan_fingerprint'],
            'idempotency_key' => $idempotencyKey,
        ]);
        self::assertSame('READ_BACK_VERIFIED', $applied['status']);
        $relationUuid = $applied['relation']['relation_uuid'];

        $byUuid = $handler->lexicalRelationRead(['relation_uuid' => $relationUuid]);
        $byKey = $handler->lexicalRelationRead(['idempotency_key' => $idempotencyKey]);
        self::assertSame('available', $byUuid['status']);
        self::assertSame($relationUuid, $byUuid['relation']['relation_uuid']);
        self::assertSame('available', $byKey['status']);
        self::assertSame($relationUuid, $byKey['relation']['relation_uuid']);
    }
}
