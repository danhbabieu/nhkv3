<?php
declare(strict_types=1);

namespace NHK\Core\Application\Authority;

use NHK\Core\Contracts\Authority\{AuthorityRepository,SemanticMergeReferenceAdapter};
use NHK\Core\Domain\Authority\{AuthorityEntity,SemanticMergeReceipt};
use NHK\Core\Shared\Uuid\UuidCodec;

/** Generic, same-type merge coordinator. Adapters own their reference stores. */
final class SemanticMergeService
{
    /** @var array<string,SemanticMergeReceipt> */
    private array $receipts = [];
    /** @param list<SemanticMergeReferenceAdapter> $adapters */
    public function __construct(private AuthorityRepository $authority, private array $adapters, private ?\Closure $audit = null) {}

    public function merge(string $sourceUuid, string $targetUuid, int $sourceRevision, int $targetRevision, string $idempotencyKey): SemanticMergeReceipt
    {
        if (!UuidCodec::isValid($sourceUuid) || !UuidCodec::isValid($targetUuid)) throw new \InvalidArgumentException('Merge identity is invalid.');
        if ($sourceUuid === $targetUuid) throw new \InvalidArgumentException('Merge source and target must differ.');
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Merge idempotency key is required.');
        if ($this->adapters === []) throw new \RuntimeException('Merge reference adapters are unavailable.');
        if (isset($this->receipts[$idempotencyKey])) {
            $prior = $this->receipts[$idempotencyKey];
            if ($prior->sourceUuid !== $sourceUuid || $prior->targetUuid !== $targetUuid || $prior->sourceRevision !== $sourceRevision || $prior->targetRevision !== $targetRevision) throw new \RuntimeException('Merge idempotency conflict.');
            return $prior;
        }
        $source = $this->authority->findByCanonicalId($sourceUuid) ?? throw new \RuntimeException('Merge source not found.');
        $target = $this->authority->findByCanonicalId($targetUuid) ?? throw new \RuntimeException('Merge target not found.');
        if ($source->entityType !== $target->entityType) throw new \InvalidArgumentException('Cross-type merge is forbidden.');
        if (!$source->active() || !$target->active()) throw new \RuntimeException('Only active merge identities are eligible.');
        if ($source->revision !== $sourceRevision || $target->revision !== $targetRevision) throw new \RuntimeException('Merge revision conflict.');
        $references = [];
        foreach ($this->adapters as $adapter) {
            foreach ($adapter->enumerate($source, $target) as $reference) $references[] = $reference;
        }
        $planned = [];
        foreach ($this->adapters as $adapter) foreach ($adapter->plan($source, $target, $references) as $item) $planned[] = ['adapter' => $adapter, 'item' => $item];
        $fingerprint = hash('sha256', json_encode(['v'=>1,'source'=>$sourceUuid,'target'=>$targetUuid,'source_revision'=>$sourceRevision,'target_revision'=>$targetRevision,'plan'=>array_map(static fn(array $entry): array => $entry['item'], $planned)], JSON_THROW_ON_ERROR));
        $moved = 0; $deduped = 0;
        foreach ($planned as $entry) {
            $result = $entry['adapter']->apply($entry['item']);
            if (($result['action'] ?? '') === 'moved') $moved++;
            if (($result['action'] ?? '') === 'deduped') $deduped++;
        }
        $retired = $this->authority->update(new AuthorityEntity($source->canonicalId, $source->entityType, $source->stableKey, $source->canonicalName, $source->schemaVersion, $source->payload, \NHK\Core\Domain\Authority\AuthorityState::RETIRED, $source->revision, $source->createdAt, $source->updatedAt, gmdate('Y-m-d H:i:s.u')), $sourceRevision);
        $remaining = 0;
        foreach ($this->adapters as $adapter) foreach ($adapter->enumerate($retired, $target) as $reference) $remaining++;
        if ($remaining !== 0) throw new \RuntimeException('Merge left active source references.');
        $readBack = $this->authority->findByCanonicalId($sourceUuid);
        if ($readBack === null || $readBack->active() || $readBack->canonicalId !== $sourceUuid) throw new \RuntimeException('Merge read-back failed.');
        $receipt = new SemanticMergeReceipt($sourceUuid, $targetUuid, $sourceRevision, $targetRevision, $fingerprint, array_map(static fn(array $entry): array => $entry['item'], $planned), $moved, $deduped, $remaining, strtolower($retired->state->name), true);
        $this->receipts[$idempotencyKey] = $receipt;
        ($this->audit)?->call('SemanticMergeApplied', $receipt);
        return $receipt;
    }
}
