<?php

namespace App\Http\Controllers;

use App\Support\SafeCommonMarkConverter;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class HelpController extends Controller
{
    /** @return array<string, string> slug => display label */
    private static function researchToDecisionSkillCatalog(): array
    {
        return [
            'competitive-analysis' => 'Competitive analysis',
            'financial-model-review' => 'Financial model review',
            'research-synthesis' => 'Research synthesis',
            'meeting-synthesis' => 'Meeting synthesis',
            'deal-memo-drafting' => 'Deal memo drafting',
        ];
    }

    private static function researchToDecisionSkillsBasePath(): string
    {
        return resource_path('skills/research-to-decision');
    }

    public function researchToDecisionSkillsIndex(): View
    {
        $skills = [];
        foreach (self::researchToDecisionSkillCatalog() as $slug => $label) {
            $path = self::researchToDecisionSkillsBasePath()."/{$slug}/SKILL.md";
            $skills[] = [
                'slug' => $slug,
                'label' => $label,
                'missing' => ! File::isFile($path),
            ];
        }

        $readmePath = self::researchToDecisionSkillsBasePath().'/README.md';
        $bundleReadmeExists = File::isFile($readmePath);
        $thirdPartyExists = File::isFile(base_path('THIRD_PARTY_OB1.md'));

        return view('help-research-to-decision-skills', [
            'skills' => $skills,
            'bundleReadmeExists' => $bundleReadmeExists,
            'thirdPartyExists' => $thirdPartyExists,
        ]);
    }

    public function researchToDecisionSkillShow(string $skill): View
    {
        $catalog = self::researchToDecisionSkillCatalog();
        if (! isset($catalog[$skill])) {
            abort(404);
        }
        $path = self::researchToDecisionSkillsBasePath()."/{$skill}/SKILL.md";
        if (! File::isFile($path)) {
            abort(404);
        }
        $raw = File::get($path);
        $forDisplay = $this->stripSkillPreambleForDisplay($raw);
        $converter = SafeCommonMarkConverter::make();
        $bodyHtml = $converter->convert($forDisplay)->getContent();

        return view('help-research-to-decision-skill', [
            'skillSlug' => $skill,
            'skillLabel' => $catalog[$skill],
            'bodyHtml' => $bodyHtml,
        ]);
    }

    /**
     * Strip HTML comment and YAML front matter so CommonMark display is readable.
     */
    private function stripSkillPreambleForDisplay(string $raw): string
    {
        $s = $raw;
        if (preg_match('/\A<!--.*?-->\s*/s', $s, $m)) {
            $s = substr($s, strlen($m[0]));
        }
        if (preg_match('/\A---\s*\r?\n.*?\r?\n---\s*\r?\n/s', $s, $m)) {
            $s = substr($s, strlen($m[0]));
        }

        return $s;
    }

    public function researchToDecisionSkillsDownloadZip(): BinaryFileResponse
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'r2d-skills-');
        if ($zipPath === false) {
            abort(500, 'Could not create temporary file.');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            abort(500, 'Could not create archive.');
        }

        $root = 'ideatub-research-to-decision-skills';

        foreach (self::researchToDecisionSkillCatalog() as $slug => $_label) {
            $full = self::researchToDecisionSkillsBasePath()."/{$slug}/SKILL.md";
            if (File::isFile($full)) {
                $zip->addFile($full, "{$root}/{$slug}/SKILL.md");
            }
        }

        $readme = self::researchToDecisionSkillsBasePath().'/README.md';
        if (File::isFile($readme)) {
            $zip->addFile($readme, "{$root}/README.md");
        }

        $notice = base_path('THIRD_PARTY_OB1.md');
        if (File::isFile($notice)) {
            $zip->addFile($notice, "{$root}/THIRD_PARTY_OB1.md");
        }

        $zip->close();

        return response()->download($zipPath, 'ideatub-research-to-decision-skills.zip', [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    public function researchToDecisionSkillDownload(string $skill): BinaryFileResponse
    {
        $catalog = self::researchToDecisionSkillCatalog();
        if (! isset($catalog[$skill])) {
            abort(404);
        }
        $path = self::researchToDecisionSkillsBasePath()."/{$skill}/SKILL.md";
        if (! File::isFile($path)) {
            abort(404);
        }

        $name = "{$skill}-SKILL.md";

        return response()->download($path, $name, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }

    public function thirdPartyOb1(): View
    {
        $path = base_path('THIRD_PARTY_OB1.md');
        if (! File::isFile($path)) {
            abort(404);
        }
        $markdown = File::get($path);
        $converter = SafeCommonMarkConverter::make();
        $bodyHtml = $converter->convert($markdown)->getContent();

        return view('help-third-party-ob1', [
            'bodyHtml' => $bodyHtml,
        ]);
    }

    public function researchToDecision(): View
    {
        $path = resource_path('content/help/research-to-decision.md');
        $markdown = File::exists($path) ? File::get($path) : '';
        $converter = SafeCommonMarkConverter::make();
        $bodyHtml = $converter->convert($markdown)->getContent();

        return view('help-research-to-decision', [
            'bodyHtml' => $bodyHtml,
        ]);
    }

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
        $converter = SafeCommonMarkConverter::make();
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
