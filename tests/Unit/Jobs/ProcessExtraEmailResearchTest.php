<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessExtraEmailResearch;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessExtraEmailResearchTest extends TestCase
{
    #[Test]
    public function constructor_requires_exactly_one_stored_email_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one of importedEmailId or capturedInboundEmailId must be set.');

        new ProcessExtraEmailResearch;
    }

    #[Test]
    public function constructor_rejects_both_identifier_types_at_once(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one of importedEmailId or capturedInboundEmailId must be set.');

        new ProcessExtraEmailResearch(importedEmailId: 123, capturedInboundEmailId: 456);
    }
}
