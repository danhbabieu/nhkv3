<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Contracts\Authority\AuthorityRepository;

final class StructuralDiagnostics
{
    public function __construct(private AuthorityRepository $authority, private StructuralContextQuery $contexts) {}

    /** @return list<array{entity_type:string,entity_id:string,status:string,reason_code:string,parent_candidates:list<string>}> */
    public function read(): array
    {
        $findings = [];
        foreach (['model', 'variant'] as $type) foreach ($this->authority->listByType($type) as $entity) {
            if (!$entity->active()) continue;
            $context = $type === 'model' ? $this->contexts->forModel($entity->canonicalId) : $this->contexts->forVariant($entity->canonicalId);
            if ($context->reasons === []) continue;
            $findings[] = ['entity_type' => $type, 'entity_id' => $entity->canonicalId, 'status' => 'BLOCKED', 'reason_code' => $context->reasons[0], 'parent_candidates' => $this->candidates($type, $entity->canonicalId)];
        }
        usort($findings, static fn (array $a, array $b): int => [$a['entity_type'], $a['entity_id']] <=> [$b['entity_type'], $b['entity_id']]);
        return $findings;
    }

    /** @return list<string> */
    private function candidates(string $type, string $id): array
    {
        $context = $type === 'model' ? $this->contexts->forModel($id) : $this->contexts->forVariant($id);
        $candidates = $type === 'model' ? [$context->brandId] : [$context->modelId];
        $candidates = array_values(array_filter($candidates, static fn (?string $value): bool => $value !== null));
        return array_values(array_unique($candidates));
    }
}
