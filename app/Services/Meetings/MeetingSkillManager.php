<?php

namespace App\Services\Meetings;

use App\Models\MeetingSkill;
use App\Models\MeetingSkillVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MeetingSkillManager
{
    public const WORKFLOW_MEETING_BRIEF = 'meeting_brief';

    /**
     * @var array<int, string>
     */
    public const DEFAULT_CORE_CATEGORIES = ['decisions', 'action_items', 'risks', 'blockers', 'follow_ups'];

    public function create(User $user, array $attributes): MeetingSkill
    {
        return DB::transaction(function () use ($user, $attributes): MeetingSkill {
            $state = $this->normaliseCreateState($attributes);
            $this->assertMeetingBriefWorkflow($state['workflow_type']);

            if ($state['is_default']) {
                $this->clearDefaultForUser($user->id, exceptSkillId: null);
            }

            $skill = MeetingSkill::query()->create([
                'user_id' => $user->id,
                'name' => $state['name'],
                'description' => $state['description'],
                'is_manual_enabled' => $state['is_manual_enabled'],
                'allow_auto_run' => $state['allow_auto_run'],
                'is_default' => $state['is_default'],
                'is_active' => $state['is_active'],
                'latest_version_number' => 0,
            ]);

            $snapshot = $this->buildVersionSnapshotFromState($state);
            $this->insertVersion($skill, 1, $snapshot);
            $skill->update(['latest_version_number' => 1]);

            return $skill->fresh();
        });
    }

    public function update(MeetingSkill $skill, array $attributes): MeetingSkill
    {
        return DB::transaction(function () use ($skill, $attributes): MeetingSkill {
            $skill->refresh();
            $latest = $this->resolveLatestVersion($skill);
            if ($latest === null) {
                throw new InvalidArgumentException('Meeting skill has no versions; cannot update.');
            }

            $merged = $this->mergeUpdateState($skill, $latest, $attributes);

            if (array_key_exists('workflow_type', $attributes)) {
                $this->assertMeetingBriefWorkflow($merged['workflow_type']);
            }

            if ($merged['is_default']) {
                $this->clearDefaultForUser($skill->user_id, exceptSkillId: $skill->id);
            }

            $skill->update([
                'name' => $merged['name'],
                'description' => $merged['description'],
                'is_manual_enabled' => $merged['is_manual_enabled'],
                'allow_auto_run' => $merged['allow_auto_run'],
                'is_default' => $merged['is_default'],
                'is_active' => $merged['is_active'],
            ]);
            $skill->refresh();

            $candidate = $this->buildVersionSnapshotFromState($merged);

            if ($this->versionSemanticsDiffer($latest, $candidate)) {
                $next = $latest->version + 1;
                $this->insertVersion($skill, $next, $candidate);
                $skill->update(['latest_version_number' => $next]);
            }

            return $skill->fresh();
        });
    }

    public function latestVersion(MeetingSkill $skill): ?MeetingSkillVersion
    {
        return $this->resolveLatestVersion($skill);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normaliseCreateState(array $attributes): array
    {
        return [
            'name' => (string) ($attributes['name'] ?? ''),
            'description' => (string) ($attributes['description'] ?? ''),
            'workflow_type' => (string) ($attributes['workflow_type'] ?? self::WORKFLOW_MEETING_BRIEF),
            'instructions' => (string) ($attributes['instructions'] ?? ''),
            'context_options' => $attributes['context_options'] ?? null,
            'output_shape' => $attributes['output_shape'] ?? null,
            'core_categories' => $this->normalizeStringList($attributes['core_categories'] ?? self::DEFAULT_CORE_CATEGORIES, self::DEFAULT_CORE_CATEGORIES),
            'custom_categories' => $this->normalizeStringList($attributes['custom_categories'] ?? [], []),
            'intensity' => (string) ($attributes['intensity'] ?? 'standard'),
            'is_manual_enabled' => (bool) ($attributes['is_manual_enabled'] ?? true),
            'allow_auto_run' => (bool) ($attributes['allow_auto_run'] ?? false),
            'is_default' => (bool) ($attributes['is_default'] ?? false),
            'is_active' => (bool) ($attributes['is_active'] ?? true),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function mergeUpdateState(MeetingSkill $skill, MeetingSkillVersion $latest, array $attributes): array
    {
        return [
            'name' => array_key_exists('name', $attributes) ? (string) $attributes['name'] : $skill->name,
            'description' => array_key_exists('description', $attributes) ? (string) $attributes['description'] : $skill->description,
            'is_manual_enabled' => array_key_exists('is_manual_enabled', $attributes) ? (bool) $attributes['is_manual_enabled'] : $skill->is_manual_enabled,
            'allow_auto_run' => array_key_exists('allow_auto_run', $attributes) ? (bool) $attributes['allow_auto_run'] : $skill->allow_auto_run,
            'is_default' => array_key_exists('is_default', $attributes) ? (bool) $attributes['is_default'] : $skill->is_default,
            'is_active' => array_key_exists('is_active', $attributes) ? (bool) $attributes['is_active'] : $skill->is_active,
            'workflow_type' => array_key_exists('workflow_type', $attributes) ? (string) $attributes['workflow_type'] : $latest->workflow_type,
            'instructions' => array_key_exists('instructions', $attributes) ? (string) $attributes['instructions'] : $latest->instructions,
            'context_options' => array_key_exists('context_options', $attributes) ? $attributes['context_options'] : $latest->context_options,
            'output_shape' => array_key_exists('output_shape', $attributes) ? $attributes['output_shape'] : $latest->output_shape,
            'core_categories' => array_key_exists('core_categories', $attributes)
                ? $this->normalizeStringList($attributes['core_categories'], self::DEFAULT_CORE_CATEGORIES)
                : $this->normalizeStringList($latest->core_categories, self::DEFAULT_CORE_CATEGORIES),
            'custom_categories' => array_key_exists('custom_categories', $attributes)
                ? $this->normalizeStringList($attributes['custom_categories'], [])
                : $this->normalizeStringList($latest->custom_categories, []),
            'intensity' => array_key_exists('intensity', $attributes) ? (string) $attributes['intensity'] : $latest->intensity,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{workflow_type: string, instructions: string, context_options: mixed, output_shape: mixed, core_categories: array<int, string>, custom_categories: array<int, string>, intensity: string, is_auto_run_eligible: bool}
     */
    private function buildVersionSnapshotFromState(array $state): array
    {
        $workflowType = (string) $state['workflow_type'];

        return [
            'workflow_type' => $workflowType,
            'instructions' => (string) $state['instructions'],
            'context_options' => $state['context_options'] ?? null,
            'output_shape' => $state['output_shape'] ?? null,
            'core_categories' => $this->normalizeStringList($state['core_categories'] ?? self::DEFAULT_CORE_CATEGORIES, self::DEFAULT_CORE_CATEGORIES),
            'custom_categories' => $this->normalizeStringList($state['custom_categories'] ?? [], []),
            'intensity' => (string) $state['intensity'],
            'is_auto_run_eligible' => $this->computeAutoRunEligible(
                allowAutoRun: (bool) $state['allow_auto_run'],
                isActive: (bool) $state['is_active'],
                workflowType: $workflowType,
            ),
        ];
    }

    /**
     * @param  array{workflow_type: string, instructions: string, context_options: mixed, output_shape: mixed, core_categories: array<int, string>, custom_categories: array<int, string>, intensity: string, is_auto_run_eligible: bool}  $snapshot
     */
    private function insertVersion(MeetingSkill $skill, int $versionNumber, array $snapshot): MeetingSkillVersion
    {
        return MeetingSkillVersion::query()->create([
            'meeting_skill_id' => $skill->id,
            'version' => $versionNumber,
            'workflow_type' => $snapshot['workflow_type'],
            'instructions' => $snapshot['instructions'],
            'context_options' => $snapshot['context_options'],
            'output_shape' => $snapshot['output_shape'],
            'core_categories' => $snapshot['core_categories'],
            'custom_categories' => $snapshot['custom_categories'],
            'intensity' => $snapshot['intensity'],
            'is_auto_run_eligible' => $snapshot['is_auto_run_eligible'],
        ]);
    }

    /**
     * @param  array{workflow_type: string, instructions: string, context_options: mixed, output_shape: mixed, core_categories: array<int, string>, custom_categories: array<int, string>, intensity: string, is_auto_run_eligible: bool}  $candidate
     */
    private function versionSemanticsDiffer(MeetingSkillVersion $latest, array $candidate): bool
    {
        if ($latest->workflow_type !== $candidate['workflow_type']) {
            return true;
        }

        if ($latest->instructions !== $candidate['instructions']) {
            return true;
        }

        if ($latest->intensity !== $candidate['intensity']) {
            return true;
        }

        if (! $this->jsonValuesEqual($latest->context_options, $candidate['context_options'])) {
            return true;
        }

        if (! $this->jsonValuesEqual($latest->output_shape, $candidate['output_shape'])) {
            return true;
        }

        if (! $this->jsonValuesEqual($latest->core_categories, $candidate['core_categories'])) {
            return true;
        }

        if (! $this->jsonValuesEqual($latest->custom_categories, $candidate['custom_categories'])) {
            return true;
        }

        if ((bool) $latest->is_auto_run_eligible !== $candidate['is_auto_run_eligible']) {
            return true;
        }

        return false;
    }

    private function jsonValuesEqual(mixed $a, mixed $b): bool
    {
        return json_encode($a) === json_encode($b);
    }

    private function computeAutoRunEligible(bool $allowAutoRun, bool $isActive, string $workflowType): bool
    {
        return $allowAutoRun
            && $isActive
            && $workflowType === self::WORKFLOW_MEETING_BRIEF;
    }

    private function assertMeetingBriefWorkflow(string $workflowType): void
    {
        if ($workflowType !== self::WORKFLOW_MEETING_BRIEF) {
            throw new InvalidArgumentException(
                sprintf('Unsupported workflow_type %s; only %s is allowed.', $workflowType, self::WORKFLOW_MEETING_BRIEF)
            );
        }
    }

    private function clearDefaultForUser(int $userId, ?int $exceptSkillId): void
    {
        $query = MeetingSkill::query()->where('user_id', $userId)->where('is_default', true);

        if ($exceptSkillId !== null) {
            $query->where('id', '!=', $exceptSkillId);
        }

        $query->update(['is_default' => false]);
    }

    private function resolveLatestVersion(MeetingSkill $skill): ?MeetingSkillVersion
    {
        return MeetingSkillVersion::query()
            ->where('meeting_skill_id', $skill->id)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @param  array<int, string>  $fallback
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value, array $fallback): array
    {
        if (! is_array($value)) {
            return $fallback;
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $normalized = trim($item);
            if ($normalized === '') {
                continue;
            }

            $out[$normalized] = $normalized;
        }

        $normalized = array_values($out);

        return $normalized !== [] ? $normalized : $fallback;
    }
}
