<?php

namespace Database\DemoAssets;

use RuntimeException;

class DemoAssetProvider
{
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    private const WIDTH = 200;

    private const HEIGHT = 200;

    private static array $imageSpecs = [
        'lamp-primary' => [0x26, 0x6B, 0xD7],
        'lamp-gallery-1' => [0x22, 0xA6, 0x3E],
        'lamp-gallery-2' => [0xE8, 0xC5, 0x11],
        'lamp-variant' => [0x7B, 0x2D, 0xA5],
        'extinguisher-primary' => [0xD9, 0x2B, 0x2B],
        'extinguisher-gallery' => [0xF0, 0x7B, 0x13],
        'vest-primary' => [0x8B, 0xC3, 0x12],
        'gloves-primary' => [0x80, 0x80, 0x80],
        'drill-primary' => [0x8B, 0x5E, 0x3C],
        'lamp-v2-primary' => [0x1C, 0x2E, 0x60],
    ];

    private static array $pdfDocs = [
        'doc-conformity' => [
            'title' => 'Declaration of Conformity',
            'body' => 'EU Declaration of Conformity. We, NordiPass Demo Manufacturing AB, declare under our sole responsibility that the product described herein conforms to the essential requirements of the applicable EU harmonisation legislation: Directive 2006/42/EC (Machinery Directive), Directive 2014/30/EU (EMC), Directive 2011/65/EU (RoHS). This declaration is issued by: NordiPass Demo Manufacturing AB, Gävle, Sweden. Signed on: 2026-01-15. Authorised signatory: Technical Compliance Manager.',
        ],
        'doc-user-manual' => [
            'title' => 'User Manual',
            'body' => 'User Manual. This document provides operating instructions, safety guidelines, and maintenance procedures for the product. Read all instructions carefully before use. Store this manual in an accessible location. For the latest version, visit the manufacturer website. Section 1: Assembly. Section 2: Operation. Section 3: Cleaning and Maintenance. Section 4: Troubleshooting. Section 5: Technical Specifications. Section 6: Warranty and Service.',
        ],
        'doc-tech-spec' => [
            'title' => 'Technical Data Sheet',
            'body' => 'Technical Data Sheet. Product specifications, dimensional drawings, and performance characteristics. Nominal voltage: 220-240 V AC, 50 Hz. Power consumption: 40 W. Ingress protection: IP65. Operating temperature: -20 C to +50 C. Net weight: 2.8 kg. Dimensions: 320 x 180 x 120 mm. Housing material: Aluminium alloy 6061. Lens material: Tempered glass. LED type: SMD 2835, CRI > 80. Luminous flux: 4800 lm. Colour temperature: 4000 K. Expected lifetime: 50 000 h (L70). Certification: CE, UKCA.',
        ],
        'doc-warranty' => [
            'title' => 'Warranty Document',
            'body' => 'Warranty Terms and Conditions. NordiPass Demo Manufacturing AB warrants this product against defects in materials and workmanship for a period of 3 years from the date of purchase. This warranty covers repair or replacement of defective components at our discretion. Exclusions: damage caused by improper use, unauthorised modifications, use of non-original spare parts, normal wear and tear, and consumable items. To make a claim, contact your reseller or support@nordipass.test with proof of purchase. This warranty does not affect your statutory rights.',
        ],
        'doc-recycling' => [
            'title' => 'Recycling Guide',
            'body' => 'End-of-Life Recycling Guide. This product must not be disposed of with household waste. Follow local regulations for WEEE (Waste Electrical and Electronic Equipment) disposal. Disassembly procedure: 1. Disconnect from power supply. 2. Remove housing screws. 3. Separate aluminium housing from electronics. 4. Remove battery if applicable. Recycling streams: Aluminium housing -> metal recycling. Electronic PCB -> WEEE recycling. Cables -> copper recovery. Plastic components -> plastic recycling. Packaging -> cardboard recycling.',
        ],
        'doc-conformity-v2' => [
            'title' => 'Declaration of Conformity v2',
            'body' => 'EU Declaration of Conformity (Revision 2). We, NordiPass Demo Manufacturing AB, declare under our sole responsibility that the product described herein conforms to the essential requirements of: Directive 2006/42/EC (Machinery), Directive 2014/30/EU (EMC), Directive 2011/65/EU (RoHS, amended by 2015/863), Directive 2012/19/EU (WEEE). Additional standards applied: EN 60598-1:2021, EN 60598-2-1:2021, EN 55015:2019, EN 61547:2009, EN 62471:2008. This declaration supersedes all previous versions. Issued: 2026-06-01. Signed: Technical Compliance Manager.',
        ],
    ];

    public function imageBinary(string $key): string
    {
        $spec = self::$imageSpecs[$key] ?? throw new RuntimeException("Unknown image key: {$key}");

        return $this->buildPngBinary($spec[0], $spec[1], $spec[2]);
    }

    public function imageBase64(string $key): string
    {
        return base64_encode($this->imageBinary($key));
    }

    public function imageDataUri(string $key): string
    {
        return 'data:image/png;base64,'.$this->imageBase64($key);
    }

    public function pdfContent(string $key): string
    {
        $doc = self::$pdfDocs[$key] ?? throw new RuntimeException("Unknown PDF doc key: {$key}");

        return $this->buildPdfContent($doc['title'], $doc['body']);
    }

    public function pdfBinary(string $key): string
    {
        return $this->pdfContent($key);
    }

    public function imageKeys(): array
    {
        return array_keys(self::$imageSpecs);
    }

    public function pdfDocKeys(): array
    {
        return array_keys(self::$pdfDocs);
    }

    public function imageMimeType(): string
    {
        return 'image/png';
    }

    public function imageExtension(): string
    {
        return 'png';
    }

    public function pdfMimeType(): string
    {
        return 'application/pdf';
    }

    public function pdfExtension(): string
    {
        return 'pdf';
    }

    private function buildPngBinary(int $r, int $g, int $b): string
    {
        $width = self::WIDTH;
        $height = self::HEIGHT;

        $header = self::PNG_SIGNATURE;

        $ihdrData = pack('NNCCCCC',
            $width, $height,
            8,
            2,
            0,
            0,
            0
        );
        $ihdr = $this->pngChunk('IHDR', $ihdrData);

        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\x00";
            for ($x = 0; $x < $width; $x++) {
                $raw .= chr($r).chr($g).chr($b);
            }
        }

        $compressed = gzcompress($raw, 9);

        $idat = $this->pngChunk('IDAT', $compressed);

        $iend = $this->pngChunk('IEND', '');

        return $header.$ihdr.$idat.$iend;
    }

    private function pngChunk(string $type, string $data): string
    {
        $len = pack('N', strlen($data));
        $crc = pack('N', crc32($type.$data));

        return $len.$type.$data.$crc;
    }

    private function buildPdfContent(string $title, string $body): string
    {
        $titleEscaped = $this->pdfEscape($title);
        $bodyEscaped = $this->pdfEscape($body);

        $objects = [];

        $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj";
        $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj";

        $content = "BT /F1 18 Tf 50 750 Td ({$titleEscaped}) Tj T* ET\n";
        $content .= "BT /F1 11 Tf 0 -30 Td ({$bodyEscaped}) Tj ET";

        $streamObjNum = 4;
        $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents {$streamObjNum} 0 R /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> >>\nendobj";
        $objects[] = "{$streamObjNum} 0 obj\n<< /Length ".strlen($content)." >>\nstream\n{$content}\nendstream\nendobj";

        $header = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";

        $bodyText = '';
        foreach ($objects as $obj) {
            $bodyText .= $obj."\n";
        }

        $xrefOffset = strlen($header.$bodyText);

        $xref = "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        $offsets = [];
        $offset = strlen($header);

        foreach ($objects as $i => $obj) {
            $offsets[] = $offset;
            $offset += strlen($obj) + 1;
        }

        foreach ($offsets as $off) {
            $xref .= sprintf("%010d 00000 n \n", $off);
        }

        $trailer = "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $header.$bodyText.$xref.$trailer;
    }

    private function pdfEscape(string $text): string
    {
        $escaped = str_replace(
            ['\\', '(', ')', "\r"],
            ['\\\\', '\\(', '\\)', ''],
            $text
        );

        return $escaped;
    }
}
