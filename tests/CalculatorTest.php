<?php
declare(strict_types=1);
use App\Services\Calculator;
use PHPUnit\Framework\TestCase;

final class CalculatorTest extends TestCase
{
    public function testLineWithMultiplier(): void
    {
        $r = Calculator::calculate([['quantity' => 10, 'unit_price' => 150000, 'mult_enabled' => 1, 'mult_value' => 3, 'tax_rate' => 0]], 'none', 0, 'none', 0);
        $this->assertSame(4500000, $r['total']);
    }
    public function testMixedLinesDiscountTaxRounding(): void
    {
        $lines = [
            ['quantity' => 10, 'unit_price' => 150000, 'mult_enabled' => 1, 'mult_value' => 3, 'tax_rate' => 12],
            ['quantity' => 1, 'unit_price' => 5000000, 'mult_enabled' => 0, 'tax_rate' => 0],
            ['quantity' => 2, 'unit_price' => 750000, 'mult_enabled' => 1, 'mult_value' => 2, 'tax_rate' => 11, 'waived' => 1],
        ];
        $r = Calculator::calculate($lines, 'percent', 10, 'percent', 30);
        $this->assertSame(9500000, $r['subtotal']);
        $this->assertSame(950000, $r['discount_amount']);
        // components add up
        $this->assertSame($r['subtotal'] - $r['discount_amount'] + $r['tax_total'], $r['total']);
        $this->assertSame((int)round($r['total'] * 0.30), $r['dp_amount']);
    }
    public function testDisabledMultiplierIgnored(): void
    {
        $r = Calculator::calculate([['quantity' => 2, 'unit_price' => 100000, 'mult_enabled' => 0, 'mult_value' => 99, 'tax_rate' => 0]], 'none', 0, 'none', 0);
        $this->assertSame(200000, $r['total']);
    }
    public function testFixedDiscountCapped(): void
    {
        $r = Calculator::calculate([['quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 0]], 'fixed', 5000, 'none', 0);
        $this->assertSame(1000, $r['discount_amount']);
        $this->assertSame(0, $r['total']);
    }
}
