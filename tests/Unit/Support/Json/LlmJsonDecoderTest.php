<?php

namespace Tests\Unit\Support\Json;

use App\Support\Json\LlmJsonDecoder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LlmJsonDecoderTest extends TestCase
{
    #[Test]
    public function it_decodes_plain_json_into_an_associative_array(): void
    {
        $result = LlmJsonDecoder::decode('{"summary":"ok","items":[1,2]}');

        $this->assertIsArray($result);
        $this->assertSame('ok', $result['summary']);
        $this->assertSame([1, 2], $result['items']);
    }

    #[Test]
    public function it_strips_markdown_json_code_fence_wrappers(): void
    {
        $raw = "```json\n{\"summary\":\"ok\"}\n```";

        $result = LlmJsonDecoder::decode($raw);

        $this->assertIsArray($result);
        $this->assertSame('ok', $result['summary']);
    }

    #[Test]
    public function it_strips_unlabeled_code_fence_wrappers(): void
    {
        $raw = "```\n{\"summary\":\"ok\"}\n```";

        $result = LlmJsonDecoder::decode($raw);

        $this->assertIsArray($result);
        $this->assertSame('ok', $result['summary']);
    }

    #[Test]
    public function it_returns_null_on_empty_input(): void
    {
        $this->assertNull(LlmJsonDecoder::decode(''));
        $this->assertNull(LlmJsonDecoder::decode('   '));
    }

    #[Test]
    public function it_returns_null_on_invalid_json(): void
    {
        $this->assertNull(LlmJsonDecoder::decode('not json'));
        $this->assertNull(LlmJsonDecoder::decode('{"unterminated":'));
    }

    #[Test]
    public function it_extracts_json_object_when_wrapped_in_plain_text(): void
    {
        $raw = "Here is your digest:\n\n{\"summary_markdown\":\"ok\",\"references\":[]}\n\nThanks.";

        $result = LlmJsonDecoder::decode($raw);

        $this->assertIsArray($result);
        $this->assertSame('ok', $result['summary_markdown']);
        $this->assertSame([], $result['references']);
    }

    #[Test]
    public function it_returns_null_when_root_is_not_an_array(): void
    {
        $this->assertNull(LlmJsonDecoder::decode('"just a string"'));
        $this->assertNull(LlmJsonDecoder::decode('42'));
        $this->assertNull(LlmJsonDecoder::decode('true'));
        $this->assertNull(LlmJsonDecoder::decode('null'));
    }
}
