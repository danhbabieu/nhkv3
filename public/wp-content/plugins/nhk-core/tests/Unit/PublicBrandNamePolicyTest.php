<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Projection\PublicBrandNamePolicy;
use PHPUnit\Framework\TestCase;

final class PublicBrandNamePolicyTest extends TestCase
{
    public function test_confirmed_public_aliases_use_only_the_approved_spelling(): void
    {
        $source = 'ô đo / ODO / vê đét / vedet / junhan / jun hans / Junghans';

        self::assertSame('Odo / Odo / Vedette / Vedette / Junghans / Junghans / Junghans', PublicBrandNamePolicy::normalizeText($source));
    }

    public function test_alias_matching_respects_word_boundaries(): void
    {
        self::assertSame('odometer junhansen vedettes', PublicBrandNamePolicy::normalizeText('odometer junhansen vedettes'));
    }

    public function test_html_normalization_changes_visible_text_and_json_ld_but_not_attributes_or_scripts(): void
    {
        $html = '<a title="ô đo">vê đét</a><script type="application/ld+json">{"name":"junhan"}</script><script>const x = "ô đo";</script>';

        self::assertSame('<a title="ô đo">Vedette</a><script type="application/ld+json">{"name":"Junghans"}</script><script>const x = "ô đo";</script>', PublicBrandNamePolicy::normalizeHtml($html));
    }
}
