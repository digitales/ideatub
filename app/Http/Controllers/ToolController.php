<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ToolController extends Controller
{
    /**
     * Valid tool slugs.
     */
    private const VALID_TOOLS = [
        'merge',
        'split',
        'compress',
        'pdf-to-image',
        'image-to-pdf',
        'rotate',
        'reorder',
    ];

    /**
     * Tool metadata for SEO and display.
     */
    private const TOOL_METADATA = [
        'merge' => [
            'name' => 'Merge PDF',
            'title' => 'Merge PDF Online Free - Combine Multiple PDFs',
            'description' => 'Merge multiple PDF files into one document online. Free PDF merger tool - no registration required. Combine PDFs instantly.',
            'keywords' => 'merge pdf, combine pdf, pdf merger, merge pdf online free',
        ],
        'split' => [
            'name' => 'Split PDF',
            'title' => 'Split PDF Online Free - Extract Pages from PDF',
            'description' => 'Split PDF files into separate documents. Extract pages or split PDF into multiple files. Free online PDF splitter tool.',
            'keywords' => 'split pdf, extract pdf pages, pdf splitter, split pdf online free',
        ],
        'compress' => [
            'name' => 'Compress PDF',
            'title' => 'Compress PDF Online Free - Reduce PDF File Size',
            'description' => 'Compress PDF files to reduce file size. Free PDF compressor tool - reduce PDF size without losing quality.',
            'keywords' => 'compress pdf, reduce pdf size, pdf compressor, compress pdf online free',
        ],
        'pdf-to-image' => [
            'name' => 'PDF to Image',
            'title' => 'PDF to Image Converter Online Free - PDF to PNG/JPG',
            'description' => 'Convert PDF pages to images. Free PDF to PNG and PDF to JPG converter. Extract images from PDF files.',
            'keywords' => 'pdf to image, pdf to png, pdf to jpg, pdf converter',
        ],
        'image-to-pdf' => [
            'name' => 'Image to PDF',
            'title' => 'Image to PDF Converter Online Free - JPG/PNG to PDF',
            'description' => 'Convert images to PDF. Free JPG to PDF and PNG to PDF converter. Combine multiple images into one PDF.',
            'keywords' => 'image to pdf, jpg to pdf, png to pdf, image converter',
        ],
        'rotate' => [
            'name' => 'Rotate PDF',
            'title' => 'Rotate PDF Online Free - Rotate PDF Pages',
            'description' => 'Rotate PDF pages 90, 180, or 270 degrees. Free online PDF rotator tool. Fix rotated PDF documents.',
            'keywords' => 'rotate pdf, rotate pdf pages, pdf rotator, rotate pdf online',
        ],
        'reorder' => [
            'name' => 'Reorder Pages',
            'title' => 'Reorder PDF Pages Online Free - Drag and Drop',
            'description' => 'Reorder PDF pages with drag and drop. Free PDF page reorder tool. Rearrange PDF pages easily.',
            'keywords' => 'reorder pdf pages, rearrange pdf, pdf page order, reorder pdf',
        ],
    ];

    /**
     * Display a tool page.
     */
    public function show(string $tool)
    {
        if (!in_array($tool, self::VALID_TOOLS)) {
            abort(404);
        }

        $metadata = self::TOOL_METADATA[$tool];
        $operationsRemaining = auth()->check() ? auth()->user()->operationsRemainingToday() : null;

        return view('tools.' . str_replace('-', '_', $tool), [
            'tool' => $tool,
            'metadata' => $metadata,
            'operationsRemaining' => $operationsRemaining,
        ]);
    }

    /**
     * Track an operation.
     */
    public function track(Request $request)
    {
        $request->validate([
            'operation_type' => 'required|string|in:' . implode(',', self::VALID_TOOLS),
        ]);

        if (!auth()->check()) {
            return response()->json([
                'success' => true,
                'operations_remaining' => null,
                'message' => 'Operation completed. Sign in to track your usage.',
            ]);
        }

        $user = auth()->user();

        if (!$user->canPerformOperation()) {
            return response()->json([
                'error' => 'Daily limit reached',
                'message' => 'You have reached your daily limit of 3 operations.',
            ], 403);
        }

        $user->operations()->create([
            'operation_type' => $request->operation_type,
        ]);

        return response()->json([
            'success' => true,
            'operations_remaining' => $user->operationsRemainingToday(),
            'message' => 'Operation tracked successfully.',
        ]);
    }
}
