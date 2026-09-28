<?php

use App\Http\Controllers\Api\AdminSupervisorController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardSummaryController;
use App\Http\Controllers\Api\FcmTokenController;
use App\Http\Controllers\Api\FirestoreCrudController;
use App\Http\Controllers\Api\SupervisionController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\SupervisorAIController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'ok' => true,
    'status' => 'healthy',
]))->middleware('throttle:60,1');

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:10,1');
    Route::post('/google', [AuthController::class, 'google'])->middleware('throttle:10,1');
    Route::post('/firebase', [AuthController::class, 'firebase'])->middleware('throttle:10,1');
});

Route::middleware('firebase.auth')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::post('/notifications/fcm-token', [FcmTokenController::class, 'register'])
        ->middleware('throttle:10,1');
    Route::delete('/notifications/fcm-token', [FcmTokenController::class, 'revoke'])
        ->middleware('throttle:10,1');
    Route::post('/notifications/test', [FcmTokenController::class, 'test'])
        ->middleware('throttle:3,1');

    Route::get('/supervisors/{id}/patients', [PatientController::class, 'supervisedPatients']);
    Route::get('/supervisors/{uid}/dashboard-summary', [DashboardSummaryController::class, 'supervisor'])
        ->middleware('throttle:30,1');
    Route::post(
        '/supervisors/{supervisor_uid}/patients/{patient_uid}/unlink',
        [SupervisionController::class, 'unlink']
    );
    Route::get('/supervisors/resolve', [SupervisionController::class, 'resolveSupervisor'])
        ->middleware('throttle:20,1');
    Route::get('/patients/{id}/notes', [PatientController::class, 'notes']);
    Route::post('/patients/{id}/notes', [PatientController::class, 'storeNote']);

    Route::patch('/supervision-requests/{id}/respond', [SupervisionController::class, 'respond']);

    Route::post('/ai/supervisor-chat', [SupervisorAIController::class, 'chat'])
        ->middleware('throttle:10,1');
    Route::get('/ai/supervisor-chat/eligibility', [SupervisorAIController::class, 'eligibility'])
        ->middleware('throttle:30,1');

    foreach (array_keys(config('firestore.resources')) as $resource) {
        Route::get("/{$resource}", [FirestoreCrudController::class, 'index'])->defaults('resource', $resource);
        Route::post("/{$resource}", [FirestoreCrudController::class, 'store'])->defaults('resource', $resource);
        Route::get("/{$resource}/{id}", [FirestoreCrudController::class, 'show'])->defaults('resource', $resource);
        Route::put("/{$resource}/{id}", [FirestoreCrudController::class, 'update'])->defaults('resource', $resource);
        Route::patch("/{$resource}/{id}", [FirestoreCrudController::class, 'update'])->defaults('resource', $resource);
        Route::delete("/{$resource}/{id}", [FirestoreCrudController::class, 'destroy'])->defaults('resource', $resource);
    }

    Route::get('/ping', fn () => response()->json([
        'ok' => true,
        'status' => 'healthy',
    ]));
});

Route::prefix('admin')
    ->middleware(['firebase.auth', 'admin'])
    ->group(function () {
        Route::get('/ping', fn () => response()->json([
            'ok' => true,
            'status' => 'healthy',
        ]));
        Route::get('/dashboard-summary', [DashboardSummaryController::class, 'admin'])
            ->middleware('throttle:30,1');
        Route::get('/supervisors', [AdminSupervisorController::class, 'index']);
        Route::get('/supervisors/{uid}', [AdminSupervisorController::class, 'show']);
        Route::patch('/supervisors/{uid}/authorize', [AdminSupervisorController::class, 'authorizeSupervisor']);
        Route::patch('/supervisors/{uid}/reject', [AdminSupervisorController::class, 'reject']);
        Route::patch('/supervisors/{uid}/suspend', [AdminSupervisorController::class, 'suspend']);
        Route::patch('/supervisors/{uid}/reactivate', [AdminSupervisorController::class, 'reactivate']);
    });
