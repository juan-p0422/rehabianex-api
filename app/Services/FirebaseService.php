<?php

namespace App\Services;

use Google\Auth\FetchAuthTokenInterface;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use ReflectionProperty;
use RuntimeException;
use Throwable;

class FirebaseService
{
    protected $firestore;

    protected $auth;

    public function __construct()
    {
        try {
            $projectId = $this->setting('project_id');
            $emulatorMode = $this->assertSafeEmulatorConfiguration($projectId ?? '');
            $credentialsPath = $emulatorMode ? null : $this->resolveCredentialsPath();

            if (! $projectId) {
                throw new RuntimeException('FIREBASE_PROJECT_ID no está configurado.');
            }

            if (! $emulatorMode && (! $credentialsPath || ! file_exists($credentialsPath))) {
                throw new RuntimeException('No se encontró una credencial Firebase utilizable.');
            }

            if (! $emulatorMode) {
                $grpcRootsPath = $this->setting('grpc_default_ssl_roots_file_path');

                if ($grpcRootsPath) {
                    putenv('GRPC_DEFAULT_SSL_ROOTS_FILE_PATH='.$grpcRootsPath);
                    $_ENV['GRPC_DEFAULT_SSL_ROOTS_FILE_PATH'] = $grpcRootsPath;
                    $_SERVER['GRPC_DEFAULT_SSL_ROOTS_FILE_PATH'] = $grpcRootsPath;
                }

                putenv('GOOGLE_APPLICATION_CREDENTIALS='.$credentialsPath);
                $_ENV['GOOGLE_APPLICATION_CREDENTIALS'] = $credentialsPath;
                $_SERVER['GOOGLE_APPLICATION_CREDENTIALS'] = $credentialsPath;
            }

            Log::debug('Inicializando proveedor Firebase.', [
                'component' => 'firebase',
                'operation' => 'initialize',
                'mode' => $emulatorMode ? 'local_emulator' : 'configured_provider',
            ]);

            $factory = $emulatorMode
                ? $this->emulatorFactory($projectId)
                : (new Factory)->withServiceAccount($credentialsPath)->withProjectId($projectId);

            $this->firestore = $factory->createFirestore()->database();
            $this->auth = $factory->createAuth();
        } catch (Throwable $e) {
            Log::error('No se pudo inicializar el proveedor Firebase.', [
                'component' => 'firebase',
                'operation' => 'initialize',
                'error_code' => 'FIREBASE_INITIALIZATION_FAILED',
                'exception_class' => get_class($e),
            ]);

            throw new RuntimeException('Firebase no pudo inicializarse.', 0, $e);
        }
    }

    private function emulatorFactory(string $projectId): Factory
    {
        // El proceso QA no debe leer ni intercambiar la service account local.
        putenv('GOOGLE_APPLICATION_CREDENTIALS');
        unset($_ENV['GOOGLE_APPLICATION_CREDENTIALS'], $_SERVER['GOOGLE_APPLICATION_CREDENTIALS']);

        $credentials = new class implements FetchAuthTokenInterface
        {
            public function fetchAuthToken(?callable $httpHandler = null): array
            {
                return ['access_token' => 'owner', 'expires_in' => 3600];
            }

            public function getCacheKey(): string
            {
                return 'rehabianex-local-emulator-owner';
            }

            public function getLastReceivedToken(): array
            {
                return ['access_token' => 'owner', 'expires_at' => time() + 3600];
            }
        };

        $factory = (new Factory)->withProjectId($projectId);
        $property = new ReflectionProperty(Factory::class, 'googleAuthTokenCredentials');
        $property->setValue($factory, $credentials);

        return $factory;
    }

    private function assertSafeEmulatorConfiguration(string $projectId): bool
    {
        $authHost = $this->setting('auth_emulator_host');
        $firestoreHost = getenv('FIRESTORE_EMULATOR_HOST');
        $firestoreHost = is_string($firestoreHost) ? trim($firestoreHost) : null;

        if ($authHost === null && ($firestoreHost === null || $firestoreHost === '')) {
            return false;
        }

        if ($authHost === null || $firestoreHost === null || $firestoreHost === '') {
            throw new RuntimeException('Auth y Firestore Emulator deben habilitarse juntos.');
        }

        $loopbackHost = '/^(?:127\.0\.0\.1|localhost):[1-9][0-9]{0,4}$/';

        if (preg_match($loopbackHost, $authHost) !== 1
            || preg_match($loopbackHost, $firestoreHost) !== 1) {
            throw new RuntimeException('Los emuladores Firebase solo pueden usar hosts loopback.');
        }

        if (! str_starts_with($projectId, 'demo-')) {
            throw new RuntimeException('El modo emulador requiere un FIREBASE_PROJECT_ID con prefijo demo-.');
        }

        return true;
    }

    private function resolveCredentialsPath(): string
    {
        $base64 = $this->setting('credentials_base64');

        if ($base64 && $base64 !== 'TU_FIREBASE_BASE64_AQUI') {
            $directory = storage_path('app/firebase');

            if (! is_dir($directory)) {
                if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                    throw new RuntimeException('No se pudo preparar el almacenamiento seguro de Firebase.');
                }
            }

            $this->enforceDirectoryPermissions($directory);

            $path = $directory.'/firebase_credentials_from_env.json';

            $decoded = base64_decode($base64, true);

            if ($decoded === false) {
                throw new RuntimeException('FIREBASE_CREDENTIALS_BASE64 no es Base64 válido.');
            }

            $json = json_decode($decoded, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('FIREBASE_CREDENTIALS_BASE64 no contiene JSON válido: '.json_last_error_msg());
            }

            if (($json['type'] ?? null) !== 'service_account') {
                throw new RuntimeException('Las credenciales Firebase no son de tipo service_account.');
            }

            if (empty($json['project_id'])) {
                throw new RuntimeException('Las credenciales Firebase no contienen project_id.');
            }

            if (empty($json['client_email'])) {
                throw new RuntimeException('Las credenciales Firebase no contienen client_email.');
            }

            if (empty($json['private_key'])) {
                throw new RuntimeException('Las credenciales Firebase no contienen private_key.');
            }

            $configuredProjectId = $this->setting('project_id');

            if ($configuredProjectId && $json['project_id'] !== $configuredProjectId) {
                throw new RuntimeException('Las credenciales Firebase no pertenecen al proyecto configurado.');
            }

            $this->writeCredentialAtomically($path, $decoded);

            if (! file_exists($path)) {
                throw new RuntimeException('El archivo de credenciales Firebase no existe después de escribirlo.');
            }

            if (filesize($path) <= 0) {
                throw new RuntimeException('El archivo de credenciales Firebase fue escrito vacío.');
            }

            return $path;
        }

        $localPath = $this->setting('credentials');

        if ($localPath) {
            $path = $this->resolvePath($localPath);

            if (file_exists($path)) {
                return $path;
            }
        }

        $googleCredentials = $this->setting('google_application_credentials');

        if ($googleCredentials && file_exists($googleCredentials)) {
            return $googleCredentials;
        }

        throw new RuntimeException('No se encontraron credenciales Firebase. Configura FIREBASE_CREDENTIALS_BASE64 o FIREBASE_CREDENTIALS.');
    }

    private function writeCredentialAtomically(string $path, string $contents): void
    {
        $directory = dirname($path);
        $temporaryPath = tempnam($directory, '.firebase-credential-');

        if ($temporaryPath === false) {
            throw new RuntimeException('No se pudo preparar la credencial Firebase.');
        }

        try {
            $written = file_put_contents($temporaryPath, $contents, LOCK_EX);

            if ($written === false || $written !== strlen($contents)) {
                throw new RuntimeException('No se pudo materializar la credencial Firebase.');
            }

            $this->enforceCredentialPermissions($temporaryPath);

            if (PHP_OS_FAMILY === 'Windows' && file_exists($path)) {
                $existingHash = hash_file('sha256', $path);
                $incomingHash = hash('sha256', $contents);

                if (! is_string($existingHash) || ! hash_equals($existingHash, $incomingHash)) {
                    throw new RuntimeException('No se puede reemplazar atómicamente la credencial Firebase en este sistema.');
                }

                $this->enforceCredentialPermissions($path);

                return;
            }

            if (! @rename($temporaryPath, $path)) {
                throw new RuntimeException('No se pudo activar la credencial Firebase de forma atómica.');
            }

            $this->enforceCredentialPermissions($path);
        } finally {
            if (file_exists($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function enforceDirectoryPermissions(string $directory): void
    {
        if (PHP_OS_FAMILY !== 'Windows' && ! @chmod($directory, 0700)) {
            throw new RuntimeException('No se pudieron asegurar los permisos del almacenamiento Firebase.');
        }
    }

    private function enforceCredentialPermissions(string $path): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // chmod no representa ACL de Windows; el archivo hereda las ACL del directorio local.
            @chmod($path, 0600);

            return;
        }

        if (! @chmod($path, 0600)) {
            throw new RuntimeException('No se pudieron asegurar los permisos de la credencial Firebase.');
        }

        clearstatcache(true, $path);
        $permissions = fileperms($path);

        if ($permissions === false || (($permissions & 0777) !== 0600)) {
            throw new RuntimeException('La credencial Firebase no tiene permisos seguros.');
        }
    }

    private function setting(string $key): ?string
    {
        $value = config("services.firebase.{$key}") ?? env('FIREBASE_'.strtoupper($key));

        if ($key === 'google_application_credentials') {
            $value = config("services.firebase.{$key}") ?? env('GOOGLE_APPLICATION_CREDENTIALS');
        }

        if ($key === 'grpc_default_ssl_roots_file_path') {
            $value = config("services.firebase.{$key}") ?? env('GRPC_DEFAULT_SSL_ROOTS_FILE_PATH');
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value, " \t\n\r\0\x0B\"'");

        return $value !== '' ? $value : null;
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path, " \t\n\r\0\x0B\"'");

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, '/') || str_starts_with($path, '\\\\')) {
            @chmod($path, 0600);

            return $path;
        }

        return base_path($path);
    }

    public function db()
    {
        return $this->firestore;
    }

    public function auth()
    {
        return $this->auth;
    }
}
