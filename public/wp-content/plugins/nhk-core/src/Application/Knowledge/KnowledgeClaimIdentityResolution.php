<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Shared\Uuid\UuidCodec;

final class KnowledgeClaimIdentityResolution
{
    public const RESOLVED = 'RESOLVED';
    public const UNRESOLVED = 'UNRESOLVED';
    public const CONFLICTING = 'CONFLICTING';
    public const POLICY_VERSION = 'knowledge-identity-v2';

    /** @param array<string,mixed> $packet @param list<string> $reasonCodes */
    public function __construct(private string $status, private array $packet, private array $reasonCodes = [])
    {
        if (!in_array($status, [self::RESOLVED, self::UNRESOLVED, self::CONFLICTING], true)) throw new \InvalidArgumentException('KNOWLEDGE_IDENTITY_STATUS_INVALID');
        ksort($this->packet);
        $this->reasonCodes = array_values(array_unique(array_filter(array_map('strval', $reasonCodes))));
    }

    public function status(): string { return $this->status; }
    /** @return array<string,mixed> */
    public function packet(): array { return $this->packet; }
    public function policyVersion(): string { return self::POLICY_VERSION; }
    public function fingerprint(): string { return hash('sha256', json_encode(['policy' => self::POLICY_VERSION, 'packet' => $this->packet], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }
    /** @return list<string> */
    public function reasonCodes(): array { return $this->reasonCodes; }
    public function equivalentTo(self $other): bool { return $this->status === self::RESOLVED && $other->status === self::RESOLVED && hash_equals($this->fingerprint(), $other->fingerprint()); }
}
