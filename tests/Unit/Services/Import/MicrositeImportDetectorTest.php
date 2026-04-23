<?php

namespace Tests\Unit\Services\Import;

use App\Services\Import\MicrositeImportDetector;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MicrositeImportDetectorTest extends TestCase
{
    public function test_should_use_microsite_is_true_for_two_numbered_mds_with_unique_basename(): void
    {
        $a = UploadedFile::fake()->create('00-a.md', 8, 'text/plain');
        $b = UploadedFile::fake()->create('01-b.md', 8, 'text/plain');
        $this->assertTrue(MicrositeImportDetector::shouldUseMicrosite(
            ['docs/00-a.md', 'docs/01-b.md'],
            [$a, $b],
        ));
    }

    public function test_should_use_microsite_is_false_when_mixed_with_txt(): void
    {
        $md = UploadedFile::fake()->create('00-a.md', 8, 'text/plain');
        $txt = UploadedFile::fake()->create('notes.txt', 4, 'text/plain');
        $this->assertFalse(MicrositeImportDetector::shouldUseMicrosite(
            ['f/00-a.md', 'f/notes.txt'],
            [$md, $txt],
        ));
    }

    public function test_should_use_microsite_is_false_for_single_file(): void
    {
        $a = UploadedFile::fake()->create('00-a.md', 8, 'text/plain');
        $this->assertFalse(MicrositeImportDetector::shouldUseMicrosite(
            ['f/00-a.md'],
            [$a],
        ));
    }

    public function test_has_duplicate_page_path_segments_is_true_for_same_basename_different_folders(): void
    {
        $a = UploadedFile::fake()->create('00-a.md', 8, 'text/plain');
        $b = UploadedFile::fake()->create('00-a.md', 6, 'text/plain');
        $this->assertTrue(MicrositeImportDetector::hasDuplicatePagePathSegments(
            ['x/00-a.md', 'y/00-a.md'],
            [$a, $b],
        ));
    }
}
