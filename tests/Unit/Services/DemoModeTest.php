<?php

namespace Tests\Unit\Services;

use App\Services\DemoMode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DemoModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->start();
    }

    #[Test]
    public function enable_sets_enabled_flag_and_seed_once(): void
    {
        $mode = app(DemoMode::class);

        $mode->enable();
        $firstSeed = session(DemoMode::SEED_SESSION_KEY);

        $mode->enable();

        $this->assertTrue($mode->enabled());
        $this->assertSame($firstSeed, session(DemoMode::SEED_SESSION_KEY));
        $this->assertNotNull($firstSeed);
    }

    #[Test]
    public function disable_clears_enabled_and_seed(): void
    {
        $mode = app(DemoMode::class);

        $mode->enable();
        $this->assertTrue($mode->enabled());
        $this->assertNotNull(session(DemoMode::SEED_SESSION_KEY));

        $mode->disable();

        $this->assertFalse($mode->enabled());
        $this->assertNull(session(DemoMode::SEED_SESSION_KEY));
    }

    #[Test]
    public function seed_returns_current_seed_or_null(): void
    {
        $mode = app(DemoMode::class);

        $this->assertNull($mode->seed());

        $mode->enable();
        $this->assertSame(session(DemoMode::SEED_SESSION_KEY), $mode->seed());

        $mode->disable();
        $this->assertNull($mode->seed());
    }
}
