<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_web_response_includes_x_frame_options(): void
    {
        $response = $this->get('/welcome');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_web_response_includes_x_content_type_options(): void
    {
        $response = $this->get('/welcome');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_web_response_includes_hsts(): void
    {
        $response = $this->get('/welcome');
        $response->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
    }
}
