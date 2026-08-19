<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnsureJobSearchEnabledTest extends TestCase
{
    #[Test]
    public function test_route_behind_job_search_middleware_404s_when_flag_off(): void
    {
        config(['features.job_search' => false]);
        Route::middleware('job.search')->get('/__job_search_probe', fn () => 'ok');

        $this->get('/__job_search_probe')->assertNotFound();
    }

    #[Test]
    public function test_route_behind_job_search_middleware_passes_when_flag_on(): void
    {
        config(['features.job_search' => true]);
        Route::middleware('job.search')->get('/__job_search_probe', fn () => 'ok');

        $this->get('/__job_search_probe')->assertOk();
    }
}
