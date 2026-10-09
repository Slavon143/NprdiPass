<?php

use App\Services\Imports\ImportFileReader;
use App\Services\Imports\StreamingXlsxWriter;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

test('streaming export preserves literal strings unicode whitespace and wide columns', function () {
    $path = tempnam(sys_get_temp_dir(), 'np-stream-test-');
    $rows = [['name', ...array_fill(0, 27, 'column')], [' =SUM(1,2) ', ...array_fill(0, 26, ' Ångström <&> '), 'last']];
    try {
        app(StreamingXlsxWriter::class)->write($path, $rows);
        $workbook = IOFactory::load($path);
        $sheet = $workbook->getActiveSheet();
        expect($sheet->getCell('A2')->getValue())->toBe(' =SUM(1,2) ')
            ->and($sheet->getCell('B2')->getValue())->toBe(' Ångström <&> ')
            ->and($sheet->getCell('AB2')->getValue())->toBe('last')
            ->and($sheet->getCell('A2')->getDataType())->toBe('s');
        $workbook->disconnectWorksheets();
    } finally {
        unlink($path);
    }
});

test('XLSX import crosses chunk boundaries without losing row numbers formatting or formulas', function () {
    $path = tempnam(sys_get_temp_dir(), 'np-chunk-test-');
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->setCellValue('A1', 'name');
    $sheet->setCellValue('B1', 'price');
    for ($row = 2; $row <= 2050; $row++) {
        $sheet->setCellValue('A'.$row, 'Product '.$row);
    }
    $sheet->setCellValue('B2049', 42.5);
    $sheet->getStyle('B2049')->getNumberFormat()->setFormatCode('0.00');
    try {
        (new Xlsx($book))->save($path);
        $rows = iterator_to_array(app(ImportFileReader::class)->rows($path, 'xlsx'));
        expect($rows)->toHaveCount(2049)->and($rows[2049]['name'])->toBe('Product 2049')
            ->and($rows[2049]['price'])->toBe('42.50')->and($rows[2050]['name'])->toBe('Product 2050');
        $sheet->setCellValue('B2049', '=1+1');
        (new Xlsx($book))->save($path);
        expect(fn () => iterator_to_array(app(ImportFileReader::class)->rows($path, 'xlsx')))
            ->toThrow(ValidationException::class);
    } finally {
        $book->disconnectWorksheets();
        unlink($path);
    }
});

test('XLSX import skips empty chunks and retains sparse row numbers', function () {
    $path = tempnam(sys_get_temp_dir(), 'np-sparse-test-');
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->setCellValue('A1', 'name');
    $sheet->setCellValue('A2', 'First');
    $sheet->setCellValue('A8193', 'Last');
    try {
        (new Xlsx($book))->save($path);
        $rows = iterator_to_array(app(ImportFileReader::class)->rows($path, 'xlsx'));
        expect($rows)->toBe([2 => ['name' => 'First'], 8193 => ['name' => 'Last']]);
    } finally {
        $book->disconnectWorksheets();
        unlink($path);
    }
});
