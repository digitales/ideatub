<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StreamLayoutController extends Controller
{
    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'layout' => 'required|in:list,grid',
        ]);

        $request->session()->put('stream_layout', $validated['layout']);

        return response()->noContent();
    }
}
