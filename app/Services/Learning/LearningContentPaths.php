<?php

namespace App\Services\Learning;

class LearningContentPaths
{
    /**
     * Absolute paths to markdown files under `{content_root}/research/*.md`.
     *
     * @return list<string>
     */
    public function researchGlob(string $contentRoot): array
    {
        $pattern = $this->joinPath($contentRoot, 'research', '*.md');
        $paths = glob($pattern) ?: [];
        sort($paths);

        /** @var list<string> $paths */
        return $paths;
    }

    /**
     * Absolute paths to markdown files under `{content_root}/curriculum/lessons/*.md`.
     *
     * @return list<string>
     */
    public function lessonGlob(string $contentRoot): array
    {
        $pattern = $this->joinPath($contentRoot, 'curriculum', 'lessons', '*.md');
        $paths = glob($pattern) ?: [];
        sort($paths);

        /** @var list<string> $paths */
        return $paths;
    }

    /**
     * Optional manifest path: `{content_root}/learning.config.json`.
     */
    public function configJsonPath(string $contentRoot): string
    {
        return $this->joinPath($contentRoot, 'learning.config.json');
    }

    private function joinPath(string $root, string ...$segments): string
    {
        $base = rtrim($root, DIRECTORY_SEPARATOR);

        return $base.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);
    }
}
