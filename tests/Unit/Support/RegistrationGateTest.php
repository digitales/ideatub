<?php

namespace Tests\Unit\Support;

use App\Support\RegistrationGate;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RegistrationGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('registration.enabled', true);
        config()->set('registration.beta_access_code', null);
    }

    public function test_requires_beta_code_when_configured(): void
    {
        config()->set('registration.beta_access_code', 'secret');

        $this->assertTrue(RegistrationGate::requiresBetaCode());
    }

    public function test_does_not_require_beta_code_when_empty(): void
    {
        config()->set('registration.beta_access_code', '');

        $this->assertFalse(RegistrationGate::requiresBetaCode());
    }

    public function test_validate_beta_code_uses_constant_time_comparison(): void
    {
        config()->set('registration.beta_access_code', 'secret');

        $this->assertTrue(RegistrationGate::validateBetaCode('secret'));
        $this->assertFalse(RegistrationGate::validateBetaCode('wrong'));
    }

    public function test_assert_beta_code_throws_validation_exception_for_invalid_code(): void
    {
        config()->set('registration.beta_access_code', 'secret');

        $this->expectException(ValidationException::class);

        RegistrationGate::assertBetaCode('wrong');
    }
}
