<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use Illuminate\View\View;

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

        return view('help', [
            'query' => '',
            'cursorRuleContent' => $cursorRuleContent,
            'researchRuleContent' => $researchRuleContent,
        ]);
    }
}
