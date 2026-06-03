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

Route::get('/debug/firebase', function (FirebaseService $firebase) {
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
});