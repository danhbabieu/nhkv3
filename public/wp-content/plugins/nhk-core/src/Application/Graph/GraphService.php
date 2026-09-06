<?php
declare(strict_types=1);
namespace NHK\Core\Application\Graph;
use NHK\Core\Contracts\Graph\{AuditSink,GraphRepository};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry,GraphEdge,NodeReference,PredicateRegistry,RelationPolicy};
use NHK\Core\Graph\Exception\{InvalidRelationSourceType,InvalidRelationTargetType};
final class GraphService {
    public function __construct(private GraphRepository $repository, private EndpointTypeRegistry $endpoints, private PredicateRegistry $predicates, private AuditSink $audit) {}
    public function create(NodeReference $source, string $predicate, NodeReference $target): GraphEdge {
        $definition=$this->predicates->get($predicate); $source=$this->endpoints->assertExists($source); $target=$this->endpoints->assertExists($target);
        RelationPolicy::assertCanCreate($definition->key, $source->endpoint_type, $target->endpoint_type);
        if (!$definition->allows($source->endpoint_type,$target->endpoint_type)) { if(!in_array($source->endpoint_type,$definition->allowed_source_types,true)) throw new InvalidRelationSourceType('Invalid relation source type.'); throw new InvalidRelationTargetType('Invalid relation target type.'); }
        if (!$definition->allow_self_relation && $source->key()===$target->key()) throw new InvalidRelationTargetType('Self relation is not allowed.');
        $edge=$this->repository->createEdge($this->repository->resolveNode($source),$definition,$this->repository->resolveNode($target)); $this->audit->record('RelationCreated',$edge); return $edge;
    }
    public function findOutgoing(NodeReference $source, ?string $predicate=null, int $after=0, int $limit=50, bool $includeRetired=false, ?string $targetType=null): array { $ref=$this->endpoints->assertExists($source); $node=$this->repository->findNode($ref); return $node ? $this->repository->outgoing($node,$predicate,max(0,$after),min(200,max(1,$limit)),$includeRetired,$targetType) : ['items'=>[],'next_cursor'=>null]; }
    public function findIncoming(NodeReference $target, ?string $predicate=null, int $after=0, int $limit=50, bool $includeRetired=false, ?string $sourceType=null): array { $ref=$this->endpoints->assertExists($target); $node=$this->repository->findNode($ref); return $node ? $this->repository->incoming($node,$predicate,max(0,$after),min(200,max(1,$limit)),$includeRetired,$sourceType) : ['items'=>[],'next_cursor'=>null]; }
    public function findEdge(NodeReference $source,string $predicate,NodeReference $target): ?GraphEdge { $this->predicates->get($predicate); return $this->repository->findEdge($this->endpoints->assertExists($source),$predicate,$this->endpoints->assertExists($target)); }
    public function retire(string $uuid,int $expectedRevision): GraphEdge { $edge=$this->repository->findByUuid($uuid); if(!$edge) throw new \NHK\Core\Graph\Exception\EndpointNotFound('Edge not found.'); $out=$this->repository->retire($edge,$expectedRevision); $this->audit->record('RelationRetired',$out); return $out; }
    public function reactivate(string $uuid,int $expectedRevision): GraphEdge { $edge=$this->repository->findByUuid($uuid); if(!$edge) throw new \NHK\Core\Graph\Exception\EndpointNotFound('Edge not found.'); $out=$this->repository->reactivate($edge,$expectedRevision); $this->audit->record('RelationReactivated',$out); return $out; }
}
