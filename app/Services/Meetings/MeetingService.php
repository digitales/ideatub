<?php

namespace App\Services\Meetings;

use App\Jobs\ProcessMeetingRun;
use App\Models\MeetingRun;
use App\Models\MeetingSkill;
use App\Models\MeetingSkillVersion;
use App\Models\Thought;
use App\Models\User;
use App\Services\ThoughtCaptureService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MeetingService
{
    private const DEFAULT_MAX_ACTIVE_RUNS_PER_USER = 25;

    public function __construct(
        private MeetingSkillManager $meetingSkillManager,
        private ThoughtCaptureService $captureService,
    ) {}

    public function hasEligibleDefaultAutoRunSkillForUser(User $user): bool
    {
        return MeetingSkill::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('is_manual_enabled', true)
            ->where('is_default', true)
            ->where('allow_auto_run', true)
            ->whereHas('latestVersion')
            ->exists();
    }

    /**
     * Create a meeting thought from plain text content, then queue processing.
     */
    public function createMeetingAndQueueRun(
        User $user,
        string $content,
        string $source = 'mcp',
        ?int $meetingSkillId = null,
        bool $forceRerun = false,
        ?string $planSlug = null,
    ): MeetingRun {
        $result = $this->captureService->create([
            'content' => $content,
            'user_id' => $user->id,
            'source' => 'meeting',
            'source_metadata' => ['ingested_via' => $source],
            'doc_type' => 'meeting',
            'plan_slug' => $planSlug,
            'no_chunking' => true,
        ]);

        $meetingThought = $result['thought'] ?? $result['root'] ?? null;
        if (! $meetingThought instanceof Thought) {
            throw new \RuntimeException('Meeting capture failed.');
        }

        return $this->queueMeetingRunForThought($meetingThought, $source, $meetingSkillId, $forceRerun);
    }

    /**
     * Create or reuse a meeting run for this root meeting thought and queue execution.
     */
    public function queueMeetingRunForThought(
        Thought $meeting,
        string $source = 'web',
        ?int $meetingSkillId = null,
        bool $forceRerun = false
    ): MeetingRun {
        return DB::transaction(function () use ($meeting, $source, $meetingSkillId, $forceRerun): MeetingRun {
            $version = $this->resolveManualMeetingSkillVersionForThought($meeting, $meetingSkillId);

            if (! $forceRerun) {
                $existing = MeetingRun::query()
                    ->where('meeting_thought_id', $meeting->id)
                    ->where('meeting_skill_id', $version->meeting_skill_id)
                    ->whereIn('status', ['queued', 'running'])
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $this->guardUserActiveRunLimit((int) $meeting->user_id);

            $run = MeetingRun::query()->create([
                'user_id' => $meeting->user_id,
                'meeting_thought_id' => $meeting->id,
                'meeting_skill_id' => $version->meeting_skill_id,
                'meeting_skill_version_id' => $version->id,
                'source' => $source,
                'status' => 'queued',
                'workflow_type_snapshot' => $version->workflow_type,
                'context_options_snapshot' => $version->context_options,
                'output_shape_snapshot' => $version->output_shape,
                'core_categories_snapshot' => $version->core_categories,
                'custom_categories_snapshot' => $version->custom_categories,
                'intensity_snapshot' => $version->intensity,
                'current_stage' => 0,
                'total_stages' => 1,
                'usage_metadata' => null,
                'final_meeting_thought_id' => null,
                'error_summary' => null,
            ]);

            $runId = $run->id;
            DB::afterCommit(function () use ($runId): void {
                ProcessMeetingRun::dispatch($runId);
            });

            return $run;
        });
    }

    /**
     * Queue processing only when user has an eligible default skill.
     */
    public function queueAutoRunForMeetingThought(Thought $meeting, string $source = 'web'): ?MeetingRun
    {
        $user = User::query()->find($meeting->user_id);
        if (! $user instanceof User) {
            return null;
        }

        if (! $this->hasEligibleDefaultAutoRunSkillForUser($user)) {
            return null;
        }

        return $this->queueMeetingRunForThought($meeting, $source, null, false);
    }

    /**
     * Persist a completed meeting analysis body linked as a child thought.
     *
     * @param  array<string, mixed>  $normalizedPayload
     */
    public function saveRunResult(MeetingRun $run, string $analysisText, array $normalizedPayload = []): Thought
    {
        $run->loadMissing('meetingThought');

        return $this->persistAnalysisForMeeting(
            meeting: $run->meetingThought,
            run: $run,
            analysisText: $analysisText,
            normalizedPayload: $normalizedPayload,
        );
    }

    private function guardUserActiveRunLimit(int $userId): void
    {
        $limit = (int) config('research.max_active_runs_per_user', self::DEFAULT_MAX_ACTIVE_RUNS_PER_USER);
        if ($limit < 1) {
            return;
        }

        $activeRunCount = MeetingRun::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['queued', 'running'])
            ->select('id')
            ->lockForUpdate()
            ->limit($limit)
            ->get()
            ->count();

        if ($activeRunCount >= $limit) {
            throw new \RuntimeException("Active meeting run limit reached ({$limit}).");
        }
    }

    private function resolveManualMeetingSkillVersionForThought(Thought $meeting, ?int $meetingSkillId = null): MeetingSkillVersion
    {
        $user = User::query()->findOrFail($meeting->user_id);

        if ($meetingSkillId !== null) {
            $requestedSkill = MeetingSkill::query()
                ->whereKey($meetingSkillId)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->where('is_manual_enabled', true)
                ->first();

            if ($requestedSkill === null) {
                throw new InvalidArgumentException('Requested meeting skill is not available.');
            }

            $requestedVersion = $requestedSkill->latestVersion;
            if (! $requestedVersion instanceof MeetingSkillVersion) {
                throw new \RuntimeException('Requested meeting skill has no version.');
            }

            return $requestedVersion;
        }

        $skill = MeetingSkill::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('is_manual_enabled', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($skill !== null) {
            $version = $skill->latestVersion;
            if ($version instanceof MeetingSkillVersion) {
                return $version;
            }
        }

        $skill = $this->meetingSkillManager->create($user, [
            'name' => 'Default meeting',
            'is_default' => true,
            'is_manual_enabled' => true,
            'allow_auto_run' => true,
            'core_categories' => MeetingSkillManager::DEFAULT_CORE_CATEGORIES,
        ]);

        $version = $skill->latestVersion;
        if (! $version instanceof MeetingSkillVersion) {
            throw new \RuntimeException('Meeting skill was created without a version.');
        }

        return $version;
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     */
    private function persistAnalysisForMeeting(Thought $meeting, MeetingRun $run, string $analysisText, array $normalizedPayload = []): Thought
    {
        $meetingTags = [];
        $rawTags = $meeting->metadata['tags'] ?? [];
        if (is_array($rawTags)) {
            foreach ($rawTags as $tag) {
                if (! is_string($tag)) {
                    continue;
                }

                $normalized = trim(mb_strtolower($tag));
                if ($normalized === '') {
                    continue;
                }

                if (str_starts_with($normalized, 'meeting:')) {
                    $meetingTags[] = $normalized;
                }
            }
        }

        $analysisTags = ['meeting_analysis'];
        foreach ($meetingTags as $tag) {
            $slug = mb_substr($tag, strlen('meeting:'));
            if ($slug !== '') {
                $analysisTags[] = 'meeting_analysis:'.$slug;
                $analysisTags[] = 'meeting:'.$slug;
            }
        }

        $metadata = Thought::normalizeMetadataTags([
            'type' => 'meeting_analysis',
            'meeting_id' => $meeting->id,
            'meeting_run_id' => $run->id,
            'tags' => array_values(array_unique($analysisTags)),
            'summary' => $normalizedPayload['summary'] ?? null,
            'core_categories' => $normalizedPayload['core_categories'] ?? null,
            'custom_sections' => $normalizedPayload['custom_sections'] ?? null,
            'requested_sections' => $normalizedPayload['requested_sections'] ?? null,
        ]);

        return Thought::create([
            'content' => $analysisText,
            'embedding' => null,
            'metadata' => $metadata,
            'user_id' => $meeting->user_id,
            'source' => 'meeting',
            'source_metadata' => [
                'meeting_id' => $meeting->id,
                'meeting_run_id' => $run->id,
                'doc_type' => 'meeting',
            ],
            'parent_id' => $meeting->id,
        ]);
    }
}
