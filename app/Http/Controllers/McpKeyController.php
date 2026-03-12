<?php

namespace App\Http\Controllers;

use App\Models\UserMcpKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class McpKeyController extends Controller
{
    /**
     * Display the user's MCP keys and optionally the one-time new key (from flash).
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', UserMcpKey::class);

        $keys = $request->user()
            ->userMcpKeys()
            ->orderByDesc('created_at')
            ->get();

        $newKey = $request->session()->pull('new_mcp_key');

        return view('settings.mcp-keys', [
            'keys' => $keys,
            'newKey' => $newKey,
            'mcpUrl' => url('/api/mcp'),
        ]);
    }

    /**
     * Create a new MCP key for the authenticated user. The plain key is shown once via flash.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', UserMcpKey::class);

        $plainKey = 'ideatub_'.Str::random(32);
        $keyHash = UserMcpKey::hashKey($plainKey);

        $request->user()->userMcpKeys()->create([
            'key_hash' => $keyHash,
            'label' => 'Created in IdeaTub',
        ]);

        return redirect()
            ->route('settings.mcp-keys.index')
            ->with('new_mcp_key', $plainKey);
    }

    /**
     * Revoke (delete) an MCP key. Only the owner can revoke.
     */
    public function destroy(Request $request, UserMcpKey $mcpKey): RedirectResponse
    {
        $this->authorize('delete', $mcpKey);

        $mcpKey->delete();

        return redirect()
            ->route('settings.mcp-keys.index')
            ->with('success', 'MCP key revoked. Any clients using it will need a new key.');
    }
}
