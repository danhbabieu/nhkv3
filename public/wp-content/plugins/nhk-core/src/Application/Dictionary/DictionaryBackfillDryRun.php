<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

final class DictionaryBackfillDryRun
{
    /** @param callable(string,string,array,array):array $preview */
    public function __construct(private $preview) {}

    /** @param list<array{kind:string,id:string,text:string,context?:array,hints?:array}> $sources */
    public function scan(array $sources): array
    {
        $sourceCounts = [];
        $items = [];
        $totals = [
            'sources' => 0,
            'resolved_existing' => 0,
            'candidate_new' => 0,
            'ambiguous' => 0,
            'suppressed' => 0,
            'unavailable' => 0,
        ];

        foreach ($sources as $source) {
            if (!is_array($source)) continue;
            $kind = strtoupper(trim((string) ($source['kind'] ?? '')));
            $id = trim((string) ($source['id'] ?? ''));
            $text = trim((string) ($source['text'] ?? ''));
            if ($kind === '' || $id === '' || $text === '') continue;
            $context = is_array($source['context'] ?? null) ? $source['context'] : [];
            $hints = is_array($source['hints'] ?? null) ? $source['hints'] : [];

            $sourceCounts[$kind] = ($sourceCounts[$kind] ?? 0) + 1;
            $totals['sources']++;
            try {
                $plan = ($this->preview)($text, $kind, $context, $hints);
                if (!is_array($plan) || strtoupper((string) ($plan['status'] ?? 'UNAVAILABLE')) !== 'AVAILABLE') {
                    $totals['unavailable']++;
                    $items[] = ['kind' => $kind, 'id' => $id, 'status' => 'UNAVAILABLE'];
                    continue;
                }
                $resolved = count((array) ($plan['resolved_terms'] ?? []));
                $candidate = count((array) ($plan['candidate_terms'] ?? []));
                $ambiguous = count((array) ($plan['ambiguous_terms'] ?? []));
                $suppressed = in_array('DICTIONARY_TERM_SUPPRESSED', (array) ($plan['warnings'] ?? []), true) ? 1 : 0;
                $totals['resolved_existing'] += $resolved;
                $totals['candidate_new'] += $candidate;
                $totals['ambiguous'] += $ambiguous;
                $totals['suppressed'] += $suppressed;
                $items[] = [
                    'kind' => $kind,
                    'id' => $id,
                    'status' => 'AVAILABLE',
                    'resolved_existing' => $resolved,
                    'candidate_new' => $candidate,
                    'ambiguous' => $ambiguous,
                    'suppressed' => $suppressed,
                    'plan' => $plan,
                ];
            } catch (\Throwable $error) {
                $totals['unavailable']++;
                $items[] = ['kind' => $kind, 'id' => $id, 'status' => 'UNAVAILABLE', 'reason' => $error->getMessage()];
            }
        }

        ksort($sourceCounts);
        return [
            'mode' => 'DRY_RUN',
            'no_write' => true,
            'source_counts' => $sourceCounts,
            'totals' => $totals,
            'items' => $items,
        ];
    }
}
