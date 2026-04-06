<?php

namespace Tests\Feature;

use App\Jobs\ProcessNewsletterBodyAnalysis;
use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\NewsletterAnalysis;
use App\Models\Thought;
use App\Models\User;
use App\Services\NewsletterAnalysis\NewsletterAnalysisGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessNewsletterBodyAnalysisJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function fakeGeneratorResult(): array
    {
        return [
            'summary' => 'A fintech newsletter covering payments and regulation.',
            'key_points' => ['FCA proposes new rules', 'Stripe expands to Africa'],
            'positives_mentioned' => ['Bullish on open banking'],
            'negatives_mentioned' => ['Critical of incumbents'],
            'highlights' => ['100k subscriber milestone'],
            'quality_notes' => null,
        ];
    }

    #[Test]
    public function constructor_rejects_both_email_identifier_types(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one of importedEmailId or capturedInboundEmailId must be set.');

        new ProcessNewsletterBodyAnalysis(
            researchThoughtId: 'res-uuid',
            sourceThoughtId: 'src-uuid',
            importedEmailId: 1,
            capturedInboundEmailId: 2,
        );
    }

    #[Test]
    public function constructor_rejects_neither_email_identifier_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one of importedEmailId or capturedInboundEmailId must be set.');

        new ProcessNewsletterBodyAnalysis(
            researchThoughtId: 'res-uuid',
            sourceThoughtId: 'src-uuid',
        );
    }

    #[Test]
    public function job_creates_completed_analysis_for_valid_email(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'analysis-test-1',
            'direction' => 'received',
            'subject' => 'Fintech Weekly',
            'body_text' => str_repeat('Fintech newsletter body paragraph. ', 30),
            'from_json' => [['email' => 'news@fintech.com', 'name' => 'Fintech']],
            'processing_status' => 'research_completed',
        ]);

        $emailThought = Thought::factory()->create(['user_id' => $user->id, 'source' => 'email']);
        $researchThought = Thought::factory()->create(['user_id' => $user->id, 'source' => 'research']);

        $generator = Mockery::mock(NewsletterAnalysisGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->with('Fintech Weekly', Mockery::type('string'))
            ->andReturn($this->fakeGeneratorResult());
        $this->app->instance(NewsletterAnalysisGenerator::class, $generator);

        $job = new ProcessNewsletterBodyAnalysis(
            researchThoughtId: (string) $researchThought->id,
            sourceThoughtId: (string) $emailThought->id,
            importedEmailId: $imported->id,
        );
        $job->handle(app(NewsletterAnalysisGenerator::class));

        $analysis = NewsletterAnalysis::query()
            ->where('research_thought_id', $researchThought->id)
            ->first();

        $this->assertNotNull($analysis);
        $this->assertSame('completed', $analysis->status);
        $this->assertSame('A fintech newsletter covering payments and regulation.', $analysis->summary);
        $this->assertSame(['FCA proposes new rules', 'Stripe expands to Africa'], $analysis->key_points);
        $this->assertSame(['Bullish on open banking'], $analysis->positives_mentioned);
        $this->assertSame(['Critical of incumbents'], $analysis->negatives_mentioned);
        $this->assertSame(['100k subscriber milestone'], $analysis->highlights);
        $this->assertNull($analysis->quality_notes);
        $this->assertNotNull($analysis->completed_at);
        $this->assertSame('imported_email', $analysis->stored_email_type);
        $this->assertSame($imported->id, (int) $analysis->stored_email_id);
    }

    #[Test]
    public function job_marks_analysis_failed_when_body_is_too_short(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'analysis-short-body',
            'direction' => 'received',
            'subject' => 'Short',
            'body_text' => 'Hi.',
            'from_json' => [['email' => 'x@x.com']],
            'processing_status' => 'research_completed',
        ]);

        $emailThought = Thought::factory()->create(['user_id' => $user->id]);
        $researchThought = Thought::factory()->create(['user_id' => $user->id]);

        $generator = Mockery::mock(NewsletterAnalysisGenerator::class);
        $generator->shouldReceive('generate')->never();
        $this->app->instance(NewsletterAnalysisGenerator::class, $generator);

        $job = new ProcessNewsletterBodyAnalysis(
            researchThoughtId: (string) $researchThought->id,
            sourceThoughtId: (string) $emailThought->id,
            importedEmailId: $imported->id,
        );
        $job->handle(app(NewsletterAnalysisGenerator::class));

        $analysis = NewsletterAnalysis::query()
            ->where('research_thought_id', $researchThought->id)
            ->first();

        $this->assertNotNull($analysis);
        $this->assertSame('failed', $analysis->status);
        $this->assertSame('body_too_short', $analysis->failure_reason);
    }

    #[Test]
    public function job_skips_when_completed_analysis_already_exists(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'analysis-idempotent',
            'direction' => 'received',
            'subject' => 'Existing',
            'body_text' => str_repeat('Body content. ', 20),
            'from_json' => [['email' => 'x@x.com']],
            'processing_status' => 'research_completed',
        ]);

        $emailThought = Thought::factory()->create(['user_id' => $user->id]);
        $researchThought = Thought::factory()->create(['user_id' => $user->id]);

        NewsletterAnalysis::query()->create([
            'research_thought_id' => $researchThought->id,
            'source_thought_id' => $emailThought->id,
            'stored_email_type' => 'imported_email',
            'stored_email_id' => $imported->id,
            'status' => 'completed',
            'summary' => 'Existing summary.',
            'key_points' => ['Existing point'],
            'positives_mentioned' => [],
            'negatives_mentioned' => [],
            'highlights' => [],
            'completed_at' => now(),
        ]);

        $generator = Mockery::mock(NewsletterAnalysisGenerator::class);
        $generator->shouldReceive('generate')->never();
        $this->app->instance(NewsletterAnalysisGenerator::class, $generator);

        $job = new ProcessNewsletterBodyAnalysis(
            researchThoughtId: (string) $researchThought->id,
            sourceThoughtId: (string) $emailThought->id,
            importedEmailId: $imported->id,
        );
        $job->handle(app(NewsletterAnalysisGenerator::class));

        $analysis = NewsletterAnalysis::query()
            ->where('research_thought_id', $researchThought->id)
            ->first();

        // Existing row unchanged
        $this->assertSame('Existing summary.', $analysis->summary);
        $this->assertSame('completed', $analysis->status);
    }

    #[Test]
    public function job_marks_analysis_failed_when_generator_throws_on_final_attempt(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'analysis-gen-fail',
            'direction' => 'received',
            'subject' => 'Newsletter',
            'body_text' => str_repeat('Body content. ', 30),
            'from_json' => [['email' => 'x@x.com']],
            'processing_status' => 'research_completed',
        ]);

        $emailThought = Thought::factory()->create(['user_id' => $user->id]);
        $researchThought = Thought::factory()->create(['user_id' => $user->id]);

        $generator = Mockery::mock(NewsletterAnalysisGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->andThrow(new \RuntimeException('API error'));
        $this->app->instance(NewsletterAnalysisGenerator::class, $generator);

        // Set tries = 1 so attempts() (which returns 1 outside the queue runner) equals tries,
        // triggering the failure-record branch instead of a rethrow.
        $job = new ProcessNewsletterBodyAnalysis(
            researchThoughtId: (string) $researchThought->id,
            sourceThoughtId: (string) $emailThought->id,
            importedEmailId: $imported->id,
        );
        $job->tries = 1;

        $job->handle(app(NewsletterAnalysisGenerator::class));

        $analysis = NewsletterAnalysis::query()
            ->where('research_thought_id', $researchThought->id)
            ->first();

        $this->assertNotNull($analysis);
        $this->assertSame('failed', $analysis->status);
        $this->assertStringContainsString('API error', (string) $analysis->failure_reason);
    }
}
