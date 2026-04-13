<?php

namespace App\Providers;

use App\Contracts\EvernoteApiGateway;
use App\Models\InboxItem;
use App\Services\DemoMode;
use App\Services\Evernote\EvernoteSdkApiGateway;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use MrMySQL\YoutubeTranscript\TranscriptListFetcher;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('files', fn () => new Filesystem);

        $this->app->bind(EvernoteApiGateway::class, EvernoteSdkApiGateway::class);

        $this->app->singleton(TranscriptListFetcher::class, function (): TranscriptListFetcher {
            $httpFactory = new HttpFactory;

            return new TranscriptListFetcher(
                new Client([
                    'timeout' => 30,
                    'connect_timeout' => 10,
                ]),
                $httpFactory,
                $httpFactory,
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(5)->by($request->ip()),
                Limit::perMinute(10)->by($request->input('email')),
            ];
        });

        RateLimiter::for('shared-research-password', function (Request $request) {
            $token = $request->route('token') ?? 'unknown';

            return Limit::perMinutes(15, 10)->by($token.':'.$request->ip());
        });

        RateLimiter::for('project-share-password', function (Request $request) {
            $token = $request->route('token') ?? 'unknown';

            return Limit::perMinutes(15, 10)->by($token.':'.$request->ip());
        });

        Broadcast::routes(['middleware' => ['web', 'auth']]);

        $channelsPath = base_path('routes/channels.php');
        if (file_exists($channelsPath)) {
            require $channelsPath;
        }

        View::composer('layouts.idea', function ($view): void {
            $count = 0;

            if (auth()->check()) {
                $count = InboxItem::query()
                    ->forUser(auth()->user())
                    ->actionable()
                    ->count();
            }

            $view->with('inboxActionableCount', $count);
            $view->with('demoModeEnabled', app(DemoMode::class)->enabled());
        });
    }
}
