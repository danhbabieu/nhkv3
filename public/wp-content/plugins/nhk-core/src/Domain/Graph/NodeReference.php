<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Graph;
use NHK\Core\Graph\Exception\InvalidEndpointReference;

final readonly class NodeReference {
    public string $endpoint_type;
    public string $endpoint_key;
    public function __construct(string $endpoint_type, string $endpoint_key) {
        $type = trim($endpoint_type); $key = trim($endpoint_key);
        if ($type === '' || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $type) !== 1 || $key === '' || strlen($key) > 191) {
            throw new InvalidEndpointReference('Endpoint reference is invalid.');
        }
        $this->endpoint_type = $type; $this->endpoint_key = $key;
    }
    public function key(): string { return $this->endpoint_type . ':' . $this->endpoint_key; }
}
