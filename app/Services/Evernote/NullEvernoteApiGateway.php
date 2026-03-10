<?php

namespace App\Services\Evernote;

use App\Contracts\EvernoteApiGateway;
use Illuminate\Support\Facades\Log;

/**
 * No-op Evernote gateway when no API client is available.
 * Evernote API is Thrift-based; the official PHP SDK has dependency conflicts with Laravel 12.
 * Bind a real gateway when using evernote/evernote-cloud-sdk-php or a custom Thrift client.
 */
class NullEvernoteApiGateway implements EvernoteApiGateway
{
    public function createNote(string $title, string $enmlContent, ?string $notebookGuid): ?string
    {
        Log::debug('Evernote: createNote skipped (no API gateway configured)');

        return null;
    }

    public function updateNote(string $noteGuid, string $title, string $enmlContent): void
    {
        Log::debug('Evernote: updateNote skipped (no API gateway configured)', ['note_guid' => $noteGuid]);
    }
}
