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

    private const LIST_ROW_COUNT = 20;

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
        // Measured: mail_accounts + latestSyncRun eager load + shared layout inbox badge.
        $this->assertQueryCountWithinBudget($log, 4, 'settings.email-accounts.index');
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
        $this->assertQueryCountWithinBudget($log, 4, 'idea.index');
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
        $this->assertQueryCountWithinBudget($log, 6, 'idea.stream');
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
        $this->assertQueryCountWithinBudget($log, 5, 'idea.ideas');
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
        $this->assertQueryCountWithinBudget($log, 4, 'idea.completed');
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

        [$response, $log] = $this->withQueryBudget(fn (): TestResponse => $this->actingAs($user)->get(route('thoughts.show', $emailThought)));

        $response->assertOk();
        // Measured: thought + comments + imported_email lookup + repeated captured_inbound_emails + inbox layout.
        $this->assertQueryCountWithinBudget($log, 8, 'thoughts.show (email)');
    }
}
