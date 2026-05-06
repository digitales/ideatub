<?php

namespace Tests\Unit\Services\Learning;

use App\Services\Learning\LearningMarkdownFrontmatterParser;
use InvalidArgumentException;
use Tests\TestCase;

class LearningMarkdownFrontmatterParserTest extends TestCase
{
    private LearningMarkdownFrontmatterParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new LearningMarkdownFrontmatterParser;
    }

    public function test_it_parses_yaml_frontmatter_and_body_between_first_two_delimiters(): void
    {
        $markdown = <<<'MD'
---
slug: my-lesson
title: My Lesson
tags:
  - one
  - two
---

# Heading

Body line.
MD;

        $result = $this->parser->parse($markdown);

        $this->assertSame([
            'slug' => 'my-lesson',
            'title' => 'My Lesson',
            'tags' => ['one', 'two'],
        ], $result['frontmatter']);

        $expectedBody = <<<'MD'

# Heading

Body line.
MD;
        $this->assertSame($expectedBody, $result['body']);
    }

    public function test_it_accepts_windows_style_newlines(): void
    {
        $markdown = "---\r\nslug: a\r\ntitle: B\r\n---\r\n\r\nHello\r\n";

        $result = $this->parser->parse($markdown);

        $this->assertSame(['slug' => 'a', 'title' => 'B'], $result['frontmatter']);
        $this->assertSame("\r\nHello\r\n", $result['body']);
    }

    public function test_it_throws_when_opening_delimiter_is_missing(): void
    {
        $markdown = "slug: x\ntitle: y\n---\n\nBody";

        $this->expectException(InvalidArgumentException::class);
        $this->parser->parse($markdown);
    }

    public function test_it_throws_when_closing_delimiter_is_missing(): void
    {
        $markdown = <<<'MD'
---
slug: x
title: y

Body without closing delimiter
MD;

        $this->expectException(InvalidArgumentException::class);
        $this->parser->parse($markdown);
    }

    public function test_it_throws_when_yaml_is_invalid(): void
    {
        $markdown = <<<'MD'
---
slug: [broken
title: ok
---

Body
MD;

        $this->expectException(InvalidArgumentException::class);
        $this->parser->parse($markdown);
    }

    public function test_it_throws_when_yaml_is_not_a_mapping(): void
    {
        $markdown = <<<'MD'
---
plain string
---

Body
MD;

        $this->expectException(InvalidArgumentException::class);
        $this->parser->parse($markdown);
    }

    public function test_it_throws_when_slug_is_missing(): void
    {
        $markdown = <<<'MD'
---
title: Only Title
---

Body
MD;

        $this->expectException(InvalidArgumentException::class);
        $this->parser->parse($markdown);
    }

    public function test_it_throws_when_title_is_missing(): void
    {
        $markdown = <<<'MD'
---
slug: only-slug
---

Body
MD;

        $this->expectException(InvalidArgumentException::class);
        $this->parser->parse($markdown);
    }

    public function test_it_throws_when_slug_or_title_is_empty_string(): void
    {
        $markdown = <<<'MD'
---
slug: ""
title: T
---

Body
MD;

        $this->expectException(InvalidArgumentException::class);
        $this->parser->parse($markdown);
    }
}
