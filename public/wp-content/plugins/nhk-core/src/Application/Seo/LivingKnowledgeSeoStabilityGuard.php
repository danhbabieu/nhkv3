<?php
declare(strict_types=1);
namespace NHK\Core\Application\Seo;
final class LivingKnowledgeSeoStabilityGuard
{
    public function evaluate(array $before, array $after): array
    {
        $stable = ['url', 'slug', 'canonical', 'h1', 'title', 'primary_search_intent', 'robots', 'indexable', 'schema_id', 'schema_canonical_id', 'redirect_rules'];
        $changed = array_values(array_filter($stable, static fn(string $key): bool => ($before[$key] ?? null) !== ($after[$key] ?? null)));
        if ($changed !== []) return ['risk' => 'HIGH', 'allowed' => false, 'diagnostic' => 'HUMAN_GATE_REQUIRED', 'changed' => $changed];
        $changedMedium = [];
        foreach (['description', 'faq', 'section_order'] as $key) if (($before[$key] ?? null) !== ($after[$key] ?? null)) $changedMedium[] = $key;
        $medium = $changedMedium !== [];
        return ['risk' => $medium ? 'MEDIUM' : 'LOW', 'allowed' => true, 'stronger_verification_required' => $medium, 'diagnostic' => null, 'changed' => $changedMedium];
    }
}
