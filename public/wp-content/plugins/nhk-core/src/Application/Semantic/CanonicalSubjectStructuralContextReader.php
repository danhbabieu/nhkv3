<?php
declare(strict_types=1);
namespace NHK\Core\Application\Semantic;
use NHK\Core\Application\Graph\StructuralContextQuery;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\AuthorityEntity;
final class CanonicalSubjectStructuralContextReader implements SubjectStructuralContextReader
{
    public function __construct(private StructuralContextQuery $contexts, private AuthorityRepository $authority) {}
    public function contextFor(array $candidate): array
    {
        $id = trim((string) ($candidate['id'] ?? '')); $type = trim((string) ($candidate['type'] ?? '')); $entity = $id !== '' ? $this->authority->findByCanonicalId($id) : null;
        $base = ['id' => $id, 'type' => $type, 'revision' => $entity instanceof AuthorityEntity ? $entity->revision : 0];
        if (!$entity instanceof AuthorityEntity || !$entity->active() || $entity->entityType !== $type) return ['status' => 'unavailable', 'candidate' => $base, 'ancestors' => [], 'relation_path' => [], 'reasons' => ['CANONICAL_SUBJECT_NOT_ACTIVE'], 'warnings' => []];
        if ($type === 'brand') return ['status' => 'available', 'candidate' => $base, 'ancestors' => [], 'relation_path' => [], 'reasons' => [], 'warnings' => []];
        $context = match ($type) { 'model' => $this->contexts->forModel($id), 'variant' => $this->contexts->forVariant($id), default => null };
        if ($context === null) return ['status' => 'not_applicable', 'candidate' => $base, 'ancestors' => [], 'relation_path' => [], 'reasons' => [], 'warnings' => []];
        $ids = $type === 'variant' ? [$context->modelId, $context->brandId] : [$context->brandId]; $ancestors = [];
        foreach ($ids as $ancestorId) { if ($ancestorId === null || $ancestorId === '') continue; $ancestor = $this->authority->findByCanonicalId($ancestorId); if ($ancestor instanceof AuthorityEntity && $ancestor->active()) $ancestors[] = ['id' => $ancestor->canonicalId, 'type' => $ancestor->entityType, 'name' => $ancestor->canonicalName, 'stable_key' => $ancestor->stableKey, 'revision' => $ancestor->revision]; }
        return ['status' => $context->reasons === [] ? 'available' : (in_array('RELATIONSHIP_CONFLICT', $context->reasons, true) ? 'conflict' : 'unavailable'), 'candidate' => $base, 'ancestors' => $ancestors, 'relation_path' => $context->relationPath, 'reasons' => array_values($context->reasons), 'warnings' => array_values($context->warnings)];
    }
}

