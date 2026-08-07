<?php

namespace Tests\Unit;

use App\Support\AdminPasswordPolicy;
use PHPUnit\Framework\TestCase;

class AdminPasswordPolicyTest extends TestCase
{
    public function test_known_temporary_password_from_request_is_rejected(): void
    {
        $this->assertFalse(AdminPasswordPolicy::passes('Oswaldo222003'));
    }

    public function test_password_requires_every_character_class(): void
    {
        $this->assertFalse(AdminPasswordPolicy::passes('onlylowercase123!'));
        $this->assertFalse(AdminPasswordPolicy::passes('ONLYUPPERCASE123!'));
        $this->assertFalse(AdminPasswordPolicy::passes('NoNumbersHere!!'));
        $this->assertFalse(AdminPasswordPolicy::passes('NoSymbolsHere123'));
        $this->assertFalse(AdminPasswordPolicy::passes('Short1!'));
        $this->assertFalse(AdminPasswordPolicy::passes('Has Space 123!Aa'));
    }

    public function test_strong_temporary_password_is_accepted(): void
    {
        $this->assertTrue(AdminPasswordPolicy::passes('LocalAdmin-QA-4937!'));
    }
}
