<?php

namespace Tests\Unit;

use App\Services\FirebaseService;
use ReflectionClass;
use Tests\TestCase;

class FirebaseServiceSecurityTest extends TestCase
{
    public function test_firebase_service_does_not_log_raw_exception_details(): void
    {
        $source = file_get_contents(app_path('Services/FirebaseService.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString("'message' => \$e->getMessage()", $source);
        $this->assertStringNotContainsString("'file' => \$e->getFile()", $source);
        $this->assertStringNotContainsString("'line' => \$e->getLine()", $source);
        $this->assertStringContainsString("'error_code' => 'FIREBASE_INITIALIZATION_FAILED'", $source);
        $this->assertStringContainsString("'exception_class' => get_class(\$e)", $source);
    }

    public function test_atomic_writer_uses_synthetic_file_and_leaves_no_temporary_file(): void
    {
        $directory = storage_path('framework/testing/firebase-security-'.uniqid());
        mkdir($directory, 0700, true);
        $path = $directory.'/credential.json';
        $contents = '{"type":"synthetic_test_only"}';
        $service = (new ReflectionClass(FirebaseService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'writeCredentialAtomically');

        try {
            $method->invoke($service, $path, $contents);

            $this->assertFileExists($path);
            $this->assertSame(hash('sha256', $contents), hash_file('sha256', $path));
            $this->assertSame([], glob($directory.'/.firebase-credential-*') ?: []);

            if (PHP_OS_FAMILY !== 'Windows') {
                $this->assertSame(0600, fileperms($path) & 0777);
            }
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
