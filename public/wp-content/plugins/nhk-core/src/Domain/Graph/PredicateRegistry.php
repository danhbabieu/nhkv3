<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Graph;
use NHK\Core\Graph\Exception\UnknownPredicate;
final class PredicateRegistry {
    public const VERSION = '1.0.0';
    /** @var array<string,PredicateDefinition> */ private array $definitions = [];
    public function __construct() {
        $all=['wp_post','brand','model','variant','movement','music','component','classification','specimen','product','knowledge','source','media','video','evidence'];
        $this->register(new PredicateDefinition('about',$all,$all,'MANY','MANY',false,true,'Canonical semantic about relation; owner-specific associations do not become about edges','REQUIRED','REQUIRED','Public projection follows the direct Graph owner and its eligibility policy'));
        $this->register(new PredicateDefinition('depicts',['media'],$all));
        $this->register(new PredicateDefinition('model_of',['model'],['brand'],'ONE','MANY'));
        $this->register(new PredicateDefinition('variant_of',['variant'],['model'],'ONE','MANY'));
        $this->register(new PredicateDefinition('uses_movement',['variant'],['movement']));
        $this->register(new PredicateDefinition('supports_music',['movement'],['music']));
        $this->register(new PredicateDefinition('configured_with_music',['variant'],['music']));
        $this->register(new PredicateDefinition('observed_playing_music',['specimen'],['music']));
        $this->register(new PredicateDefinition('subtype_of',['classification'],['classification'],'ONE','MANY', false, true, 'source and target must share the same non-empty family; cycles are forbidden', 'OPTIONAL', 'REQUIRED', 'Classification hierarchy only', ['same_family' => true, 'cycle_prohibited' => true, 'exact_one_active_parent' => true]));
        $this->register(new PredicateDefinition('classified_as',['model','variant','specimen','product'],['classification'],'MANY','MANY', false, true, 'target must be an active Classification with a resolved family', 'OPTIONAL', 'REQUIRED', 'Clock Type membership is Graph-owned; Brand↔Clock Type is derived only', ['family_required' => true]));
    }
    public function register(PredicateDefinition $definition): void { $this->definitions[$definition->key]=$definition; }
    public function get(string $key): PredicateDefinition { if (!isset($this->definitions[$key])) throw new UnknownPredicate('Unknown predicate: '.$key); return $this->definitions[$key]; }
    /** @return list<PredicateDefinition> */ public function all(): array { return array_values($this->definitions); }
}
