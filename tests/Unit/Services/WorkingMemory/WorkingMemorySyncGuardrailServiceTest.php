<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Services\WorkingMemory\WorkingMemorySyncGuardrailService;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Tests\TestCase;

class WorkingMemorySyncGuardrailServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'working_memory.sync_guardrails_enabled' => true,
            'working_memory.sync_min_interval_seconds' => 0,
            'working_memory.sync_monthly_budget_tokens' => 0,
            'working_memory.sync_max_content_chars' => 65535,
            'working_memory.sync_min_delta_ratio' => 0.0,
            'working_memory.sync_token_chars_per_token' => 4,
        ]);
    }

    public function test_it_blocks_when_sync_frequency_window_is_active(): void
    {
        config(['working_memory.sync_min_interval_seconds' => 3600]);

        $service = app(WorkingMemorySyncGuardrailService::class);
        $service->enforce('upsert', 10, 'wm:project:abc', '## Current Focus'.PHP_EOL.'- Ship v1');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('frequency guardrail');

        $service->enforce('upsert', 10, 'wm:project:abc', '## Current Focus'.PHP_EOL.'- Ship v2');
    }

    public function test_it_blocks_when_monthly_token_budget_is_exceeded(): void
    {
        config([
            'working_memory.sync_monthly_budget_tokens' => 10,
            'working_memory.sync_token_chars_per_token' => 1,
        ]);

        $service = app(WorkingMemorySyncGuardrailService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('monthly token budget reached');

        $service->enforce('capture', 22, 'wm:client:dezeen', str_repeat('x', 30));
    }

    public function test_it_blocks_low_delta_updates_when_threshold_is_enabled(): void
    {
        config(['working_memory.sync_min_delta_ratio' => 0.2]);

        $service = app(WorkingMemorySyncGuardrailService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('low change delta');

        $service->enforce(
            'capture',
            33,
            'wm:client:dezeen',
            '## Current Focus'.PHP_EOL.'- Stabilise comments pipeline.',
            '## Current Focus'.PHP_EOL.'- Stabilize comments pipeline.'
        );
    }
}
