<?php

declare(strict_types=1);

namespace M2Performance;

/**
 * Single source of truth for the tool version.
 *
 * Dependency-free on purpose: services (BaselineManager, SarifExporter
 * callers, MCP server) need the version without pulling in the Symfony
 * Console application class.
 */
class ToolVersion
{
    public const VERSION = '1.0.4.1';
}
