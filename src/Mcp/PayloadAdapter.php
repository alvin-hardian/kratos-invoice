<?php
declare(strict_types=1);
namespace App\Mcp;

use App\Services\DocService;
use PDO;

/**
 * Adapts MCP JSON tool arguments to the shape DocService::normalizePayload()
 * expects (same as the HTML editor POST: sections[].lines[] etc.).
 */
final class PayloadAdapter
{
    /** @return array shape compatible with DocService::normalizePayload() */
    public static function toPost(array $args): array
    {
        $post = [];
        foreach (['customer_id', 'doc_date', 'expiry_date', 'due_date', 'reference',
            'discount_type', 'discount_value', 'dp_type', 'dp_value', 'dp_due_date',
            'notes', 'terms', 'show_signature', 'bank_ids', 'sections'] as $k) {
            if (array_key_exists($k, $args)) $post[$k] = $args[$k];
        }
        $post['discount_type'] = $post['discount_type'] ?? 'none';
        $post['dp_type'] = $post['dp_type'] ?? 'none';
        if (!isset($post['bank_ids'])) $post['bank_ids'] = [];
        if (!is_array($post['bank_ids'])) $post['bank_ids'] = [$post['bank_ids']];
        if (!isset($post['sections']) || !is_array($post['sections'])) $post['sections'] = [];
        // Normalize each section/line so missing keys behave like the web form.
        $secs = [];
        foreach (array_values($post['sections']) as $sec) {
            if (!is_array($sec)) continue;
            $lines = [];
            foreach (array_values($sec['lines'] ?? []) as $l) {
                if (!is_array($l)) continue;
                $lines[] = [
                    'product_id' => $l['product_id'] ?? '',
                    'description' => $l['description'] ?? '',
                    'quantity' => $l['quantity'] ?? 1,
                    'unit_label' => $l['unit_label'] ?? 'pcs',
                    'unit_price' => $l['unit_price'] ?? 0,
                    'mult_enabled' => $l['mult_enabled'] ?? false,
                    'mult_label' => $l['mult_label'] ?? '',
                    'mult_value' => $l['mult_value'] ?? '',
                    'tax_rate_id' => $l['tax_rate_id'] ?? '',
                    'tax_rate' => $l['tax_rate'] ?? '',
                    'waived' => $l['waived'] ?? false,
                    'price_mode' => $l['price_mode'] ?? 'normal',
                ];
            }
            $secs[] = [
                'title' => $sec['title'] ?? '',
                'description' => $sec['description'] ?? '',
                'lines' => $lines,
            ];
        }
        $post['sections'] = $secs;
        return $post;
    }

    /** @return array normalized payload incl. ['calc' => ...] */
    public static function normalize(PDO $db, array $args): array
    {
        return DocService::normalizePayload(self::toPost($args), $db);
    }
}
