<?php

namespace App\Services\Imports;

use Generator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Security\XmlScanner;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Shared\StringHelper;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

/** Stream cell data; retain PhpSpreadsheet's workbook styles and cell formatter. */
class StreamingXlsxReader
{
    public function __construct(private readonly ?string $temporaryDirectory = null) {}

    /** ImportFileReader must validate archive expansion bounds before calling this.
     * @return Generator<int, list<string>>
     */
    public function rows(string $path): Generator
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->invalid('The XLSX archive cannot be opened.');
        }
        $temporary = [];
        $book = null;
        $data = null;
        $index = null;
        try {
            $data = tmpfile();
            $index = tmpfile();
            if ($data === false || $index === false) {
                throw new RuntimeException('Unable to allocate XLSX string storage.');
            }
            [$book, $sheetPath, $stringsPath, $namespace] = $this->formatter($zip, $temporary);
            $sheetFile = $this->extract($zip, $sheetPath, $temporary);
            $width = $this->width($sheetFile, $namespace);
            if ($width === 0) {
                return;
            }
            $stringCount = 0;
            if ($stringsPath !== null) {
                $stringsFile = $this->extract($zip, $stringsPath, $temporary);
                foreach ($this->elements($stringsFile, 'si', $namespace) as $item) {
                    $value = $this->text($item, $namespace);
                    $offset = ftell($data);
                    if ($offset === false || fwrite($index, pack('J', $offset)) !== 8
                        || fwrite($data, pack('N', strlen($value)).$value) !== strlen($value) + 4) {
                        throw new RuntimeException('Unable to store XLSX shared string.');
                    }
                    $stringCount++;
                }
            }
            $previous = 0;
            $cell = $book->getSheet(0)->getCell('A1');
            foreach ($this->elements($sheetFile, 'row', $namespace) as $row) {
                $number = isset($row['r']) ? (int) $row['r'] : $previous + 1;
                // The row coordinate is optional in OOXML. A cell coordinate
                // still determines the physical row, including sparse sheets.
                if (! isset($row['r']) && isset($row->children($namespace)->c[0])) {
                    $firstAttributes = $row->children($namespace)->c[0]->attributes();
                    if (isset($firstAttributes['r'])) {
                        $number = Coordinate::indexesFromString((string) $firstAttributes['r'])[1];
                    }
                }
                if ($number <= $previous || $number > 1048576) {
                    $this->invalid('XLSX rows must have increasing valid row numbers.');
                }
                if ($previous === 0 && $number > 1) {
                    yield 1 => array_fill(0, $width, '');
                }
                $values = array_fill(0, $width, '');
                $column = 0;
                foreach ($row->children($namespace)->c as $source) {
                    $attributes = $source->attributes();
                    if (isset($attributes['r'])) {
                        [$column, $referenceRow] = Coordinate::indexesFromString((string) $attributes['r']);
                        if ($referenceRow !== $number) {
                            $this->invalid('XLSX cell reference does not match its row.');
                        }
                    } else {
                        $column++;
                    }
                    if ($column > $width || $column < 1) {
                        $this->invalid('The XLSX cell column is outside the supported range.');
                    }
                    if (isset($source->f)) {
                        $this->invalid("Formulas are not allowed in import files (row {$number}).");
                    }
                    $type = (string) ($attributes['t'] ?? DataType::TYPE_NUMERIC);
                    $raw = isset($source->v) ? (string) $source->v : null;
                    if ($type === DataType::TYPE_STRING) {
                        $value = $raw === null || $raw === '' ? '' : $this->sharedString($data, $index, $stringCount, $raw);
                        $type = DataType::TYPE_STRING;
                    } elseif ($type === DataType::TYPE_INLINE) {
                        $value = isset($source->is) ? $this->text($source->is, $namespace) : '';
                        $type = DataType::TYPE_STRING;
                    } elseif ($type === DataType::TYPE_BOOL) {
                        $value = $raw === null ? null : (bool) $raw;
                        $type = $value === null ? DataType::TYPE_NULL : DataType::TYPE_BOOL;
                    } elseif ($type === DataType::TYPE_NUMERIC) {
                        if ($raw === null || $raw === '') {
                            $value = null;
                            $type = DataType::TYPE_NULL;
                        } elseif (is_numeric($raw)) {
                            $value = $raw + 0;
                        } else {
                            $this->invalid('The XLSX numeric cell is invalid.');
                        }
                    } elseif ($type === DataType::TYPE_ISO_DATE) {
                        $calendar = Date::getExcelCalendar();
                        try {
                            Date::setExcelCalendar($book->getExcelCalendar());
                            $value = Date::convertIsoDate($raw ?? '');
                            $type = DataType::TYPE_NUMERIC;
                        } finally {
                            Date::setExcelCalendar($calendar);
                        }
                    } elseif (in_array($type, [DataType::TYPE_STRING2, DataType::TYPE_ERROR], true)) {
                        $value = $raw;
                        $type = $raw === null ? DataType::TYPE_NULL : $type;
                    } else {
                        $this->invalid('The XLSX cell type is unsupported.');
                    }
                    $style = (int) ($attributes['s'] ?? 0);
                    if ($style < 0 || $style >= count($book->getCellXfCollection())) {
                        $this->invalid('The XLSX cell style is invalid.');
                    }
                    $cell->setValueExplicit($value, $type)->setXfIndex($style);
                    $values[$column - 1] = trim($cell->getFormattedValue());
                }
                $previous = $number;
                yield $number => $values;
            }
        } finally {
            $book?->disconnectWorksheets();
            foreach ([$data, $index] as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $zip->close();
            foreach ($temporary as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /** @param list<string> $temporary
     * @return array{Spreadsheet, string, string|null, string}
     */
    private function formatter(ZipArchive $source, array &$temporary): array
    {
        $root = $this->metadata($source, '_rels/.rels');
        $workbookPath = null;
        foreach ($root->Relationship as $relationship) {
            if (str_ends_with((string) $relationship['Type'], '/officeDocument')) {
                $workbookPath = $this->target('', $relationship);
                break;
            }
        }
        if ($workbookPath === null) {
            $this->invalid('The XLSX workbook relationship is missing.');
        }
        $workbook = $this->metadata($source, $workbookPath);
        $namespace = array_find($workbook->getDocNamespaces(), fn ($uri) => in_array($uri, [
            'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
            'http://purl.oclc.org/ooxml/spreadsheetml/main',
        ], true));
        if (! is_string($namespace)) {
            $this->invalid('The XLSX workbook namespace is unsupported.');
        }
        $first = $workbook->children($namespace)->sheets->sheet[0] ?? null;
        if ($first === null) {
            $this->invalid('The XLSX workbook has no sheets.');
        }
        $relationNamespace = array_find($first->getNamespaces(true), fn ($uri) => str_ends_with($uri, '/relationships'));
        $firstId = is_string($relationNamespace) ? (string) $first->attributes($relationNamespace)['id'] : '';
        $directory = dirname($workbookPath);
        $relsPath = $directory.'/_rels/'.basename($workbookPath).'.rels';
        $relationships = $this->metadata($source, $relsPath);
        $metadataFile = $this->temporary($temporary);
        $zip = new ZipArchive;
        if ($zip->open($metadataFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to allocate XLSX formatter workbook.');
        }
        $sheetPath = null;
        $stringsPath = null;
        try {
            foreach (['[Content_Types].xml', '_rels/.rels', $workbookPath, $relsPath] as $path) {
                $this->copyMetadata($source, $zip, $path);
            }
            foreach ($root->Relationship as $relationship) {
                if (! str_ends_with((string) $relationship['Type'], '/officeDocument')
                    && (string) $relationship['TargetMode'] !== 'External') {
                    $this->copyMetadata($source, $zip, $this->target('', $relationship));
                }
            }
            foreach ($relationships->Relationship as $relationship) {
                $type = (string) $relationship['Type'];
                if (! preg_match('#/(worksheet|chartsheet|sharedStrings|styles|theme)$#', $type)) {
                    continue;
                }
                $path = $this->target($directory, $relationship);
                if (str_ends_with($type, '/worksheet') || str_ends_with($type, '/chartsheet')) {
                    if ((string) $relationship['Id'] === $firstId) {
                        if (! str_ends_with($type, '/worksheet')) {
                            $this->invalid('The first XLSX sheet must be a worksheet.');
                        }
                        $sheetPath = $path;
                    }
                    $zip->addFromString($path, '<worksheet xmlns="'.$namespace.'"><sheetData/></worksheet>');
                } elseif (str_ends_with($type, '/sharedStrings')) {
                    $stringsPath = $path;
                    $zip->addFromString($path, '<sst xmlns="'.$namespace.'"/>');
                } else {
                    $this->copyMetadata($source, $zip, $path);
                }
            }
        } finally {
            $zip->close();
        }
        if ($sheetPath === null) {
            $this->invalid('The first XLSX worksheet relationship is missing.');
        }
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(false);
        $reader->setReadEmptyCells(false);
        $reader->setIncludeCharts(false);
        $reader->setLoadSheetsOnly((string) $first->attributes()['name']);

        return [$reader->load($metadataFile), $sheetPath, $stringsPath, $namespace];
    }

    private function target(string $directory, SimpleXMLElement $relationship): string
    {
        $target = (string) $relationship['Target'];
        if ((string) $relationship['TargetMode'] === 'External' || str_contains($target, ':') || str_contains($target, '\\')) {
            $this->invalid('External XLSX data relationships are not allowed.');
        }
        $parts = [];
        foreach (explode('/', str_starts_with($target, '/') ? ltrim($target, '/') : $directory.'/'.$target) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    $this->invalid('The XLSX relationship escapes its archive.');
                }
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    private function metadata(ZipArchive $zip, string $path): SimpleXMLElement
    {
        $bytes = $zip->getFromName($path);
        if (! is_string($bytes)) {
            $this->invalid('The XLSX metadata part is missing.');
        }

        return new SimpleXMLElement((new XmlScanner)->scan($bytes), LIBXML_NONET);
    }

    private function copyMetadata(ZipArchive $source, ZipArchive $destination, string $path): void
    {
        $bytes = $source->getFromName($path);
        if (is_string($bytes) && ! $destination->addFromString($path, (new XmlScanner)->scan($bytes))) {
            throw new RuntimeException('Unable to copy XLSX formatter metadata.');
        }
    }

    /** @param list<string> $temporary */
    private function extract(ZipArchive $zip, string $path, array &$temporary): string
    {
        $input = $zip->getStream($path);
        if (! is_resource($input)) {
            $this->invalid('The XLSX worksheet or string part is missing.');
        }
        $output = null;
        try {
            $file = $this->temporary($temporary);
            $output = fopen($file, 'wb');
            if ($output === false || stream_copy_to_stream($input, $output) === false) {
                throw new RuntimeException('Unable to spool XLSX XML.');
            }
        } finally {
            fclose($input);
            if (is_resource($output)) {
                fclose($output);
            }
        }

        return $file;
    }

    /** @param list<string> $temporary */
    private function temporary(array &$temporary): string
    {
        $path = tempnam($this->temporaryDirectory ?? sys_get_temp_dir(), 'np-xlsx-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate XLSX spool.');
        }
        $temporary[] = $path;

        return $path;
    }

    private function width(string $path, string $namespace): int
    {
        $maximum = 0;
        foreach ($this->elements($path, 'row', $namespace) as $row) {
            $column = 0;
            foreach ($row->children($namespace)->c as $cell) {
                $attributes = $cell->attributes();
                $column = isset($attributes['r']) ? Coordinate::indexesFromString((string) $attributes['r'])[0] : $column + 1;
                $maximum = max($maximum, $column);
                if ($maximum > 200) {
                    $this->invalid('Import files may contain at most 200 columns.');
                }
            }
        }

        return $maximum;
    }

    /** @return Generator<int, SimpleXMLElement> */
    private function elements(string $path, string $name, string $namespace): Generator
    {
        $reader = new XMLReader;
        $errors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            if (! $reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
                $this->invalid('The XLSX XML cannot be read.');
            }
            $reader->setParserProperty(XMLReader::LOADDTD, false);
            $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::DOC_TYPE) {
                    $this->invalid('DTD declarations are not allowed in XLSX imports.');
                }
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === $name && $reader->namespaceURI === $namespace) {
                    $xml = new SimpleXMLElement((new XmlScanner)->scan($reader->readOuterXml()), LIBXML_NONET);
                    yield $xml;
                }
            }
            foreach (libxml_get_errors() as $error) {
                if ($error->level >= LIBXML_ERR_ERROR) {
                    $this->invalid('The XLSX XML is malformed.');
                }
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($errors);
        }
    }

    private function text(SimpleXMLElement $element, string $namespace): string
    {
        $element = $element->children($namespace);
        if (isset($element->t)) {
            return StringHelper::controlCharacterOOXML2PHP((string) $element->t);
        }
        $value = '';
        foreach ($element->r as $run) {
            $value .= StringHelper::controlCharacterOOXML2PHP((string) $run->t);
        }

        return $value;
    }

    /** @param resource $data @param resource $index */
    private function sharedString($data, $index, int $count, string $raw): string
    {
        if (! ctype_digit($raw) || (int) $raw >= $count || fseek($index, (int) $raw * 8) !== 0) {
            $this->invalid('The XLSX shared string reference is invalid.');
        }
        $offset = fread($index, 8);
        if ($offset === false || strlen($offset) !== 8) {
            throw new RuntimeException('Unable to read XLSX string index.');
        }
        $position = unpack('J', $offset)[1];
        if (fseek($data, $position) !== 0) {
            throw new RuntimeException('Unable to seek XLSX shared string.');
        }
        $length = fread($data, 4);
        if ($length === false || strlen($length) !== 4) {
            throw new RuntimeException('Unable to read XLSX string length.');
        }
        $size = unpack('N', $length)[1];
        $value = $size > 0 ? fread($data, $size) : '';
        if ($value === false || strlen($value) !== $size) {
            throw new RuntimeException('Unable to read XLSX shared string.');
        }

        return $value;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
