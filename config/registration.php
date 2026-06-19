<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Registration enabled
    |--------------------------------------------------------------------------
    |
    | When false, new account creation is blocked (email/password and OAuth).
    | Existing users can still sign in.
    |
    */
    'enabled' => filter_var(env('REGISTRATION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Beta access code
    |--------------------------------------------------------------------------
    |
    | When set, new signups must provide this code (email/password or OAuth).
    | Leave empty to allow open registration while REGISTRATION_ENABLED is true.
    |
    */
    'beta_access_code' => env('BETA_ACCESS_CODE'),
];
