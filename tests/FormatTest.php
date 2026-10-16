<?php
declare(strict_types=1);
use App\Support\Format;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    public function testParseQtyDbRoundTrip(): void
    {
        // DECIMAL(10,3) values come back as "1.000" — must stay 1, not 1000.
        $this->assertSame(1.0, Format::parseQty('1.000'));
        $this->assertSame(1.5, Format::parseQty('1.500'));
        $this->assertSame(10.0, Format::parseQty('10.000'));
        $this->assertSame(2.0, Format::parseQty(2));
        $this->assertSame(1.5, Format::parseQty('1,5'));
    }
    public function testParseQtyTrueThousands(): void
    {
        $this->assertSame(1500000.0, Format::parseQty('1.500.000'));
    }
}
