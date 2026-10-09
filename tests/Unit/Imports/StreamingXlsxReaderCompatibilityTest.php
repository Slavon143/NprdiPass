<?php

use App\Services\Imports\StreamingXlsxReader;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

test('candidate XLSX reader matches native formatted cells sparse coordinates and workbook calendars', function (int $calendar, bool $explicitRowCoordinates) {
    $book = new Spreadsheet;
    $book->setExcelCalendar($calendar);
    $sheet = $book->getActiveSheet();
    $sheet->fromArray([['text', 'number', 'boolean', 'date', 'rich', 'error', 'split_escape'], ['Å & <lamp>', 42.5, true, 45123.5]]);
    $sheet->getStyle('B2')->getNumberFormat()->setFormatCode('0.00');
    $sheet->getStyle('D2')->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm');
    $rich = new RichText;
    $rich->createText('Recycled ');
    $rich->createTextRun('steel')->getFont()->setBold(true);
    $sheet->setCellValue('E2', $rich);
    $split = new RichText;
    $split->createTextRun('_x00');
    $split->createTextRun('0D_');
    $sheet->setCellValue('G2', $split);
    $sheet->setCellValueExplicit('F2', '#N/A', DataType::TYPE_ERROR);
    $sheet->setCellValueExplicit('A8193', '00123', DataType::TYPE_STRING);
    $sheet->setCellValue('B8193', 0.125);
    $sheet->getStyle('B8193')->getNumberFormat()->setFormatCode('0.0%');
    $sheet->setCellValue('C8193', false);
    $book->createSheet()->setCellValue('Z1', 'Ignored second sheet');
    $path = tempnam(sys_get_temp_dir(), 'np-reader-compare-');
    try {
        (new Xlsx($book))->save($path);
        if (! $explicitRowCoordinates) {
            $zip = new ZipArchive;
            $zip->open($path);
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->addFromString('xl/worksheets/sheet1.xml', preg_replace('/(<row) r="[0-9]+"/', '$1', $xml));
            $zip->close();
        }
        $native = IOFactory::load($path);
        $calendarBefore = Date::getExcelCalendar();
        $expected = [];
        foreach ([1, 2, 8193] as $number) {
            $expected[$number] = [];
            foreach (range('A', 'G') as $column) {
                $expected[$number][] = trim($native->getSheet(0)->getCell($column.$number)->getFormattedValue());
            }
        }
        $actual = iterator_to_array(app(StreamingXlsxReader::class)->rows($path));
        expect($actual)->toBe($expected)->and(Date::getExcelCalendar())->toBe($calendarBefore);
        $native->disconnectWorksheets();
    } finally {
        $book->disconnectWorksheets();
        Date::setExcelCalendar(Date::CALENDAR_WINDOWS_1900);
        unlink($path);
    }
})->with([[Date::CALENDAR_WINDOWS_1900, true], [Date::CALENDAR_MAC_1904, true],
    [Date::CALENDAR_WINDOWS_1900, false], [Date::CALENDAR_MAC_1904, false]]);

test('streaming reader preserves inline rich text ISO dates and plain string cells', function () {
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->fromArray([['name', 'number', 'date', 'string'], ['Product', 42.5, 45123.5, 'Text']]);
    $sheet->getStyle('C2')->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm');
    $path = tempnam(sys_get_temp_dir(), 'np-reader-inline-');
    try {
        (new Xlsx($book))->save($path);
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $xml = preg_replace('/<c r="A2"[^>]*>.*?<\/c>/', '<c r="A2" t="inlineStr"><is><r><t>Å &amp; </t></r><r><t>steel_x000D_</t></r></is></c>', $xml);
        $xml = preg_replace_callback('/<c r="C2"([^>]*)>.*?<\/c>/', fn ($match) => '<c r="C2"'.preg_replace('/ t="[^"]*"/', '', $match[1]).' t="d"><v>2023-07-16T12:00:00Z</v></c>', $xml);
        $xml = preg_replace('/<c r="D2"[^>]*>.*?<\/c>/', '<c r="D2" t="str"><v>prefix_x000D_suffix</v></c>', $xml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();
        $native = IOFactory::load($path);
        $expected = [];
        foreach ([1, 2] as $row) {
            foreach (range('A', 'D') as $column) {
                $expected[$row][] = trim($native->getSheet(0)->getCell($column.$row)->getFormattedValue());
            }
        }
        expect(iterator_to_array(app(StreamingXlsxReader::class)->rows($path)))->toBe($expected);
        $native->disconnectWorksheets();
    } finally {
        $book->disconnectWorksheets();
        Date::setExcelCalendar(Date::CALENDAR_WINDOWS_1900);
        unlink($path);
    }
});

test('candidate reader rejects malformed data and cleans spools after failure or early close', function (string $case, string $message) {
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray([['name'], ['Fixture']]);
    $directory = storage_path('framework/reader-candidate-'.(string) str()->uuid());
    mkdir($directory, 0777, true);
    $path = $directory.'/fixture.xlsx';
    try {
        (new Xlsx($book))->save($path);
        $zip = new ZipArchive;
        $zip->open($path);
        if ($case === 'doctype') {
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $xml = preg_replace('/(<\?xml[^>]+>)/', '$1<!DOCTYPE worksheet [<!ENTITY leaked SYSTEM "file:///nonexistent-nordipass-fixture">]>', $xml);
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        } elseif ($case === 'string') {
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $xml = preg_replace('/<v>1<\/v>/', '<v>999999999</v>', $xml);
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        }
        $zip->close();
        $reader = new StreamingXlsxReader($directory);
        if ($case === 'early_close') {
            $rows = $reader->rows($path);
            foreach ($rows as $values) {
                break;
            }
            unset($rows);
            gc_collect_cycles();
        } else {
            expect(fn () => iterator_to_array($reader->rows($path)))->toThrow(ValidationException::class, $message);
        }
        expect(glob($directory.'/np-xlsx-*'))->toBe([]);
    } finally {
        $book->disconnectWorksheets();
        unlink($path);
        foreach (glob($directory.'/np-xlsx-*') as $spool) {
            unlink($spool);
        }
        rmdir($directory);
    }
})->with([['doctype', 'DTD declarations'], ['string', 'shared string reference'], ['early_close', '']]);

test('candidate reader rejects formulas at a sparse late row', function () {
    $book = new Spreadsheet;
    $book->getActiveSheet()->setCellValue('A1', 'name')->setCellValue('A8193', '=1+1');
    $path = tempnam(sys_get_temp_dir(), 'np-reader-formula-');
    try {
        (new Xlsx($book))->save($path);
        expect(fn () => iterator_to_array(app(StreamingXlsxReader::class)->rows($path)))
            ->toThrow(ValidationException::class, 'Formulas are not allowed');
    } finally {
        $book->disconnectWorksheets();
        unlink($path);
    }
});
