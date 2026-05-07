<?php

namespace App\Http\Controllers;

use App\Models\WorkingMemoryVersion;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemoryCompactionController extends Controller
{
    public function show(Request $request, string $scopeType, string $scopeKey, string $versionId): View
    {
        $userId = (int) $request->user()->id;

        $version = WorkingMemoryVersion::query()
            ->whereHas('workingMemory', function ($query) use ($userId, $scopeType, $scopeKey): void {
                $query->where('user_id', $userId)
                    ->where('scope_type', $scopeType)
                    ->where('scope_key', $scopeKey);
            })
            ->where('id', $versionId)
            ->where('build_type', 'like', 'compaction:%')
            ->with('inputs.thought')
            ->firstOrFail();

        return view('memory.compactions.show', [
            'version' => $version,
            'scopeType' => $scopeType,
            'scopeKey' => $scopeKey,
        ]);
    }
}
