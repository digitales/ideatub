<?php

namespace Tests\Feature;

use App\Models\MailAccount;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ViewPresenterQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /** Rows seeded for list-style routes (matches typical page size ~20) so per-row lazy loads blow the budget. */
    private const LIST_ROW_COUNT = 20;

    /** Reply thoughts under the email detail fixture; show view iterates `@foreach ($thought->childThoughts)`. */
    private const EMAIL_DETAIL_REPLY_COUNT = 15;

    /**
     * `settings.email-accounts.index` with many accounts.
     * Expected: `mail_accounts` list, `latestSyncRun` constrained eager load, shared authenticated layout inbox badge.
     */
    private const QUERY_BUDGET_SETTINGS_EMAIL_ACCOUNTS_INDEX = 4;

    /**
     * `idea.index` recent feed (no search) with a full recent page.
     * Expected: main thoughts + eager `comments`, shared inbox badge. Must stay flat vs row count (no N+1 per card).
     */
    private const QUERY_BUDGET_IDEA_INDEX = 4;

    /**
     * `idea.stream` first page with a full page of thoughts.
     * Expected: paginate (count + page), eager `comments`, `research_shares` for the page, inbox badge.
     */
    private const QUERY_BUDGET_IDEA_STREAM = 6;

    /**
     * `idea.ideas` with a full page of incomplete ideas.
     * Expected: paginate (count + page), one batched research lookup for visible idea ids, inbox badge.
     */
    private const QUERY_BUDGET_IDEA_IDEAS = 5;

    /**
     * `idea.completed` with a full page of completed ideas.
     * Expected: paginate (count + page), inbox badge.
     */
    private const QUERY_BUDGET_IDEA_COMPLETED = 4;

    /**
     * `thoughts.show` for an email thought with many replies rendered in the Replies section.
     * Expected: route model binding + eager `comments` (one query for all replies), imported email resolution,
     * sender-rule / captured-inbound lookups (current implementation hits `captured_inbound_emails` more than once),
     * inbox badge. Reply count must not add per-reply queries beyond the single eager `comments` load.
     * Remeasured with {@see self::EMAIL_DETAIL_REPLY_COUNT} child rows: total query count unchanged vs zero replies,
     * so this cap matches the prior budget (8 = measured 7 + one headroom).
     */
    private const QUERY_BUDGET_THOUGHTS_SHOW_EMAIL = 8;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Enable SQL query logging and strict lazy-loading prevention for the duration of $callback.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return array{0: T, 1: list<array{query: string, bindings: array, time: float}>}
     */
    protected function withQueryBudget(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $hadPrevent = Model::preventsLazyLoading();
        Model::preventLazyLoading(true);

        try {
            $result = $callback();

            return [$result, DB::getQueryLog()];
        } finally {
            Model::preventLazyLoading($hadPrevent);
            DB::disableQueryLog();
        }
    }

    /**
     * @param  list<array{query: string, bindings: array, time: float}>  $queryLog
     */
    protected function assertQueryCountWithinBudget(array $queryLog, int $maxQueries, string $context): void
    {
        $n = count($queryLog);
        if ($n <= $maxQueries) {
            return;
        }

        $sample = array_slice(array_column($queryLog, 'query'), 0, 25);

        $this->fail("{$context}: expected at most {$maxQueries} queries, got {$n}.\n".implode("\n", $sample));
    }

    public function test_settings_email_accounts_index_query_budget_with_many_accounts(): void
    {
        config(['services.mail_sync.enabled' => true]);

        $user = User::factory()->create();
        MailAccount::factory()->count(self::LIST_ROW_COUNT)->create(['user_id' => $user->id]);

        [$response, $log] = $this->withQueryBudget(fn (): TestResponse => $this->actingAs($user)->get(route('settings.email-accounts.index')));

        $response->assertOk();
        $this->assertQueryCountWithinBudget($log, self::QUERY_BUDGET_SETTINGS_EMAIL_ACCOUNTS_INDEX, 'settings.email-accounts.index');
    }

    public function test_idea_index_query_budget_with_many_recent_thoughts(): void
    {
        $user = User::factory()->create();
        Thought::factory()->count(self::LIST_ROW_COUNT)->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'web',
            'metadata' => null,
        ]);

        [$response, $log] = $this->withQueryBudget(fn (): TestResponse => $this->actingAs($user)->get(route('idea.index')));

        $response->assertOk();
        $this->assertQueryCountWithinBudget($log, self::QUERY_BUDGET_IDEA_INDEX, 'idea.index');
    }

    public function test_idea_stream_query_budget_with_many_thoughts(): void
    {
        $user = User::factory()->create();
        Thought::factory()->count(self::LIST_ROW_COUNT)->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'web',
            'metadata' => null,
        ]);

        [$response, $log] = $this->withQueryBudget(fn (): TestResponse => $this->actingAs($user)->get(route('idea.stream')));

        $response->assertOk();
        $this->assertQueryCountWithinBudget($log, self::QUERY_BUDGET_IDEA_STREAM, 'idea.stream');
    }

    public function test_idea_ideas_query_budget_with_many_incomplete_ideas(): void
    {
        $user = User::factory()->create();
        foreach (range(1, self::LIST_ROW_COUNT) as $i) {
            Thought::factory()->create([
                'user_id' => $user->id,
                'content' => "Idea seed {$i}",
                'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
                'embedding' => null,
                'parent_id' => null,
            ]);
        }

        [$response, $log] = $this->withQueryBudget(fn (): TestResponse => $this->actingAs($user)->get(route('idea.ideas')));

        $response->assertOk();
        $this->assertQueryCountWithinBudget($log, self::QUERY_BUDGET_IDEA_IDEAS, 'idea.ideas');
    }

    public function test_idea_completed_query_budget_with_many_completed_ideas(): void
    {
        $user = User::factory()->create();
        foreach (range(1, self::LIST_ROW_COUNT) as $i) {
            Thought::factory()->create([
                'user_id' => $user->id,
                'content' => "Completed idea {$i}",
                'metadata' => [
                    'type' => 'idea',
                    'completed' => true,
                    'logged_date' => '2025-02-01',
                    'completed_at' => '2026-03-10T10:00:00+00:00',
                ],
                'embedding' => null,
                'parent_id' => null,
            ]);
        }

        [$response, $log] = $this->withQueryBudget(fn (): TestResponse => $this->actingAs($user)->get(route('idea.completed')));

        $response->assertOk();
        $this->assertQueryCountWithinBudget($log, self::QUERY_BUDGET_IDEA_COMPLETED, 'idea.completed');
    }

    public function test_thoughts_show_email_thought_query_budget(): void
    {
        $user = User::factory()->create();
        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Budget email body',
            'source' => 'email',
            'source_metadata' => [
                'subject' => 'Budget subject',
            ],
            'embedding' => null,
            'parent_id' => null,
        ]);

        foreach (range(1, self::EMAIL_DETAIL_REPLY_COUNT) as $i) {
            Thought::factory()->create([
                'user_id' => $user->id,
                'parent_id' => $emailThought->id,
                'content' => "Reply row {$i} for email detail query budget.",
                'source' => 'web',
                'embedding' => null,
                'metadata' => null,
            ]);
        }

        [$response, $log] = $this->withQueryBudget(fn (): TestResponse => $this->actingAs($user)->get(route('thoughts.show', $emailThought->fresh())));

        $response->assertOk();
        $response->assertSee('Reply row '.self::EMAIL_DETAIL_REPLY_COUNT.' for email detail query budget.', false);

        $this->assertQueryCountWithinBudget($log, self::QUERY_BUDGET_THOUGHTS_SHOW_EMAIL, 'thoughts.show (email)');
    }
}
