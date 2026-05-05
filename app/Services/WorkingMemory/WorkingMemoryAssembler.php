<?php

namespace App\Services\WorkingMemory;

use App\Models\Thought;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WorkingMemoryAssembler
{
    /**
     * @param  Collection<int, Thought>  $thoughts
     * @return array{
     *     executive_summary: string,
     *     key_concepts: array<int, array{title: string}>,
     *     active_threads: array<int, array{title: string}>,
     *     open_questions: array<int, array{question: string}>,
     *     next_actions: array<int, array{action: string}>,
     *     confidence_score: float
     * }
     */
    public function assemblePayload(Collection $thoughts): array
    {
        $tagCounts = $this->countTags($thoughts);
        $keyConcepts = array_map(
            fn (string $tag): array => ['title' => $tag],
            array_slice(array_keys($tagCounts), 0, 5)
        );

        $activeThreads = $thoughts
            ->pluck('content')
            ->filter(fn ($content): bool => is_string($content) && trim($content) !== '')
            ->map(fn (string $content): array => ['title' => Str::limit(trim($content), 90)])
            ->unique('title')
            ->take(5)
            ->values()
            ->all();

        $openQuestions = $thoughts
            ->pluck('content')
            ->filter(fn ($content): bool => is_string($content) && str_contains($content, '?'))
            ->map(fn (string $content): array => ['question' => Str::finish(Str::limit(trim($content), 90, ''), '?')])
            ->unique('question')
            ->take(5)
            ->values()
            ->all();

        $nextActions = $thoughts
            ->pluck('content')
            ->filter(fn ($content): bool => is_string($content) && trim($content) !== '')
            ->map(fn (string $content): array => ['action' => Str::limit(trim($content), 90)])
            ->unique('action')
            ->take(5)
            ->values()
            ->all();

        $thoughtCount = $thoughts->count();
        $confidenceScore = $this->boundConfidence(25 + ($thoughtCount * 2.5) + (count($keyConcepts) * 8));

        if ($keyConcepts === []) {
            $keyConcepts = [['title' => 'No key concepts identified yet']];
        }
        if ($activeThreads === []) {
            $activeThreads = [['title' => 'No active threads identified yet']];
        }
        if ($openQuestions === []) {
            $openQuestions = [['question' => 'What information is still missing?']];
        }
        if ($nextActions === []) {
            $nextActions = [['action' => 'Capture more thoughts to improve memory coverage']];
        }

        return [
            'executive_summary' => $this->executiveSummary($thoughtCount, $keyConcepts),
            'key_concepts' => $keyConcepts,
            'active_threads' => $activeThreads,
            'open_questions' => $openQuestions,
            'next_actions' => $nextActions,
            'confidence_score' => $confidenceScore,
        ];
    }

    /**
     * @param  array{
     *     executive_summary: string,
     *     key_concepts: array<int, array{title: string}>,
     *     active_threads: array<int, array{title: string}>,
     *     open_questions: array<int, array{question: string}>,
     *     next_actions: array<int, array{action: string}>
     * }  $payload
     */
    public function renderSummary(array $payload): string
    {
        return implode("\n\n", [
            '## Executive summary',
            $payload['executive_summary'],
            '## Key concepts',
            $this->renderBullets($payload['key_concepts'], 'title'),
            '## Active threads',
            $this->renderBullets($payload['active_threads'], 'title'),
            '## Open questions',
            $this->renderBullets($payload['open_questions'], 'question'),
            '## Next actions',
            $this->renderBullets($payload['next_actions'], 'action'),
        ]);
    }

    public function boundConfidence(float $confidenceScore): float
    {
        return max(0.0, min(100.0, round($confidenceScore, 2)));
    }

    /**
     * @param  Collection<int, Thought>  $thoughts
     * @return array<string, int>
     */
    private function countTags(Collection $thoughts): array
    {
        $counts = [];

        foreach ($thoughts as $thought) {
            $tags = data_get($thought->metadata, 'tags');
            if (! is_array($tags)) {
                continue;
            }

            foreach ($tags as $tag) {
                $normalizedTag = Str::of((string) $tag)->trim()->lower()->toString();
                if ($normalizedTag === '') {
                    continue;
                }

                $counts[$normalizedTag] = ($counts[$normalizedTag] ?? 0) + 1;
            }
        }

        arsort($counts);

        return $counts;
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function renderBullets(array $rows, string $key): string
    {
        return collect($rows)
            ->map(fn (array $row): string => '- '.(string) ($row[$key] ?? ''))
            ->implode("\n");
    }

    /**
     * @param  array<int, array{title: string}>  $keyConcepts
     */
    private function executiveSummary(int $thoughtCount, array $keyConcepts): string
    {
        $topConcept = $keyConcepts[0]['title'] ?? 'unclassified topics';

        return "First-pass synthesis across {$thoughtCount} thoughts highlights {$topConcept} as the strongest signal.";
    }
}
