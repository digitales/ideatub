<?php

namespace Tests\Unit\Services;

use App\Services\DemoMode;
use App\Services\DemoObfuscationGenerator;
use App\Services\DemoObfuscator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DemoObfuscatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->start();
    }

    #[Test]
    public function same_input_and_context_produce_same_output_for_one_session_seed(): void
    {
        session([DemoMode::SEED_SESSION_KEY => 'session-seed-abc']);

        $obfuscator = app(DemoObfuscator::class);

        $a = $obfuscator->obfuscate('Secret meeting notes', 'thought_content');
        $b = $obfuscator->obfuscate('Secret meeting notes', 'thought_content');

        $this->assertSame($a, $b);
        $this->assertNotSame('Secret meeting notes', $a);
    }

    #[Test]
    public function different_field_contexts_produce_different_output_for_same_source_string(): void
    {
        session([DemoMode::SEED_SESSION_KEY => 'session-seed-xyz']);

        $obfuscator = app(DemoObfuscator::class);
        $source = 'Quarterly revenue figures';

        $asThought = $obfuscator->obfuscate($source, 'thought_content');
        $asEmailSubject = $obfuscator->obfuscate($source, 'email_subject');
        $asSnippet = $obfuscator->obfuscate($source, 'research_snippet');

        $this->assertNotSame($asThought, $asEmailSubject);
        $this->assertNotSame($asThought, $asSnippet);
        $this->assertNotSame($asEmailSubject, $asSnippet);
    }

    #[Test]
    public function null_and_empty_strings_pass_through_unchanged(): void
    {
        session([DemoMode::SEED_SESSION_KEY => 'any-seed']);

        $obfuscator = app(DemoObfuscator::class);

        $this->assertNull($obfuscator->obfuscate(null, 'thought_content'));
        $this->assertSame('', $obfuscator->obfuscate('', 'thought_content'));
    }

    #[Test]
    public function internal_failure_returns_demo_content_hidden_placeholder(): void
    {
        session([DemoMode::SEED_SESSION_KEY => 'seed']);
        $this->app->bind(DemoObfuscationGenerator::class, fn () => new class extends DemoObfuscationGenerator
        {
            public function generate(string $normalized, string $fieldContext, string $seed): string
            {
                throw new \RuntimeException('simulated obfuscation failure');
            }
        });

        $obfuscator = app(DemoObfuscator::class);

        $this->assertSame('Demo content hidden', $obfuscator->obfuscate('any text', 'thought_content'));
    }
}
