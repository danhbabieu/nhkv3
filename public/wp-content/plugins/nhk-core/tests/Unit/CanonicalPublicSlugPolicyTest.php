<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\PublicIdentity\CanonicalPublicSlugPolicy;
use PHPUnit\Framework\TestCase;

final class CanonicalPublicSlugPolicyTest extends TestCase
{
    public function test_vietnamese_transliteration_happens_before_ascii_cleanup(): void
    {
        $policy = new CanonicalPublicSlugPolicy();

        self::assertSame('tuoi', $policy->slug('tuổi'));
        self::assertSame('nguoi-viet', $policy->slug('người Việt'));
        self::assertSame('duoc', $policy->slug('được'));
        self::assertSame('suu-tap', $policy->slug('sưu tập'));
        self::assertSame('am-thanh-diem-nhac', $policy->slug('Âm thanh điểm nhạc'));
        self::assertSame('vi-sao-nguoi-viet-goi-la-54', $policy->slug('Vì sao người Việt gọi là 54?'));
        self::assertSame('o-u-d', $policy->slug('Ơ Ư Đ'));
    }

    public function test_all_vietnamese_vowel_families_normalize_to_ascii(): void
    {
        $policy = new CanonicalPublicSlugPolicy();

        self::assertSame('aaaaaaaaaaaaaaaaaa', $policy->slug('aàáảãạăằắẳẵặâầấẩẫậ'));
        self::assertSame('eeeeeeeeeee', $policy->slug('eèéẻẽẹêềếểễệ'));
        self::assertSame('iiiiii', $policy->slug('iìíỉĩị'));
        self::assertSame('oooooooooooooooooo', $policy->slug('oòóỏõọôồốổỗộơờớởỡợ'));
        self::assertSame('uuuuuuuuuuuu', $policy->slug('uùúủũụưừứửữự'));
        self::assertSame('yyyyyy', $policy->slug('yỳýỷỹỵ'));
    }

    public function test_nfd_and_nfc_inputs_are_equivalent(): void
    {
        $policy = new CanonicalPublicSlugPolicy();
        $nfd = "tuo\u{031B}\u{0309}i";

        self::assertSame('tuoi', $policy->slug($nfd));
        self::assertSame($policy->slug('tuổi'), $policy->slug($nfd));
    }

    public function test_separators_punctuation_and_repeated_delimiters_collapse_to_one_hyphen(): void
    {
        $policy = new CanonicalPublicSlugPolicy();

        self::assertSame('dong-ho-co-may-36-10', $policy->slug('  Đồng   hồ / cổ – máy — 36__10!!!  '));
        self::assertStringNotContainsString('--', $policy->slug('a // -- __ b'));
        self::assertSame('a-b', $policy->slug('--- a --- b ---'));
    }

    public function test_standalone_nhk_token_expands_only_in_public_slug(): void
    {
        $policy = new CanonicalPublicSlugPolicy();

        self::assertSame('nha-kho-video', $policy->slug('NHK video'));
        self::assertSame('video-nha-kho', $policy->slug('video nhk'));
        self::assertSame('nhkv3-video', $policy->slug('NHKV3 video'));
        self::assertSame('banhk-video', $policy->slug('banhk video'));
    }

    public function test_ascii_slug_remains_stable_and_generation_is_deterministic(): void
    {
        $policy = new CanonicalPublicSlugPolicy();
        $input = 'already-correct-ascii-36-10';

        self::assertSame($input, $policy->slug($input));
        self::assertSame($policy->slug($input), $policy->slug($input));
    }

    public function test_collision_free_slug_uses_shortest_meaningful_form(): void
    {
        $policy = new CanonicalPublicSlugPolicy();

        self::assertSame('dong-ho-co', $policy->resolve('Đồng hồ cổ', ['1978', 'model-36'], static fn(string $slug): bool => false));
    }

    public function test_real_collision_uses_first_meaningful_available_qualifier(): void
    {
        $policy = new CanonicalPublicSlugPolicy();
        $taken = ['dong-ho-co' => true, 'dong-ho-co-1978' => true];

        self::assertSame('dong-ho-co-model-36', $policy->resolve(
            'Đồng hồ cổ',
            ['1978', 'model 36'],
            static fn(string $slug): bool => isset($taken[$slug]),
        ));
    }

    public function test_unresolved_collision_fails_closed_instead_of_inventing_timestamp_hash_or_id(): void
    {
        $policy = new CanonicalPublicSlugPolicy();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PUBLIC_SLUG_COLLISION_REQUIRES_RECONCILIATION');
        $policy->resolve('Đồng hồ cổ', [], static fn(string $slug): bool => true);
    }
}
