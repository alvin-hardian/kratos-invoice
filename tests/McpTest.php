<?php
declare(strict_types=1);
use App\Mcp\Auth;
use App\Mcp\McpRouter;
use App\Mcp\PayloadAdapter;
use App\Mcp\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class McpTest extends TestCase
{
    public function testInitializeNegotiatesVersion(): void
    {
        $r = McpRouter::handleSingle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']], null);
        $this->assertSame('2025-06-18', $r['result']['protocolVersion']);
        $this->assertSame('kratos-invoice', $r['result']['serverInfo']['name']);
    }

    public function testInitializeFallsBackForUnknownVersion(): void
    {
        $r = McpRouter::handleSingle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => []], null);
        $this->assertSame('2025-06-18', $r['result']['protocolVersion']);
    }

    public function testPingAndNotification(): void
    {
        $r = McpRouter::handleSingle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping'], null);
        $this->assertSame([], $r['result']);
        $this->assertNull(McpRouter::handleSingle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], null));
    }

    public function testToolsListCoversReadWrite(): void
    {
        $r = McpRouter::handleSingle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list'], null);
        $names = array_column($r['result']['tools'], 'name');
        foreach (['dashboard_stats', 'get_invoice', 'get_summary', 'preview_calculation', 'create_quotation_draft', 'convert_quotation_to_invoice', 'record_payment', 'void_payment'] as $t) {
            $this->assertContains($t, $names, "missing tool $t");
        }
        $this->assertTrue(ToolRegistry::isWrite('record_payment'));
        $this->assertFalse(ToolRegistry::isWrite('get_summary'));
    }

    public function testUnknownMethodAndTool(): void
    {
        $r = McpRouter::handleSingle(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'nope/nope'], null);
        $this->assertSame(-32601, $r['error']['code']);
        // tools/call with unknown name needs a PDO; use sqlite memory (fails at DB stage only for known tools).
        $db = new PDO('sqlite::memory:');
        $r = McpRouter::handleSingle(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'nope', 'arguments' => []]], $db);
        $this->assertSame(-32602, $r['error']['code']);
    }

    public function testInvalidRequest(): void
    {
        $r = McpRouter::handleSingle(['nope' => true], null);
        $this->assertSame(-32600, $r['error']['code']);
    }

    public function testAuthBearerParsing(): void
    {
        $this->assertSame('abc123', Auth::bearerFromHeader('Bearer abc123'));
        $this->assertSame('', Auth::bearerFromHeader('Basic xyz'));
        $this->assertSame('', Auth::bearerFromHeader(''));
    }

    public function testPayloadAdapterShape(): void
    {
        $post = PayloadAdapter::toPost([
            'customer_id' => 7, 'doc_date' => '2026-10-08',
            'sections' => [['title' => 'S1', 'lines' => [['description' => 'Item', 'quantity' => 2, 'unit_price' => 100000]]]],
        ]);
        $this->assertSame(7, $post['customer_id']);
        $this->assertSame('none', $post['discount_type']);
        $this->assertSame('S1', $post['sections'][0]['title']);
        $this->assertSame('pcs', $post['sections'][0]['lines'][0]['unit_label']);
    }

    public function testPreviewCalculationValidationErrorIsToolError(): void
    {
        $db = new PDO('sqlite::memory:');
        // tax_rates table stub so Repo::taxMap works on sqlite
        $db->exec('CREATE TABLE tax_rates (id INTEGER PRIMARY KEY, code TEXT, label TEXT, rate REAL, active INTEGER)');
        $r = McpRouter::handleSingle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'preview_calculation', 'arguments' => ['lines' => []]]], $db);
        $this->assertTrue($r['result']['isError'] ?? false);
    }

    public function testResourcesAndPromptsList(): void
    {
        $r = McpRouter::handleSingle(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'resources/list'], null);
        $this->assertNotEmpty($r['result']['resources']);
        $r = McpRouter::handleSingle(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'prompts/list'], null);
        $this->assertNotEmpty($r['result']['prompts']);
        $r = McpRouter::handleSingle(['jsonrpc' => '2.0', 'id' => 10, 'method' => 'prompts/get', 'params' => ['name' => 'month-close-summary']], null);
        $this->assertNotEmpty($r['result']['messages']);
    }
}
