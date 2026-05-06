<?php

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FeaturesConfigTest extends TestCase
{
    public function test_file_upload_config_key_is_registered(): void
    {
        // The key must resolve (not null). The actual value depends on the
        // environment; CI sets FEATURE_FILE_UPLOAD=true (see Task 20) because
        // routes register based on this flag.
        $this->assertNotNull(config('features.file_upload'));
    }

    public function test_file_upload_feature_flag_can_be_toggled_at_runtime(): void
    {
        config()->set('features.file_upload', true);
        $this->assertTrue(config('features.file_upload'));

        config()->set('features.file_upload', false);
        $this->assertFalse(config('features.file_upload'));
    }

    #[Test]
    public function working_memory_feature_keys_exist(): void
    {
        $this->assertIsBool(config('features.working_memory_ui'));
        $this->assertIsBool(config('features.working_memory_insights'));
    }
}
