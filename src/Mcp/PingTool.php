<?php

namespace App\Mcp;

use Mcp\Capability\Attribute\McpTool;

final class PingTool
{
    #[McpTool(name: 'ping', description: 'Check whether the KLMS MCP server is responding.')]
    public function ping(): string
    {
        return 'pong';
    }
}
