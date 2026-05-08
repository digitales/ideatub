<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Services\WorkingMemory\WorkingMemoryLegacyRowCitationResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryLegacyRowCitationResolverTest extends TestCase
{
    #[Test]
    public function it_returns_null_for_empty_citations(): void
    {
        $resolver = new WorkingMemoryLegacyRowCitationResolver;

        $this->assertNull($resolver->resolvePrimaryThought([]));
    }

    #[Test]
    public function it_prefers_thought_type_with_thought_id(): void
    {
        $resolver = new WorkingMemoryLegacyRowCitationResolver;

        $result = $resolver->resolvePrimaryThought([
            [
                'type' => 'thought',
                'thought_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'url' => '/thoughts/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'label' => 'Note',
            ],
        ]);

        $this->assertNotNull($result);
        $this->assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $result['thought_id']);
        $this->assertSame('/thoughts/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $result['url']);
    }

    #[Test]
    public function it_extracts_uuid_from_thought_url_when_thought_id_missing(): void
    {
        $resolver = new WorkingMemoryLegacyRowCitationResolver;

        $result = $resolver->resolvePrimaryThought([
            [
                'type' => 'thought',
                'url' => '/thoughts/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'label' => 'Note',
            ],
        ]);

        $this->assertNotNull($result);
        $this->assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $result['thought_id']);
        $this->assertSame('/thoughts/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $result['url']);
    }

    #[Test]
    public function it_falls_back_to_any_url_with_thoughts_path(): void
    {
        $resolver = new WorkingMemoryLegacyRowCitationResolver;

        $result = $resolver->resolvePrimaryThought([
            [
                'type' => 'compaction',
                'url' => '/thoughts/cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                'label' => 'Compaction',
            ],
        ]);

        $this->assertNotNull($result);
        $this->assertSame('cccccccc-cccc-4ccc-8ccc-cccccccccccc', $result['thought_id']);
    }

    #[Test]
    public function it_skips_unsafe_urls(): void
    {
        $resolver = new WorkingMemoryLegacyRowCitationResolver;

        $this->assertNull($resolver->resolvePrimaryThought([
            [
                'type' => 'thought',
                'url' => 'javascript:alert(1)',
                'label' => 'X',
            ],
        ]));
    }

    #[Test]
    public function it_prefers_first_thought_citation_when_multiple_present(): void
    {
        $resolver = new WorkingMemoryLegacyRowCitationResolver;

        $result = $resolver->resolvePrimaryThought([
            [
                'type' => 'thought',
                'thought_id' => '11111111-1111-4111-8111-111111111111',
                'url' => '/thoughts/11111111-1111-4111-8111-111111111111',
                'label' => 'First',
            ],
            [
                'type' => 'thought',
                'thought_id' => '22222222-2222-4222-8222-222222222222',
                'url' => '/thoughts/22222222-2222-4222-8222-222222222222',
                'label' => 'Second',
            ],
        ]);

        $this->assertSame('11111111-1111-4111-8111-111111111111', $result['thought_id']);
    }

    #[Test]
    public function it_returns_thought_id_without_url_when_only_thought_id_present(): void
    {
        $resolver = new WorkingMemoryLegacyRowCitationResolver;

        $result = $resolver->resolvePrimaryThought([
            [
                'type' => 'thought',
                'thought_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'label' => 'Note',
            ],
        ]);

        $this->assertNotNull($result);
        $this->assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $result['thought_id']);
        $this->assertArrayNotHasKey('url', $result);
    }
}
