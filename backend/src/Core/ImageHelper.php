<?php

declare(strict_types=1);

namespace CeylonCues\Core;

/**
 * Image processing helper for thumbnails and WebP conversion.
 */
final class ImageHelper
{
    /**
     * Generate an optimized thumbnail image.
     */
    public static function createThumbnail(string $sourcePath, string $targetPath, int $maxWidth = 300, int $maxHeight = 300): bool
    {
        if (!file_exists($sourcePath)) {
            return false;
        }

        [$origWidth, $origHeight, $type] = getimagesize($sourcePath) ?: [0, 0, 0];
        if ($origWidth === 0 || $origHeight === 0) {
            return false;
        }

        // Calculate aspect-preserving dimensions
        $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight, 1.0);
        $newWidth = (int)round($origWidth * $ratio);
        $newHeight = (int)round($origHeight * $ratio);

        $srcImg = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG  => @imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($sourcePath),
            default        => false,
        };

        if (!$srcImg) {
            return false;
        }

        $destImg = imagecreatetruecolor($newWidth, $newHeight);
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            imagealphablending($destImg, false);
            imagesavealpha($destImg, true);
        }

        imagecopyresampled($destImg, $srcImg, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $ext = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
        $success = match ($ext) {
            'webp'        => imagewebp($destImg, $targetPath, 85),
            'png'         => imagepng($destImg, $targetPath, 8),
            default       => imagejpeg($destImg, $targetPath, 85),
        };

        imagedestroy($srcImg);
        imagedestroy($destImg);

        return $success;
    }

    /**
     * Convert an image to WebP format.
     */
    public static function convertToWebP(string $sourcePath, string $targetWebpPath, int $quality = 85): bool
    {
        if (!file_exists($sourcePath)) {
            return false;
        }

        [$width, $height, $type] = getimagesize($sourcePath) ?: [0, 0, 0];
        if ($width === 0 || $height === 0) {
            return false;
        }

        $srcImg = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG  => @imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($sourcePath),
            default        => false,
        };

        if (!$srcImg) {
            return false;
        }

        $dir = dirname($targetWebpPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        imagealphablending($srcImg, true);
        imagesavealpha($srcImg, true);

        $ok = imagewebp($srcImg, $targetWebpPath, $quality);
        imagedestroy($srcImg);

        return $ok;
    }
}
