<?php

namespace Tests\Unit\Support;

use App\Support\SharedResearch\DocumentShareReturnTo;
use Tests\TestCase;

class DocumentShareReturnToTest extends TestCase
{
    public function test_accepts_thought_detail_path(): void
    {
        $id = '00000000-0000-4000-8000-000000000001';
        $url = url('/thoughts/'.$id);

        $this->assertSame($url, DocumentShareReturnTo::resolve($url));
    }

    public function test_accepts_stream_paths(): void
    {
        foreach (['/stream', '/stream/plans', '/stream/meetings', '/stream/research'] as $path) {
            $url = url($path);
            $this->assertSame($url, DocumentShareReturnTo::resolve($url), $path);
        }
    }

    public function test_accepts_home_index(): void
    {
        $url = url('/');

        $this->assertSame($url, DocumentShareReturnTo::resolve($url));
    }

    public function test_rejects_external_url(): void
    {
        $this->assertNull(DocumentShareReturnTo::resolve('https://evil.example/thoughts/x'));
    }

    public function test_rejects_disallowed_path(): void
    {
        $this->assertNull(DocumentShareReturnTo::resolve(url('/login')));
    }

    public function test_null_and_empty_return_null(): void
    {
        $this->assertNull(DocumentShareReturnTo::resolve(null));
        $this->assertNull(DocumentShareReturnTo::resolve(''));
    }
}
