<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\{DictionaryCurationService, DictionaryPublicQuery, DictionaryRuntime};
use PHPUnit\Framework\TestCase;

final class DictionaryRuntimeContractTest extends TestCase
{
    public function test_dictionary_runtime_exposes_the_existing_curation_and_public_query_boundaries(): void
    {
        $runtime = new DictionaryRuntime(new \stdClass());

        self::assertInstanceOf(DictionaryCurationService::class, $runtime->curation());
        self::assertInstanceOf(DictionaryPublicQuery::class, $runtime->publicQuery());
        self::assertFalse(method_exists(DictionaryCurationService::class, 'selectPreferredIllustration'));
    }

    public function test_dictionary_runtime_does_not_expose_a_second_media_owner_boundary(): void
    {
        $runtime = new DictionaryRuntime(new \stdClass());

        self::assertInstanceOf(DictionaryCurationService::class, $runtime->curation());
        self::assertFalse(method_exists(DictionaryCurationService::class, 'createMedia'));
        self::assertFalse(method_exists(DictionaryCurationService::class, 'createEvidence'));
        self::assertFalse(method_exists(DictionaryCurationService::class, 'createGraphRelation'));
    }
}
