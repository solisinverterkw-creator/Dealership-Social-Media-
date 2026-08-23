<?php
/**
 * Downsizes an uploaded image in place if it's larger than needed — phone
 * photos can be 4000px+ and 10-15MB, which (once base64-encoded for the
 * Gemini vision API) can produce a request too large to complete, failing
 * with a generic "HTTP 0" curl error. Keeps the original file format (so the
 * file extension still matches its actual content/MIME type) — only the
 * pixel dimensions and, for jpeg, the compression quality change.
 */
class ImageResizer
{
    public static function resizeInPlace(string $path, int $maxDimension = 600, int $jpegQuality = 80): void
    {
        if (!function_exists('imagecreatefromstring') || !file_exists($path)) {
            return; // GD unavailable or file missing — leave the upload as-is
        }

        $data = @file_get_contents($path);
        if ($data === false) {
            return;
        }
        $src = @imagecreatefromstring($data);
        if (!$src) {
            return; // not a format GD can decode — leave as-is
        }

        $width = imagesx($src);
        $height = imagesy($src);
        $longest = max($width, $height);

        if ($longest <= $maxDimension) {
            imagedestroy($src);
            return; // already a sane size, nothing to do
        }

        $scale = $maxDimension / $longest;
        $newWidth = max(1, (int)round($width * $scale));
        $newHeight = max(1, (int)round($height * $scale));

        $dst = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($src);

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'png':
                imagepng($dst, $path, 6);
                break;
            case 'webp':
                imagewebp($dst, $path, $jpegQuality);
                break;
            default:
                // JPEG has no alpha channel — flatten onto white first.
                $flat = imagecreatetruecolor($newWidth, $newHeight);
                $white = imagecolorallocate($flat, 255, 255, 255);
                imagefill($flat, 0, 0, $white);
                imagecopy($flat, $dst, 0, 0, 0, 0, $newWidth, $newHeight);
                imagejpeg($flat, $path, $jpegQuality);
                imagedestroy($flat);
        }
        imagedestroy($dst);
    }
}
