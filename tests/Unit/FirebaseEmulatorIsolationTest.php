<?php

namespace Tests\Unit;

use App\Services\FirebaseService;
use Google\Auth\FetchAuthTokenInterface;
use Illuminate\Support\Facades\Config;
use Kreait\Firebase\Factory;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class FirebaseEmulatorIsolationTest extends TestCase
{
    private string|false $previousFirestoreHost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFirestoreHost = getenv('FIRESTORE_EMULATOR_HOST');
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment('FIRESTORE_EMULATOR_HOST', $this->previousFirestoreHost);
        parent::tearDown();
    }

    public function test_emulator_mode_requires_both_loopback_hosts_and_demo_project(): void
    {
        Config::set('services.firebase.auth_emulator_host', '127.0.0.1:9199');
        putenv('FIRESTORE_EMULATOR_HOST=localhost:8180');

        $this->assertTrue($this->invokeConfigurationCheck('demo-rehabianex'));
    }

    public function test_partial_emulator_configuration_fails_closed(): void
    {
        Config::set('services.firebase.auth_emulator_host', '127.0.0.1:9199');
        putenv('FIRESTORE_EMULATOR_HOST');

        $this->expectException(RuntimeException::class);
        $this->invokeConfigurationCheck('demo-rehabianex');
    }

    public function test_non_demo_project_is_rejected_in_emulator_mode(): void
    {
        Config::set('services.firebase.auth_emulator_host', '127.0.0.1:9199');
        putenv('FIRESTORE_EMULATOR_HOST=127.0.0.1:8180');

        $this->expectException(RuntimeException::class);
        $this->invokeConfigurationCheck('production-project');
    }

    public function test_emulator_factory_uses_only_synthetic_owner_credential(): void
    {
        $previousCredentials = getenv('GOOGLE_APPLICATION_CREDENTIALS');
        putenv('GOOGLE_APPLICATION_CREDENTIALS=C:\\synthetic-test-only.json');

        try {
            $service = $this->serviceWithoutConstructor();
            $method = (new ReflectionClass(FirebaseService::class))->getMethod('emulatorFactory');
            $factory = $method->invoke($service, 'demo-rehabianex');

            $property = new ReflectionProperty(Factory::class, 'googleAuthTokenCredentials');
            $credentials = $property->getValue($factory);

            $this->assertInstanceOf(FetchAuthTokenInterface::class, $credentials);
            $this->assertSame('owner', $credentials->fetchAuthToken()['access_token']);
            $this->assertFalse(getenv('GOOGLE_APPLICATION_CREDENTIALS'));
        } finally {
            $this->restoreEnvironment('GOOGLE_APPLICATION_CREDENTIALS', $previousCredentials);
        }
    }

    private function invokeConfigurationCheck(string $projectId): bool
    {
        $service = $this->serviceWithoutConstructor();
        $method = (new ReflectionClass(FirebaseService::class))
            ->getMethod('assertSafeEmulatorConfiguration');

        return $method->invoke($service, $projectId);
    }

    private function serviceWithoutConstructor(): FirebaseService
    {
        return (new ReflectionClass(FirebaseService::class))->newInstanceWithoutConstructor();
    }

    private function restoreEnvironment(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);

            return;
        }

        putenv($name.'='.$value);
    }
}
