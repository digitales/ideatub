<?php

namespace App\Http\Controllers;

use App\Models\ResearchShare;
use Illuminate\View\View;

class SharedResearchViewController extends Controller
{
    /**
     * Show shared research (readonly). No auth; password gate in Task 2.3.
     */
    public function show(string $token): View
    {
        $share = ResearchShare::where('token', $token)->first();

        if ($share === null) {
            abort(404, 'Link not found or no longer available.');
        }

        if ($share->isExpired()) {
            abort(410, 'This link has expired.');
        }

        // If password_hash is set, skip check for now (cookie gate in Task 2.3)
        $thought = $share->thought;

        if ($thought === null) {
            abort(404, 'Link not found or no longer available.');
        }

        $sections = $thought->comments()->orderBy('created_at')->get();

        return view('shared_research.readonly', [
            'root' => $thought,
            'sections' => $sections,
        ]);
    }
}
