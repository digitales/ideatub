<?php

return [

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    |
    | Most templating systems load templates from disk. Here you may specify
    | an array of paths that should be checked for your views. Of course
    | the usual Laravel view path has already been registered for you.
    |
    */

    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    |
    | This option determines where all the compiled Blade templates will be
    | stored for your application. We avoid realpath() here so that the
    | path is always a string; on read-only or minimal deployments
    | (e.g. Laravel Cloud) the directory may not exist yet, and
    | realpath() would return false and break the view compiler.
    |
    | Set VIEW_COMPILED_PATH in .env (e.g. to /tmp/laravel-views) if your
    | app runs on a read-only filesystem.
    |
    */

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        storage_path('framework/views')
    ),

];
