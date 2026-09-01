<?php
declare(strict_types=1);
namespace NHK\Core\Infrastructure\Graph;
use NHK\Core\Contracts\Graph\EndpointResolver;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Graph\Exception\InvalidEndpointReference;
use NHK\Core\Shared\Uuid\UuidCodec;
final class AuthorityEndpointResolver implements EndpointResolver {
 public function __construct(private EntityTypeRegistry $types,private AuthorityRepository $repo){}
 public function supports(string $type):bool{return $this->types->has($type)&&$this->types->get($type)->graphEnabled;}
 public function normalize(NodeReference $r):NodeReference{if(!$this->supports($r->endpoint_type)||!UuidCodec::isValid($r->endpoint_key))throw new InvalidEndpointReference('Authority endpoint key must be UUID.');return $r;}
 public function exists(NodeReference $r):bool{return $this->repo->findByCanonicalId($r->endpoint_key)!==null;}
}
