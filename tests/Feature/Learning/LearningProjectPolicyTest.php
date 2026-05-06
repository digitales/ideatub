<?php

namespace Tests\Feature\Learning;

use App\Models\LearningProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class LearningProjectPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_learning_project(): void
    {
        $owner = User::factory()->create();
        $learningProject = LearningProject::query()->create([
            'user_id' => $owner->id,
            'slug' => 'policy-owner-view',
            'title' => 'Policy Owner View',
            'content_root' => storage_path('framework/testing/learning-owner-view'),
            'source_url' => 'https://example.test/learning-owner-view',
        ]);

        $this->assertTrue(Gate::forUser($owner)->allows('view', $learningProject));
    }

    public function test_non_owner_is_forbidden_from_viewing_learning_project(): void
    {
        $owner = User::factory()->create();
        $nonOwner = User::factory()->create();
        $learningProject = LearningProject::query()->create([
            'user_id' => $owner->id,
            'slug' => 'policy-non-owner-view',
            'title' => 'Policy Non Owner View',
            'content_root' => storage_path('framework/testing/learning-non-owner-view'),
            'source_url' => 'https://example.test/learning-non-owner-view',
        ]);

        $this->assertFalse(Gate::forUser($nonOwner)->allows('view', $learningProject));
    }
}
