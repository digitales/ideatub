<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidJiraCredentialsException;
use App\Jobs\SyncUserJiraActivity;
use App\Models\UserJiraCredential;
use App\Models\UserPreference;
use App\Services\JiraSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class JiraSettingsController extends Controller
{
    public function __construct(
        private JiraSyncService $jiraSync
    ) {}
    private const SYNC_STATUS_KEY = 'jira_sync_status';

    public function index(Request $request): View
    {
        $user = $request->user();
        $credential = $user->jiraCredential;
        $syncStatus = UserPreference::get($user, self::SYNC_STATUS_KEY);

        return view('settings.jira', [
            'connected' => $credential !== null,
            'jiraSiteUrl' => $credential?->jira_site_url ?? '',
            'jiraEmail' => $credential?->jira_email ?? $user->email ?? '',
            'syncStatus' => is_array($syncStatus) ? $syncStatus : null,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $syncStatus = UserPreference::get($request->user(), self::SYNC_STATUS_KEY);

        return response()->json(is_array($syncStatus) ? $syncStatus : ['status' => null]);
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
        $email = $validated['jira_email'] ? trim($validated['jira_email']) : $user->email;
        $token = trim($validated['jira_api_token'] ?? '');
        if ($token === '' && $user->jiraCredential !== null) {
            $token = $user->jiraCredential->jira_api_token; // use existing when updating without new token
        }
        if ($token !== '') {
            try {
                $this->jiraSync->validateCredentials($url, $email ?? '', $token);
            } catch (InvalidJiraCredentialsException $e) {
                return redirect()
                    ->route('settings.jira.index')
                    ->withInput($request->only('jira_site_url', 'jira_email'))
                    ->with('error', $e->getMessage());
            }
        }

        $credential = $user->jiraCredential;
        if ($credential !== null) {
            $data = ['jira_site_url' => $url, 'jira_email' => $validated['jira_email'] ? trim($validated['jira_email']) : null];
            if (! empty($validated['jira_api_token'] ?? '')) {
                $data['jira_api_token'] = $validated['jira_api_token'];
            }
            $credential->update($data);
        } else {
            UserJiraCredential::create([
                'user_id' => $user->id,
                'jira_site_url' => $url,
                'jira_api_token' => $validated['jira_api_token'],
                'jira_email' => $validated['jira_email'] ? trim($validated['jira_email']) : null,
            ]);
        }

        return redirect()
            ->route('settings.jira.index')
            ->with('success', 'Jira credentials saved and verified.');
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
        UserPreference::set($user, self::SYNC_STATUS_KEY, [
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
            'completed_at' => null,
            'message' => null,
        ]);
        SyncUserJiraActivity::dispatch($user->id, $days);

        return redirect()
            ->route('settings.jira.index')
            ->with('success', 'Jira sync started. You can watch progress below.');
    }

    public static function getSyncStatusKey(): string
    {
        return self::SYNC_STATUS_KEY;
    }
}
