<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ImportController extends Controller
{
    public function quick(Request $request): RedirectResponse|JsonResponse
    {
        abort(501);
    }

    public function batch(Request $request): RedirectResponse|JsonResponse
    {
        abort(501);
    }

    public function show(ImportBatch $batch): View
    {
        abort(501);
    }

    public function status(ImportBatch $batch): JsonResponse
    {
        abort(501);
    }

    public function cancel(ImportBatch $batch): RedirectResponse|JsonResponse
    {
        abort(501);
    }

    public function retryFailed(ImportBatch $batch): RedirectResponse|JsonResponse
    {
        abort(501);
    }

    public function destroyThoughts(ImportBatch $batch): RedirectResponse|JsonResponse
    {
        abort(501);
    }
}
