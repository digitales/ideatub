<?php

namespace App\Http\Controllers;

use Illuminate\View\View;
use League\CommonMark\CommonMarkConverter;

class ExamplePromptsController extends Controller
{
    /**
     * Open Brain Companion Prompts from Prompt Kit.
     * Content is loaded from markdown files in resources/content/example-prompts/.
     * Source: https://promptkit.natebjones.com/20260224_uq1_promptkit_1
     */
    public function index(): View
    {
        $converter = new CommonMarkConverter;
        $promptsDir = resource_path('content/example-prompts');

        $prompts = [];
        foreach (['01-memory-migration', '02-second-brain-migration', '03-open-brain-spark', '04-quick-capture-templates', '05-weekly-review'] as $i => $slug) {
            $path = "{$promptsDir}/{$slug}.md";
            $number = $i + 1;
            $markdown = file_exists($path) ? file_get_contents($path) : "*(Missing: {$slug}.md)*";
            $bodyHtml = $converter->convert($markdown)->getContent();
            $prompts[] = [
                'number' => $number,
                'slug' => $slug,
                'body_html' => $bodyHtml,
            ];
        }

        return view('example-prompts', [
            'prompts' => $prompts,
            'query' => '',
            'source_url' => 'https://promptkit.natebjones.com/20260224_uq1_promptkit_1',
        ]);
    }
}
