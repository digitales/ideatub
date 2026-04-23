<?php

namespace Tests\Unit\Support\Research;

use App\Models\Thought;
use App\Support\Research\MicrositePageLabel;
use PHPUnit\Framework\TestCase;

class MicrositePageLabelTest extends TestCase
{
    public function test_uses_first_markdown_heading_when_present(): void
    {
        $t = new Thought;
        $t->forceFill([
            'content' => "# My Title\n\nbody",
            'source_metadata' => ['page_path_segment' => '01-ignored'],
        ]);
        $this->assertStringContainsString('My Title', MicrositePageLabel::forThought($t));
    }

    public function test_falls_back_to_segment_stripped_of_numeric_prefix(): void
    {
        $t = new Thought;
        $t->forceFill([
            'content' => 'no heading here',
            'source_metadata' => ['page_path_segment' => '02-setup-guide'],
        ]);
        $this->assertSame('Setup Guide', MicrositePageLabel::forThought($t));
    }
}
