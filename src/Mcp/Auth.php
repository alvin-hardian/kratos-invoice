<?php
declare(strict_types=1);
namespace App\Mcp;

use App\Support\DB;

final class Auth
{
    public static function token(): string
    {
        return DB::env('MCP_TOKEN', '');
    }

    public static function enabled(): bool
    {
        if (self::token() === '') return false;
        return DB::env('MCP_ENABLED', '1') !== '0';
    }

    public static function readOnly(): bool
    {
        return DB::env('MCP_READONLY', '0') === '1';
    }

    /** Actor id recorded in audit_log for MCP writes (null = system). */
    public static function actorId(): ?int
    {
        $v = (int) DB::env('MCP_ACTOR_ID', '0');
        return $v > 0 ? $v : null;
    }

    public static function bearerFromHeader(string $header): string
    {
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) return $m[1];
        return '';
    }

    public static function verify(string $authHeader): bool
    {
        $expected = self::token();
        if ($expected === '') return false;
        $got = self::bearerFromHeader($authHeader);
        if ($got === '') return false;
        return hash_equals($expected, $got);
    }
}
