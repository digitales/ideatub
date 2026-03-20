<?php

namespace App\Services\Fastmail;

use Illuminate\Support\Facades\Http;

class FastmailHttpClient
{
    /**
     * @param  array{credential: string}  $credentials
     * @return array<string, mixed>
     */
    public function discoverSession(array $credentials): array
    {
        return Http::withToken($credentials['credential'])
            ->acceptJson()
            ->get('https://api.fastmail.com/jmap/session')
            ->throw()
            ->json();
    }

    /**
     * @param  array{credential: string, api_url?: string}  $credentials
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function request(array $credentials, array $payload): array
    {
        $apiUrl = $credentials['api_url'] ?? 'https://api.fastmail.com/jmap/api/';

        return Http::withToken($credentials['credential'])
            ->acceptJson()
            ->post($apiUrl, $payload)
            ->throw()
            ->json();
    }
}
