<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Services\WorkingMemory\WorkingMemoryContentFingerprint;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryContentFingerprintTest extends TestCase
{
    #[Test]
    public function it_strips_volatile_lines_by_default(): void
    {
        $a = <<<'MD'
# Working Memory
Last Updated: 2026-05-19 (refreshed at 2026-05-19T01:00:00Z)
Scope: Client-level live state

## Current Focus
- Same bullet
MD;
        $b = <<<'MD'
# Working Memory
Last Updated: 2026-05-19 (refreshed at 2026-05-19T02:00:00Z)
Scope: Client-level live state

## Current Focus
- Same bullet
MD;

        $fp = app(WorkingMemoryContentFingerprint::class);

        $this->assertSame($fp->hash($a), $fp->hash($b));
    }

    #[Test]
    public function strict_mode_treats_volatile_lines_as_significant(): void
    {
        $a = "Last Updated: 2026-05-19\n\n## Focus\n- x";
        $b = "Last Updated: 2026-05-20\n\n## Focus\n- x";

        $fp = app(WorkingMemoryContentFingerprint::class);

        $this->assertNotSame($fp->hash($a, strict: true), $fp->hash($b, strict: true));
    }

    #[Test]
    public function it_normalizes_whitespace_and_markdown(): void
    {
        $fp = app(WorkingMemoryContentFingerprint::class);
        $this->assertSame(
            $fp->hash("##  Current Focus\n\n-  Item"),
            $fp->hash("## Current Focus\n- Item")
        );
    }

    #[Test]
    public function it_rejects_empty_normalized_content(): void
    {
        $fp = app(WorkingMemoryContentFingerprint::class);

        $this->expectException(\InvalidArgumentException::class);
        $fp->hash('   ');
    }
}
