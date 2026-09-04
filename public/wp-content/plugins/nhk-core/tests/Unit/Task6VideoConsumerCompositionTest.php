<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class Task6VideoConsumerCompositionTest extends TestCase
{
    public function test_plugin_composes_one_fully_governed_video_policy_for_every_public_consumer(): void
    {
        $plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');

        self::assertStringContainsString(
            '$publicPredicates = new PredicateRegistry();',
            $plugin,
        );
        self::assertStringContainsString(
            '$publicGraph = new GraphService(new WpdbGraphRepository($wpdb), $publicEndpoints, $publicPredicates, new WpdbAuditSink());',
            $plugin,
        );
        self::assertStringContainsString(
            '$publicVideoPolicy = new VideoUrlPolicy($publicIdentityRepository, $publicAuthority, $publicTypes, $publicEvidence, $publicSources, $publicPredicates);',
            $plugin,
        );
        self::assertStringContainsString(
            'new SearchSemanticQuery($publicAuthority, $publicMedia, $publicVideos, $publicClaims, $publicTypes, $publicStatus, $publicRoutes, $publicCollection, $publicIdentityRepository, $publicVideoPolicy)',
            $plugin,
        );
        self::assertStringContainsString(
            'new MediaVideoPageQuery($publicMedia, $publicAssets, $publicUsages, $publicVideos, $publicStatus, null, $publicRelated, $publicIdentityRepository, $publicVideoPolicy)',
            $plugin,
        );
        self::assertStringContainsString(
            'new PublicVideoSitemapRoutes($publicVideos, $publicStatus, $publicIdentityRepository, $publicVideoPolicy)',
            $plugin,
        );
        self::assertStringContainsString(
            'new ReadApi($media, $assets, $usages, $videos, $claims, $sources, $evidence, new MigrationStatus(), null, $restIdentityRepository, $restVideoPolicy)',
            $plugin,
        );
        self::assertStringContainsString(
            'new SearchApi($media, $videos, $claims, $authority, $types, $publicStatus, $publicCollection, $restIdentityRepository, $restVideoPolicy)',
            $plugin,
        );
    }
}
