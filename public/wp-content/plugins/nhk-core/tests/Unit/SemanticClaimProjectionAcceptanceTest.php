<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Projection\{ClaimClusterer, ClaimRanker, SeoProjectionBuilder};
use NHK\Core\Domain\Knowledge\KnowledgeClaim;
use NHK\Core\Domain\Projection\{ClaimProjectionScope, ProjectedClaim};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class SemanticClaimProjectionAcceptanceTest extends TestCase
{
    public function test_direct_claim_ranks_above_related_claim_and_cluster_keeps_canonical_ids(): void
    {
        $direct = $this->projected('Côn chữ M thường được sử dụng.', ClaimProjectionScope::DIRECT, 0, 'APPROVED');
        $related = $this->projected('Côn chữ M được ghi nhận.', ClaimProjectionScope::RELATED, 1, 'APPROVED');
        $ranked = (new ClaimRanker())->rank([$related, $direct]);
        self::assertSame($direct->claim->canonicalId, $ranked[0]->claim->canonicalId);
        self::assertCount(1, (new ClaimClusterer())->cluster([$direct, $related]));
        self::assertNotSame($direct->claim->canonicalId, $related->claim->canonicalId);
    }

    public function test_seo_projection_escapes_xss_and_preserves_dispute_framing(): void
    {
        $ledger = ['node_uuid' => 'node', 'sections' => [['key' => 'sound', 'label' => 'Âm thanh', 'claims' => [['claim_uuid' => 'claim', 'display_text' => '<script>alert(1)</script>', 'status' => 'disputed']]]]];
        $projection = (new SeoProjectionBuilder())->build($ledger, '/mau/odo36/', 'Odo 36');
        self::assertStringNotContainsString('<script>', $projection['sections']['sound']['content']);
        self::assertStringContainsString('Các nguồn hiện ghi nhận', $projection['sections']['sound']['content']);
        self::assertSame('/mau/odo36/', $projection['canonical_url']);
        self::assertSame('Odo 36', $projection['h1']);
    }

    private function projected(string $text, string $scope, int $distance, string $status): ProjectedClaim
    {
        $claim = new KnowledgeClaim(UuidCodec::newV7(), 'claim.' . substr(hash('sha256', $text . $distance), 0, 10), $text, 'fact', ['metadata' => ['projection_category' => 'music_and_strike']]);
        return new ProjectedClaim($claim, 'music_and_strike', new ClaimProjectionScope('node', $claim->canonicalId, $scope, $distance), $status);
    }
}
