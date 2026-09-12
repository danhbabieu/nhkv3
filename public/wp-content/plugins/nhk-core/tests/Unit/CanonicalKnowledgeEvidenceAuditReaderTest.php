<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Audit\CanonicalKnowledgeEvidenceAuditReader;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class CanonicalKnowledgeEvidenceAuditReaderTest extends TestCase
{
    public function test_exact_subject_and_supported_evidence_returns_safe_audit_summary(): void
    {
        $subject = $this->id('1'); $target = $this->id('2'); $claim = $this->claim($subject, $target); $source = $this->source('3');
        $evidence = new Evidence($this->id('4'), $claim->canonicalId, $source->canonicalId, 'supports', 'PRIVATE EXCERPT MUST NOT LEAK', metadata: ['visibility' => 'PRIVATE']);
        $reader = new CanonicalKnowledgeEvidenceAuditReader(new FakeKnowledgeAuditRepository([$claim]), new FakeSourceAuditRepository([$source]), new FakeEvidenceAuditRepository([$evidence]));

        $rows = $reader->findForSubject('variant', $subject);
        self::assertCount(1, $rows);
        self::assertSame('SUPPORTED', $rows[0]['evidence_status']);
        self::assertSame('PRIVATE', $rows[0]['support_summary']['visibility'][0]);
        self::assertArrayNotHasKey('claim_text', $rows[0]);
        self::assertArrayNotHasKey('excerpt', $rows[0]);
        self::assertStringNotContainsString('PRIVATE EXCERPT MUST NOT LEAK', json_encode($rows[0], JSON_THROW_ON_ERROR));
    }

    public function test_wrong_subject_and_scope_are_not_returned_as_exact_support(): void
    {
        $subject = $this->id('11'); $claim = $this->claim($this->id('12'), $this->id('13')); $source = $this->source('14');
        $wrongScope = $this->claim($subject, $this->id('15'), true, 'variant', 'model');
        $reader = new CanonicalKnowledgeEvidenceAuditReader(new FakeKnowledgeAuditRepository([$claim, $wrongScope]), new FakeSourceAuditRepository([$source]), new FakeEvidenceAuditRepository());
        $rows = $reader->findForSubject('variant', $subject);
        self::assertCount(1, $rows);
        self::assertSame('UNSUPPORTED', $rows[0]['evidence_status']);
    }

    public function test_inactive_claim_source_or_evidence_is_not_supported(): void
    {
        $subject = $this->id('21'); $target = $this->id('22'); $source = $this->source('23', false);
        $claim = $this->claim($subject, $target, false, 'specimen');
        $evidence = new Evidence($this->id('24'), $claim->canonicalId, $source->canonicalId, 'supports', 'excerpt');
        $reader = new CanonicalKnowledgeEvidenceAuditReader(new FakeKnowledgeAuditRepository([$claim]), new FakeSourceAuditRepository([$source]), new FakeEvidenceAuditRepository([$evidence]));
        $row = $reader->findForSubject('specimen', $subject)[0];
        self::assertSame('UNSUPPORTED', $row['evidence_status']);
        self::assertSame('INACTIVE', $row['support_summary']['source_state']);
    }

    public function test_text_match_without_canonical_target_is_not_exact_evidence(): void
    {
        $subject = $this->id('31'); $claim = $this->claim($subject, '', true, 'model'); $source = $this->source('32');
        $reader = new CanonicalKnowledgeEvidenceAuditReader(new FakeKnowledgeAuditRepository([$claim]), new FakeSourceAuditRepository([$source]), new FakeEvidenceAuditRepository());
        $row = $reader->findForSubject('model', $subject)[0];
        self::assertSame('', $row['target_uuid']);
        self::assertSame('NO_EVIDENCE', $row['evidence_status']);
    }

    public function test_inactive_evidence_is_not_supported(): void
    {
        $subject = $this->id('41'); $target = $this->id('42'); $claim = $this->claim($subject, $target); $source = $this->source('43');
        $evidence = new Evidence($this->id('44'), $claim->canonicalId, $source->canonicalId, 'supports', 'inactive evidence', active: false);
        $reader = new CanonicalKnowledgeEvidenceAuditReader(new FakeKnowledgeAuditRepository([$claim]), new FakeSourceAuditRepository([$source]), new FakeEvidenceAuditRepository([$evidence]));
        self::assertSame('UNSUPPORTED', $reader->findForSubject('variant', $subject)[0]['evidence_status']);
    }

    private function claim(string $subject, string $target, bool $active = true, string $sourceType = 'variant', string $scope = ''): KnowledgeClaim
    {
        $metadata = ['subject_id' => $subject, 'subject_type' => $sourceType, 'scope' => $scope !== '' ? $scope : $sourceType, 'audit_tier' => 'B', 'provenance_class' => 'CATALOG_SUPPORTED', 'knowledge_status' => 'APPROVED'];
        if ($target !== '') $metadata['target_uuid'] = $target;
        return new KnowledgeClaim($this->id('c' . substr($subject, -2)), 'audit.claim.' . substr($subject, -2), 'A claim that is never serialized by this adapter.', 'fact', ['metadata' => $metadata], $active);
    }

    private function source(string $suffix, bool $active = true): Source { return new Source($this->id($suffix), 'audit.source.' . $suffix, 'Audit source', 'catalog', active: $active); }
    private function id(string $suffix): string { return '00000000-0000-4000-8000-' . str_pad(substr(preg_replace('/[^0-9]/', '', $suffix) ?: '1', -12), 12, '0', STR_PAD_LEFT); }
}

final class FakeKnowledgeAuditRepository implements KnowledgeRepository
{
    /** @param list<KnowledgeClaim> $items */
    public function __construct(private array $items) {}
    public function findByCanonicalId(string $id): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
    public function findByStableKey(string $stableKey): ?KnowledgeClaim { return null; }
    public function create(KnowledgeClaim $claim): KnowledgeClaim { throw new \LogicException('read-only adapter'); }
    public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { throw new \LogicException('read-only adapter'); }
    public function list(bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn (KnowledgeClaim $item): bool => $includeRetired || $item->active)); }
}

final class FakeSourceAuditRepository implements SourceRepository
{
    /** @param list<Source> $items */
    public function __construct(private array $items) {}
    public function findByCanonicalId(string $id): ?Source { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
    public function findByStableKey(string $stableKey): ?Source { return null; }
    public function create(Source $source): Source { throw new \LogicException('read-only adapter'); }
    public function update(Source $source, int $expectedRevision): Source { throw new \LogicException('read-only adapter'); }
    public function list(bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn (Source $item): bool => $includeRetired || $item->active)); }
}

final class FakeEvidenceAuditRepository implements EvidenceRepository
{
    /** @param list<Evidence> $items */
    public function __construct(private array $items = []) {}
    public function findByCanonicalId(string $id): ?Evidence { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
    public function create(Evidence $evidence): Evidence { throw new \LogicException('read-only adapter'); }
    public function update(Evidence $evidence, int $expectedRevision): Evidence { throw new \LogicException('read-only adapter'); }
    public function listByClaim(string $claimId, bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn (Evidence $item): bool => $item->claimId === $claimId && ($includeRetired || $item->active))); }
    public function listBySource(string $sourceId, bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn (Evidence $item): bool => $item->sourceId === $sourceId && ($includeRetired || $item->active))); }
}
