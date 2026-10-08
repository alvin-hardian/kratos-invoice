<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Authoritative document calculator. Money = whole IDR ints.
 * Policy: line = waived?0:round(qty*[mult]*price) -> subtotal -> discount (pre-tax,
 * proportional allocation w/ largest remainder) -> exclusive tax per line -> total.
 */
final class Calculator
{
    /** @param array $lines each: quantity, unit_price, mult_enabled, mult_value, tax_rate, waived */
    public static function calculate(array $lines, string $discountType, float $discountValue, string $dpType, float $dpValue): array
    {
        $n = count($lines);
        $amounts = [];
        foreach ($lines as $l) {
            $amounts[] = self::lineAmount($l);
        }
        $subtotal = array_sum($amounts);
        // discount
        $discountAmount = 0;
        if ($discountType === 'percent' && $discountValue > 0 && $subtotal > 0) {
            $discountAmount = (int) round($subtotal * min($discountValue, 100) / 100);
        } elseif ($discountType === 'fixed' && $discountValue > 0) {
            $discountAmount = min((int) round($discountValue), $subtotal);
        }
        // proportional allocation (largest remainder)
        $discountAlloc = array_fill(0, $n, 0);
        if ($discountAmount > 0 && $subtotal > 0) {
            $floors = []; $frac = [];
            $assigned = 0;
            foreach ($amounts as $i => $a) {
                $exact = $a * $discountAmount / $subtotal;
                $f = (int) floor($exact);
                $floors[$i] = $f; $frac[$i] = $exact - $f; $assigned += $f;
            }
            $rest = $discountAmount - $assigned;
            arsort($frac);
            foreach (array_keys($frac) as $i) {
                if ($rest <= 0) break;
                if ($amounts[$i] - $floors[$i] > 0) { $floors[$i]++; $rest--; }
            }
            $discountAlloc = $floors;
        }
        // tax
        $taxAmounts = []; $breakdown = [];
        foreach ($lines as $i => $l) {
            $taxable = $amounts[$i] - $discountAlloc[$i];
            $rate = (float) ($l['tax_rate'] ?? 0);
            $t = ($taxable > 0 && $rate > 0) ? (int) round($taxable * $rate / 100) : 0;
            $taxAmounts[$i] = $t;
            if ($t > 0 || ($taxable > 0 && $rate > 0)) {
                $key = number_format($rate, 2, '.', '');
                $breakdown[$key]['rate'] = $rate;
                $breakdown[$key]['base'] = ($breakdown[$key]['base'] ?? 0) + $taxable;
                $breakdown[$key]['tax'] = ($breakdown[$key]['tax'] ?? 0) + $t;
            }
        }
        $taxTotal = array_sum($taxAmounts);
        $total = $subtotal - $discountAmount + $taxTotal;
        // down payment
        $dpAmount = 0;
        if ($dpType === 'percent' && $dpValue > 0 && $total > 0) {
            $dpAmount = (int) round($total * min($dpValue, 100) / 100);
        } elseif ($dpType === 'fixed' && $dpValue > 0) {
            $dpAmount = min((int) round($dpValue), $total);
        }
        return [
            'line_amounts' => $amounts,
            'line_discounts' => $discountAlloc,
            'line_taxes' => $taxAmounts,
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'tax_total' => $taxTotal,
            'tax_breakdown' => array_values($breakdown),
            'total' => $total,
            'dp_amount' => $dpAmount,
            'remaining_after_dp' => $total - $dpAmount,
        ];
    }

    public static function lineAmount(array $l): int
    {
        if (!empty($l['waived'])) return 0;
        $qty = (float) ($l['quantity'] ?? 0);
        $price = (int) round((float) ($l['unit_price'] ?? 0));
        if ($qty <= 0 || $price < 0) return 0;
        if (!empty($l['mult_enabled'])) {
            $m = (float) ($l['mult_value'] ?? 0);
            if ($m <= 0) return 0;
            return (int) round($qty * $m * $price);
        }
        return (int) round($qty * $price);
    }

    /** Validate raw editor payload; returns [errors, normalizedSections]. */
    public static function validateDocument(array $data): array
    {
        $errors = [];
        if (empty($data['customer_id'])) $errors[] = 'Customer is required.';
        if (empty($data['doc_date']) || !self::validDate($data['doc_date'])) $errors[] = 'Invalid document date.';
        $sections = $data['sections'] ?? [];
        if (!is_array($sections) || count($sections) === 0) $errors[] = 'At least one section is required.';
        $lineCount = 0;
        foreach ($sections as $si => $sec) {
            if (trim((string)($sec['title'] ?? '')) === '') $errors[] = 'Section ' . ($si+1) . ' title is required.';
            $lines = $sec['lines'] ?? [];
            if (!is_array($lines) || count($lines) === 0) $errors[] = 'Section "' . ($sec['title'] ?? ($si+1)) . '" must contain at least one item.';
            foreach ($lines as $li => $l) {
                $lineCount++;
                if (trim((string)($l['description'] ?? '')) === '') $errors[] = "Line " . ($li+1) . " in section " . ($si+1) . " needs a description.";
                $q = (float)($l['quantity'] ?? 0);
                if ($q <= 0) $errors[] = "Quantity must be > 0 (section " . ($si+1) . ", line " . ($li+1) . ").";
                if ((int)($l['unit_price'] ?? 0) < 0) $errors[] = 'Unit price cannot be negative.';
                if (!empty($l['mult_enabled']) && (float)($l['mult_value'] ?? 0) <= 0) $errors[] = 'Enabled multiplier must be > 0.';
                $r = (float)($l['tax_rate'] ?? 0);
                if ($r < 0 || $r > 100) $errors[] = 'Invalid tax rate.';
            }
        }
        if ($lineCount === 0) $errors[] = 'At least one line item is required.';
        // discount
        $dt = $data['discount_type'] ?? 'none';
        $dv = (float)($data['discount_value'] ?? 0);
        if ($dt === 'percent' && ($dv <= 0 || $dv > 100)) $errors[] = 'Discount percent must be 0–100.';
        if ($dt === 'fixed' && $dv < 0) $errors[] = 'Invalid fixed discount.';
        // dp validated against total later by caller
        return $errors;
    }

    public static function validDate(?string $d): bool
    {
        if (!$d) return false;
        $t = strtotime($d);
        return $t !== false && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    }
}
