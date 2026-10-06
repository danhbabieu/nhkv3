<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryDuplicateCandidateAudit;
use NHK\Core\Contracts\Dictionary\DictionaryDuplicateAuditReader;
use PHPUnit\Framework\TestCase;

final class DictionaryDuplicateCandidateAuditTest extends TestCase
{
    public function test_historical_con_hoa_thi_rows_are_reported_in_one_reviewable_cluster(): void
    {
        $reader = new class implements DictionaryDuplicateAuditReader {
            public function read(int $limit = 1000): array
            {
                return [
                    $this->row('01a106b0-2b77-7caa-8f0b-b79cf62ad1a7', '01a10626-1317-77fc-9504-5800350cd07b', 'Côn hoa thị', ['domain' => 'clock'], '01a10c73-50dd-77bf-ac78-c0c4f66b2208'),
                    $this->row('01a10c9f-4890-712a-b9c1-a326c8767f0c', '01a10c9f-488f-7e0c-b2db-85731024f6f1', 'Côn hoa thị', ['domain' => 'clock'], '01a10c73-50dd-77bf-ac78-c0c4f66b2208'),
                ];
            }
            private function row(string $entry, string $sense, string $form, array $context, string $owner): array { return ['entry_id' => $entry, 'sense_id' => $sense, 'form_text' => $form, 'normalized_form' => 'côn hoa thị', 'entry_status' => 'APPROVED', 'sense_status' => 'APPROVED', 'entry_revision' => 3, 'sense_revision' => 2, 'context' => $context, 'destination_type' => 'classification', 'destination_id' => $owner, 'state' => 1]; }
        };

        $result = (new DictionaryDuplicateCandidateAudit($reader))->run();

        self::assertSame('available', $result['status']);
        self::assertCount(1, $result['clusters']);
        self::assertSame('côn hoa thị', $result['clusters'][0]['cluster_key']);
        self::assertSame(['01a106b0-2b77-7caa-8f0b-b79cf62ad1a7', '01a10c9f-4890-712a-b9c1-a326c8767f0c'], array_column($result['clusters'][0]['candidates'], 'entry_id'));
        self::assertContains('EXACT_NORMALIZED_FORM_COLLISION', $result['clusters'][0]['reasons']);
        self::assertContains('SAME_SEMANTIC_OWNER', $result['clusters'][0]['reasons']);
        self::assertSame('REVIEW_REQUIRED', $result['clusters'][0]['review_status']);
    }

    public function test_context_and_inactive_evidence_are_reported_without_merging(): void
    {
        $reader = new class implements DictionaryDuplicateAuditReader {
            public function read(int $limit = 1000): array
            {
                return [
                    ['entry_id' => 'entry-1', 'sense_id' => 'sense-1', 'form_text' => 'Côn', 'normalized_form' => 'côn', 'entry_status' => 'APPROVED', 'sense_status' => 'APPROVED', 'entry_revision' => 1, 'sense_revision' => 1, 'context' => ['domain' => 'machine'], 'destination_type' => null, 'destination_id' => null, 'state' => 1],
                    ['entry_id' => 'entry-2', 'sense_id' => 'sense-2', 'form_text' => 'Côn', 'normalized_form' => 'côn', 'entry_status' => 'RETIRED', 'sense_status' => 'APPROVED', 'entry_revision' => 2, 'sense_revision' => 1, 'context' => ['domain' => 'pen'], 'destination_type' => null, 'destination_id' => null, 'state' => 0],
                ];
            }
        };

        $clusters = (new DictionaryDuplicateCandidateAudit($reader))->run()['clusters'];

        self::assertContains('DIVERGENT_CONTEXT', $clusters[0]['reasons']);
        self::assertContains('INACTIVE_CANDIDATE', $clusters[0]['reasons']);
        self::assertSame(2, count($clusters[0]['candidates']));
    }

    public function test_reader_failure_is_distinguished_from_empty_audit(): void
    {
        $reader = new class implements DictionaryDuplicateAuditReader {
            public function read(int $limit = 1000): array { throw new \RuntimeException('read unavailable'); }
        };

        $result = (new DictionaryDuplicateCandidateAudit($reader))->run();

        self::assertSame('unavailable', $result['status']);
        self::assertSame('DICTIONARY_DUPLICATE_AUDIT_UNAVAILABLE', $result['reason']);
    }

    public function test_audit_deduplicates_entry_form_sense_and_classifies_homographs(): void
    {
        $row = ['entry_id' => 'entry-1', 'sense_id' => 'sense-1', 'form_text' => 'Côn', 'normalized_form' => 'côn', 'entry_status' => 'APPROVED', 'sense_status' => 'APPROVED', 'entry_revision' => 1, 'sense_revision' => 1, 'context' => ['domain' => 'machine'], 'destination_type' => null, 'destination_id' => null, 'state' => 1];
        $reader = new class($row) implements DictionaryDuplicateAuditReader {
            public function __construct(private array $row) {}
            public function read(int $limit = 1000): array
            {
                return [$this->row, $this->row, array_replace($this->row, ['entry_id' => 'entry-2', 'sense_id' => 'sense-2', 'context' => ['domain' => 'pen']])];
            }
        };

        $result = (new DictionaryDuplicateCandidateAudit($reader))->run();

        self::assertSame(2, $result['clusters'][0]['candidate_count']);
        self::assertContains('DUPLICATE_ENTRY_CANDIDATE', $result['clusters'][0]['reasons']);
        self::assertContains('CONTEXTUAL_HOMOGRAPH', $result['clusters'][0]['reasons']);
    }

    public function test_audit_classifies_multiple_senses_on_one_entry(): void
    {
        $reader = new class implements DictionaryDuplicateAuditReader {
            public function read(int $limit = 1000): array
            {
                return [
                    ['entry_id' => 'entry-1', 'sense_id' => 'sense-1', 'form_id' => 'form-1', 'form_text' => 'Côn', 'normalized_form' => 'côn', 'entry_status' => 'APPROVED', 'sense_status' => 'APPROVED', 'context' => ['domain' => 'machine'], 'state' => 1],
                    ['entry_id' => 'entry-1', 'sense_id' => 'sense-2', 'form_id' => 'form-1', 'form_text' => 'Côn', 'normalized_form' => 'côn', 'entry_status' => 'APPROVED', 'sense_status' => 'APPROVED', 'context' => ['domain' => 'machine'], 'state' => 1],
                ];
            }
        };

        $result = (new DictionaryDuplicateCandidateAudit($reader))->run();

        self::assertContains('MULTI_SENSE_SINGLE_ENTRY', $result['clusters'][0]['reasons']);
        self::assertNotContains('DUPLICATE_ENTRY_CANDIDATE', $result['clusters'][0]['reasons']);
    }
}
