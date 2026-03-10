<?php

namespace App\Services\Evernote;

use App\Contracts\EvernoteApiGateway;
use Illuminate\Support\Facades\Log;

/**
 * Evernote API gateway using the official Evernote Cloud SDK for PHP when available.
 *
 * When evernote/evernote-cloud-sdk-php is not installed (e.g. due to Laravel 12
 * dependency conflicts with psr/log), createNote returns null and updateNote is a no-op.
 * Install the SDK where possible: composer require evernote/evernote-cloud-sdk-php -W
 */
class EvernoteSdkApiGateway implements EvernoteApiGateway
{
    public function createNote(string $title, string $enmlContent, ?string $notebookGuid): ?string
    {
        $token = config('services.evernote.access_token');
        if ($token === null || $token === '') {
            return null;
        }

        $clientClass = 'Evernote\Client';
        if (! class_exists($clientClass)) {
            Log::debug('Evernote: createNote skipped (SDK not installed)');

            return null;
        }

        $noteClass = 'EDAM\Types\Note';
        if (! class_exists($noteClass)) {
            Log::debug('Evernote: createNote skipped (SDK types not available)');

            return null;
        }

        try {
            $client = new $clientClass(['token' => $token]);
            $noteStore = $client->getUserNotestore();

            $edamNote = new $noteClass();
            $edamNote->title = $title;
            $edamNote->content = $enmlContent;
            if ($notebookGuid !== null && $notebookGuid !== '') {
                $edamNote->notebookGuid = $notebookGuid;
            }

            $created = $noteStore->createNote($token, $edamNote);

            return $created->guid ?? null;
        } catch (\Throwable $e) {
            Log::warning('Evernote createNote failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function updateNote(string $noteGuid, string $title, string $enmlContent): void
    {
        $token = config('services.evernote.access_token');
        if ($token === null || $token === '') {
            return;
        }

        $clientClass = 'Evernote\Client';
        if (! class_exists($clientClass)) {
            Log::debug('Evernote: updateNote skipped (SDK not installed)', ['note_guid' => $noteGuid]);

            return;
        }

        try {
            $client = new $clientClass(['token' => $token]);
            $noteStore = $client->getUserNotestore();

            $edamNote = $noteStore->getNote($token, $noteGuid, true, true, false, false);
            $edamNote->title = $title;
            $edamNote->content = $enmlContent;

            $noteStore->updateNote($token, $edamNote);
        } catch (\Throwable $e) {
            Log::warning('Evernote updateNote failed', ['note_guid' => $noteGuid, 'error' => $e->getMessage()]);
        }
    }
}
