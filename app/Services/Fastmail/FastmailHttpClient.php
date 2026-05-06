<?php

namespace App\Services\Fastmail;

use Illuminate\Support\Facades\Http;

class FastmailHttpClient
{
    private function jmapTimeoutSeconds(): int
    {
        return max(30, (int) config('services.mail_sync.jmap_timeout_seconds', 600));
    }

    private function jmapConnectTimeoutSeconds(): int
    {
        return max(5, (int) config('services.mail_sync.jmap_connect_timeout_seconds', 30));
    }

    /**
     * @param  array{credential: string}  $credentials
     * @return array<string, mixed>
     */
    public function discoverSession(array $credentials): array
    {
        return Http::withToken($credentials['credential'])
            ->acceptJson()
            ->connectTimeout($this->jmapConnectTimeoutSeconds())
            ->timeout($this->jmapTimeoutSeconds())
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
            ->connectTimeout($this->jmapConnectTimeoutSeconds())
            ->timeout($this->jmapTimeoutSeconds())
            ->post($apiUrl, $payload)
            ->throw()
            ->json();
    }
}
