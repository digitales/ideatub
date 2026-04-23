<?php

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportBatchesTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_batches_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('import_batches'));
        foreach ([
            'id', 'user_id', 'project_id', 'root_folder_name', 'source',
            'status', 'file_count', 'total_bytes',
            'processed_count', 'failed_count', 'skipped_count',
            'no_chunking', 'skip_ai_metadata', 'options',
            'staging_path', 'laravel_batch_id',
            'completion_notified_at',
            'created_at', 'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('import_batches', $col),
                "import_batches missing {$col}"
            );
        }
    }

    public function test_import_batch_files_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('import_batch_files'));
        foreach ([
            'id', 'import_batch_id', 'relative_path', 'original_filename',
            'size_bytes', 'sha256', 'status', 'thought_id',
            'error_code', 'error_message', 'attempts', 'processed_at',
            'created_at', 'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('import_batch_files', $col),
                "import_batch_files missing {$col}"
            );
        }
    }
}
