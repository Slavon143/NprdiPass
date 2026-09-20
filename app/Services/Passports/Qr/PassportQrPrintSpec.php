<?php

namespace App\Services\Passports\Qr;

class PassportQrPrintSpec
{
    public const MIN_CONTRAST_RATIO = 4.5;

    /**
     * @return array{
     *     foreground: string,
     *     background: string,
     *     contrast_ratio: float,
     *     contrast_passes: bool,
     *     error_correction: string,
     *     quiet_zone_modules: int,
     *     min_recommended_size_mm: int,
     *     recommended_packaging_size_mm: int
     * }
     */
    public function toArray(): array
    {
        $foreground = (string) config('passports.qr.foreground', '#000000');
        $background = (string) config('passports.qr.background', '#ffffff');
        $contrastRatio = $this->contrastRatio($foreground, $background);

        return [
            'foreground' => $foreground,
            'background' => $background,
            'contrast_ratio' => round($contrastRatio, 2),
            'contrast_passes' => $contrastRatio >= self::MIN_CONTRAST_RATIO,
            'error_correction' => (string) config('passports.qr.error_correction', 'medium'),
            'quiet_zone_modules' => (int) config('passports.qr.quiet_zone', 4),
            'min_recommended_size_mm' => (int) config('passports.qr.print.min_recommended_size_mm', 25),
            'recommended_packaging_size_mm' => (int) config('passports.qr.print.recommended_packaging_size_mm', 30),
        ];
    }

    public function contrastRatio(string $foreground, string $background): float
    {
        $fg = $this->relativeLuminance($foreground);
        $bg = $this->relativeLuminance($background);

        $lighter = max($fg, $bg);
        $darker = min($fg, $bg);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return [0, 0, 0];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = $this->rgb($hex);

        $channels = array_map(
            fn (int $value): float => $this->linearChannel($value / 255),
            [$r, $g, $b],
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private function linearChannel(float $channel): float
    {
        if ($channel <= 0.03928) {
            return $channel / 12.92;
        }

        return (($channel + 0.055) / 1.055) ** 2.4;
    }
}
