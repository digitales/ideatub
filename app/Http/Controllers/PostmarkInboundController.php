<?php

namespace App\Http\Controllers;

use App\Services\PostmarkInboundService;
use Illuminate\Http\Request;

class PostmarkInboundController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();
        if (! is_array($payload) || $payload === []) {
            return response()->json(['error' => 'Invalid payload'], 422);
        }
        try {
            app(PostmarkInboundService::class)->process($payload);

            return response('', 200);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Processing failed'], 503);
        }
    }
}
