<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class FileCompressionService
{
    /**
     * Compress a file if it exceeds 1MB. Returns the path to the compressed file.
     * Works for Images (GD) and PDFs (Ghostscript).
     *
     * @param  string  $sourcePath  Absolute path to the source file
     * @param  string  $mimeType  Mime type of the file
     * @param  string  $extension  Extension of the file
     * @return string Path to the compressed file (could be the same if not compressed)
     */
    public function compressIfNeeded(string $sourcePath, string $mimeType, string $extension): string
    {
        if (! file_exists($sourcePath)) {
            return $sourcePath;
        }

        $filesize = filesize($sourcePath);

        // 1MB = 1048576 bytes. Let's trigger compression if > 1MB
        if ($filesize <= 1048576) {
            return $sourcePath;
        }

        if (str_contains($mimeType, 'image')) {
            return $this->compressImage($sourcePath, $extension);
        }

        if ($mimeType === 'application/pdf') {
            return $this->compressPdf($sourcePath);
        }

        // Return original if format is not supported for compression
        return $sourcePath;
    }

    protected function compressImage(string $sourcePath, string $extension): string
    {
        $extension = strtolower($extension);

        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'])) {
            return $sourcePath;
        }

        $tempPath = $sourcePath.'.compressed.'.$extension;

        // Try to load image
        if (in_array($extension, ['jpg', 'jpeg'])) {
            $image = @imagecreatefromjpeg($sourcePath);
        } elseif ($extension === 'png') {
            $image = @imagecreatefrompng($sourcePath);
        } elseif ($extension === 'webp') {
            $image = @imagecreatefromwebp($sourcePath);
        } else {
            return $sourcePath;
        }

        if (! $image) {
            Log::warning("Failed to load image for compression: $sourcePath");

            return $sourcePath;
        }

        // We can just save it with lower quality for JPG/WEBP.
        // For PNG, maybe we convert to JPG to save space, or just use PNG compression.
        $success = false;
        if (in_array($extension, ['jpg', 'jpeg'])) {
            // Quality 60 is usually good enough to drop size below 1MB
            $success = imagejpeg($image, $tempPath, 60);
        } elseif ($extension === 'webp') {
            $success = imagewebp($image, $tempPath, 60);
        } elseif ($extension === 'png') {
            // PNG compression level 9 (max)
            $success = imagepng($image, $tempPath, 9);
        }

        imagedestroy($image);

        if ($success && file_exists($tempPath)) {
            // Check if the compressed file is actually smaller
            if (filesize($tempPath) < filesize($sourcePath)) {
                // Replace original with compressed
                unlink($sourcePath);
                rename($tempPath, $sourcePath);
            } else {
                unlink($tempPath);
            }
        }

        return $sourcePath;
    }

    protected function compressPdf(string $sourcePath): string
    {
        $tempPath = $sourcePath.'.compressed.pdf';

        // Using Ghostscript to compress PDF
        // /screen = low-resolution output similar to the Acrobat Distiller "Screen Optimized" setting (72 dpi)
        // /ebook = medium-resolution output (150 dpi)
        // Fix the escaping because Process handles it automatically if we pass array
        $process = new Process([
            'gs',
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.4',
            '-dPDFSETTINGS=/ebook',
            '-dNOPAUSE',
            '-dQUIET',
            '-dBATCH',
            '-sOutputFile='.$tempPath,
            $sourcePath,
        ]);

        $process->setTimeout(60); // 60 seconds max

        try {
            $process->run();

            if ($process->isSuccessful() && file_exists($tempPath)) {
                // If it's smaller, keep it. Otherwise, discard.
                if (filesize($tempPath) > 0 && filesize($tempPath) < filesize($sourcePath)) {
                    unlink($sourcePath);
                    rename($tempPath, $sourcePath);
                } else {
                    @unlink($tempPath);
                }
            } else {
                Log::warning('Ghostscript compression failed: '.$process->getErrorOutput());
            }
        } catch (\Exception $e) {
            Log::error('Ghostscript execution error: '.$e->getMessage());
        }

        return $sourcePath;
    }
}
