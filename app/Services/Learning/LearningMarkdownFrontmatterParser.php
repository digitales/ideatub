<?php

namespace App\Services\Learning;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class LearningMarkdownFrontmatterParser
{
    /**
     * Parse YAML frontmatter between the first two full-line `---` delimiters.
     *
     * @return array{frontmatter: array<string, mixed>, body: string}
     */
    public function parse(string $markdown): array
    {
        $markdown = $this->stripBom($markdown);

        if (! preg_match(
            '/^(?:\xEF\xBB\xBF)?---\R([\s\S]*?)^---\R([\s\S]*)/m',
            $markdown,
            $matches
        )) {
            throw new InvalidArgumentException('Markdown must include YAML frontmatter bounded by two --- delimiter lines.');
        }

        $yamlBlock = $matches[1];
        $body = $matches[2];

        try {
            $parsed = Yaml::parse($yamlBlock);
        } catch (ParseException $e) {
            throw new InvalidArgumentException('YAML frontmatter is invalid.', previous: $e);
        }

        if (! is_array($parsed)) {
            throw new InvalidArgumentException('YAML frontmatter must parse to a mapping.');
        }

        /** @var array<string, mixed> $parsed */
        $this->assertRequiredStringKeys($parsed, 'slug');
        $this->assertRequiredStringKeys($parsed, 'title');

        return [
            'frontmatter' => $parsed,
            'body' => $body,
        ];
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    private function assertRequiredStringKeys(array $frontmatter, string $key): void
    {
        if (! array_key_exists($key, $frontmatter)) {
            throw new InvalidArgumentException(sprintf('Frontmatter is missing required key "%s".', $key));
        }

        $value = $frontmatter[$key];

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Frontmatter key "%s" must be a non-empty string.', $key));
        }
    }

    private function stripBom(string $markdown): string
    {
        if (str_starts_with($markdown, "\xEF\xBB\xBF")) {
            return substr($markdown, 3);
        }

        return $markdown;
    }
}
