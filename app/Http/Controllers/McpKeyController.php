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

        $validated = $request->validate([
            'label' => 'nullable|string|max:64',
        ]);

        $plainKey = 'ideatub_'.Str::random(32);
        $keyHash = UserMcpKey::hashKey($plainKey);

        $request->user()->userMcpKeys()->create([
            'key_hash' => $keyHash,
            'label' => $this->resolvedMcpKeyLabel($validated['label'] ?? null),
        ]);

        return redirect()
            ->route('settings.mcp-keys.index')
            ->with('new_mcp_key', $plainKey);
    }

    /**
     * Update the human-readable label for an MCP key. Secret is never changed here.
     */
    public function update(Request $request, UserMcpKey $mcpKey): RedirectResponse
    {
        $this->authorize('update', $mcpKey);

        $field = 'label_'.$mcpKey->id;
        $validated = $request->validate([
            $field => 'nullable|string|max:64',
        ]);

        $mcpKey->update([
            'label' => $this->resolvedMcpKeyLabel($validated[$field] ?? null),
        ]);

        return redirect()
            ->route('settings.mcp-keys.index')
            ->with('success', 'Label updated.');
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

    /**
     * Trim; empty becomes the default label used when the user does not name a key.
     */
    private function resolvedMcpKeyLabel(?string $label): string
    {
        $trimmed = trim((string) ($label ?? ''));

        return $trimmed === '' ? 'Created in IdeaTub' : $trimmed;
    }
}
