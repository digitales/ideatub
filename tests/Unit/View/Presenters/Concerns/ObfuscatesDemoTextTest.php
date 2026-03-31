<?php

namespace Tests\Unit\View\Presenters\Concerns;

use App\Services\DemoMode;
use App\Services\DemoObfuscator;
use App\View\Presenters\Concerns\ObfuscatesDemoText;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ObfuscatesDemoTextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->start();
    }

    private function presenterDouble(): object
    {
        return new class
        {
            use ObfuscatesDemoText;

            public function present(?string $value, string $context): ?string
            {
                return $this->demoText($value, $context);
            }
        };
    }

    #[Test]
    public function demo_mode_off_returns_the_raw_value(): void
    {
        $presenter = $this->presenterDouble();

        $this->assertSame('Original thought text', $presenter->present('Original thought text', 'thought_content'));
    }

    #[Test]
    public function demo_mode_on_with_session_seed_returns_obfuscated_text(): void
    {
        config(['services.demo_mode.enabled' => true]);
        session([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'trait-seed-123',
        ]);

        $presenter = $this->presenterDouble();
        $value = 'Original thought text';

        $obfuscated = $presenter->present($value, 'thought_content');

        $this->assertNotSame($value, $obfuscated);
        $this->assertSame(
            app(DemoObfuscator::class)->obfuscate($value, 'thought_content'),
            $obfuscated,
        );
    }
}
