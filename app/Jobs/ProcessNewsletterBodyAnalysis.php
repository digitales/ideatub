<?php

namespace App\Jobs;

use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\NewsletterAnalysis;
use App\Services\NewsletterAnalysis\NewsletterAnalysisGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ProcessNewsletterBodyAnalysis implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 120;

    public function __construct(
        public readonly string $researchThoughtId,
        public readonly string $sourceThoughtId,
        public readonly ?int $importedEmailId = null,
        public readonly ?int $capturedInboundEmailId = null,
    ) {
        if (($importedEmailId === null) === ($capturedInboundEmailId === null)) {
            throw new InvalidArgumentException(
                'Exactly one of importedEmailId or capturedInboundEmailId must be set.'
            );
        }
    }

    public function handle(NewsletterAnalysisGenerator $generator): void
    {
        $lock = Cache::lock(
            'process-newsletter-body-analysis:'.$this->researchThoughtId,
            $this->timeout + 30
        );

        if (! $lock->get()) {
            $this->release($this->backoff);

            return;
        }

        try {
            $this->handleLocked($generator);
        } finally {
            $lock->release();
        }
    }

    private function handleLocked(NewsletterAnalysisGenerator $generator): void
    {
        $analysis = NewsletterAnalysis::query()->firstOrNew([
            'research_thought_id' => $this->researchThoughtId,
        ]);

        if ($analysis->exists && $analysis->status === 'completed') {
            return;
        }

        $stored = $this->resolveStoredEmail();
        if ($stored === null) {
            Log::warning('ProcessNewsletterBodyAnalysis: stored email not found.', [
                'imported_email_id' => $this->importedEmailId,
                'captured_inbound_email_id' => $this->capturedInboundEmailId,
            ]);

            return;
        }

        $body = trim((string) ($stored->body_text ?? ''));
        $subject = trim((string) ($stored->subject ?? ''));
        [$storedType, $storedId] = $this->storedEmailIdentity($stored);

        if (mb_strlen($body) < 50) {
            $analysis->fill([
                'source_thought_id' => $this->sourceThoughtId,
                'stored_email_type' => $storedType,
                'stored_email_id' => $storedId,
                'status' => 'failed',
                'failure_reason' => 'body_too_short',
            ]);
            $analysis->save();

            return;
        }

        $analysis->fill([
            'source_thought_id' => $this->sourceThoughtId,
            'stored_email_type' => $storedType,
            'stored_email_id' => $storedId,
            'status' => 'processing',
        ]);
        $analysis->save();

        try {
            $result = $generator->generate($subject, $body);
        } catch (\Throwable $e) {
            if ($this->attempts() < $this->tries) {
                throw $e;
            }

            $analysis->update([
                'status' => 'failed',
                'failure_reason' => Str::limit($e->getMessage(), 255),
            ]);

            return;
        }

        $analysis->update([
            'status' => 'completed',
            'summary' => $result['summary'],
            'key_points' => $result['key_points'],
            'positives_mentioned' => $result['positives_mentioned'],
            'negatives_mentioned' => $result['negatives_mentioned'],
            'highlights' => $result['highlights'],
            'quality_notes' => $result['quality_notes'],
            'failure_reason' => null,
            'completed_at' => now(),
        ]);
    }

    private function resolveStoredEmail(): ImportedEmail|CapturedInboundEmail|null
    {
        if ($this->importedEmailId !== null) {
            return ImportedEmail::query()->find($this->importedEmailId);
        }

        return CapturedInboundEmail::query()->find($this->capturedInboundEmailId);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function storedEmailIdentity(ImportedEmail|CapturedInboundEmail $stored): array
    {
        if ($stored instanceof CapturedInboundEmail) {
            return ['captured_inbound_email', (int) $stored->id];
        }

        return ['imported_email', (int) $stored->id];
    }
}
