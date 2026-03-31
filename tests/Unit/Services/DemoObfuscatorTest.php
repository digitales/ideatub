<?php

namespace Tests\Unit\Services;

use App\Services\DemoObfuscator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DemoObfuscatorTest extends TestCase
{
    #[Test]
    public function same_input_and_context_produce_same_output_for_one_session_seed(): void
    {
        $obfuscator = new DemoObfuscator('session-seed-abc');

        $a = $obfuscator->obfuscate('Secret meeting notes', 'thought_content');
        $b = $obfuscator->obfuscate('Secret meeting notes', 'thought_content');

        $this->assertSame($a, $b);
        $this->assertNotSame('Secret meeting notes', $a);
    }

    #[Test]
    public function different_field_contexts_produce_different_output_for_same_source_string(): void
    {
        $obfuscator = new DemoObfuscator('session-seed-xyz');
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
        $obfuscator = new DemoObfuscator('any-seed');

        $this->assertNull($obfuscator->obfuscate(null, 'thought_content'));
        $this->assertSame('', $obfuscator->obfuscate('', 'thought_content'));
    }

    #[Test]
    public function internal_failure_returns_demo_content_hidden_placeholder(): void
    {
        $obfuscator = new class('seed') extends DemoObfuscator
        {
            protected function buildFakeContent(string $normalized, string $fieldContext, string $seed): string
            {
                throw new \RuntimeException('simulated obfuscation failure');
            }
        };

        $this->assertSame('Demo content hidden', $obfuscator->obfuscate('any text', 'thought_content'));
    }
}
