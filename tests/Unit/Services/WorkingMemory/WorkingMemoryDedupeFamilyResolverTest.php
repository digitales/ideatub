<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Services\WorkingMemory\WorkingMemoryDedupeFamilyResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryDedupeFamilyResolverTest extends TestCase
{
    #[Test]
    public function it_detects_wm_capture_from_working_memory_tag(): void
    {
        $resolver = app(WorkingMemoryDedupeFamilyResolver::class);
        $this->assertTrue($resolver->isWorkingMemoryCapture(
            planSlug: 'client-working-memory-2026-05-19',
            extraTags: ['working-memory', 'client:dezeen'],
            project: 'dezeen',
        ));
    }

    #[Test]
    public function it_detects_wm_capture_from_plan_slug_prefix(): void
    {
        $resolver = app(WorkingMemoryDedupeFamilyResolver::class);
        $this->assertTrue($resolver->isWorkingMemoryCapture(
            planSlug: 'client-working-memory-2026-05-19',
            extraTags: [],
            project: 'dezeen',
        ));
    }

    #[Test]
    public function it_builds_client_family_from_client_tag(): void
    {
        $resolver = app(WorkingMemoryDedupeFamilyResolver::class);
        $this->assertSame(
            'wm:client:dezeen',
            $resolver->resolveForCapture(
                planSlug: 'client-working-memory-2026-05-19',
                extraTags: ['working-memory', 'client:dezeen', 'scope:client'],
                project: 'dezeen',
            )
        );
    }

    #[Test]
    public function it_builds_project_family_from_project_metadata(): void
    {
        $resolver = app(WorkingMemoryDedupeFamilyResolver::class);
        $this->assertSame(
            'wm:project:dezeen/my-app',
            $resolver->resolveForCapture(
                planSlug: 'project-working-memory-2026-05-19',
                extraTags: ['working-memory', 'project:my-app', 'client:dezeen'],
                project: 'dezeen/my-app',
            )
        );
    }

    #[Test]
    public function it_builds_upsert_family_from_scope(): void
    {
        $resolver = app(WorkingMemoryDedupeFamilyResolver::class);
        $this->assertSame(
            'wm:project:019e0705-5591-73e9-be2e-0fb9c86b269a',
            $resolver->resolveForUpsert('project', '019E0705-5591-73E9-BE2E-0FB9C86B269A')
        );
    }
}
