<?php

namespace App\Providers;

use App\Contracts\EvernoteApiGateway;
use App\Services\Evernote\EvernoteSdkApiGateway;
use Illuminate\Filesystem\Filesystem;
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
        //
    }
}
