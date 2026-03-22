<?php

namespace Tests\Unit\Support;

use App\Models\Thought;
use App\Support\ThoughtTypeNavigation;
use Tests\TestCase;

class ThoughtTypeNavigationTest extends TestCase
{
    public function test_ordered_nav_types_match_spec(): void
    {
        $this->assertSame(['jira', 'email', 'research', 'plan'], ThoughtTypeNavigation::orderedNavTypes());
    }

    public function test_collection_labels(): void
    {
        $this->assertSame('Jira', ThoughtTypeNavigation::collectionLabel('jira'));
        $this->assertSame('Emails', ThoughtTypeNavigation::collectionLabel('email'));
        $this->assertSame('Research', ThoughtTypeNavigation::collectionLabel('research'));
        $this->assertSame('Plans', ThoughtTypeNavigation::collectionLabel('plan'));
    }

    public function test_thought_display_labels(): void
    {
        $this->assertSame('Jira', ThoughtTypeNavigation::thoughtDisplayLabel('jira'));
        $this->assertSame('Email', ThoughtTypeNavigation::thoughtDisplayLabel('email'));
        $this->assertSame('Research', ThoughtTypeNavigation::thoughtDisplayLabel('research'));
        $this->assertSame('Plan', ThoughtTypeNavigation::thoughtDisplayLabel('plan'));
    }

    public function test_aliases_normalize(): void
    {
        $this->assertSame('email', ThoughtTypeNavigation::normalizeTypeKey('emails'));
        $this->assertSame('plan', ThoughtTypeNavigation::normalizeTypeKey('plans'));
        $this->assertSame('email', ThoughtTypeNavigation::normalizeTypeKey('EMAIL'));
        $this->assertSame('plan', ThoughtTypeNavigation::normalizeTypeKey(' PlanS '));
    }

    public function test_stored_values_for_collection_are_shared_with_normalization(): void
    {
        $this->assertSame(['email', 'emails'], ThoughtTypeNavigation::storedValuesForCollection('EMAIL'));
        $this->assertSame(['plan', 'plans'], ThoughtTypeNavigation::storedValuesForCollection('plans'));
    }

    public function test_jira_availability_follows_config(): void
    {
        config(['services.jira.enabled' => true]);
        $this->assertTrue(ThoughtTypeNavigation::isAvailable('jira'));

        config(['services.jira.enabled' => false]);
        $this->assertFalse(ThoughtTypeNavigation::isAvailable('jira'));
    }

    public function test_non_jira_types_remain_available_when_jira_disabled(): void
    {
        config(['services.jira.enabled' => false]);
        $this->assertTrue(ThoughtTypeNavigation::isAvailable('email'));
        $this->assertTrue(ThoughtTypeNavigation::isAvailable('research'));
        $this->assertTrue(ThoughtTypeNavigation::isAvailable('plan'));
    }

    public function test_route_names_per_type(): void
    {
        $this->assertSame('idea.stream.jira', ThoughtTypeNavigation::routeName('jira'));
        $this->assertSame('idea.stream.emails', ThoughtTypeNavigation::routeName('email'));
        $this->assertSame('idea.stream.research', ThoughtTypeNavigation::routeName('research'));
        $this->assertSame('idea.stream.plans', ThoughtTypeNavigation::routeName('plan'));
    }

    public function test_resolve_thought_to_type_key_from_source_and_metadata(): void
    {
        $jira = new Thought(['source' => 'jira', 'metadata' => null]);
        $this->assertSame('jira', ThoughtTypeNavigation::resolveThoughtToTypeKey($jira));

        $email = new Thought(['source' => 'email', 'metadata' => ['type' => 'research']]);
        $this->assertSame('email', ThoughtTypeNavigation::resolveThoughtToTypeKey($email));

        $research = new Thought(['source' => 'web', 'metadata' => ['type' => 'research']]);
        $this->assertSame('research', ThoughtTypeNavigation::resolveThoughtToTypeKey($research));

        $plan = new Thought(['source' => 'web', 'metadata' => ['type' => 'plan']]);
        $this->assertSame('plan', ThoughtTypeNavigation::resolveThoughtToTypeKey($plan));
    }

    public function test_resolve_normalizes_metadata_type_aliases(): void
    {
        $t = new Thought(['source' => 'web', 'metadata' => ['type' => 'plans']]);
        $this->assertSame('plan', ThoughtTypeNavigation::resolveThoughtToTypeKey($t));
    }

    public function test_resolve_thought_handles_null_metadata_without_throwing(): void
    {
        $t = new Thought(['source' => 'web', 'metadata' => null]);
        $this->assertNull(ThoughtTypeNavigation::resolveThoughtToTypeKey($t));
    }
}
