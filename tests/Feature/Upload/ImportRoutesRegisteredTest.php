<?php

namespace Tests\Feature\Upload;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ImportRoutesRegisteredTest extends TestCase
{
    public function test_import_routes_exist_when_feature_enabled(): void
    {
        config()->set('features.file_upload', true);
        $this->assertTrue(Route::has('imports.quick'));
        $this->assertTrue(Route::has('imports.batch'));
        $this->assertTrue(Route::has('imports.show'));
        $this->assertTrue(Route::has('imports.status'));
        $this->assertTrue(Route::has('imports.cancel'));
        $this->assertTrue(Route::has('imports.retry-failed'));
        $this->assertTrue(Route::has('imports.thoughts.destroy'));
    }
}
