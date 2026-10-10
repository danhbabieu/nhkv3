<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\FacebookAudit;

use InvalidArgumentException;
use ZipArchive;

final class NativeXlsxWriter
{
    /** @param array<string,array{headers:list<string>,rows:list<list<mixed>>}> $sheets */
    public function write(array $sheets, string $path): void
    {
        if ($sheets === []) throw new InvalidArgumentException('EMPTY_WORKBOOK');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new InvalidArgumentException('WORKBOOK_OPEN_FAILED');
        $names = array_keys($sheets);
        $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($names)));
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('xl/workbook.xml', $this->workbook($names));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships(count($names)));
        $zip->addFromString('xl/styles.xml', $this->styles());
        foreach ($sheets as $index => $sheet) {
            $sheetNumber = array_search($index, $names, true) + 1;
            [$xml, $rels] = $this->worksheet($sheet, $sheetNumber);
            $zip->addFromString('xl/worksheets/sheet' . $sheetNumber . '.xml', $xml);
            if ($rels !== '') $zip->addFromString('xl/worksheets/_rels/sheet' . $sheetNumber . '.xml.rels', $rels);
        }
        if ($zip->close() !== true) throw new InvalidArgumentException('WORKBOOK_WRITE_FAILED');
    }

    /** @param array{headers:list<string>,rows:list<list<mixed>>} $sheet @return array{0:string,1:string} */
    private function worksheet(array $sheet, int $sheetNumber): array
    {
        $rows = array_merge([$sheet['headers']], $sheet['rows']);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetData>';
        $hyperlinks = [];
        foreach ($rows as $rowIndex => $row) {
            $number = $rowIndex + 1;
            $xml .= '<row r="' . $number . '">';
            foreach (array_values($row) as $columnIndex => $cell) {
                $ref = $this->column($columnIndex + 1) . $number;
                $link = is_array($cell) && isset($cell['url']) ? trim((string) $cell['url']) : null;
                $value = is_array($cell) && array_key_exists('value', $cell) ? $cell['value'] : $cell;
                $value = $this->safeString($value);
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $this->xml($value) . '</t></is></c>';
                if ($link !== null && $this->isSafeUrl($link)) $hyperlinks[] = [$ref, $link, 'rId' . (count($hyperlinks) + 1)];
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        $rels = '';
        if ($hyperlinks !== []) {
            $xml .= '<hyperlinks>';
            foreach ($hyperlinks as [$ref, , $id]) $xml .= '<hyperlink ref="' . $ref . '" r:id="' . $id . '"/>';
            $xml .= '</hyperlinks>';
            $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            foreach ($hyperlinks as [, $url, $id]) $rels .= '<Relationship Id="' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="' . $this->xml($url) . '" TargetMode="External"/>';
            $rels .= '</Relationships>';
        }
        return [$xml . '</worksheet>', $rels];
    }

    private function safeString(mixed $value): string
    {
        if ($value === null) return 'NULL';
        if (is_bool($value)) return $value ? 'TRUE' : 'FALSE';
        if (is_array($value)) return implode(', ', array_map(fn (mixed $item): string => $this->safeString($item), $value));
        $value = (string) $value;
        if ($value !== '' && str_contains("=+-@\t\r\n", $value[0])) $value = "'" . $value;
        return $value;
    }

    private function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https' && strtolower((string) ($parts['host'] ?? '')) === 'www.facebook.com';
    }

    private function column(int $number): string { $column = ''; while ($number > 0) { $number--; $column = chr(65 + ($number % 26)) . $column; $number = intdiv($number, 26); } return $column; }
    private function xml(string $value): string { return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
    private function rootRelationships(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>'; }
    private function styles(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Aptos"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="1"><xf/></cellXfs></styleSheet>'; }
    private function workbook(array $names): string { $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'; foreach ($names as $index => $name) $xml .= '<sheet name="' . $this->xml((string) $name) . '" sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>'; return $xml . '</sheets></workbook>'; }
    private function workbookRelationships(int $count): string { $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'; for ($i = 1; $i <= $count; $i++) $xml .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>'; return $xml . '<Relationship Id="rId' . ($count + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>'; }
    private function contentTypes(int $count): string { $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'; for ($i = 1; $i <= $count; $i++) $xml .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'; return $xml . '</Types>'; }
}
