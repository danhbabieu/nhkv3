<?php
declare(strict_types=1);
namespace NHK\Core\Application\Seo;
final class LivingKnowledgeSeoStabilityGuard
{
    public function evaluate(array $before, array $after): array
    {
        $stable = ['url', 'canonical', 'h1', 'title', 'indexable'];
        $changed = array_values(array_filter($stable, static fn(string $key): bool => ($before[$key] ?? null) !== ($after[$key] ?? null)));
        if ($changed !== []) return ['risk' => 'HIGH', 'allowed' => false, 'diagnostic' => 'HUMAN_GATE_REQUIRED', 'changed' => $changed];
        $medium = ($before['description'] ?? null) !== ($after['description'] ?? null) || ($before['faq'] ?? null) !== ($after['faq'] ?? null);
        return ['risk' => $medium ? 'MEDIUM' : 'LOW', 'allowed' => true, 'diagnostic' => null, 'changed' => $medium ? ['description'] : []];
    }
}
