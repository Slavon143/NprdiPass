<?php

namespace App\Services\Passports\Qr;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

class PassportQrRenderer
{
    private PassportQrPayloadFactory $payloadFactory;

    public function __construct(PassportQrPayloadFactory $payloadFactory)
    {
        $this->payloadFactory = $payloadFactory;
    }

    public function renderSvg(string $publicId): string
    {
        $payload = $this->payloadFactory->create($publicId);
        $builder = new Builder(new SvgWriter);

        $result = $builder->build(
            data: $payload,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: $this->errorCorrectionLevel(),
            size: config('passports.qr.preview_size'),
            margin: config('passports.qr.quiet_zone'),
        );

        return $result->getString();
    }

    public function renderPng(string $publicId): string
    {
        $payload = $this->payloadFactory->create($publicId);
        $builder = new Builder(new PngWriter);

        $result = $builder->build(
            data: $payload,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: $this->errorCorrectionLevel(),
            size: config('passports.qr.download_size'),
            margin: config('passports.qr.quiet_zone'),
        );

        return $result->getString();
    }

    public function eTag(string $publicId, string $format): string
    {
        $version = config('passports.qr.renderer_version');

        return md5("{$version}:{$publicId}:{$format}:".config('passports.public_base_url'));
    }

    public function cacheKey(string $publicId, string $format): string
    {
        $version = config('passports.qr.renderer_version');

        return "passport-qr:{$version}:{$publicId}:{$format}";
    }

    private function errorCorrectionLevel(): ErrorCorrectionLevel
    {
        return match (config('passports.qr.error_correction')) {
            'low' => ErrorCorrectionLevel::Low,
            'quartile' => ErrorCorrectionLevel::Quartile,
            'high' => ErrorCorrectionLevel::High,
            default => ErrorCorrectionLevel::Medium,
        };
    }
}
