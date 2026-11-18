<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use ZipArchive;

final class StatsExportService
{
    /**
     * @param array<string, mixed> $stats
     * @return array{body: string, contentType: string, extension: string}
     */
    public function render(array $stats, string $format): array
    {
        if (strtolower($format) === 'xlsx') {
            return [
                'body' => $this->toXlsx($stats),
                'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'extension' => 'xlsx',
            ];
        }

        return [
            'body' => $this->toCsv($stats),
            'contentType' => 'text/csv; charset=utf-8',
            'extension' => 'csv',
        ];
    }

    public function reportDownloadName(string $kind, ?string $title): string
    {
        $label = $kind === 'shortcut' ? 'Отчет по ссылке' : 'Отчет по кампании';
        $clean = preg_replace('/[\\\\\/:*?"<>|\r\n]+/u', ' ', (string) $title) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
        if (mb_strlen($clean) > 80) {
            $clean = rtrim(mb_substr($clean, 0, 80));
        }
        return $clean !== '' ? "{$label} {$clean}" : $label;
    }

    public function contentDisposition(string $filename, string $extension): string
    {
        $utfName = rawurlencode("{$filename}.{$extension}");
        return "attachment; filename=\"report.{$extension}\"; filename*=UTF-8''{$utfName}";
    }

    /**
     * @param array<string, mixed> $stats
     * @return list<list<int|string>>
     */
    private function buildRows(array $stats): array
    {
        $total = (int) ($stats['total'] ?? 0);
        $unique = (int) ($stats['unique'] ?? 0);

        $rows = [
            ['Период', ($stats['dateFrom'] ?? '') . ' — ' . ($stats['dateTo'] ?? '')],
            ['Переходы', $total],
            ['Уникальные переходы', $unique],
            [],
            ['Дата', 'Переходы', 'Уникальные переходы'],
        ];

        foreach ($stats['series'] ?? [] as $point) {
            $rows[] = [
                (string) ($point['period'] ?? ''),
                (int) ($point['clicks'] ?? 0),
                (int) ($point['uniqueVisitors'] ?? 0),
            ];
        }

        $shortcuts = $stats['shortcuts'] ?? null;
        if (is_array($shortcuts) && $shortcuts !== []) {
            $rows[] = [];
            $rows[] = ['Ссылка', 'Переходы', 'Уникальные переходы', 'Доля'];
            foreach ($shortcuts as $row) {
                $clicks = (int) ($row['clicks'] ?? 0);
                $share = $total > 0 ? $clicks / $total : 0.0;
                $rows[] = [
                    (string) ($row['shortcut'] ?? ''),
                    $clicks,
                    (int) ($row['uniqueVisitors'] ?? 0),
                    sprintf('%.4f', $share),
                ];
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $stats */
    private function toCsv(array $stats): string
    {
        // Русский Excel ждёт разделитель «;» и UTF-8 с BOM
        $fp = fopen('php://temp', 'r+b');
        if ($fp === false) {
            throw new RuntimeException('Не удалось открыть временный буфер CSV');
        }
        fwrite($fp, "\xEF\xBB\xBF");
        foreach ($this->buildRows($stats) as $row) {
            fputcsv($fp, $row, ';', '"', '');
        }
        rewind($fp);
        $content = stream_get_contents($fp);
        fclose($fp);
        return $content === false ? '' : $content;
    }

    /** @param array<string, mixed> $stats */
    private function toXlsx(array $stats): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Расширение PHP zip (ZipArchive) недоступно на сервере');
        }

        $sheetXml = $this->buildSheetXml($this->buildRows($stats));
        $contentTypes = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $workbook = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Отчет" sheetId="1" r:id="rId1"/></sheets></workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';

        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        if ($tmpFile === false) {
            throw new RuntimeException('Не удалось создать временный файл для XLSX');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Не удалось инициализировать XLSX-архив');
            }
            $zip->addFromString('[Content_Types].xml', $contentTypes);
            $zip->addFromString('_rels/.rels', $rels);
            $zip->addFromString('xl/workbook.xml', $workbook);
            $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
            $zip->close();

            $bytes = file_get_contents($tmpFile);
            return $bytes === false ? '' : $bytes;
        } finally {
            @unlink($tmpFile);
        }
    }

    /** @param list<list<int|string>> $rows */
    private function buildSheetXml(array $rows): string
    {
        $body = [];
        foreach ($rows as $rowIndex => $row) {
            $r = $rowIndex + 1;
            $cells = [];
            foreach ($row as $colIndex => $value) {
                $colLetter = chr(65 + $colIndex);
                $ref = "{$colLetter}{$r}";
                if (is_int($value)) {
                    $cells[] = "<c r=\"{$ref}\"><v>{$value}</v></c>";
                } elseif ($value !== '') {
                    $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    $cells[] = "<c r=\"{$ref}\" t=\"inlineStr\"><is><t>{$escaped}</t></is></c>";
                }
            }
            $body[] = "<row r=\"{$r}\">" . implode('', $cells) . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . implode('', $body) . '</sheetData></worksheet>';
    }
}
