<?php

declare(strict_types=1);

namespace LlmRouter\Enum;

enum DriverType: string
{
    case LLM = 'llm';
    case AGENT = 'agent';
    case MCP = 'mcp';
    case EMBEDDING = 'embedding';
    case STORAGE = 'storage';
}
