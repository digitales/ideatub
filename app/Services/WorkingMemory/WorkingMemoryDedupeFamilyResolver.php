<?php

namespace App\Services\WorkingMemory;

use Illuminate\Support\Str;

class WorkingMemoryDedupeFamilyResolver
{
    /**
     * @param  list<string>  $extraTags
     */
    public function isWorkingMemoryCapture(?string $planSlug, array $extraTags, ?string $project): bool
    {
        $normalizedTags = $this->normalizeTags($extraTags);
        if (in_array('working-memory', $normalizedTags, true)) {
            return true;
        }

        $slug = Str::of((string) $planSlug)->trim()->lower()->toString();
        if ($slug !== '' && preg_match('/^(client|project)-working-memory/i', $slug) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $extraTags
     */
    public function resolveForCapture(?string $planSlug, array $extraTags, ?string $project): string
    {
        $normalizedTags = $this->normalizeTags($extraTags);
        $projectKey = Str::of((string) $project)->trim()->lower()->toString();

        if ($projectKey !== '' && str_contains($projectKey, '/')) {
            return 'wm:project:'.$projectKey;
        }

        foreach ($normalizedTags as $tag) {
            if (str_starts_with($tag, 'client:')) {
                $client = substr($tag, strlen('client:'));

                return 'wm:client:'.$client;
            }
        }

        if (in_array('scope:project', $normalizedTags, true)) {
            foreach ($normalizedTags as $tag) {
                if (str_starts_with($tag, 'project:')) {
                    $projectSlug = substr($tag, strlen('project:'));
                    $client = $projectKey !== '' ? $projectKey : 'unknown';

                    return 'wm:project:'.$client.'/'.$projectSlug;
                }
            }
        }

        $slug = Str::of((string) $planSlug)->trim()->lower()->toString();
        $baseSlug = preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $slug) ?? $slug;

        return 'wm:plan:'.($baseSlug !== '' ? $baseSlug : 'unknown');
    }

    public function resolveForUpsert(string $scopeType, string $scopeKey): string
    {
        return 'wm:'.Str::of($scopeType)->trim()->lower()->toString().':'
            .Str::of($scopeKey)->trim()->lower()->toString();
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    private function normalizeTags(array $tags): array
    {
        return array_values(array_filter(array_map(
            fn ($tag): string => Str::of((string) $tag)->trim()->lower()->toString(),
            $tags,
        ), fn (string $tag): bool => $tag !== ''));
    }
}
