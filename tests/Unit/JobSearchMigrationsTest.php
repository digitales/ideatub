<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobSearchMigrationsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_job_search_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('companies'));
        $this->assertTrue(Schema::hasColumns('companies', ['id', 'user_id', 'name', 'website', 'notes', 'research_thought_id']));

        $this->assertTrue(Schema::hasTable('job_prospects'));
        $this->assertTrue(Schema::hasColumns('job_prospects', [
            'id', 'user_id', 'company', 'role_title', 'source', 'url', 'salary_signal',
            'fit_score', 'status', 'discovered_at', 'scored_at', 'notes', 'promoted_application_id',
        ]));

        $this->assertTrue(Schema::hasTable('applications'));
        $this->assertTrue(Schema::hasColumns('applications', [
            'id', 'user_id', 'company_id', 'job_prospect_id', 'role_title', 'stage', 'source',
            'salary_min', 'salary_max', 'applied_at', 'last_activity_at', 'research_thought_id',
            'cv_markdown', 'cover_letter_markdown', 'cv_pdf_path', 'cover_letter_pdf_path',
            'cv_exported_at', 'cover_letter_exported_at',
        ]));

        $this->assertTrue(Schema::hasTable('interactions'));
        $this->assertTrue(Schema::hasColumns('interactions', [
            'id', 'user_id', 'application_id', 'type', 'occurred_at', 'summary', 'debrief_thought_id',
        ]));

        $this->assertTrue(Schema::hasTable('achievements'));
        $this->assertTrue(Schema::hasColumns('achievements', [
            'id', 'user_id', 'tag', 'bullet_text', 'times_used', 'last_used_at', 'retired_at',
        ]));
    }
}
