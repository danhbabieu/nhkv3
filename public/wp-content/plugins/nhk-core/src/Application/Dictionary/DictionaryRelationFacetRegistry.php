<?php
declare(strict_types=1);
namespace NHK\Core\Application\Dictionary;
final class DictionaryRelationFacetRegistry
{
    /** @var array<string,array<string,mixed>> */
    private array $definitions = [];
    public const VERSION = '1.0.0';
    public function __construct()
    {
        foreach ([
            ['facet_key'=>'brands','allowed_target_types'=>['brand']],
            ['facet_key'=>'clock_types','allowed_target_types'=>['classification'],'allowed_classification_families'=>['clock_type']],
            ['facet_key'=>'configurations','allowed_target_types'=>['classification'],'allowed_classification_families'=>['configuration']],
            ['facet_key'=>'movements','allowed_target_types'=>['movement']],
            ['facet_key'=>'music','allowed_target_types'=>['music']],
            ['facet_key'=>'countries','allowed_target_types'=>['classification'],'allowed_classification_families'=>['country']],
            ['facet_key'=>'models','allowed_target_types'=>['model']],
            ['facet_key'=>'variants','allowed_target_types'=>['variant']],
            ['facet_key'=>'specimens','allowed_target_types'=>['specimen']],
            ['facet_key'=>'components','allowed_target_types'=>['component']],
        ] as $definition) $this->register($definition);
    }
    /** @param array<string,mixed> $definition */
    public function register(array $definition): void
    {
        $key = trim((string) ($definition['facet_key'] ?? ''));
        $types = array_values(array_unique(array_map('strval', (array) ($definition['allowed_target_types'] ?? []))));
        $families = array_values(array_unique(array_map('strval', (array) ($definition['allowed_classification_families'] ?? []))));
        if ($key === '' || $types === []) throw new \InvalidArgumentException('DICTIONARY_FACET_DEFINITION_INVALID');
        if (isset($this->definitions[$key]) && $this->definitions[$key] !== ['facet_key'=>$key,'allowed_target_types'=>$types,'allowed_classification_families'=>$families]) throw new \InvalidArgumentException('DICTIONARY_FACET_CONFLICT');
        $this->definitions[$key] = ['facet_key'=>$key,'allowed_target_types'=>$types,'allowed_classification_families'=>$families];
    }
    public function facetFor(string $targetType, ?string $classificationFamily = null): ?string
    {
        foreach ($this->definitions as $key => $definition) {
            if (!in_array($targetType, $definition['allowed_target_types'], true)) continue;
            $families = $definition['allowed_classification_families'];
            if ($targetType === 'classification' && (!in_array((string) $classificationFamily, $families, true))) continue;
            if ($targetType !== 'classification' && $families !== []) continue;
            return $key;
        }
        return null;
    }
    /** @return array<string,array<string,mixed>> */
    public function all(): array { return $this->definitions; }
    public function version(): string { return self::VERSION; }
    public function hash(): string { return hash('sha256', json_encode(['version'=>self::VERSION,'definitions'=>$this->definitions], JSON_UNESCAPED_SLASHES)); }
}
