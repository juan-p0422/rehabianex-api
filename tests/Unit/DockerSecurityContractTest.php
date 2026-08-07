<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DockerSecurityContractTest extends TestCase
{
    public function test_apache_disables_indexes_and_denies_dotfiles(): void
    {
        $dockerfile = file_get_contents(base_path('dockerfile'));

        $this->assertIsString($dockerfile);
        $this->assertStringContainsString('Options -Indexes +FollowSymLinks', $dockerfile);
        $this->assertStringContainsString('<FilesMatch "^\\.">', $dockerfile);
        $this->assertStringContainsString('Require all denied', $dockerfile);
        $this->assertStringContainsString('expose_php=Off', $dockerfile);
        $this->assertStringContainsString('ServerTokens Prod', $dockerfile);
        $this->assertStringContainsString('ServerSignature Off', $dockerfile);
        $this->assertStringNotContainsString('Options Indexes FollowSymLinks', $dockerfile);
    }
}
