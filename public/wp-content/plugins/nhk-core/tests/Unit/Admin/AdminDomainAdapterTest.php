<?php
declare(strict_types=1);

namespace NHK\Tests\Unit\Admin;

use NHK\Core\Domain\Media\Media;
use NHK\Core\Domain\Video\Video;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Infrastructure\Admin\{AdminContentAdapter, AdminGovernanceAdapter, AdminMediaAdapter, AdminVideoAdapter};
use PHPUnit\Framework\TestCase;

final class AdminDomainAdapterTest extends TestCase
{
    public function test_video_adapter_finds_by_external_id_and_returns_read_only_projection(): void
    {
        $video = Video::fromUrl('https://youtu.be/truOChTNbwA', 'Video thử', [], null, '01a07af5-3303-7a73-9f15-b7f675293dc5');
        $rows = (new AdminVideoAdapter([$video]))->find('truochtnbwa');
        self::assertCount(1, $rows);
        self::assertSame('01a07af5-3303-7a73-9f15-b7f675293dc5', $rows[0]['id']);
    }

    public function test_media_adapter_exposes_readiness_without_mutation(): void
    {
        $media = new Media('01a07af5-3303-7a73-9f15-b7f675293dc6', 'media.example', 'Ảnh thử', 'ready');
        self::assertSame('ready', (new AdminMediaAdapter([$media]))->find()[0]['readiness']);
    }

    public function test_content_adapter_has_only_editorial_and_video_tabs(): void
    {
        self::assertSame(['article', 'video'], array_column((new AdminContentAdapter())->tabs(), 'id'));
    }

    public function test_governance_adapter_humanizes_operation_and_keeps_raw_data_technical(): void
    {
        $proposal = new Proposal('01a07af5-3303-7a73-9f15-b7f675293dc7', 'subject', 'relation_create', ['source_type' => 'video'], str_repeat('a', 64), 1, str_repeat('b', 64), ProposalState::SUBMITTED, entityType: 'relation');
        $row = (new AdminGovernanceAdapter())->humanize($proposal);
        self::assertSame('Chờ duyệt', $row['state_label']);
        self::assertStringContainsString('Gắn quan hệ', $row['summary']);
        self::assertSame($proposal->id, $row['technical']['proposal_uuid']);
    }
}
