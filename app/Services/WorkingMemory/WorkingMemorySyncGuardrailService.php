<?php

namespace App\Services\WorkingMemory;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class WorkingMemorySyncGuardrailService
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly WorkingMemoryContentFingerprint $fingerprint,
    ) {}

    /**
     * @return array{estimated_tokens: int, delta_ratio: float|null, month_usage_tokens: int|null}
     */
    public function enforce(
        string $channel,
        int $userId,
        string $scopeKey,
        string $content,
        ?string $previousContent = null,
        bool $strictContentHash = false,
    ): array {
        if (! config('working_memory.sync_guardrails_enabled', true)) {
            return [
                'estimated_tokens' => 0,
                'delta_ratio' => null,
                'month_usage_tokens' => null,
            ];
        }

        $scope = $scopeKey !== '' ? $scopeKey : 'unknown';
        $contentChars = mb_strlen($content);
        $maxChars = max(0, (int) config('working_memory.sync_max_content_chars', 65535));
        if ($maxChars > 0 && $contentChars > $maxChars) {
            $this->reject(
                'max_content_chars',
                "Working memory sync blocked: content has {$contentChars} chars, limit is {$maxChars}.",
                $channel,
                $userId,
                $scope,
            );
        }

        $normalized = $this->fingerprint->normalize($content, $strictContentHash);
        $estimatedTokens = $this->estimateTokens($normalized);

        $deltaRatio = null;
        $minDelta = max(0.0, (float) config('working_memory.sync_min_delta_ratio', 0.0));
        if ($minDelta > 0 && is_string($previousContent) && trim($previousContent) !== '') {
            $previousNormalized = $this->fingerprint->normalize($previousContent, $strictContentHash);
            if ($previousNormalized !== '') {
                similar_text($normalized, $previousNormalized, $similarityPercent);
                $deltaRatio = max(0.0, 1.0 - ($similarityPercent / 100.0));
                if ($deltaRatio < $minDelta) {
                    $this->reject(
                        'min_delta_ratio',
                        sprintf(
                            'Working memory sync blocked: low change delta %.2f%% (min %.2f%%).',
                            $deltaRatio * 100,
                            $minDelta * 100
                        ),
                        $channel,
                        $userId,
                        $scope,
                        ['delta_ratio' => $deltaRatio]
                    );
                }
            }
        }

        $minIntervalSeconds = max(0, (int) config('working_memory.sync_min_interval_seconds', 0));
        if ($minIntervalSeconds > 0) {
            $lastAttemptKey = $this->lastAttemptCacheKey($userId, $channel, $scope);
            $lastAttemptTs = (int) $this->cache->get($lastAttemptKey, 0);
            $nowTs = now()->timestamp;
            $elapsed = $lastAttemptTs > 0 ? ($nowTs - $lastAttemptTs) : null;
            if ($elapsed !== null && $elapsed < $minIntervalSeconds) {
                $retryAfterSeconds = $minIntervalSeconds - $elapsed;
                $this->reject(
                    'min_interval_seconds',
                    "Working memory sync blocked by frequency guardrail. Retry in {$retryAfterSeconds} seconds.",
                    $channel,
                    $userId,
                    $scope,
                    ['retry_after_seconds' => $retryAfterSeconds]
                );
            }
            $this->cache->put($lastAttemptKey, $nowTs, now()->addDays(2));
        }

        $monthUsage = null;
        $monthlyBudget = max(0, (int) config('working_memory.sync_monthly_budget_tokens', 0));
        if ($monthlyBudget > 0) {
            $month = now()->format('Y-m');
            $monthUsageKey = $this->monthlyUsageCacheKey($userId, $channel, $month);
            $currentUsage = (int) $this->cache->get($monthUsageKey, 0);
            $projectedUsage = $currentUsage + $estimatedTokens;
            if ($projectedUsage > $monthlyBudget) {
                $this->reject(
                    'monthly_budget_tokens',
                    "Working memory sync blocked: monthly token budget reached ({$monthlyBudget}).",
                    $channel,
                    $userId,
                    $scope,
                    [
                        'estimated_tokens' => $estimatedTokens,
                        'current_usage_tokens' => $currentUsage,
                        'monthly_budget_tokens' => $monthlyBudget,
                    ]
                );
            }
            $this->cache->add($monthUsageKey, 0, now()->endOfMonth());
            $this->cache->increment($monthUsageKey, $estimatedTokens);
            $monthUsage = (int) $this->cache->get($monthUsageKey, $projectedUsage);
        }

        Log::info('working_memory.sync_guardrail.accepted', [
            'channel' => $channel,
            'user_id' => $userId,
            'scope' => $scope,
            'estimated_tokens' => $estimatedTokens,
            'delta_ratio' => $deltaRatio,
            'month_usage_tokens' => $monthUsage,
        ]);

        return [
            'estimated_tokens' => $estimatedTokens,
            'delta_ratio' => $deltaRatio,
            'month_usage_tokens' => $monthUsage,
        ];
    }

    private function estimateTokens(string $normalized): int
    {
        $charsPerToken = max(1, (int) config('working_memory.sync_token_chars_per_token', 4));
        $chars = max(1, mb_strlen($normalized));

        return (int) ceil($chars / $charsPerToken);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function reject(
        string $reason,
        string $message,
        string $channel,
        int $userId,
        string $scope,
        array $context = [],
    ): never {
        Log::warning('working_memory.sync_guardrail.blocked', array_merge([
            'reason' => $reason,
            'channel' => $channel,
            'user_id' => $userId,
            'scope' => $scope,
        ], $context));

        throw new InvalidArgumentException($message);
    }

    private function lastAttemptCacheKey(int $userId, string $channel, string $scope): string
    {
        return "working-memory:sync:last-attempt:{$userId}:{$channel}:{$scope}";
    }

    private function monthlyUsageCacheKey(int $userId, string $channel, string $month): string
    {
        return "working-memory:sync:monthly-usage:{$userId}:{$channel}:{$month}";
    }
}
