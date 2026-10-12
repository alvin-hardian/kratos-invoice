<?php
declare(strict_types=1);
namespace App\Mcp;

use PDO;
use Throwable;

/**
 * Minimal MCP Streamable-HTTP dispatcher (JSON-RPC 2.0, single endpoint).
 * No external SDK: implements initialize / tools/* / resources/* / prompts/* / ping.
 */
final class McpRouter
{
    public const SERVER_NAME = 'kratos-invoice';
    public const SERVER_VERSION = '1.0.0';
    private const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];
    private const DEFAULT_PROTOCOL = '2025-06-18';

    /**
     * Dispatch a decoded JSON-RPC message. Returns [httpStatus, responseBody|null].
     * $body may be an array (single request) or a list (batch).
     */
    public static function dispatch(mixed $body, PDO $db): array
    {
        if (is_array($body) && array_is_list($body)) {
            $out = [];
            foreach ($body as $msg) {
                $r = self::handleSingle(is_array($msg) ? $msg : [], $db);
                if ($r !== null) $out[] = $r;
            }
            if ($out === []) return [202, null];
            return [200, $out];
        }
        if (!is_array($body)) {
            return [400, self::error(null, -32700, 'Parse error: body must be a JSON object.')];
        }
        $r = self::handleSingle($body, $db);
        if ($r === null) return [202, null]; // notification
        $code = isset($r['error']) ? self::httpForError((int)$r['error']['code']) : 200;
        return [$code, $r];
    }

    /** @return array|null JSON-RPC response, or null for notifications */
    public static function handleSingle(array $msg, ?PDO $db): ?array
    {
        $id = $msg['id'] ?? null;
        $hasId = array_key_exists('id', $msg);
        $method = $msg['method'] ?? null;
        $params = $msg['params'] ?? [];
        if (!is_array($params)) $params = [];
        if (($msg['jsonrpc'] ?? null) !== '2.0' || !is_string($method)) {
            return self::error($hasId ? $id : null, -32600, 'Invalid Request: expected {jsonrpc:"2.0", method, ...}.');
        }
        if (!$hasId) {
            // Notification: only acknowledged method; no DB needed.
            if ($method === 'notifications/initialized') return null;
            return null;
        }
        try {
            $result = self::callMethod($method, $params, $db);
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        } catch (McpException $e) {
            return self::error($id, $e->getCode(), $e->getMessage(), $e->getData());
        } catch (Throwable $e) {
            $msg = (bool)(getenv('APP_DEBUG') ?: true) ? $e->getMessage() : 'Internal error.';
            return self::error($id, -32603, $msg);
        }
    }

    private static function callMethod(string $method, array $params, ?PDO $db): mixed
    {
        switch ($method) {
            case 'initialize': {
                $client = (string)($params['protocolVersion'] ?? '');
                $neg = in_array($client, self::PROTOCOL_VERSIONS, true) ? $client : self::DEFAULT_PROTOCOL;
                return [
                    'protocolVersion' => $neg,
                    'capabilities' => ['tools' => ['listChanged' => false], 'resources' => ['subscribe' => false, 'listChanged' => false], 'prompts' => ['listChanged' => false]],
                    'serverInfo' => ['name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION],
                ];
            }
            case 'ping':
                return [];
            case 'tools/list':
                return ['tools' => ToolRegistry::definitions()];
            case 'tools/call': {
                if ($db === null) throw new McpException(-32603, 'Database unavailable.');
                $name = (string)($params['name'] ?? '');
                $args = $params['arguments'] ?? [];
                if ($name === '') throw new McpException(-32602, 'tools/call requires params.name.');
                if (!is_array($args)) throw new McpException(-32602, 'params.arguments must be an object.');
                if (!in_array($name, ToolRegistry::names(), true)) throw new McpException(-32602, "Unknown tool: $name");
                if (Auth::readOnly() && ToolRegistry::isWrite($name)) {
                    throw new McpException(-32000, 'MCP server is read-only (MCP_READONLY=1).');
                }
                try {
                    $data = ToolRegistry::call($db, $name, $args);
                } catch (Throwable $e) {
                    // Tool-level failure -> MCP result with isError (not a protocol error).
                    return ['content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]], 'isError' => true];
                }
                return ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: 'null']]];
            }
            case 'resources/list':
                return ['resources' => [
                    ['uri' => 'kratos://dashboard', 'name' => 'Dashboard stats', 'mimeType' => 'application/json'],
                    ['uri' => 'kratos://summary', 'name' => 'Period summary (use get_summary tool for params)', 'mimeType' => 'application/json'],
                ]];
            case 'resources/read': {
                if ($db === null) throw new McpException(-32603, 'Database unavailable.');
                $uri = (string)($params['uri'] ?? '');
                if ($uri === 'kratos://dashboard') {
                    $data = ToolRegistry::call($db, 'dashboard_stats', []);
                } elseif (preg_match('#^invoice://(\d+)$#', $uri, $m)) {
                    $data = ToolRegistry::call($db, 'get_invoice', ['id' => (int)$m[1]]);
                } elseif (preg_match('#^quotation://(\d+)$#', $uri, $m)) {
                    $data = ToolRegistry::call($db, 'get_quotation', ['id' => (int)$m[1]]);
                } else {
                    throw new McpException(-32602, 'Unknown resource URI. Try kratos://dashboard, invoice://{id}, quotation://{id}.');
                }
                return ['contents' => [['uri' => $uri, 'mimeType' => 'application/json', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE) ?: 'null']]];
            }
            case 'prompts/list':
                return ['prompts' => [
                    ['name' => 'draft-quote-from-request', 'description' => 'Turn a customer request into create_quotation_draft arguments.'],
                    ['name' => 'chase-overdue', 'description' => 'List overdue invoices and draft follow-up actions.'],
                    ['name' => 'month-close-summary', 'description' => 'Summarize issued/paid/outstanding for a period.'],
                ]];
            case 'prompts/get': {
                $name = (string)($params['name'] ?? '');
                $text = match ($name) {
                    'draft-quote-from-request' => "Collect customer_id (or create_customer), doc_date, and sections[] with title + lines(description, quantity, unit_price, tax). Call preview_calculation first, then create_quotation_draft, then issue_quotation only on confirmation.",
                    'chase-overdue' => "Call list_invoices with status=issued, compute overdue from due_date vs today and balance>0, or get_summary for the period. Present number, customer, balance, days overdue.",
                    'month-close-summary' => "Call get_summary with from/to (YYYY-MM-DD). Report issued_invoices, paid, outstanding, awaiting_quotations.",
                    default => throw new McpException(-32602, "Unknown prompt: $name"),
                };
                return ['messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]]];
            }
            default:
                throw new McpException(-32601, "Method not found: $method");
        }
    }

    public static function error(mixed $id, int $code, string $message, mixed $data = null): array
    {
        $e = ['code' => $code, 'message' => $message];
        if ($data !== null) $e['data'] = $data;
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $e];
    }

    private static function httpForError(int $code): int
    {
        return match ($code) {
            -32700 => 400,
            -32600 => 400,
            -32601 => 404,
            -32602 => 422,
            -32001 => 401,
            default => 500,
        };
    }
}

final class McpException extends \RuntimeException
{
    public function __construct(int $code, string $message, mixed $data = null)
    {
        parent::__construct($message, $code);
        $this->data = $data;
    }
    public mixed $data = null;
    public function getData(): mixed { return $this->data; }
}
