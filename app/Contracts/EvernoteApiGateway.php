<?php

namespace App\Contracts;

/**
 * Gateway for Evernote NoteStore API (create/update note).
 * Evernote API is Thrift-based; bind a real implementation when the SDK or a Thrift client is available.
 */
interface EvernoteApiGateway
{
    /**
     * Create a note in Evernote.
     *
     * @return string|null Note GUID if created, null if skipped or not implemented
     */
    public function createNote(string $title, string $enmlContent, ?string $notebookGuid): ?string;

    /**
     * Update an existing note by GUID.
     */
    public function updateNote(string $noteGuid, string $title, string $enmlContent): void;
}
