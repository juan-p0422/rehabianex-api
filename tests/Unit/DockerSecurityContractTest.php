<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DockerSecurityContractTest extends TestCase
{
    public function test_apache_disables_indexes_and_denies_dotfiles(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2).'/dockerfile');

        $this->assertIsString($dockerfile);
        $this->assertStringContainsString('Options -Indexes +FollowSymLinks', $dockerfile);
        $this->assertStringContainsString('<FilesMatch "^\\.">', $dockerfile);
        $this->assertStringContainsString('Require all denied', $dockerfile);
        $this->assertStringContainsString('expose_php=Off', $dockerfile);
        $this->assertStringContainsString('ServerTokens Prod', $dockerfile);
        $this->assertStringContainsString('ServerSignature Off', $dockerfile);
        $this->assertStringNotContainsString('Options Indexes FollowSymLinks', $dockerfile);
    }

    public function test_grpc_build_is_reproducible_and_retries_transient_pecl_failures(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2).'/dockerfile');

        $this->assertIsString($dockerfile);
        $this->assertStringContainsString('ARG GRPC_VERSION=1.84.0', $dockerfile);
        $this->assertStringContainsString('pecl install "grpc-${GRPC_VERSION}"', $dockerfile);
        $this->assertStringContainsString('for attempt in 1 2 3', $dockerfile);
        $this->assertStringContainsString('if [ "$attempt" -eq 3 ]', $dockerfile);
        $this->assertStringContainsString('docker-php-ext-enable grpc', $dockerfile);
    }
}
