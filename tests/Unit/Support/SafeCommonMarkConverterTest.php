<?php

namespace Tests\Unit\Support;

use App\Support\SafeCommonMarkConverter;
use PHPUnit\Framework\TestCase;

class SafeCommonMarkConverterTest extends TestCase
{
    public function test_renders_gfm_pipe_table(): void
    {
        $md = <<<'MD'
| a | b |
| --- | --- |
| 1 | 2 |
MD;
        $html = SafeCommonMarkConverter::make()
            ->convert($md)
            ->getContent();

        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('</table>', $html);
    }
}
