<?php

namespace App\Music\Artwork;

use RuntimeException;

class RasterArtworkProcessor
{
    /** @param array{body:string,width:int,height:int} $download
     * @return array{body:string,mime_type:string,width:int,height:int,extension:string}
     */
    public function normalize(array $download): array
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The GD extension is required to normalize artwork.');
        }
        $this->assertMemoryAvailable($download);

        $image = @imagecreatefromstring($download['body']);
        if ($image === false) {
            throw new RuntimeException('Artwork failed a complete raster decode.');
        }
        $width = $download['width'];
        $height = $download['height'];
        $output = $image;
        $resized = null;
        try {
            if (max($width, $height) > 1600) {
                $scale = 1600 / max($width, $height);
                $width = max(1, (int) round($width * $scale));
                $height = max(1, (int) round($height * $scale));
                $resized = imagecreatetruecolor($width, $height);
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                if (! imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, $download['width'], $download['height'])) {
                    throw new RuntimeException('Artwork could not be resized safely.');
                }
                $output = $resized;
            }
            imagepalettetotruecolor($output);
            imagealphablending($output, true);
            imagesavealpha($output, true);
            ob_start();
            $encoded = imagewebp($output, null, 86);
            $body = ob_get_clean();
        } finally {
            if ($resized !== null) {
                imagedestroy($resized);
            }
            imagedestroy($image);
        }
        if (! $encoded || ! is_string($body) || $body === '') {
            throw new RuntimeException('Artwork could not be normalized to WebP.');
        }

        return [
            'body' => $body,
            'mime_type' => 'image/webp',
            'width' => $width,
            'height' => $height,
            'extension' => 'webp',
        ];
    }

    /** @param array{body:string,width:int,height:int} $download */
    private function assertMemoryAvailable(array $download): void
    {
        $limit = $this->memoryLimitBytes();
        if ($limit === null) {
            return;
        }
        $scale = min(1, 1600 / max($download['width'], $download['height']));
        $targetWidth = max(1, (int) round($download['width'] * $scale));
        $targetHeight = max(1, (int) round($download['height'] * $scale));
        $estimated = strlen($download['body'])
            + ($download['width'] * $download['height'] * 5)
            + ($targetWidth * $targetHeight * 8)
            + (16 * 1024 * 1024);
        if (memory_get_usage(false) + $estimated > $limit - (8 * 1024 * 1024)) {
            throw new RuntimeException('Artwork exceeds the available raster processing memory.');
        }
    }

    private function memoryLimitBytes(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1') {
            return null;
        }
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
