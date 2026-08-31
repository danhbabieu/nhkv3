<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Governance;

use NHK\Core\Contracts\Governance\DependencyRepository;
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbDependencyRepository implements DependencyRepository
{
    public function __construct(private ?object $database = null) {}
    private function db(): object { global $wpdb; return $this->database ?? $wpdb; }
    private function proposals(): string { return $this->db()->prefix.'nhk_proposals'; }
    private function dependencies(): string { return $this->db()->prefix.'nhk_proposal_dependencies'; }

    public function directDependencies(string $proposalId): array
    {
        $db = $this->db();
        $id = $db->get_var($db->prepare('SELECT id FROM '.$this->proposals().' WHERE proposal_uuid=%s', UuidCodec::toBinary($proposalId)));
        if (!$id) return [];
        $rows = $db->get_col($db->prepare('SELECT depends_on_proposal_id FROM '.$this->dependencies().' WHERE proposal_id=%d ORDER BY id', (int) $id));
        return array_map(static fn (string $uuid): string => UuidCodec::fromBinary($uuid), $rows ?: []);
    }

    public function add(string $proposalId, string $dependencyUuid): void
    {
        $db = $this->db();
        $source = $db->get_var($db->prepare('SELECT id FROM '.$this->proposals().' WHERE proposal_uuid=%s', UuidCodec::toBinary($proposalId)));
        if (!$source) throw new \RuntimeException('PROPOSAL_NOT_FOUND');
        $ok = $db->query($db->prepare('INSERT INTO '.$this->dependencies().' (proposal_id,depends_on_proposal_id,created_at) VALUES (%d,%s,%s)', (int) $source, UuidCodec::toBinary($dependencyUuid), gmdate('Y-m-d H:i:s.u')));
        if ($ok === false && stripos((string) $db->last_error, 'duplicate') === false) throw new \RuntimeException('DEPENDENCY_INSERT_FAILED: '.(string) $db->last_error);
    }
}
