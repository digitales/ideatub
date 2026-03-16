<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use League\CommonMark\CommonMarkConverter;

class HelpController extends Controller
{
    public function index(): View
    {
        $cursorRulePath = base_path('.cursor/rules/ideatub-sync-docs.mdc');
        $cursorRuleContent = File::exists($cursorRulePath)
            ? File::get($cursorRulePath)
            : null;

        $researchRulePath = base_path('.cursor/rules/ideatub-sync-research.mdc');
        $researchRuleContent = File::exists($researchRulePath)
            ? File::get($researchRulePath)
            : null;

        $prompts = $this->loadExamplePrompts();

        return view('help', [
            'query' => '',
            'cursorRuleContent' => $cursorRuleContent,
            'researchRuleContent' => $researchRuleContent,
            'prompts' => $prompts['prompts'],
            'examplePromptsSourceUrl' => $prompts['source_url'],
        ]);
    }

    /**
     * Load Open Brain Companion Prompts from resources/content/example-prompts/.
     * Source: https://promptkit.natebjones.com/20260224_uq1_promptkit_1
     */
    private function loadExamplePrompts(): array
    {
        $converter = new CommonMarkConverter;
        $promptsDir = resource_path('content/example-prompts');
        $slugs = ['01-memory-migration', '02-second-brain-migration', '03-open-brain-spark', '04-quick-capture-templates', '05-weekly-review'];
        $prompts = [];

        foreach ($slugs as $i => $slug) {
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

        return [
            'prompts' => $prompts,
            'source_url' => 'https://promptkit.natebjones.com/20260224_uq1_promptkit_1',
        ];
    }
}
