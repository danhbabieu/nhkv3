<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Graph;
use NHK\Core\Graph\Exception\UnknownPredicate;
final class PredicateRegistry {
    /** @var array<string,PredicateDefinition> */ private array $definitions = [];
    public function __construct() {
        $all=['wp_post','brand','model','variant','movement','music','component','classification','specimen','product','knowledge','source','media','video','evidence'];
        $this->register(new PredicateDefinition('about',$all,$all));
        $this->register(new PredicateDefinition('depicts',['media'],$all));
        $this->register(new PredicateDefinition('model_of',['model'],['brand'],'ONE','MANY'));
        $this->register(new PredicateDefinition('variant_of',['variant'],['model'],'ONE','MANY'));
        $this->register(new PredicateDefinition('uses_movement',['variant'],['movement']));
        $this->register(new PredicateDefinition('supports_music',['movement'],['music']));
        $this->register(new PredicateDefinition('configured_with_music',['variant'],['music']));
        $this->register(new PredicateDefinition('observed_playing_music',['specimen'],['music']));
    }
    public function register(PredicateDefinition $definition): void { $this->definitions[$definition->key]=$definition; }
    public function get(string $key): PredicateDefinition { if (!isset($this->definitions[$key])) throw new UnknownPredicate('Unknown predicate: '.$key); return $this->definitions[$key]; }
    /** @return list<PredicateDefinition> */ public function all(): array { return array_values($this->definitions); }
}
