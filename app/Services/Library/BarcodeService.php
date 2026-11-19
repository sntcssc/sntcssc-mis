<?php

namespace App\Services\Library;

use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class BarcodeService
{
    /**
     * Code 128B pattern table for encoding alphanumeric strings.
     * Each symbol is represented as an array of 6 bar/space widths (sum is 11).
     */
    private const CODE128_PATTERNS = [
        0 => '212222', 1 => '222122', 2 => '222221', 3 => '121223', 4 => '121322',
        5 => '131222', 6 => '122213', 7 => '122312', 8 => '132212', 9 => '221213',
        10 => '221312', 11 => '231212', 12 => '112232', 13 => '122132', 14 => '122231',
        15 => '113222', 16 => '123122', 17 => '123221', 18 => '223211', 19 => '221132',
        20 => '221231', 21 => '213212', 22 => '223112', 23 => '312131', 24 => '311222',
        25 => '321122', 26 => '321221', 27 => '312212', 28 => '322112', 29 => '322211',
        30 => '212123', 31 => '212321', 32 => '232121', 33 => '111323', 34 => '131123',
        35 => '131321', 36 => '112313', 37 => '132113', 38 => '132311', 39 => '211313',
        40 => '231113', 41 => '231311', 42 => '112133', 43 => '112331', 44 => '132131',
        45 => '113123', 46 => '113321', 47 => '133121', 48 => '313121', 49 => '211331',
        50 => '231131', 51 => '213113', 52 => '213311', 53 => '213131', 54 => '311123',
        55 => '311321', 56 => '331121', 57 => '312113', 58 => '312311', 59 => '332111',
        60 => '314111', 61 => '221411', 62 => '431111', 63 => '111224', 64 => '111422',
        65 => '121124', 66 => '121421', 67 => '141122', 68 => '141221', 69 => '112214',
        70 => '112412', 71 => '122114', 72 => '122411', 73 => '142112', 74 => '142211',
        75 => '241211', 76 => '221114', 77 => '413111', 78 => '241112', 79 => '134111',
        80 => '111242', 81 => '121142', 82 => '121241', 83 => '114212', 84 => '124112',
        85 => '124211', 86 => '411212', 87 => '421112', 88 => '421211', 89 => '212141',
        90 => '214121', 91 => '412121', 92 => '111143', 93 => '111341', 94 => '131141',
        95 => '114113', 96 => '114311', 97 => '411113', 98 => '411311', 99 => '113141',
        100 => '114131', 101 => '311141', 102 => '411131',
        104 => '211412', // Start B
        106 => '2331112', // Stop
    ];

    /**
     * Generate an SVG Code 128 Barcode for the given text.
     *
     * @param  string  $text  The text to encode (e.g. ACC-2026-0001)
     * @param  int  $height  Barcode height in pixels (default 50)
     * @param  int  $barWidth  Module width in pixels (default 2)
     * @param  bool  $showText  Whether to show human-readable text below
     */
    public static function generateBarcodeSvg(
        string $text,
        int $height = 50,
        int $barWidth = 2,
        bool $showText = true
    ): string {
        $text = trim($text);
        if ($text === '') {
            $text = 'EMPTY';
        }

        // Code 128B start symbol = 104
        $values = [104];
        $checkSum = 104;

        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ascii = ord($text[$i]);
            // ASCII 32 to 126 maps to values 0 to 94
            $val = $ascii >= 32 && $ascii <= 126 ? $ascii - 32 : 0;
            $values[] = $val;
            $checkSum += $val * ($i + 1);
        }

        $checkDigit = $checkSum % 103;
        $values[] = $checkDigit;
        $values[] = 106; // Stop code

        // Build bars sequence: odd indexes = bar, even = space
        $patterns = '';
        foreach ($values as $val) {
            $patterns .= self::CODE128_PATTERNS[$val] ?? '212222';
        }

        $quietZone = 10 * $barWidth;
        $x = $quietZone;
        $rects = [];

        $pLen = strlen($patterns);
        for ($i = 0; $i < $pLen; $i++) {
            $width = ((int) $patterns[$i]) * $barWidth;
            $isBar = ($i % 2 === 0);
            if ($isBar) {
                $rects[] = "<rect x=\"{$x}\" y=\"0\" width=\"{$width}\" height=\"{$height}\" fill=\"#0f172a\" />";
            }
            $x += $width;
        }

        $totalWidth = $x + $quietZone;
        $totalHeight = $showText ? $height + 18 : $height;
        $textY = $height + 14;

        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$totalWidth} {$totalHeight}\" width=\"{$totalWidth}\" height=\"{$totalHeight}\">\n";
        $svg .= "  <rect width=\"100%\" height=\"100%\" fill=\"#ffffff\" />\n";
        $svg .= '  '.implode("\n  ", $rects)."\n";
        if ($showText) {
            $centerX = $totalWidth / 2;
            $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $svg .= "  <text x=\"{$centerX}\" y=\"{$textY}\" text-anchor=\"middle\" font-family=\"monospace\" font-size=\"12\" font-weight=\"600\" fill=\"#1e293b\">{$escaped}</text>\n";
        }
        $svg .= '</svg>';

        return $svg;
    }

    /**
     * Generate base64 Data URI for barcode SVG.
     */
    public static function generateBarcodeDataUri(
        string $text,
        int $height = 50,
        int $barWidth = 2,
        bool $showText = true
    ): string {
        $svg = self::generateBarcodeSvg($text, $height, $barWidth, $showText);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Generate QR code Data URI.
     */
    public static function generateQrCodeDataUri(string $content, int $scale = 5): string
    {
        try {
            if (extension_loaded('gd')) {
                $options = new QROptions([
                    'outputInterface' => QRGdImagePNG::class,
                    'outputBase64' => true,
                    'scale' => $scale,
                ]);

                return (new QRCode($options))->render($content);
            }

            $options = new QROptions([
                'outputBase64' => true,
                'scale' => $scale,
            ]);

            return (new QRCode($options))->render($content);
        } catch (\Throwable $e) {
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120"><rect width="120" height="120" fill="#f8fafc" stroke="#cbd5e1" stroke-width="2" rx="8"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="10" font-family="monospace" fill="#0f172a">'.htmlspecialchars($content, ENT_QUOTES).'</text></svg>';

            return 'data:image/svg+xml;base64,'.base64_encode($svg);
        }
    }
}
