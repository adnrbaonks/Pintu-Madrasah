<?php

namespace App\Support;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

/** Generator QR PNG (backend GD, tanpa imagick). */
class Qr
{
    public static function png(string $text, int $size = 200): string
    {
        $writer = new PngWriter;

        return $writer->write(new QrCode($text))->getString();
    }

    public static function dataUri(string $text, int $size = 200): string
    {
        return 'data:image/png;base64,'.base64_encode(static::png($text, $size));
    }
}
