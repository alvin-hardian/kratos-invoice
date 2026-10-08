<?php
declare(strict_types=1);
namespace App\Support;

use PDO;

final class Repo
{
    public static function settings(PDO $db): array
    {
        $s = $db->query('SELECT * FROM company_settings WHERE id=1')->fetch();
        if (!$s) { $db->exec('INSERT IGNORE INTO company_settings (id) VALUES (1)'); $s = $db->query('SELECT * FROM company_settings WHERE id=1')->fetch(); }
        return $s ?: [];
    }
    public static function taxMap(PDO $db): array
    {
        $rows = $db->query('SELECT * FROM tax_rates WHERE active=1 ORDER BY rate, code')->fetchAll();
        $map = [];
        foreach ($rows as $r) $map[(int)$r['id']] = $r;
        return $map;
    }
    public static function audit(PDO $db, string $entity, int $id, string $action, ?int $actor, ?string $detail = null): void
    {
        $st = $db->prepare('INSERT INTO audit_log (entity_type, entity_id, action, actor_id, detail) VALUES (?,?,?,?,?)');
        $st->execute([$entity, $id, $action, $actor, $detail]);
    }
    /** Short number: PREFIX-YYMMNNN e.g. INV-2608001. Monthly sequence, never reused. */
    public static function nextNumber(PDO $db, string $type, string $prefix, int $year, int $month): string
    {
        // atomic counter inside caller's transaction (no LOCK TABLES)
        $db->prepare('INSERT INTO document_counters (doc_type, year, `month`, last_no) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE last_no=last_no+1')->execute([$type, $year, $month]);
        $st = $db->prepare('SELECT last_no FROM document_counters WHERE doc_type=? AND year=? AND `month`=?');
        $st->execute([$type, $year, $month]);
        $next = (int)$st->fetch()['last_no'];
        return sprintf('%s-%02d%02d%03d', $prefix, $year % 100, $month, $next);
    }
}
