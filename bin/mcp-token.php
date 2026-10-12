<?php
declare(strict_types=1);
// Generate an MCP bearer token: php bin/mcp-token.php [--write]
// --write inserts/updates MCP_TOKEN in .env (leaves a backup at .env.bak).
$write = in_array('--write', $argv ?? [], true);
$token = bin2hex(random_bytes(32));
if (!$write) {
    echo "MCP_TOKEN=$token\n";
    echo "Add it to .env, or run: php bin/mcp-token.php --write\n";
    exit(0);
}
$envFile = __DIR__ . '/../.env';
$lines = is_file($envFile) ? file($envFile, FILE_IGNORE_NEW_LINES) : [];
$found = false;
foreach ($lines as $i => $ln) {
    if (preg_match('/^\s*MCP_TOKEN\s*=/', $ln)) { $lines[$i] = "MCP_TOKEN=$token"; $found = true; }
}
if (!$found) {
    if (!empty($lines) && trim(end($lines)) !== '') $lines[] = '';
    $lines[] = "MCP_TOKEN=$token";
}
if (is_file($envFile)) copy($envFile, __DIR__ . '/../.env.bak');
file_put_contents($envFile, implode("\n", $lines) . "\n");
echo "Wrote MCP_TOKEN to .env (backup: .env.bak)\n";
