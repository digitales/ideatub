<?php

namespace App\Providers;

use App\Contracts\EvernoteApiGateway;
use App\Events\IdeaResearchRequested;
use App\Listeners\RunResearchForIdeaListener;
use App\Models\InboxItem;
use App\Services\Evernote\EvernoteSdkApiGateway;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
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
        Broadcast::routes(['middleware' => ['web', 'auth']]);

        $channelsPath = base_path('routes/channels.php');
        if (file_exists($channelsPath)) {
            require $channelsPath;
        }

        Event::listen(IdeaResearchRequested::class, RunResearchForIdeaListener::class);

        View::composer('layouts.idea', function ($view): void {
            $count = 0;

            if (auth()->check()) {
                $count = InboxItem::query()
                    ->forUser(auth()->user())
                    ->actionable()
                    ->count();
            }

            $view->with('inboxActionableCount', $count);
        });
    }
}
