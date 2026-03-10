<?php

namespace App\Services;

use App\Contracts\EvernoteApiGateway;
use App\Models\Thought;
use Illuminate\Support\Str;

class EvernoteService
{
    private const ENML_PREFIX = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
        .'<!DOCTYPE en-note SYSTEM "https://xml.evernote.com/pub/enml2.dtd">'."\n";

    public function __construct(
        private readonly EvernoteApiGateway $apiGateway
    ) {}

    /**
     * Whether Evernote is configured (non-empty access token).
     */
    public function isConfigured(): bool
    {
        $token = config('services.evernote.access_token');

        return $token !== null && $token !== '';
    }

    /**
     * Resolve target notebook GUID from thought metadata (type, tags) and config mapping.
     * Falls back to 'default' if no mapping matches.
     *
     * @return string|null Notebook GUID, or null if not configured or mapping empty
     */
    public function resolveNotebookGuid(Thought $thought): ?string
    {
        $mapping = config('services.evernote.notebook_mapping', []);
        if (! is_array($mapping)) {
            return $this->defaultNotebookGuid();
        }

        $metadata = $thought->metadata ?? [];
        $type = isset($metadata['type']) ? Str::lower(trim((string) $metadata['type'])) : null;
        $tags = isset($metadata['tags']) && is_array($metadata['tags'])
            ? array_map(fn ($t) => Str::lower(trim((string) $t)), $metadata['tags'])
            : [];

        // Prefer explicit type mapping (e.g. idea, task)
        if ($type !== null && $type !== '' && isset($mapping[$type])) {
            $guid = $mapping[$type];
            if ($guid !== null && $guid !== '') {
                return $guid;
            }
        }

        // Then first tag that has a mapping
        foreach ($tags as $tag) {
            if ($tag !== '' && isset($mapping[$tag])) {
                $guid = $mapping[$tag];
                if ($guid !== null && $guid !== '') {
                    return $guid;
                }
            }
        }

        return $this->defaultNotebookGuid();
    }

    /**
     * Create a new Evernote note for the thought when evernote_note_guid is null.
     * Skips if token empty or thought already has evernote_note_guid.
     *
     * @return string|null Note GUID if created, null if skipped or gateway returned null
     */
    public function createNote(Thought $thought): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        if ($thought->evernote_note_guid !== null && $thought->evernote_note_guid !== '') {
            return null;
        }

        $title = $this->noteTitle($thought);
        $enml = $this->buildEnmlContent($thought->content);
        $notebookGuid = $this->resolveNotebookGuid($thought);

        return $this->apiGateway->createNote($title, $enml, $notebookGuid);
    }

    /**
     * Update the existing Evernote note for the thought by evernote_note_guid.
     * Skips if token empty or evernote_note_guid is null.
     */
    public function updateNote(Thought $thought): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $guid = $thought->evernote_note_guid;
        if ($guid === null || $guid === '') {
            return;
        }

        $title = $this->noteTitle($thought);
        $enml = $this->buildEnmlContent($thought->content);

        $this->apiGateway->updateNote($guid, $title, $enml);
    }

    /**
     * Build ENML (Evernote Markup Language) body for note content.
     */
    public function buildEnmlContent(string $plainText): string
    {
        $body = htmlspecialchars($plainText, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $body = nl2br($body);

        return self::ENML_PREFIX.'<en-note>'.$body.'</en-note>';
    }

    private function defaultNotebookGuid(): ?string
    {
        $mapping = config('services.evernote.notebook_mapping', []);
        $default = is_array($mapping) && isset($mapping['default'])
            ? $mapping['default']
            : null;

        return $default !== null && $default !== '' ? $default : null;
    }

    private function noteTitle(Thought $thought): string
    {
        $content = $thought->content;
        $firstLine = Str::before(Str::replace(["\r\n", "\r", "\n"], "\n", $content), "\n");
        $trimmed = trim($firstLine);

        if ($trimmed === '') {
            return 'IdeaTub thought';
        }

        return Str::limit($trimmed, 255);
    }
}
