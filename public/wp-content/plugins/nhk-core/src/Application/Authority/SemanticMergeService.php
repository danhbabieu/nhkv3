<?php
declare(strict_types=1);

namespace NHK\Core\Application\Authority;

use NHK\Core\Contracts\Authority\{AuthorityRepository,SemanticMergeReferenceAdapter,SemanticMergeReceiptRepository};
use NHK\Core\Domain\Authority\{AuthorityEntity,SemanticMergeReceipt};
use NHK\Core\Shared\Uuid\UuidCodec;

/** Generic, same-type merge coordinator. Adapters own their reference stores. */
final class SemanticMergeService
{
    /** @var array<string,SemanticMergeReceipt> */
    /** @param list<SemanticMergeReferenceAdapter> $adapters */
    public function __construct(private AuthorityRepository $authority, private array $adapters, private ?\Closure $audit = null, private ?SemanticMergeReceiptRepository $receiptRepository = null) {}

    public function merge(string $sourceUuid, string $targetUuid, int $sourceRevision, int $targetRevision, string $idempotencyKey): SemanticMergeReceipt
    {
        if (!UuidCodec::isValid($sourceUuid) || !UuidCodec::isValid($targetUuid)) throw new \InvalidArgumentException('Merge identity is invalid.');
        if ($sourceUuid === $targetUuid) throw new \InvalidArgumentException('Merge source and target must differ.');
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Merge idempotency key is required.');
        if ($this->adapters === []) throw new \RuntimeException('Merge reference adapters are unavailable.');
        $prior = $this->receiptRepository?->findByIdempotencyKey($idempotencyKey);
        if ($prior !== null && ($prior->sourceUuid !== $sourceUuid || $prior->targetUuid !== $targetUuid || $prior->sourceRevision !== $sourceRevision || $prior->targetRevision !== $targetRevision)) throw new \RuntimeException('Merge idempotency conflict.');
        if ($prior !== null && $prior->status === 'completed' && $prior->readBackVerified) return $prior;
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
        if ($prior !== null && $prior->planFingerprint !== $fingerprint) throw new \RuntimeException('Merge idempotency plan conflict.');
        $attemptId = UuidCodec::newV7();
        $startedAt = gmdate('Y-m-d H:i:s.u');
        $this->persist(new SemanticMergeReceipt($sourceUuid, $targetUuid, $sourceRevision, $targetRevision, $fingerprint, array_map(static fn(array $entry): array => $entry['item'], $planned), 0, 0, count($planned), strtolower($source->state->name), false, 'merge', 'applying', count($planned), 0, 0, count($planned), $attemptId, $prior?->createdAt ?? $startedAt, $startedAt, $idempotencyKey));
        $moved = $prior?->referencesMoved ?? 0; $deduped = $prior?->referencesDeduped ?? 0;
        foreach ($planned as $index => $entry) {
            if ($prior !== null && $index < $prior->referencesMoved + $prior->referencesDeduped) continue;
            try {
                $result = $entry['adapter']->apply($entry['item']);
                if (($result['action'] ?? '') === 'moved') $moved++;
                if (($result['action'] ?? '') === 'deduped') $deduped++;
            } catch (\Throwable $error) {
                $this->persist(new SemanticMergeReceipt($sourceUuid, $targetUuid, $sourceRevision, $targetRevision, $fingerprint, array_map(static fn(array $entry): array => $entry['item'], $planned), $moved, $deduped, count($planned) - $moved - $deduped, strtolower($source->state->name), false, 'merge', 'partial', count($planned), $moved, $deduped, count($planned) - $moved - $deduped, $attemptId, $prior?->createdAt ?? $startedAt, gmdate('Y-m-d H:i:s.u'), $idempotencyKey));
                throw $error;
            }
            $this->persist(new SemanticMergeReceipt($sourceUuid, $targetUuid, $sourceRevision, $targetRevision, $fingerprint, array_map(static fn(array $entry): array => $entry['item'], $planned), $moved, $deduped, count($planned) - $moved - $deduped, strtolower($source->state->name), false, 'merge', 'applying', count($planned), $moved, $deduped, count($planned) - $moved - $deduped, $attemptId, $prior?->createdAt ?? $startedAt, gmdate('Y-m-d H:i:s.u'), $idempotencyKey));
        }
        $retirementCandidate = new AuthorityEntity($source->canonicalId, $source->entityType, $source->stableKey, $source->canonicalName, $source->schemaVersion, $source->payload, \NHK\Core\Domain\Authority\AuthorityState::RETIRED, $source->revision, $source->createdAt, $source->updatedAt, gmdate('Y-m-d H:i:s.u'));
        $remaining = 0;
        foreach ($this->adapters as $adapter) foreach ($adapter->enumerate($retirementCandidate, $target) as $reference) $remaining++;
        foreach ($planned as $entry) if (!$entry['adapter']->verify($entry['item'])) throw new \RuntimeException('Merge reference read-back failed.');
        if ($remaining !== 0) throw new \RuntimeException('Merge left active source references.');
        $retired = $this->authority->update($retirementCandidate, $sourceRevision);
        $readBack = $this->authority->findByCanonicalId($sourceUuid);
        if ($readBack === null || $readBack->active() || $readBack->canonicalId !== $sourceUuid) throw new \RuntimeException('Merge read-back failed.');
        $receipt = new SemanticMergeReceipt($sourceUuid, $targetUuid, $sourceRevision, $targetRevision, $fingerprint, array_map(static fn(array $entry): array => $entry['item'], $planned), $moved, $deduped, $remaining, strtolower($retired->state->name), true, 'merge', 'completed', count($planned), $moved, $deduped, $remaining, $attemptId, $prior?->createdAt ?? $startedAt, gmdate('Y-m-d H:i:s.u'), $idempotencyKey);
        $this->persist($receipt);
        ($this->audit)?->call('SemanticMergeApplied', $receipt);
        return $receipt;
    }

    private function persist(SemanticMergeReceipt $receipt): void
    {
        $this->receiptRepository?->append($receipt);
    }
}
