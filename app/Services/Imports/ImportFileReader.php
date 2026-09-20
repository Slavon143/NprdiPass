<?php

namespace App\Services\Imports;

use Generator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use SplFileObject;
use ZipArchive;

class ImportFileReader
{
    /** @return Generator<int, array<string, string|null>> */
    public function rows(string $path, string $format): Generator
    {
        yield from $format === 'xlsx' ? $this->xlsxRows($path) : $this->csvRows($path);
    }

    /** @return list<string> */
    public function headers(string $path, string $format): array
    {
        foreach ($this->rows($path, $format) as $row) {
            return array_keys($row);
        }

        return [];
    }

    public function assertSafeXlsx(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'The XLSX archive cannot be opened.']);
        }

        $compressed = 0;
        $uncompressed = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (! is_array($stat)) {
                continue;
            }
            $compressed += $stat['comp_size'];
            $uncompressed += $stat['size'];
        }
        $zip->close();

        if ($uncompressed > 250 * 1024 * 1024 || ($compressed > 0 && $uncompressed / $compressed > 150)) {
            throw ValidationException::withMessages(['file' => 'The XLSX archive expands beyond the safe processing limit.']);
        }
    }

    /** @return Generator<int, array<string, string|null>> */
    private function csvRows(string $path): Generator
    {
        $file = new SplFileObject($path, 'rb');
        $firstLine = (string) $file->fgets();
        $delimiter = $this->delimiter($firstLine);
        $file->rewind();
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl($delimiter, '"', '\\');

        $headers = null;
        foreach ($file as $index => $values) {
            if (! is_array($values) || $values === [null]) {
                continue;
            }
            $values = array_map(fn (mixed $value): ?string => $value === null ? null : trim((string) $value), $values);
            if ($headers === null) {
                $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($values[0] ?? ''));
                $headers = $this->normalizeHeaders($values);

                continue;
            }
            if ($this->emptyRow($values)) {
                continue;
            }
            yield $index + 1 => $this->combine($headers, $values);
        }
    }

    /** @return Generator<int, array<string, string|null>> */
    private function xlsxRows(string $path): Generator
    {
        $this->assertSafeXlsx($path);
        $reader = IOFactory::createReader('Xlsx');
        $this->configureReader($reader);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheet(0);
        $headers = null;

        try {
            foreach ($sheet->getRowIterator() as $row) {
                $values = [];
                foreach ($row->getCellIterator('A', $sheet->getHighestDataColumn()) as $cell) {
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        throw ValidationException::withMessages(['file' => "Formulas are not allowed in import files (row {$row->getRowIndex()})."]);
                    }
                    $values[] = trim($cell->getFormattedValue());
                }
                if ($headers === null) {
                    $headers = $this->normalizeHeaders($values);

                    continue;
                }
                if ($this->emptyRow($values)) {
                    continue;
                }
                yield $row->getRowIndex() => $this->combine($headers, $values);
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function configureReader(IReader $reader): void
    {
        $reader->setReadDataOnly(false);
        $reader->setReadEmptyCells(false);
        $reader->setIncludeCharts(false);
    }

    /** @param list<mixed> $values @return list<string> */
    private function normalizeHeaders(array $values): array
    {
        if (count($values) > 200) {
            throw ValidationException::withMessages(['file' => 'Import files may contain at most 200 columns.']);
        }
        $seen = [];
        $headers = [];
        foreach ($values as $index => $value) {
            $header = trim((string) $value);
            if ($header === '') {
                $header = 'Column '.($index + 1);
            }
            if (mb_strlen($header) > 255) {
                throw ValidationException::withMessages(['file' => 'Import column names may not exceed 255 characters.']);
            }
            if (isset($seen[mb_strtolower($header)])) {
                throw ValidationException::withMessages(['file' => "Duplicate header: {$header}."]);
            }
            $seen[mb_strtolower($header)] = true;
            $headers[] = $header;
        }

        return $headers;
    }

    /** @param list<string> $headers @param list<string|null> $values @return array<string, string|null> */
    private function combine(array $headers, array $values): array
    {
        $values = array_slice(array_pad($values, count($headers), null), 0, count($headers));

        return array_combine($headers, $values) ?: [];
    }

    /** @param list<string|null> $values */
    private function emptyRow(array $values): bool
    {
        return count(array_filter($values, fn (?string $value): bool => $value !== null && trim($value) !== '')) === 0;
    }

    private function delimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }
}
