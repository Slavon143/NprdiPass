<?php

namespace App\Services\Imports;

use RuntimeException;
use XMLWriter;
use ZipArchive;

class StreamingXlsxWriter
{
    /** @param iterable<array<string|null>> $rows */
    public function write(string $path, iterable $rows): void
    {
        $sheetPath = tempnam(sys_get_temp_dir(), 'np-sheet-');
        $stringsPath = tempnam(sys_get_temp_dir(), 'np-strings-');
        if ($sheetPath === false || $stringsPath === false) {
            foreach ([$sheetPath, $stringsPath] as $temporary) {
                if (is_string($temporary)) {
                    unlink($temporary);
                }
            }
            throw new RuntimeException('Unable to allocate export worksheet.');
        }
        $zip = new ZipArchive;
        try {
            $xml = new XMLWriter;
            if (! $xml->openUri($sheetPath)) {
                throw new RuntimeException('Unable to open export worksheet.');
            }
            $xml->startDocument('1.0', 'UTF-8');
            $xml->startElementNS(null, 'worksheet', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $xml->startElement('sheetData');
            $strings = new XMLWriter;
            if (! $strings->openUri($stringsPath)) {
                throw new RuntimeException('Unable to open export shared strings.');
            }
            $strings->startDocument('1.0', 'UTF-8');
            $strings->startElementNS(null, 'sst', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $stringIndex = 0;
            $rowNumber = 0;
            foreach ($rows as $values) {
                $rowNumber++;
                if ($rowNumber > 1048576 || count($values) > 16384) {
                    throw new RuntimeException('The export exceeds XLSX worksheet limits.');
                }
                $xml->startElement('row');
                $xml->writeAttribute('r', (string) $rowNumber);
                foreach (array_values($values) as $index => $value) {
                    $xml->startElement('c');
                    $xml->writeAttribute('r', $this->column($index + 1).$rowNumber);
                    $xml->writeAttribute('t', 's');
                    $xml->writeElement('v', (string) $stringIndex++);
                    $xml->endElement();
                    $strings->startElement('si');
                    $strings->startElement('t');
                    $strings->writeAttribute('xml:space', 'preserve');
                    $strings->text((string) preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) ($value ?? '')));
                    $strings->endElement();
                    $strings->endElement();
                }
                $xml->endElement();
                $xml->flush();
                $strings->flush();
            }
            $xml->endElement();
            $xml->endElement();
            $xml->endDocument();
            $xml->flush();
            $strings->endElement();
            $strings->endDocument();
            $strings->flush();
            unset($xml, $strings);

            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to open export archive.');
            }
            $files = [
                '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>',
                '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
                'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Worksheet" sheetId="1" r:id="rId1"/></sheets></workbook>',
                'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>',
            ];
            foreach ($files as $name => $contents) {
                if (! $zip->addFromString($name, $contents)) {
                    throw new RuntimeException('Unable to write export archive metadata.');
                }
            }
            if (! $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml')
                || ! $zip->addFile($stringsPath, 'xl/sharedStrings.xml') || ! $zip->close()) {
                throw new RuntimeException('Unable to finish export archive.');
            }
        } finally {
            unset($xml, $strings, $zip);
            if (is_file($sheetPath)) {
                unlink($sheetPath);
            }
            if (is_file($stringsPath)) {
                unlink($stringsPath);
            }
        }
    }

    private function column(int $number): string
    {
        $column = '';
        while ($number > 0) {
            $number--;
            $column = chr(65 + $number % 26).$column;
            $number = intdiv($number, 26);
        }

        return $column;
    }
}
