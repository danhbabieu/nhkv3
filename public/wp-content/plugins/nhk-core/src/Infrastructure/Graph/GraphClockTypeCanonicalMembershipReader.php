<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Graph;

use NHK\Core\Application\Entity\EntityProfileResolver;
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Capture\{ClockTypeCanonicalMembershipDiagnosticsReader, ClockTypeCanonicalMembershipReadResult, ClockTypeCanonicalMembershipReader};
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Read-only adapter from the canonical Graph application boundary to the
 * Capture shadow classifier. It never materializes nodes or relations.
 */
final class GraphClockTypeCanonicalMembershipReader implements ClockTypeCanonicalMembershipReader, ClockTypeCanonicalMembershipDiagnosticsReader
{
    /** @var list<string> */
    private const ALLOWED_SOURCE_TYPES = ['model', 'variant', 'specimen', 'product'];

    public function __construct(
        private GraphService $graph,
        private AuthorityRepository $authority,
        private EntityProfileResolver $profiles = new EntityProfileResolver(),
    ) {}

    /** @return list<AuthorityEntity> */
    public function listClockTypesForSubject(string $sourceType, string $sourceId): array
    {
        return $this->readClockTypeMemberships($sourceType, $sourceId)->members;
    }

    public function readClockTypeMemberships(string $sourceType, string $sourceId): ClockTypeCanonicalMembershipReadResult
    {
        $sourceType = trim($sourceType);
        $sourceId = trim($sourceId);
        if (!in_array($sourceType, self::ALLOWED_SOURCE_TYPES, true)) {
            return new ClockTypeCanonicalMembershipReadResult('EMPTY', [], ['UNSUPPORTED_MEMBERSHIP_SOURCE_TYPE']);
        }
        if (!UuidCodec::isValid($sourceId)) {
            return new ClockTypeCanonicalMembershipReadResult('EMPTY', [], ['INVALID_MEMBERSHIP_SOURCE_ID']);
        }

        try {
            $result = $this->graph->findOutgoing(
                new NodeReference($sourceType, $sourceId),
                'classified_as',
                0,
                200,
                false,
                'classification',
            );
        } catch (\Throwable) {
            return new ClockTypeCanonicalMembershipReadResult('UNAVAILABLE', [], ['CANONICAL_GRAPH_MEMBERSHIP_READ_UNAVAILABLE']);
        }

        $members = [];
        $diagnostics = [];
        foreach ((array) ($result['items'] ?? []) as $edge) {
            if (!$edge instanceof \NHK\Core\Domain\Graph\GraphEdge || !$edge->isActive()) {
                $diagnostics[] = 'INVALID_CLASSIFIED_AS_EDGE';
                continue;
            }
            if ($edge->source->reference->endpoint_type !== $sourceType || $edge->source->reference->endpoint_key !== $sourceId) {
                $diagnostics[] = 'INVALID_CLASSIFIED_AS_SOURCE';
                continue;
            }
            $target = $edge->target->reference;
            if ($target->endpoint_type !== 'classification') {
                $diagnostics[] = 'INVALID_CLASSIFIED_AS_TARGET_TYPE';
                continue;
            }
            $entity = $this->authority->findByCanonicalId($target->endpoint_key);
            if (!$entity instanceof AuthorityEntity) {
                $diagnostics[] = 'DANGLING_CLASSIFIED_AS_TARGET';
                continue;
            }
            if (!$entity->active()) {
                $diagnostics[] = 'INACTIVE_CLASSIFIED_AS_TARGET';
                continue;
            }
            $profile = $this->profiles->resolveProfile($entity);
            if (!$profile->resolved() || $profile->profileKey !== 'clock_type') {
                $diagnostics[] = $profile->diagnostic === 'FAMILY_NOT_CLOCK_TYPE'
                    ? 'CLASSIFICATION_FAMILY_NOT_CLOCK_TYPE'
                    : 'CLASSIFICATION_FAMILY_UNRESOLVED';
                continue;
            }
            $members[$entity->canonicalId] = $entity;
            if ($profile->status === 'COMPATIBILITY_READ') $diagnostics[] = 'DATA_COMPATIBILITY_GAP';
        }

        return new ClockTypeCanonicalMembershipReadResult(
            $members === [] ? 'EMPTY' : 'AVAILABLE',
            array_values($members),
            array_values(array_unique($diagnostics)),
        );
    }
}
