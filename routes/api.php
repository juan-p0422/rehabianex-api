<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RehabianexSeederController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\SupervisorAIController;
use App\Services\FirebaseService;

if (app()->environment('local')) {
    Route::post('/demo/seed-rehabianex', [RehabianexSeederController::class, 'seed']);
}

Route::get('/supervisors/{id}/patients', [PatientController::class, 'supervisedPatients']);

Route::get('/patients/{id}', [PatientController::class, 'show']);
Route::get('/patients/{id}/notes', [PatientController::class, 'notes']);
Route::post('/patients/{id}/notes', [PatientController::class, 'storeNote']);

Route::middleware('throttle:10,1')->post('/ai/supervisor-chat', [SupervisorAIController::class, 'chat']);

Route::get('/ping', function () {
    return response()->json([
        'ok' => true,
        'message' => 'Laravel API funciona dentro de Docker',
        'app_env' => app()->environment(),
        'app_debug' => config('app.debug'),
        'time' => now()->toIso8601String(),
    ]);
});

Route::get('/debug/env', function () {
    return response()->json([
        'ok' => true,
        'app_key_exists' => !empty(config('app.key')),
        'firebase_project_id' => env('FIREBASE_PROJECT_ID'),
        'firebase_base64_exists' => !empty(env('FIREBASE_CREDENTIALS_BASE64')),
        'firebase_base64_is_placeholder' => env('FIREBASE_CREDENTIALS_BASE64') === 'TU_FIREBASE_BASE64_AQUI',
        'grpc_roots' => env('GRPC_DEFAULT_SSL_ROOTS_FILE_PATH'),
        'ai_provider' => env('AI_PROVIDER'),
        'ai_key_exists' => !empty(env('AI_API_KEY')),
        'ai_model' => env('AI_MODEL'),
    ]);
});

Route::get('/debug/firebase', function () {
    try {
        $firebase = app(\App\Services\FirebaseService::class);

        $db = $firebase->db();

        $documents = $db
            ->collection('patients')
            ->limit(1)
            ->documents();

        $items = [];

        foreach ($documents as $document) {
            if ($document->exists()) {
                $items[] = $document->data();
            }
        }

        return response()->json([
            'ok' => true,
            'message' => 'Firebase respondió correctamente',
            'sample_count' => count($items),
            'sample' => $items,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'ok' => false,
            'message' => 'Error conectando con Firebase',
            'error' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'class' => get_class($e),
        ], 500);
    }
});


Route::get('/debug/firebase-error', function () {
    try {
        $firebase = app(\App\Services\FirebaseService::class);

        $db = $firebase->db();

        $documents = $db
            ->collection('patients')
            ->limit(1)
            ->documents();

        $items = [];

        foreach ($documents as $document) {
            if ($document->exists()) {
                $items[] = $document->data();
            }
        }

        return response()->json([
            'ok' => true,
            'message' => 'Firebase respondió correctamente',
            'sample_count' => count($items),
            'sample' => $items,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'ok' => false,
            'message' => 'Error conectando con Firebase',
            'error' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'class' => get_class($e),
            'trace_preview' => collect(explode("\n", $e->getTraceAsString()))
                ->take(5)
                ->values(),
        ], 500);
    }
});


Route::get('/debug/php', function () {
    return response()->json([
        'ok' => true,
        'php_version' => PHP_VERSION,
        'grpc_loaded' => extension_loaded('grpc'),
        'protobuf_loaded' => extension_loaded('protobuf'),
        'openssl_loaded' => extension_loaded('openssl'),
        'curl_loaded' => extension_loaded('curl'),
        'json_loaded' => extension_loaded('json'),
        'storage_path' => storage_path('app/firebase'),
        'storage_writable' => is_writable(storage_path('app')),
        'firebase_dir_exists' => is_dir(storage_path('app/firebase')),
        'firebase_dir_writable' => is_dir(storage_path('app/firebase')) ? is_writable(storage_path('app/firebase')) : null,
    ]);
});

Route::get('/debug/firebase-credentials', function () {
    try {
        $base64 = env('FIREBASE_CREDENTIALS_BASE64');

        if (!$base64) {
            return response()->json([
                'ok' => false,
                'error' => 'FIREBASE_CREDENTIALS_BASE64 no está configurado',
            ], 500);
        }

        $decoded = base64_decode($base64, true);

        if ($decoded === false) {
            return response()->json([
                'ok' => false,
                'error' => 'FIREBASE_CREDENTIALS_BASE64 no es Base64 válido',
            ], 500);
        }

        $json = json_decode($decoded, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json([
                'ok' => false,
                'error' => 'El contenido decodificado no es JSON válido',
                'json_error' => json_last_error_msg(),
            ], 500);
        }

        $directory = storage_path('app/firebase');

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory . '/firebase_credentials_debug.json';

        file_put_contents($path, $decoded);

        return response()->json([
            'ok' => true,
            'message' => 'Credenciales Firebase decodificadas y escritas correctamente',
            'type' => $json['type'] ?? null,
            'project_id' => $json['project_id'] ?? null,
            'client_email_exists' => !empty($json['client_email']),
            'private_key_exists' => !empty($json['private_key']),
            'directory' => $directory,
            'directory_exists' => is_dir($directory),
            'directory_writable' => is_writable($directory),
            'file_written' => file_exists($path),
            'file_size' => file_exists($path) ? filesize($path) : null,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'ok' => false,
            'error' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'class' => get_class($e),
        ], 500);
    }
});
