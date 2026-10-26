<?php

declare(strict_types=1);

namespace App\Support;

use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * 二维码生成（SVG 内联输出，无需图片目录与额外扩展）。
 */
final class QrCode
{
    public static function svg(string $content, int $size = 180): string
    {
        $style = new RendererStyle($size, 1, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(19, 26, 33)));
        $renderer = new ImageRenderer($style, new SvgImageBackEnd());
        $writer = new Writer($renderer);

        return $writer->writeString($content);
    }
}
