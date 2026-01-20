<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Display the homepage.
     */
    public function index()
    {
        $tools = [
            [
                'slug' => 'merge',
                'name' => 'Merge PDF',
                'description' => 'Combine multiple PDF files into one document',
                'icon' => '📄',
            ],
            [
                'slug' => 'split',
                'name' => 'Split PDF',
                'description' => 'Extract pages or split PDF into multiple files',
                'icon' => '✂️',
            ],
            [
                'slug' => 'compress',
                'name' => 'Compress PDF',
                'description' => 'Reduce PDF file size without losing quality',
                'icon' => '🗜️',
            ],
            [
                'slug' => 'pdf-to-image',
                'name' => 'PDF to Image',
                'description' => 'Convert PDF pages to PNG or JPG images',
                'icon' => '🖼️',
            ],
            [
                'slug' => 'image-to-pdf',
                'name' => 'Image to PDF',
                'description' => 'Convert images to PDF documents',
                'icon' => '📷',
            ],
            [
                'slug' => 'rotate',
                'name' => 'Rotate PDF',
                'description' => 'Rotate PDF pages 90, 180, or 270 degrees',
                'icon' => '🔄',
            ],
            [
                'slug' => 'reorder',
                'name' => 'Reorder Pages',
                'description' => 'Drag and drop to reorder PDF pages',
                'icon' => '📑',
            ],
        ];

        return view('home', compact('tools'));
    }
}
