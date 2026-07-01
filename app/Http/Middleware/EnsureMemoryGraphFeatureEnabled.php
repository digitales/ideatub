<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMemoryGraphFeatureEnabled
{
    private const LEVEL_CONFIG = [
        'local' => 'memory_graph_local',
        'project' => 'memory_graph_project',
        'tag' => 'memory_graph_tag',
        'semantic' => 'memory_graph_semantic',
        'vault' => 'memory_graph_vault',
        'suggestions' => 'memory_graph_suggestions',
    ];

    public function handle(Request $request, Closure $next, string $level): Response
    {
        $configKey = self::LEVEL_CONFIG[$level] ?? null;
        if ($configKey === null || ! config("features.{$configKey}")) {
            abort(404);
        }

        return $next($request);
    }
}
