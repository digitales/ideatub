<?php

namespace App\Http\Controllers;

use App\Jobs\SyncUserJiraActivity;
use App\Models\UserJiraCredential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class JiraSettingsController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $credential = $user->jiraCredential;

        return view('settings.jira', [
            'connected' => $credential !== null,
            'jiraSiteUrl' => $credential?->jira_site_url ?? '',
            'jiraEmail' => $credential?->jira_email ?? $user->email ?? '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $isUpdate = $user->jiraCredential !== null;
        $validated = $request->validate([
            'jira_site_url' => 'required|url|max:500',
            'jira_api_token' => $isUpdate ? 'nullable|string|max:1000' : 'required|string|max:1000',
            'jira_email' => 'nullable|email|max:255',
        ]);

        $url = rtrim($validated['jira_site_url'], '/');
        $email = $validated['jira_email'] ? trim($validated['jira_email']) : null;

        $credential = $user->jiraCredential;
        if ($credential !== null) {
            $data = ['jira_site_url' => $url, 'jira_email' => $email];
            if (! empty($validated['jira_api_token'] ?? '')) {
                $data['jira_api_token'] = $validated['jira_api_token'];
            }
            $credential->update($data);
        } else {
            UserJiraCredential::create([
                'user_id' => $user->id,
                'jira_site_url' => $url,
                'jira_api_token' => $validated['jira_api_token'],
                'jira_email' => $email,
            ]);
        }

        return redirect()
            ->route('settings.jira.index')
            ->with('success', 'Jira credentials saved.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->jiraCredential?->delete();

        return redirect()
            ->route('settings.jira.index')
            ->with('success', 'Jira disconnected.');
    }

    public function sync(Request $request): RedirectResponse
    {
        $user = $request->user();
        $days = (int) config('services.jira.default_days', 14);
        SyncUserJiraActivity::dispatch($user->id, $days);

        return redirect()
            ->route('settings.jira.index')
            ->with('success', 'Jira sync started. Your activity will appear in Stream and search when the job finishes.');
    }
}
