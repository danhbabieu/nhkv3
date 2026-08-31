<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Governance;

use NHK\Core\Contracts\Governance\GovernanceAuditSink;
use NHK\Core\Domain\Governance\Proposal;
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbAuditSink implements GovernanceAuditSink
{
    public function __construct(private ?object $database = null) {}

    public function record(string $event, Proposal $proposal): void
    {
        $events = ['created'=>'ProposalCreated','submitted'=>'ProposalSubmitted','approved'=>'ProposalApproved','rejected'=>'ProposalRejected','cancelled'=>'ProposalCancelled','superseded'=>'ProposalSuperseded','applied'=>'ApplySucceeded'];
        $actor = in_array($event, ['approved', 'rejected', 'cancelled', 'superseded'], true) ? $proposal->decisionActor : $proposal->actor;
        $this->recordEvent($events[$event] ?? $event, 'proposal', $proposal->id, $actor !== null ? (int) $actor : null, ['revision' => $proposal->revision, 'state' => $proposal->state->value, 'fingerprint' => $proposal->contentFingerprint]);
    }

    public function recordEvent(string $eventType, string $objectType, string $objectKey, ?int $actorUserId, array $context = []): void
    {
        global $wpdb;
        $db = $this->database ?? $wpdb;
        $safe = static function (mixed $value, ?string $key = null) use (&$safe): mixed {
            if ($key !== null && preg_match('/password|token|secret|private.?key|api.?key|body|content/i', $key)) return '[REDACTED]';
            if (is_array($value)) { $out=[]; foreach ($value as $k=>$v) $out[(string)$k]=$safe($v,(string)$k); return $out; }
            if (!is_string($value)) return $value;
            return substr($value, 0, 512);
        };
        $ok=$db->query($db->prepare('INSERT INTO ' . $db->prefix . 'nhk_audit_events (event_uuid,event_type,object_type,object_key,actor_user_id,context_json,created_at) VALUES (%s,%s,%s,%s,%s,%s,%s)', UuidCodec::toBinary(UuidCodec::newV7()), substr($eventType, 0, 96), substr($objectType, 0, 64), substr($objectKey, 0, 191), $actorUserId, wp_json_encode($safe($context), JSON_UNESCAPED_SLASHES), gmdate('Y-m-d H:i:s.u')));
        if ($ok === false) throw new \RuntimeException('AUDIT_EVENT_INSERT_FAILED: '.(string)$db->last_error);
    }
}
