<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FirebaseService
{
    protected $firestore;

    public function __construct()
    {
        try {
            $credentialsPath = $this->resolveCredentialsPath();
            $projectId = env('FIREBASE_PROJECT_ID');

            $grpcRootsPath = env('GRPC_DEFAULT_SSL_ROOTS_FILE_PATH');

            if ($grpcRootsPath) {
                putenv('GRPC_DEFAULT_SSL_ROOTS_FILE_PATH=' . $grpcRootsPath);
                $_ENV['GRPC_DEFAULT_SSL_ROOTS_FILE_PATH'] = $grpcRootsPath;
                $_SERVER['GRPC_DEFAULT_SSL_ROOTS_FILE_PATH'] = $grpcRootsPath;
            }

            if (!$projectId) {
                throw new RuntimeException('FIREBASE_PROJECT_ID no está configurado.');
            }

            if (!$credentialsPath || !file_exists($credentialsPath)) {
                throw new RuntimeException('No se encontró el archivo de credenciales Firebase en: ' . ($credentialsPath ?: 'NULL'));
            }

            putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $credentialsPath);
            $_ENV['GOOGLE_APPLICATION_CREDENTIALS'] = $credentialsPath;
            $_SERVER['GOOGLE_APPLICATION_CREDENTIALS'] = $credentialsPath;

            Log::debug('Inicializando Firebase', [
                'project_id' => $projectId,
                'credentials_path' => $credentialsPath,
                'credentials_exists' => file_exists($credentialsPath),
                'credentials_size' => filesize($credentialsPath),
                'grpc_roots_path' => $grpcRootsPath,
                'google_application_credentials' => getenv('GOOGLE_APPLICATION_CREDENTIALS'),
            ]);

            $factory = (new Factory)
                ->withServiceAccount($credentialsPath)
                ->withProjectId($projectId);

            $this->firestore = $factory->createFirestore()->database();
        } catch (Throwable $e) {
            Log::error('Error inicializando FirebaseService', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            throw $e;
        }
    }

    private function resolveCredentialsPath(): string
    {
        $base64 = env('FIREBASE_CREDENTIALS_BASE64');

        if ($base64 && $base64 !== 'TU_FIREBASE_BASE64_AQUI') {
            $directory = storage_path('app/firebase');

            if (!is_dir($directory)) {
                mkdir($directory, 0775, true);
            }

            $path = $directory . '/firebase_credentials_from_env.json';

            $decoded = base64_decode($base64, true);

            if ($decoded === false) {
                throw new RuntimeException('FIREBASE_CREDENTIALS_BASE64 no es Base64 válido.');
            }

            $json = json_decode($decoded, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('FIREBASE_CREDENTIALS_BASE64 no contiene JSON válido: ' . json_last_error_msg());
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

            $result = file_put_contents($path, $decoded);

            if ($result === false) {
                throw new RuntimeException('No se pudo escribir el archivo de credenciales Firebase.');
            }

            if (!file_exists($path)) {
                throw new RuntimeException('El archivo de credenciales Firebase no existe después de escribirlo.');
            }

            if (filesize($path) <= 0) {
                throw new RuntimeException('El archivo de credenciales Firebase fue escrito vacío.');
            }

            return $path;
        }

        $localPath = env('FIREBASE_CREDENTIALS');

        if ($localPath) {
            $path = base_path($localPath);

            if (file_exists($path)) {
                return $path;
            }
        }

        $googleCredentials = env('GOOGLE_APPLICATION_CREDENTIALS');

        if ($googleCredentials && file_exists($googleCredentials)) {
            return $googleCredentials;
        }

        throw new RuntimeException('No se encontraron credenciales Firebase. Configura FIREBASE_CREDENTIALS_BASE64.');
    }

    public function db()
    {
        return $this->firestore;
    }
}
