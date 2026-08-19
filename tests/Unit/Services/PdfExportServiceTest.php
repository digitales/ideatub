<?php

namespace Tests\Unit\Services;

use App\Models\Application;
use App\Models\User;
use App\Services\Documents\PdfExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PdfExportServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_export_writes_pdf_and_stamps_path_and_exported_at(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $application = Application::factory()->for($user)->create([
            'cv_markdown' => "# Test CV\n\n- Did a thing",
        ]);

        $service = new class extends PdfExportService {
            protected function renderPdf(string $html): string
            {
                return '%PDF-1.4 fake';
            }
        };

        $path = $service->export($application, 'cv');

        Storage::disk('local')->assertExists($path);
        $application->refresh();
        $this->assertSame($path, $application->cv_pdf_path);
        $this->assertNotNull($application->cv_exported_at);
    }

    #[Test]
    public function test_export_rejects_unknown_document_type(): void
    {
        $user = User::factory()->create();
        $application = Application::factory()->for($user)->create();

        $this->expectException(\InvalidArgumentException::class);
        (new PdfExportService)->export($application, 'resume');
    }
}
