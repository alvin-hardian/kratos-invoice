<?php
declare(strict_types=1);
namespace App\Support;

final class Logo
{
    private const ALLOWED = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    private const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * Base64 data URI for embedding in PDFs (Dompdf runs with remote URLs
     * disabled, so http/file URLs would not render). Null when missing/invalid.
     */
    public static function dataUri(string $publicDir, ?string $logoPath): ?string
    {
        if ($logoPath === null || $logoPath === '') return null;
        $rel = ltrim(str_replace('\\', '/', $logoPath), '/');
        if (!str_starts_with($rel, 'uploads/')) return null; // only our uploads dir
        $real = realpath(rtrim($publicDir, '/\\') . '/' . $rel);
        if ($real === false || !is_file($real) || (filesize($real) ?: 0) > self::MAX_BYTES) return null;
        $mime = mime_content_type($real);
        if (!isset(self::ALLOWED[$mime])) return null;
        $bin = file_get_contents($real);
        if ($bin === false || $bin === '') return null;
        return 'data:' . $mime . ';base64,' . base64_encode($bin);
    }
}
