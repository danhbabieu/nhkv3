<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\{DictionaryCurationService, DictionaryPublicQuery, DictionaryRuntime};
use NHK\Core\Application\Media\MediaService;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use PHPUnit\Framework\TestCase;

final class DictionaryRuntimeContractTest extends TestCase
{
    public function test_dictionary_runtime_exposes_the_existing_curation_and_public_query_boundaries(): void
    {
        $runtime = new DictionaryRuntime($this->database());

        self::assertInstanceOf(DictionaryCurationService::class, $runtime->curation());
        self::assertInstanceOf(DictionaryPublicQuery::class, $runtime->publicQuery());
        self::assertFalse(method_exists(DictionaryCurationService::class, 'selectPreferredIllustration'));
    }

    public function test_dictionary_runtime_wires_media_reuse_dependencies_into_curation(): void
    {
        $runtime = new DictionaryRuntime($this->database());
        $curation = $runtime->curation();
        $reflection = new \ReflectionObject($curation);

        $wired = $reflection->hasProperty('mediaService')
            && $reflection->hasProperty('media')
            && $reflection->hasProperty('assets')
            && $reflection->hasProperty('usages');

        self::assertTrue($wired, 'Dictionary curation must receive MediaService, MediaRepository, MediaAssetRepository and MediaUsageRepository.');
        if (!$wired) return;

        self::assertInstanceOf(MediaService::class, $reflection->getProperty('mediaService')->getValue($curation));
        self::assertInstanceOf(MediaRepository::class, $reflection->getProperty('media')->getValue($curation));
        self::assertInstanceOf(MediaAssetRepository::class, $reflection->getProperty('assets')->getValue($curation));
        self::assertInstanceOf(MediaUsageRepository::class, $reflection->getProperty('usages')->getValue($curation));
    }

    public function test_dictionary_runtime_does_not_expose_a_second_media_owner_boundary(): void
    {
        $runtime = new DictionaryRuntime($this->database());

        self::assertInstanceOf(DictionaryCurationService::class, $runtime->curation());
        self::assertFalse(method_exists(DictionaryCurationService::class, 'createMedia'));
        self::assertFalse(method_exists(DictionaryCurationService::class, 'createEvidence'));
        self::assertFalse(method_exists(DictionaryCurationService::class, 'createGraphRelation'));
    }

    private function database(): object
    {
        return new class {
            public string $prefix = 'wp_';
        };
    }
}
