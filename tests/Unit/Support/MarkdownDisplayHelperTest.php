<?php

namespace Tests\Unit\Support;

use App\Support\MarkdownDisplayHelper;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarkdownDisplayHelperTest extends TestCase
{
    #[Test]
    public function it_strips_multiline_yaml_front_matter_between_fences(): void
    {
        $raw = <<<'MD'
---
layout: doc
title: Hello
description: Sub
---

# Hello

Body.
MD;

        $out = MarkdownDisplayHelper::stripPreambleForMarkdownDisplay($raw);

        $this->assertStringStartsWith('# Hello', trim($out));
        $this->assertStringNotContainsString('layout: doc', $out);
    }

    #[Test]
    public function it_strips_leading_key_value_lines_without_fences(): void
    {
        $raw = <<<'MD'
layout: doc
title: Structured-Prompt-Driven Development (SPDD)
description: Research microsite.

# Structured-Prompt-Driven Development (SPDD)

Intro.
MD;

        $out = MarkdownDisplayHelper::stripPreambleForMarkdownDisplay($raw);

        $this->assertStringStartsWith('# Structured-Prompt-Driven', trim($out));
        $this->assertStringNotContainsString('layout: doc', $out);
    }

    #[Test]
    public function it_strips_single_line_concatenated_front_matter(): void
    {
        $raw = "layout: doc title: SPDD description: Research microsite synthesis.\n\n# SPDD\n\nBody.\n";

        $out = MarkdownDisplayHelper::stripPreambleForMarkdownDisplay($raw);

        $this->assertStringStartsWith('# SPDD', trim($out));
        $this->assertStringNotContainsString('layout: doc', $out);
    }

    #[Test]
    public function it_leaves_regular_markdown_unchanged(): void
    {
        $raw = "# Title\n\nlayout: is not front matter here.\n";

        $out = MarkdownDisplayHelper::stripPreambleForMarkdownDisplay($raw);

        $this->assertSame($raw, $out);
    }

    #[Test]
    public function it_strips_html_comment_then_front_matter(): void
    {
        $raw = <<<'MD'
<!-- skill meta -->
---
title: X
---

# Hi
MD;

        $out = MarkdownDisplayHelper::stripPreambleForMarkdownDisplay($raw);

        $this->assertStringStartsWith('# Hi', trim($out));
    }
}
