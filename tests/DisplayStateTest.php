<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use App\Services\DocService;
use PHPUnit\Framework\TestCase;

final class DisplayStateTest extends TestCase
{
    public function testPrecedence(): void
    {
        // cancelled/draft always win, even with payments present
        $this->assertSame('cancelled', DocService::displayState('cancelled', 100, 100, null, '2026-10-22'));
        $this->assertSame('draft', DocService::displayState('draft', 100, 0, null, '2026-10-22'));
        // fully paid reads paid (never "issued")
        $this->assertSame('paid', DocService::displayState('issued', 500000, 500000, '2026-11-05', '2026-10-22'));
        // Rp0 total with zero balance is settled, not "unpaid"
        $this->assertSame('paid', DocService::displayState('issued', 0, 0, null, '2026-10-22'));
        // partial beats overdue
        $this->assertSame('partial', DocService::displayState('issued', 1000, 400, '2026-10-01', '2026-10-22'));
        // unpaid + past due = overdue
        $this->assertSame('overdue', DocService::displayState('issued', 1000, 0, '2026-10-01', '2026-10-22'));
        // unpaid + due in future = issued
        $this->assertSame('issued', DocService::displayState('issued', 1000, 0, '2026-12-01', '2026-10-22'));
        // unpaid + no due date = issued
        $this->assertSame('issued', DocService::displayState('issued', 1000, 0, null, '2026-10-22'));
    }
}
