<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

use InvalidArgumentException;
use NHK\Core\Domain\Article\SemanticProposalCommand;
use NHK\Core\Domain\Governance\CommandCanonicalizer;

final class SemanticProposalPlanner
{
    /** @param list<array<string,mixed>> $commands @return list<SemanticProposalCommand> */
    public function plan(string $operationId, array $commands): array
    {
        if ($operationId === '') throw new InvalidArgumentException('Article operation identity is required.');
        $slots = [];
        $planned = [];
        foreach ($commands as $command) {
            $slot = trim((string) ($command['slot'] ?? ''));
            if ($slot === '' || isset($slots[$slot])) throw new InvalidArgumentException('Semantic proposal slot is missing or duplicated.');
            $slots[$slot] = true;
            $dependencies = array_values(array_map('strval', is_array($command['dependency_slots'] ?? null) ? $command['dependency_slots'] : []));
            $payload = is_array($command['payload'] ?? null) ? $command['payload'] : [];
            $operation = trim((string) ($command['operation'] ?? ''));
            $entityType = trim((string) ($command['entity_type'] ?? ''));
            $subjectId = trim((string) ($command['subject_id'] ?? ''));
            $targetUuid = isset($command['target_uuid']) && (string) $command['target_uuid'] !== '' ? (string) $command['target_uuid'] : null;
            $expectedRevision = (int) ($command['expected_revision'] ?? 1);
            $content = CommandCanonicalizer::fingerprint($operation, $entityType, $targetUuid, $expectedRevision, $payload, []);
            $dependencyFingerprint = CommandCanonicalizer::fingerprint($operation, $entityType, $targetUuid, $expectedRevision, $payload, $dependencies);
            $planned[] = new SemanticProposalCommand($slot, $operation, $entityType, $subjectId, $targetUuid, $expectedRevision, $payload, $dependencies, $operationId . ':semantic:' . $slot, bin2hex($content), bin2hex($dependencyFingerprint));
        }
        return $planned;
    }
}
