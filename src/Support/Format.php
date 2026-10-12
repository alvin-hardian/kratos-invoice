<?php
declare(strict_types=1);
namespace App\Support;

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Jakarta');

final class Format
{
    public static function idr(int|float $n): string
    {
        return 'Rp' . number_format((int) round($n), 0, ',', '.');
    }
    /** Parse Indonesian formatted input ("1.500.000", "Rp 1.500.000", "1500000", "1,5"?) to int rupiah. */
    public static function parseIdr(mixed $v): int
    {
        if ($v === null || $v === '') return 0;
        $s = trim((string) $v);
        $s = preg_replace('/[^0-9,\.\-]/', '', $s) ?? '';
        // Indonesian: '.' thousands, ',' decimal -> drop decimals (whole rupiah)
        if (str_contains($s, ',')) {
            $s = explode(',', $s)[0];
        }
        $s = str_replace('.', '', $s);
        return (int) preg_replace('/[^0-9\-]/', '', $s);
    }
    public static function parseQty(mixed $v): float
    {
        if ($v === null || $v === '') return 0;
        $s = trim((string) $v);
        // allow "1,5" as 1.5
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {
            $s = str_replace('.', '', $s);
        }
        $s = str_replace(',', '.', $s);
        $s = preg_replace('/[^0-9\.\-]/', '', $s) ?? '';
        return (float) $s;
    }
    /** Format DECIMAL(10,3) qty/mult values without trailing zeros ("3.000"->"3", "1.500"->"1.5").
     *  Piece units (pcs/pc/piece/pieces) never show decimals: rounded to whole numbers. */
    public static function qty(mixed $v, ?string $unit = null): string
    {
        if ($v === null || $v === '') return '';
        $s = trim((string) $v);
        $s = str_replace(',', '.', $s);
        if (!is_numeric($s)) return $s;
        $f = (float) $s;
        if ($unit !== null && preg_match('/^(pcs?|pieces?)$/i', trim($unit))) {
            return (string) (int) round($f);
        }
        $s = number_format($f, 3, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return $s === '' ? '0' : $s;
    }
    public static function today(): string
    {
        return date('Y-m-d');
    }
    public static function escDate(?string $d): string
    {
        return $d ? date('d M Y', strtotime($d)) : '-';
    }
}
