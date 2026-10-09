<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Graph;
use NHK\Core\Graph\Exception\UnknownPredicate;
final class PredicateRegistry {
    public const VERSION = '1.2.0';
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
        $this->register(new PredicateDefinition(
            'specimen_of',
            ['specimen'],
            ['model', 'variant'],
            'ONE',
            'MANY',
            false,
            true,
            'Physical-object identity; a Specimen has one active Model-or-Variant target.',
            'REQUIRED',
            'REQUIRED',
            'Public projection derives Model and Brand through the canonical parent chain; no shortcut Brand edge.',
            ['identity_relation' => true, 'one_active_target_across_target_types' => true, 'compatibility_field' => 'model_uuid']
        ));
        $this->register(new PredicateDefinition(
            'lists_specimen',
            ['product'],
            ['specimen'],
            'ONE',
            'MANY',
            false,
            true,
            'Commercial Product listing for one concrete physical Specimen.',
            'REQUIRED',
            'REQUIRED',
            'Product remains commercial truth; Specimen remains physical truth and historical links are retained.',
            ['listing_relation' => true, 'historical' => true, 'multi_object_forbidden' => true]
        ));
        $this->register(new PredicateDefinition('associated_with',['component','movement','variant'],['brand','movement','music','classification'],'MANY','MANY',false,true,'Bounded curated association; exact source/target pairs are enforced by SemanticEnrichmentRelationRegistry','REQUIRED','REQUIRED','Only approved pairwise associations; stronger predicates win',['bounded' => true, 'exact_pairs' => true, 'strong_predicate_precedence' => true]));
        $this->register(new PredicateDefinition('subtype_of',['classification'],['classification'],'ONE','MANY', false, true, 'source and target must share the same non-empty family; cycles are forbidden', 'OPTIONAL', 'REQUIRED', 'Classification hierarchy only', ['same_family' => true, 'cycle_prohibited' => true, 'exact_one_active_parent' => true]));
        $this->register(new PredicateDefinition('classified_as',['model','variant','specimen','product'],['classification'],'MANY','MANY', false, true, 'target must be an active Classification with a resolved family', 'OPTIONAL', 'REQUIRED', 'Clock Type membership is Graph-owned; Brand↔Clock Type is derived only', ['family_required' => true]));
    }
    public function register(PredicateDefinition $definition): void { $this->definitions[$definition->key]=$definition; }
    public function get(string $key): PredicateDefinition { if (!isset($this->definitions[$key])) throw new UnknownPredicate('Unknown predicate: '.$key); return $this->definitions[$key]; }
    /** @return list<PredicateDefinition> */ public function all(): array { return array_values($this->definitions); }
}
