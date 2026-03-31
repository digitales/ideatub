<?php

namespace App\Services;

use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\ResearchRun;
use App\Models\Thought;
use Illuminate\Http\Client\RequestException;

/**
 * Runs research for ideas and creates linked research thoughts.
 * Research thoughts are created directly (no embedding) and linked via metadata.idea_id.
 */
class ResearchService
{
    public function __construct(
        private OpenRouterService $openRouter,
        private ThoughtCaptureService $captureService
    ) {}

    /**
     * Run research for an existing idea and create a linked research thought.
     *
     * @param  string  $source  'web' or 'mcp'
     *
     * @throws RequestException
     * @throws \RuntimeException
     */
    public function runResearchForIdea(Thought $idea, string $source = 'web'): Thought
    {
        // Rate-limit can be applied here when config('research.rate_limit_enabled') is true (e.g. throttle by user_id).
        $researchText = $this->openRouter->researchNote($idea->content);

        return $this->persistResearchForIdea($idea, $researchText, $source);
    }

    /**
     * Persist a completed research body for an idea (OpenRouter already ran elsewhere).
     */
    public function saveRunResult(ResearchRun $run, string $researchText): Thought
    {
        $run->loadMissing('ideaThought');

        return $this->persistResearchForIdea($run->ideaThought, $researchText, $run->source ?? 'web');
    }

    public function persistResearchForIdea(Thought $idea, string $researchText, string $source = 'web'): Thought
    {
        $metadata = Thought::normalizeMetadataTags([
            'type' => 'research',
            'idea_id' => $idea->id,
            'tags' => ['research'],
        ]);

        $emailLinkage = $this->buildEmailLinkagePayload($idea);
        if ($emailLinkage !== null) {
            $metadata = array_merge($metadata, $emailLinkage);
        }

        $research = Thought::create([
            'content' => $researchText,
            'embedding' => null,
            'metadata' => $metadata,
            'user_id' => $idea->user_id,
            'source' => $this->shouldStoreAsResearchSource($idea, $source) ? 'research' : $source,
            'source_metadata' => $emailLinkage,
        ]);

        if ($emailLinkage !== null) {
            $this->persistEmailResearchBackLinks($idea, $research);
        }

        return $research;
    }

    /**
     * Create an idea thought only (no research). Use when research will run in the background via event.
     *
     * @param  string  $source  'web' or 'mcp'
     */
    public function createIdeaOnly(string $ideaContent, string $source = 'web'): Thought
    {
        $ideaMetadata = [
            'type' => 'idea',
            'completed' => false,
            'logged_date' => now()->toDateString(),
        ];

        $result = $this->captureService->create([
            'content' => trim($ideaContent),
            'user_id' => (int) auth()->id(),
            'source' => $source,
            'idea_metadata' => $ideaMetadata,
        ]);

        $idea = $result['thought'] ?? $result['root'];
        if (! $idea instanceof Thought) {
            throw new \RuntimeException('ThoughtCaptureService did not return an idea thought.');
        }

        return $idea;
    }

    /**
     * Create an idea thought then run research and link the research thought.
     * If research fails, the idea is still created; research will be null.
     *
     * @param  string  $source  'web' or 'mcp'
     * @return array{idea: Thought, research: Thought|null}
     */
    public function createIdeaAndResearch(string $ideaContent, string $source = 'web'): array
    {
        $idea = $this->createIdeaOnly($ideaContent, $source);

        try {
            $research = $this->runResearchForIdea($idea, $source);
        } catch (\Throwable) {
            return ['idea' => $idea, 'research' => null];
        }

        return ['idea' => $idea, 'research' => $research];
    }

    /**
     * @return array{email_thought_id: string, email_subject: string, email_sender: string}|null
     */
    private function buildEmailLinkagePayload(Thought $idea): ?array
    {
        if (! $this->isCanonicalEmailSource($idea->source)) {
            return null;
        }

        $subject = trim((string) ($idea->source_metadata['subject'] ?? $idea->source_metadata['email_subject'] ?? ''));
        $sender = trim((string) ($idea->source_metadata['from'] ?? $idea->source_metadata['email_sender'] ?? ''));

        $imported = $idea->importedEmail();
        if ($imported !== null) {
            $subject = trim((string) ($imported->subject ?? $subject));
            $sender = $this->formatImportedEmailSender($imported) ?: $sender;
        }

        $captured = $this->resolveCapturedInboundEmail($idea);
        if ($captured !== null) {
            $subject = trim((string) ($captured->subject ?? $subject));
            $sender = trim((string) ($captured->sender_email ?? $sender));
        }

        if ($subject === '' || $sender === '') {
            return null;
        }

        return [
            'email_thought_id' => (string) $idea->id,
            'email_subject' => $subject,
            'email_sender' => $sender,
        ];
    }

    private function shouldStoreAsResearchSource(Thought $idea, string $source): bool
    {
        return $this->isCanonicalEmailSource($idea->source) || $source === 'research';
    }

    private function isCanonicalEmailSource(?string $source): bool
    {
        return in_array(mb_strtolower(trim((string) $source)), ['email', 'emails'], true);
    }

    private function persistEmailResearchBackLinks(Thought $emailThought, Thought $research): void
    {
        $emailMeta = is_array($emailThought->source_metadata) ? $emailThought->source_metadata : [];
        $emailMeta['research_thought_id'] = $research->id;
        $emailThought->update(['source_metadata' => $emailMeta]);

        $imported = $emailThought->importedEmail();
        if ($imported !== null) {
            $imported->update(['research_thought_id' => $research->id]);
        }

        $captured = $this->resolveCapturedInboundEmail($emailThought);
        if ($captured !== null) {
            $captured->update(['research_thought_id' => $research->id]);
        }
    }

    private function resolveCapturedInboundEmail(Thought $thought): ?CapturedInboundEmail
    {
        $capturedId = data_get($thought->source_metadata, 'captured_inbound_email_id');
        if ($capturedId !== null && (string) $capturedId !== '') {
            $row = CapturedInboundEmail::query()
                ->where('user_id', $thought->user_id)
                ->find($capturedId);
            if ($row !== null) {
                return $row;
            }
        }

        return CapturedInboundEmail::query()
            ->where('user_id', $thought->user_id)
            ->where('thought_id', $thought->id)
            ->first();
    }

    private function formatImportedEmailSender(ImportedEmail $row): string
    {
        $from = is_array($row->from_json) ? ($row->from_json[0] ?? null) : null;
        if (! is_array($from)) {
            return '';
        }

        $email = trim((string) ($from['email'] ?? ''));
        $name = trim((string) ($from['name'] ?? ''));
        if ($email === '') {
            return '';
        }

        return $name !== '' ? $name.' <'.$email.'>' : $email;
    }
}
