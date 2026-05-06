<?php

namespace App\Services\WorkingMemory;

use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Str;

class ForcedTagResolver
{
    /**
     * @return array<int, string>
     */
    public function forUserId(int $userId): array
    {
        $user = User::query()->find($userId);
        if ($user === null) {
            return [];
        }

        $raw = UserPreference::get($user, UserPreference::KEY_WORKING_MEMORY_FORCED_TAGS);

        return $this->normalizeTags($raw);
    }

    /**
     * @param  array<int, mixed>|string|null  $raw
     * @return array<int, string>
     */
    public function normalizeTags(array|string|null $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $values = is_array($raw)
            ? $raw
            : $this->stringToTagCandidates($raw);

        $normalized = [];
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $tag = Str::of((string) $value)->trim()->lower()->toString();
            if ($tag === '') {
                continue;
            }

            $normalized[$tag] = $tag;
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, mixed>
     */
    private function stringToTagCandidates(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        if (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
            $decoded = $this->decodeArrayString($trimmed);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        return preg_split('/[\r\n,]+/', $trimmed) ?: [];
    }

    /**
     * @return array<int, mixed>|null
     */
    private function decodeArrayString(string $raw): ?array
    {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $singleQuoted = preg_replace("/'([^'\\\\]*(?:\\\\.[^'\\\\]*)*)'/", '"$1"', $raw);
        if (! is_string($singleQuoted)) {
            return null;
        }

        $decodedSingleQuoted = json_decode($singleQuoted, true);

        return is_array($decodedSingleQuoted) ? $decodedSingleQuoted : null;
    }
}
