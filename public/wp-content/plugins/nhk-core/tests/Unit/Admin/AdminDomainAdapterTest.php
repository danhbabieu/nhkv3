<?php
declare(strict_types=1);

namespace NHK\Tests\Unit\Admin;

use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
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
        self::assertSame('01a07af5', $rows[0]['canonical_short_id']);
        self::assertArrayHasKey('publication_state', $rows[0]);
        self::assertArrayHasKey('frontend_state', $rows[0]);
    }

    public function test_video_detail_projection_exposes_readback_layers_and_gates_frontend_link(): void
    {
        $video = Video::fromUrl('https://youtu.be/truOChTNbwA', 'Video thử', [
            'editorial' => ['title' => 'Tiêu đề đúng', 'summary' => 'Tóm tắt đúng'],
            'source_snapshot' => ['availability' => 'available', 'embeddable' => true],
            'semantic_attachments' => [['target_type' => 'variant', 'target_key' => 'variant-1']],
        ], null, '01a07af5-3303-7a73-9f15-b7f675293dc5');

        $detail = (new AdminVideoAdapter([$video]))->detail($video, [
            ['target_type' => 'variant', 'target_key' => 'variant-1', 'predicate' => 'about'],
        ], [[
            'evidence_id' => 'evidence-1',
            'claim_id' => 'claim-1',
            'source_id' => 'source-1',
            'relation' => 'supports',
            'excerpt' => 'Excerpt',
        ]], ['state' => 'approved', 'proposal_id' => 'proposal-1'], [
            'eligible' => true,
            'path' => '/video/tieu-de-dung/',
        ]);

        self::assertSame('Tóm tắt đúng', $detail['metadata']['editorial']['summary']);
        self::assertSame('variant-1', $detail['relations'][0]['target_key']);
        self::assertSame('evidence-1', $detail['evidence'][0]['evidence_id']);
        self::assertSame('approved', $detail['governance']['state']);
        self::assertTrue($detail['frontend_projection']['eligible']);
        self::assertSame('/video/tieu-de-dung/', $detail['frontend_projection']['path']);
        self::assertSame('variant-1', $detail['primary_semantic_target']['key']);
        self::assertSame(1, $detail['relation_count']);
        self::assertSame(1, $detail['evidence_count']);
        self::assertArrayHasKey('technical', $detail);
    }

    public function test_media_adapter_exposes_readiness_without_mutation(): void
    {
        $media = new Media('01a07af5-3303-7a73-9f15-b7f675293dc6', 'media.example', 'Ảnh thử', 'ready');
        $asset = new MediaAsset('01a07af5-3303-7a73-9f15-b7f675293dc7', $media->canonicalId, 'derivative', 'image.webp', hash('sha256', 'image'), 'image/webp', 10, 640, 480, 'PUBLIC');
        $usage = new MediaUsage('01a07af5-3303-7a73-9f15-b7f675293dc8', $media->canonicalId, 'variant', '01a07af5-3303-7a73-9f15-b7f675293dc9', 'representative');
        $row = (new AdminMediaAdapter([$media], [$asset], [$usage]))->find()[0];
        self::assertSame('ready', $row['readiness']);
        self::assertSame('640 × 480', $row['dimensions']);
        self::assertSame('representative', $row['semantic_role']);
        self::assertSame(1, $row['usage_count']);
        self::assertSame('variant', $row['primary_entity']['type']);
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
