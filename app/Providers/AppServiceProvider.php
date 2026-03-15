<?php

namespace App\Providers;

use App\Contracts\EvernoteApiGateway;
use App\Events\IdeaResearchRequested;
use App\Listeners\RunResearchForIdeaListener;
use App\Services\Evernote\EvernoteSdkApiGateway;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('files', fn () => new Filesystem);

        $this->app->bind(EvernoteApiGateway::class, EvernoteSdkApiGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(IdeaResearchRequested::class, RunResearchForIdeaListener::class);
    }
}
