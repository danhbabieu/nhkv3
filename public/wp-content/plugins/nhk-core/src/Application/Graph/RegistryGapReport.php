<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Domain\Graph\PredicateRegistry;

final class RegistryGapReport
{
    private const APPROVED = [
        'model_of' => ['model', 'brand'], 'variant_of' => ['variant', 'model'], 'uses_movement' => ['variant', 'movement'],
        'supports_music' => ['movement', 'music'], 'configured_with_music' => ['variant', 'music'], 'observed_playing_music' => ['specimen', 'music'],
    ];

    public function __construct(private PredicateRegistry $registry) {}

    /** @return array<string,array{classification:string,source_type:string,target_type:string}> */
    public function read(): array
    {
        $report = [];
        foreach (self::APPROVED as $key => [$source, $target]) {
            $classification = 'REGISTERED';
            try { $definition = $this->registry->get($key); if ($definition->allowed_source_types !== [$source] || $definition->allowed_target_types !== [$target]) $classification = 'CONSTITUTION_CONFLICT'; }
            catch (\Throwable) { $classification = 'REGISTRY_GAP'; }
            $report[$key] = ['classification' => $classification, 'source_type' => $source, 'target_type' => $target];
        }
        return $report;
    }
}
