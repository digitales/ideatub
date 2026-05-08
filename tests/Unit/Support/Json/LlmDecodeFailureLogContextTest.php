<?php

namespace Tests\Unit\Support\Json;

use App\Support\Json\LlmDecodeFailureLogContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LlmDecodeFailureLogContextTest extends TestCase
{
    #[Test]
    public function it_returns_base_unchanged_when_preview_logging_disabled(): void
    {
        config(['working_memory.log_llm_decode_failure_preview' => false]);

        $base = ['user_id' => 1];
        $result = LlmDecodeFailureLogContext::withOptionalRawPreview($base, 'not json prose');

        $this->assertSame($base, $result);
    }

    #[Test]
    public function it_appends_raw_preview_when_enabled(): void
    {
        config([
            'working_memory.log_llm_decode_failure_preview' => true,
            'working_memory.llm_decode_failure_preview_max_chars' => 12,
        ]);

        $result = LlmDecodeFailureLogContext::withOptionalRawPreview([], str_repeat('a', 100));

        $this->assertArrayHasKey('raw_preview', $result);
        $this->assertSame('aaaaaaaaaaaa…', $result['raw_preview']);
    }

    #[Test]
    public function it_skips_preview_when_max_chars_is_zero_or_negative(): void
    {
        config([
            'working_memory.log_llm_decode_failure_preview' => true,
            'working_memory.llm_decode_failure_preview_max_chars' => 0,
        ]);

        $base = ['x' => 1];
        $result = LlmDecodeFailureLogContext::withOptionalRawPreview($base, 'hello');

        $this->assertSame($base, $result);
    }
}
