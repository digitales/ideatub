<?php

namespace App\Http\Controllers;

use App\Models\ResearchShare;
use App\Models\Thought;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class SharedResearchController extends Controller
{
    /**
     * List the current user's research shares.
     */
    public function index(Request $request): View
    {
        $shares = ResearchShare::where('user_id', $request->user()->id)
            ->with('thought')
            ->orderByDesc('created_at')
            ->get();

        $topLevelThoughts = Thought::where('user_id', $request->user()->id)
            ->whereNull('parent_id')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('shared_research.index', [
            'shares' => $shares,
            'focusShareId' => $request->query('share'),
            'topLevelThoughts' => $topLevelThoughts,
        ]);
    }

    /**
     * Create a new research share.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'thought_id' => 'required|exists:thoughts,id',
            'password' => 'nullable|string|min:1',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $thought = Thought::find($validated['thought_id']);

        if ($thought->user_id !== $request->user()->id) {
            abort(403, 'You can only share your own thoughts.');
        }

        if ($thought->parent_id !== null) {
            abort(403, 'Only top-level thoughts can be shared.');
        }

        if (ResearchShare::where('thought_id', $thought->id)->exists()) {
            return redirect()
                ->route('shared-research.index')
                ->with('error', 'This research is already shared; manage it below.');
        }

        $share = ResearchShare::create([
            'user_id' => $request->user()->id,
            'thought_id' => $thought->id,
            'token' => ResearchShare::generateToken(),
            'password_hash' => $request->filled('password') ? Hash::make($request->password) : null,
            'expires_at' => $request->input('expires_at'),
        ]);

        return redirect()
            ->route('shared-research.index', ['share' => $share->id])
            ->with('success', 'Link created. Copy below.');
    }

    /**
     * Update password or expiry for a share.
     */
    public function update(Request $request, ResearchShare $researchShare): RedirectResponse
    {
        $this->authorize('update', $researchShare);

        $validated = $request->validate([
            'password' => 'nullable|string',
            'password_remove' => 'sometimes|boolean',
            'expires_at' => 'nullable|date',
        ]);

        $passwordChanged = false;

        if (! empty($validated['password_remove'] ?? false)) {
            $researchShare->password_hash = null;
            $passwordChanged = true;
        } elseif ($request->filled('password')) {
            $researchShare->password_hash = Hash::make($request->password);
            $passwordChanged = true;
        }

        if (array_key_exists('expires_at', $validated)) {
            $researchShare->expires_at = $validated['expires_at'];
        }

        $researchShare->save();

        $response = redirect()->back()->with('success', 'Share updated.');

        if ($passwordChanged) {
            $response->withCookie(Cookie::forget('research_share_'.$researchShare->token));
        }

        return $response;
    }

    /**
     * Revoke (delete) a share.
     */
    public function destroy(ResearchShare $researchShare): RedirectResponse
    {
        $this->authorize('delete', $researchShare);

        $researchShare->delete();

        return redirect()
            ->route('shared-research.index')
            ->with('success', 'Share revoked.');
    }
}
