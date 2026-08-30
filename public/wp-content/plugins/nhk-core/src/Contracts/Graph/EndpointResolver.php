<?php
declare(strict_types=1);
namespace NHK\Core\Contracts\Graph;
use NHK\Core\Domain\Graph\NodeReference;
interface EndpointResolver { public function supports(string $endpoint_type): bool; public function exists(NodeReference $reference): bool; public function normalize(NodeReference $reference): NodeReference; }
