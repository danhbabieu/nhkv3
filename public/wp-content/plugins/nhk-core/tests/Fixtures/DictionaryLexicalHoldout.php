<?php
declare(strict_types=1);

namespace NHK\Tests\Fixtures;

final class DictionaryLexicalHoldout
{
    /** @return list<array<string,mixed>> */
    public static function cases(): array
    {
        return [
            [
                'id' => 'technical_configuration',
                'text' => 'Mã Ref 81.12 dùng cấu hình 17 alpha 19 beta.',
                'expected' => ['ref 81.12' => 'QUALIFIED', '17 alpha 19 beta' => 'QUALIFIED'],
                'forbidden' => [],
                'gold_complete' => true,
            ],
            [
                'id' => 'specialist_history',
                'text' => 'Lịch sử ghi chép về cơ cấu escapement trong tài liệu chuyên môn.',
                'expected' => ['cơ cấu escapement' => 'QUALIFIED', 'tài liệu chuyên môn' => 'QUALIFIED'],
                'forbidden' => ['chép về cơ cấu escapement'],
                'gold_complete' => true,
            ],
            [
                'id' => 'advertising_noise',
                'text' => 'Quảng cáo nói sản phẩm này rất đẹp và cực kỳ hiếm.',
                'expected' => [],
                'forbidden' => [],
                'gold_complete' => false,
                'limitation' => 'Generic source-genre nouns require a larger labeled advertising corpus before suppression is measured.',
            ],
            [
                'id' => 'advertising_claims',
                'text' => 'Mua ngay phiên bản giới hạn, ưu đãi hôm nay, sản phẩm tốt nhất thị trường.',
                'expected' => [],
                'forbidden' => [],
                'gold_complete' => false,
                'limitation' => 'Advertising claim labels remain incomplete; no suppression precision is inferred from this sample.',
            ],
            [
                'id' => 'dialogue_transcript',
                'text' => 'Dialogue: I saw the solivane yesterday, it was running.',
                'expected' => ['solivane' => 'OBSERVATION_ONLY'],
                'forbidden' => ['i saw the solivane yesterday'],
                'gold_complete' => true,
            ],
            [
                'id' => 'dialogue_uncertain_statement',
                'text' => 'Người kể nói: bộ thoát vẫn chạy, nhưng tôi chưa chắc.',
                'expected' => [],
                'forbidden' => [],
                'gold_complete' => false,
                'limitation' => 'Dialogue attribution and hedging need additional independently labeled transcripts.',
            ],
            [
                'id' => 'narrative_context',
                'text' => 'Trong câu chuyện, bộ thoát hoạt động và mặt số phản chiếu ánh sáng.',
                'expected' => ['bộ thoát' => 'QUALIFIED'],
                'forbidden' => [],
                'gold_complete' => false,
                'limitation' => 'Narrative prose has partial labels; unlisted qualified spans remain unavailable for recall.',
            ],
            [
                'id' => 'proper_names',
                'text' => 'Tên riêng là Jean-Léon Reutter và Nguyễn Văn An.',
                'expected' => ['jean-léon reutter' => 'QUALIFIED', 'nguyễn văn an' => 'QUALIFIED'],
                'forbidden' => ['jean-léon reutter và'],
                'gold_complete' => true,
            ],
            [
                'id' => 'unknown_quoted_term',
                'text' => 'Thuật ngữ "solivane" xuất hiện trong bảng chú giải.',
                'expected' => ['solivane' => 'QUALIFIED'],
                'forbidden' => ['thuật ngữ "solivane"'],
                'gold_complete' => false,
                'limitation' => 'Contextual noun phrases remain review-only until a broader Vietnamese lexical gold set exists.',
            ],
            [
                'id' => 'new_multilingual_term',
                'text' => '"Zorvane mechanism"',
                'expected' => ['zorvane mechanism' => 'QUALIFIED'],
                'forbidden' => [],
                'source' => ['source_id' => 'holdout:new-term', 'raw_or_derived' => 'RAW', 'lineage' => ['source_family' => 'independent-holdout']],
                'gold_complete' => true,
            ],
            [
                'id' => 'weak_single_word',
                'text' => 'Một rotor quay trên trục.',
                'expected' => ['rotor' => 'OBSERVATION_ONLY'],
                'forbidden' => ['rotor quay'],
                'gold_complete' => true,
            ],
            [
                'id' => 'ambiguous_context',
                'text' => 'Mặt số như thế nào và nằm ở đâu?',
                'expected' => ['mặt số' => 'QUALIFIED'],
                'forbidden' => [],
                'gold_complete' => false,
                'limitation' => 'Resolver ambiguity is evaluated by the existing Dictionary resolver suite, not detector-only labels.',
            ],
            [
                'id' => 'derived_copy_lineage',
                'text' => 'Alpha',
                'expected' => ['alpha' => 'OBSERVATION_ONLY'],
                'forbidden' => [],
                'source' => ['source_id' => 'derived:alpha-copy', 'raw_or_derived' => 'DERIVED', 'lineage' => ['parent_source_id' => 'knowledge:1']],
                'gold_complete' => false,
                'limitation' => 'Independent-source counting is covered by the corpus audit tests.',
            ],
        ];
    }
}
