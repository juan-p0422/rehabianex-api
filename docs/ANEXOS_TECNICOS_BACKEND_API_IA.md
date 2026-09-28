# Anexos técnicos del backend de RehabiAnex

Los siguientes extractos proceden del código local del backend Laravel/PHP. Se conservan literalmente las líneas seleccionadas; los bloques parciales requieren el contexto de sus clases y métodos originales. Las rutas indicadas son relativas a la raíz del repositorio y la numeración de líneas corresponde a la versión revisada. No se incluyen archivos de credenciales ni valores de configuración privada.

Alcance: esta revisión documenta implementación y pruebas existentes; no acredita disponibilidad actual en Render, ejecución contra Firebase real, validación clínica ni aptitud para producción clínica. No se ejecutaron las pruebas durante esta revisión. Los casos de prueba citados emplean datos sintéticos y dobles de prueba.

## Anexo 1. Rutas API protegidas y endpoint de salud

### Descripción

Centraliza las rutas de autenticación, pacientes, supervisión, consentimiento e IA. Permite identificar qué operaciones requieren una sesión Firebase y qué endpoint sirve como comprobación básica de disponibilidad HTTP.

### Archivo o ubicación

`routes/api.php`; registro de middleware en `bootstrap/app.php`.

### Fragmento de código

**Extracto:** `routes/api.php`, líneas 12–61.

```php
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
```

### Explicación técnica

Entrada: solicitudes HTTP bajo `/api`; el grupo público de autenticación incluye registro, login, renovación y acceso mediante Google o Firebase. Validaciones: las rutas dentro del grupo requieren `firebase.auth`; varias tienen límites de frecuencia. Datos: los controladores correspondientes procesan pacientes, solicitudes y recursos como `consents`; el bucle genera sus rutas CRUD desde la configuración. Respuesta: `/api/health` devuelve HTTP 200 con `ok` y `status` y tiene límite de 60 solicitudes por minuto. Seguridad: el estado público no expone configuración ni credenciales. Este endpoint devuelve un estado fijo: confirma que Laravel responde, pero no comprueba conectividad con Firestore, Firebase Auth o el proveedor IA. `bootstrap/app.php` también registra `/up` como ruta de salud del framework.

## Anexo 2. Autenticación Firebase y revocación de sesiones

### Descripción

Verifica la identidad antes de permitir el acceso a las rutas protegidas y adjunta al request el UID y el rol obtenidos del servidor.

### Archivo o ubicación

`app/Http/Middleware/AuthenticateFirebase.php`.

### Fragmento de código

**Extracto:** `app/Http/Middleware/AuthenticateFirebase.php`, líneas 17–95.

```php
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return ApiErrorResponse::make(
                'Autenticacion requerida. Envia Authorization: Bearer {id_token}.',
                401
            );
        }

        try {
            $verifiedToken = $this->firebase->auth()->verifyIdToken($token, false, 60);
            $uid = $verifiedToken->claims()->get('sub');
            $user = $this->firebase->auth()->getUser($uid);
            $role = $user->customClaims['role'] ?? null;

            $authenticatedAt = $verifiedToken->claims()->get('auth_time');
            $authenticatedAtTimestamp = $authenticatedAt instanceof \DateTimeInterface
                ? $authenticatedAt->getTimestamp()
                : (int) $authenticatedAt;
            $validAfterTimestamp = $user->tokensValidAfterTime?->getTimestamp();

            if ($validAfterTimestamp !== null && $authenticatedAtTimestamp < $validAfterTimestamp) {
                return ApiErrorResponse::make(
                    'La sesion fue revocada. Inicia sesion nuevamente.',
                    401
                );
            }

            $tokenHash = hash('sha256', $token);
            $revokedToken = $this->firebase->db()
                ->collection('revoked_tokens')
                ->document($tokenHash)
                ->snapshot();

            if ($revokedToken->exists()) {
                return ApiErrorResponse::make(
                    'La sesion fue revocada. Inicia sesion nuevamente.',
                    401
                );
            }

            if ($user->disabled) {
                return ApiErrorResponse::make('La cuenta esta deshabilitada.', 401);
            }

            $profileCollections = config('firestore.profile_collections', []);

            if (! isset($profileCollections[$role])) {
                return ApiErrorResponse::make('La cuenta no tiene un rol valido.', 403);
            }

            $collection = $profileCollections[$role];
            $profile = $this->firebase->db()
                ->collection($collection)
                ->document($uid)
                ->snapshot();

            if (! $profile->exists()) {
                return ApiErrorResponse::make('La cuenta no tiene un perfil activo.', 403);
            }

            $request->attributes->set('firebase_uid', $uid);
            $request->attributes->set('firebase_role', $role);
            $request->attributes->set('firebase_user', $user);
            $request->attributes->set('firebase_profile', $profile->data());
            $request->attributes->set('firebase_token_hash', $tokenHash);
            $request->attributes->set('firebase_token_expires_at', $verifiedToken->claims()->get('exp'));

            return $next($request);
        } catch (Throwable $e) {
            Log::warning('Firebase ID token rejected.', [
                'exception' => get_class($e),
            ]);

            return ApiErrorResponse::make('Token invalido, expirado o revocado.', 401);
        }
    }
```

### Explicación técnica

Entrada: token de identidad en `Authorization: Bearer`. Validaciones: `verifyIdToken` valida el token con 60 segundos de tolerancia; el código consulta al usuario y compara `auth_time` con `tokensValidAfterTime`, verifica el hash en `revoked_tokens`, rechaza cuentas deshabilitadas y exige un rol configurado y un perfil existente. Datos: consulta Firebase Authentication y Firestore; solo incorpora atributos a la solicitud. Respuesta: continúa al controlador o devuelve errores controlados 401/403. Seguridad: el rol proviene de `customClaims`, no del cuerpo enviado por el cliente; la lista de revocación usa SHA-256 del token. El argumento `false` desactiva la comprobación de revocación integrada de esa llamada; las comprobaciones posteriores implementan el control mostrado. El bloque `catch` abarca también la llamada a `$next`, por lo que una excepción propagada desde allí puede quedar presentada como 401.

## Anexo 3. Control por roles y administración de supervisores

### Descripción

Restringe la administración a cuentas administradoras activas y gestiona autorización, rechazo, suspensión y reactivación de supervisores.

### Archivo o ubicación

`app/Http/Middleware/RequireAdmin.php`; `app/Http/Controllers/Api/AdminSupervisorController.php`; `routes/api.php`.

### Fragmento de código

**Extracto:** `app/Http/Middleware/RequireAdmin.php`, líneas 12–32.

```php
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('firebase_role') !== 'admin') {
            return ApiErrorResponse::make(
                'Acceso restringido a administradores.',
                403
            );
        }

        $profile = $request->attributes->get('firebase_profile');
        $status = is_array($profile) ? ($profile['status'] ?? null) : null;

        if ($status !== 'active') {
            return ApiErrorResponse::make(
                'La cuenta administradora no está activa.',
                403
            );
        }

        return $next($request);
    }
```

**Extracto:** `routes/api.php`, líneas 63–78.

```php
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
```

**Extracto:** `app/Http/Controllers/Api/AdminSupervisorController.php`, líneas 126–181.

```php
    public function authorizeSupervisor(Request $request, string $uid)
    {
        $validator = Validator::make($request->all(), [
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $supervisor = $this->findSupervisor($uid);
        $this->assertStatus($supervisor, ['pending', 'pending_review', 'rejected']);
        $now = Carbon::now()->toIso8601String();
        $changes = [
            'authorized' => true,
            'verified' => true,
            'status' => 'active',
            'authorized_at' => $now,
            'authorized_by' => $this->adminUid($request),
            'supervisor_code' => ($supervisor['supervisor_code'] ?? null)
                ?: 'RA-'.strtoupper(substr(sha1($uid), 0, 8)),
            'rejection_reason' => null,
            'updated_at' => $now,
        ];

        if ($request->filled('notes')) {
            $changes['authorization_notes'] = $validator->validated()['notes'];
        }

        return $this->saveTransition($uid, $supervisor, $changes, 'Supervisor autorizado correctamente.');
    }

    public function reject(Request $request, string $uid)
    {
        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $supervisor = $this->findSupervisor($uid);
        $this->assertStatus($supervisor, ['pending', 'pending_review']);
        $now = Carbon::now()->toIso8601String();

        return $this->saveTransition($uid, $supervisor, [
            'authorized' => false,
            'verified' => false,
            'status' => 'rejected',
            'rejection_reason' => $validator->validated()['reason'],
            'rejected_at' => $now,
            'rejected_by' => $this->adminUid($request),
            'updated_at' => $now,
        ], 'Supervisor rechazado correctamente.');
    }
```

**Extracto:** `app/Http/Controllers/Api/AdminSupervisorController.php`, líneas 183–224.

```php
    public function suspend(Request $request, string $uid)
    {
        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $supervisor = $this->findSupervisor($uid);
        $this->assertStatus($supervisor, ['active']);
        $now = Carbon::now()->toIso8601String();

        return $this->saveTransition($uid, $supervisor, [
            'authorized' => false,
            'status' => 'suspended',
            'suspension_reason' => $validator->validated()['reason'],
            'suspended_at' => $now,
            'suspended_by' => $this->adminUid($request),
            'updated_at' => $now,
        ], 'Supervisor suspendido correctamente.');
    }

    public function reactivate(Request $request, string $uid)
    {
        $supervisor = $this->findSupervisor($uid);
        $this->assertStatus($supervisor, ['suspended']);
        $now = Carbon::now()->toIso8601String();

        return $this->saveTransition($uid, $supervisor, [
            'authorized' => true,
            'verified' => true,
            'status' => 'active',
            'suspended_at' => null,
            'suspended_by' => null,
            'suspension_reason' => null,
            'reactivated_at' => $now,
            'reactivated_by' => $this->adminUid($request),
            'updated_at' => $now,
        ], 'Supervisor reactivado correctamente.');
    }
```

### Explicación técnica

Entrada: sesión administradora, UID del supervisor y notas opcionales o motivo obligatorio según la operación. Validaciones: rol `admin`, perfil `active`, existencia del supervisor y transiciones de estado permitidas mediante `assertStatus`; motivos y notas tienen máximo de 1000 caracteres. Datos: `saveTransition` actualiza el documento de `supervisors` y devuelve una proyección de campos. Respuesta: JSON de confirmación, 422 por validación, 404 por documento inexistente o 409 por transición incompatible. Seguridad: registra UID del administrador y fecha de la operación. Para usar las funciones de supervisión, `FirestoreAccessService::isAuthorizedSupervisorProfile` exige simultáneamente `status=active`, `authorized=true` y `verified=true`. El rol técnico es `supervisor`; el código mostrado no establece roles independientes denominados doctor o padrino ni demuestra verificación externa de credenciales profesionales.

## Anexo 4. Resolución de supervisor y solicitudes de vinculación

### Descripción

Resuelve el código de un supervisor disponible, prepara solicitudes del paciente y procesa su aceptación o rechazo sin otorgar consentimiento automáticamente.

### Archivo o ubicación

`app/Http/Controllers/Api/SupervisionController.php`; `app/Services/FirestoreAccessService.php`.

### Fragmento de código

**Extracto:** `app/Http/Controllers/Api/SupervisionController.php`, líneas 27–72.

```php
    public function resolveSupervisor(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'code' => ['required', 'string', 'regex:/^RA-[A-Fa-f0-9]{8}$/'],
        ], [
            'code.required' => 'Debes indicar el codigo del supervisor.',
            'code.regex' => 'El codigo debe usar el formato RA-XXXXXXXX.',
        ]);

        if ($validator->fails()) {
            return ApiErrorResponse::make(
                'Los datos enviados no son validos.',
                422,
                $validator->errors()->toArray()
            );
        }

        $code = strtoupper((string) $validator->validated()['code']);
        $uid = $this->access->resolveSupervisorUid($code);

        if ($uid === null) {
            abort(404, 'Supervisor no disponible.');
        }

        $snapshot = $this->db->collection('supervisors')->document($uid)->snapshot();

        if (! $snapshot->exists()) {
            abort(404, 'Supervisor no disponible.');
        }

        try {
            $this->access->assertAuthorizedSupervisor($uid);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 403) {
                abort(404, 'Supervisor no disponible.');
            }

            throw $exception;
        }

        if (! $this->isSupervisorAvailable($snapshot->data())) {
            abort(404, 'Supervisor no disponible.');
        }

        return response()->json($this->resolvedSupervisorResponse($uid, $snapshot->data()));
    }
```

**Extracto:** `app/Services/FirestoreAccessService.php`, líneas 919–960.

```php
    public function resolveSupervisorUid(string $uidOrCode): ?string
    {
        $value = trim($uidOrCode);

        if ($value === '') {
            return null;
        }

        $snapshot = $this->db->collection('supervisors')->document($value)->snapshot();

        if ($snapshot->exists()) {
            return $value;
        }

        $normalizedCode = strtoupper($value);
        $documents = $this->db->collection('supervisors')
            ->where('supervisor_code', '=', $normalizedCode)
            ->limit(1)
            ->documents();

        foreach ($documents as $document) {
            if ($document->exists()) {
                return (string) (($document->data()['uid'] ?? null) ?: $document->id());
            }
        }

        $allSupervisors = $this->db->collection('supervisors')->documents();

        foreach ($allSupervisors as $document) {
            if (! $document->exists()) {
                continue;
            }

            $uid = (string) (($document->data()['uid'] ?? null) ?: $document->id());

            if ($this->supervisorCode($uid) === $normalizedCode) {
                return $uid;
            }
        }

        return null;
    }
```

**Extracto:** `app/Services/FirestoreAccessService.php`, líneas 365–414.

```php
        if ($resource === 'supervision-requests') {
            $this->assertRole($role, 'patient');
            $this->assertOwnerValue($data, 'patient_uid', $uid);
            $type = (string) ($data['type'] ?? $data['request_type'] ?? 'link');

            if (! in_array($type, ['link', 'unlink'], true)) {
                abort(422, 'type debe ser link o unlink.');
            }

            if ($type === 'unlink') {
                $patient = $this->documentData('patients', $uid);
                $assignedSupervisorUid = (string) ($patient['supervisor_uid'] ?? '');

                if ($assignedSupervisorUid === ''
                    || ($patient['supervision_status'] ?? null) !== 'accepted') {
                    abort(409, 'El paciente no tiene una relación de supervisión activa.');
                }

                if (isset($data['supervisor_uid'])
                    && (string) $data['supervisor_uid'] !== $assignedSupervisorUid) {
                    abort(422, 'supervisor_uid no coincide con el supervisor vinculado.');
                }

                $data['supervisor_uid'] = $assignedSupervisorUid;
            } else {
                $patient = $this->documentData('patients', $uid);

                if (! empty($patient['supervisor_uid'])
                    && ($patient['supervision_status'] ?? null) === 'accepted') {
                    abort(409, 'El paciente ya tiene una relación de supervisión activa.');
                }

                $data['supervisor_uid'] = $this->availableSupervisorUid(
                    (string) ($data['supervisor_uid'] ?? '')
                );
            }

            $data['patient_uid'] = $uid;
            $data['patient_name'] = $this->patientDisplayName($uid);
            $data['patient_email'] = $this->patientEmail($uid);
            $data['type'] = $type;
            $data['status'] = 'pending';
            $data['requested_at'] = Carbon::now()->toIso8601String();
            $data['responded_at'] = null;

            return $this->only($data, [
                'patient_uid', 'patient_name', 'patient_email', 'supervisor_uid', 'type', 'status', 'message',
                'requested_at', 'responded_at',
            ]);
        }
```

**Extracto:** `app/Http/Controllers/Api/SupervisionController.php`, líneas 104–191.

```php
            if (! in_array($type, ['link', 'unlink'], true)) {
                abort(409, 'La solicitud tiene un tipo incompatible.');
            }
            $supervisionRequest['type'] = $type;

            if (($supervisionRequest['supervisor_uid'] ?? null) !== $supervisorUid) {
                abort(403, 'La solicitud no pertenece al supervisor autenticado.');
            }

            if (($supervisionRequest['status'] ?? null) !== 'pending') {
                abort(409, 'La solicitud ya fue respondida.');
            }

            $patientUid = (string) ($supervisionRequest['patient_uid'] ?? '');
            $patientRef = $this->db->collection('patients')->document($patientUid);
            $patientSnapshot = $patientRef->snapshot();

            if (! $patientSnapshot->exists()) {
                abort(404, 'Paciente no encontrado.');
            }

            $status = (string) $request->input('status');
            $now = Carbon::now()->toIso8601String();
            $supervisionRequest['status'] = $status;
            $supervisionRequest['responded_at'] = $now;
            $supervisionRequest['updated_at'] = $now;

            $patient = $patientSnapshot->data();

            $revokedConsents = [];

            if ($status === 'accepted' && $type === 'link') {
                if (! empty($patient['supervisor_uid'])
                    && ($patient['supervisor_uid'] ?? null) !== $supervisorUid
                    && ($patient['supervision_status'] ?? null) === 'accepted') {
                    abort(409, 'El paciente ya está vinculado con otro supervisor.');
                }

                $patient['wants_supervision'] = true;
                $patient['supervisor_uid'] = $supervisorUid;
                $patient['supervision_status'] = 'accepted';
                $patient['supervision_accepted_at'] = $now;
                $patient['supervision_ended_at'] = null;
                $patient['updated_at'] = $now;
            } elseif ($status === 'accepted' && $type === 'unlink') {
                $this->assertActiveRelationship($patient, $supervisorUid);
                $patient = $this->unlinkedPatient($patient, $now);
                $revokedConsents = $this->consentRevocations(
                    $patientUid,
                    $supervisorUid,
                    $now
                );
            }

            $this->db->runTransaction(function ($transaction) use (
                $requestRef,
                $patientRef,
                $supervisionRequest,
                $patient,
                $status,
                $type,
                $revokedConsents
            ): void {
                $transaction->set($requestRef, $supervisionRequest);

                if ($status === 'accepted') {
                    $transaction->set($patientRef, $patient);

                    if ($type === 'unlink') {
                        foreach ($revokedConsents as $consent) {
                            $transaction->set($consent['reference'], $consent['data']);
                        }
                    }
                }
            });

            return response()->json([
                'ok' => true,
                'message' => match (true) {
                    $status === 'rejected' => 'Solicitud rechazada.',
                    $type === 'unlink' => 'Solicitud aceptada y relación de supervisión finalizada.',
                    default => 'Solicitud aceptada. El paciente debe otorgar consentimiento explícito antes de compartir información.',
                },
                'data' => $supervisionRequest,
                'relationship' => $status === 'accepted' && $type === 'unlink'
                    ? $this->unlinkSummary($supervisorUid, $patientUid, $now, count($revokedConsents))
                    : null,
            ]);
```

### Explicación técnica

Entrada: código `RA-XXXXXXXX`, datos de solicitud del paciente y respuesta `accepted` o `rejected` del supervisor. Validaciones: formato del código, disponibilidad y autorización del supervisor; rol y propiedad del paciente al crear; el método `respond`, antes del extracto, exige supervisor autorizado y valida el estado recibido. El bloque presentado impide responder solicitudes ajenas o ya resueltas. Datos: consulta `supervisors`, `patients` y `supervision_requests`; prepara la solicitud como `pending` y guarda la respuesta y, cuando corresponde, la relación mediante transacción. La creación se persiste desde `FirestoreCrudController::store` tras `prepareCreate`. Respuesta: perfil resuelto, solicitud creada o confirmación de aceptación/rechazo; los conflictos se rechazan. Seguridad: aceptar el vínculo no crea consentimiento; el paciente debe otorgarlo explícitamente. La solicitud incorpora nombre y correo dentro del backend, por lo que esa estructura no es un contexto seudonimizado para IA. La consulta de solicitudes usa el CRUD protegido y sus controles de acceso. La resolución tiene una búsqueda de compatibilidad que puede recorrer todos los supervisores.

## Anexo 5. Consentimiento granular: activación, pausa y revocación

### Descripción

Mantiene el permiso explícito del paciente separado de la relación de supervisión y permite modificar su vigencia y los ámbitos autorizados.

### Archivo o ubicación

`app/Services/FirestoreAccessService.php`, métodos `prepareCreate`, `prepareUpdate` y `assertConsentCanBeActivated`; persistencia mediante el CRUD de `consents`.

### Fragmento de código

**Extracto:** `app/Services/FirestoreAccessService.php`, líneas 307–333.

```php
        if ($resource === 'consents') {
            $this->assertRole($role, 'patient');
            $this->assertOwnerValue($data, 'patient_uid', $uid);
            $data['supervisor_uid'] = $this->availableSupervisorUid((string) ($data['supervisor_uid'] ?? ''));
            $patient = $this->documentData('patients', $uid);

            if (($patient['supervisor_uid'] ?? null) !== $data['supervisor_uid']
                || ($patient['wants_supervision'] ?? false) !== true
                || ($patient['supervision_status'] ?? null) !== 'accepted') {
                abort(409, 'Debe existir una relación de supervisión aceptada antes de otorgar consentimiento.');
            }

            if (($data['explicit_consent'] ?? null) !== true) {
                abort(422, 'El consentimiento debe aceptarse explicitamente.');
            }

            $data['patient_uid'] = $uid;
            $data['status'] = 'active';
            $data['accepted_at'] = Carbon::now()->toIso8601String();
            $data['revoked_at'] = null;
            $data['paused_at'] = null;

            return $this->only($data, [
                'patient_uid', 'supervisor_uid', 'type', 'explicit_consent',
                'consent_text', 'scope', 'status', 'accepted_at', 'revoked_at', 'paused_at',
            ]);
        }
```

**Extracto:** `app/Services/FirestoreAccessService.php`, líneas 470–527.

```php
        if ($resource === 'consents' && $role === 'patient') {
            $updated = $this->only($data, ['explicit_consent', 'consent_text', 'scope', 'status']);
            $now = Carbon::now()->toIso8601String();
            $explicitConsentProvided = array_key_exists('explicit_consent', $updated);

            if (array_key_exists('explicit_consent', $updated)
                && $updated['explicit_consent'] === false
                && isset($updated['status'])
                && $updated['status'] !== 'revoked') {
                abort(422, 'explicit_consent=false solo es compatible con status=revoked.');
            }

            if (array_key_exists('explicit_consent', $updated)
                && $updated['explicit_consent'] === true
                && isset($updated['status'])
                && $updated['status'] !== 'active') {
                abort(422, 'explicit_consent=true solo es compatible con status=active.');
            }

            $activating = ($updated['status'] ?? null) === 'active'
                || ($updated['explicit_consent'] ?? null) === true;

            if ($activating) {
                $this->assertConsentCanBeActivated($current, $updated, $uid);
            }

            if (array_key_exists('status', $updated)) {
                if ($updated['status'] === 'active') {
                    $updated['explicit_consent'] = true;
                    $updated['accepted_at'] = $now;
                    $updated['revoked_at'] = null;
                    $updated['paused_at'] = null;
                } elseif ($updated['status'] === 'paused') {
                    $updated['explicit_consent'] = false;
                    $updated['paused_at'] = $now;
                    $updated['revoked_at'] = null;
                } elseif ($updated['status'] === 'revoked') {
                    $updated['explicit_consent'] = false;
                    $updated['revoked_at'] = $now;
                    $updated['paused_at'] = null;
                }
            }

            if ($explicitConsentProvided) {
                if ($updated['explicit_consent'] === true) {
                    $updated['status'] = 'active';
                    $updated['accepted_at'] = $now;
                    $updated['revoked_at'] = null;
                    $updated['paused_at'] = null;
                } else {
                    $updated['status'] = 'revoked';
                    $updated['revoked_at'] = $now;
                    $updated['paused_at'] = null;
                }
            }

            return $updated;
        }
```

**Extracto:** `app/Services/FirestoreAccessService.php`, líneas 559–575.

```php
    private function assertConsentCanBeActivated(array $current, array $changes, string $patientUid): void
    {
        $scope = $changes['scope'] ?? $current['scope'] ?? [];

        if (! is_array($scope) || $scope === []) {
            abort(422, 'Un consentimiento activo requiere al menos un scope valido.');
        }

        $patient = $this->documentData('patients', $patientUid);

        if (($current['patient_uid'] ?? null) !== $patientUid
            || ($patient['supervisor_uid'] ?? null) !== ($current['supervisor_uid'] ?? null)
            || ($patient['wants_supervision'] ?? false) !== true
            || ($patient['supervision_status'] ?? null) !== 'accepted') {
            abort(409, 'No existe una relacion de supervision activa para reactivar el consentimiento.');
        }
    }
```

### Explicación técnica

Entrada: datos del consentimiento con `scope`, `explicit_consent` y/o `status`. Validaciones: el paciente debe actuar sobre sus propios datos y tener vínculo aceptado; crear requiere consentimiento explícito verdadero. `prepareUpdate` primero ejecuta `assertCanRead`; activar verifica propiedad, relación vigente y un arreglo de ámbitos no vacío. Rechaza combinaciones incompatibles de consentimiento explícito y estado. Datos: prepara `accepted_at`, `paused_at`, `revoked_at` y la lista permitida de campos para su persistencia. Respuesta: arreglo saneado al controlador CRUD o errores 409/422. Seguridad: pausar o revocar establece `explicit_consent=false`; reactivar limpia fechas de pausa y revocación. Los permisos como `ai_chat_summary` se evalúan al acceder a la información. Los extractos no constituyen una firma electrónica certificada ni validación jurídica del consentimiento.

## Anexo 6. Finalización de la relación y revocación asociada

### Descripción

Permite al paciente o supervisor vinculado finalizar la supervisión y retirar los consentimientos correspondientes a esa pareja.

### Archivo o ubicación

`app/Http/Controllers/Api/SupervisionController.php`, métodos `unlink`, `unlinkedPatient` y `consentRevocations`.

### Fragmento de código

**Extracto:** `app/Http/Controllers/Api/SupervisionController.php`, líneas 204–276.

```php
    public function unlink(Request $request, string $supervisorUid, string $patientUid)
    {
        try {
            $actorRole = $this->access->role($request);
            $actorUid = $this->access->uid($request);

            if ($actorRole === 'supervisor') {
                if ($actorUid !== $supervisorUid) {
                    abort(403, 'Solo el supervisor vinculado puede finalizar esta relación.');
                }

                $this->access->assertAuthorizedSupervisor($supervisorUid);
            } elseif ($actorRole === 'patient') {
                if ($actorUid !== $patientUid) {
                    abort(403, 'Solo el paciente vinculado puede finalizar esta relación.');
                }
            } else {
                abort(403, 'Tu rol no puede finalizar relaciones de supervisión.');
            }

            $patientRef = $this->db->collection('patients')->document($patientUid);
            $patientSnapshot = $patientRef->snapshot();

            if (! $patientSnapshot->exists()) {
                abort(404, 'Paciente no encontrado.');
            }

            $patient = $patientSnapshot->data();
            $this->assertActiveRelationship($patient, $supervisorUid);
            $now = Carbon::now()->toIso8601String();
            $patient = $this->unlinkedPatient($patient, $now);
            $revokedConsents = $this->consentRevocations($patientUid, $supervisorUid, $now);

            $this->db->runTransaction(function ($transaction) use (
                $patientRef,
                $patient,
                $revokedConsents
            ): void {
                $transaction->set($patientRef, $patient);

                foreach ($revokedConsents as $consent) {
                    $transaction->set($consent['reference'], $consent['data']);
                }
            });

            return response()->json([
                'ok' => true,
                'message' => 'Relación de supervisión finalizada correctamente.',
                'relationship' => $this->unlinkSummary(
                    $supervisorUid,
                    $patientUid,
                    $now,
                    count($revokedConsents)
                ),
                'patient' => [
                    'uid' => $patientUid,
                    'supervisor_uid' => null,
                    'wants_supervision' => false,
                    'supervision_status' => 'not_requested',
                    'supervision_ended_at' => $now,
                ],
            ]);
        } catch (Throwable $e) {
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            Log::error('Unable to unlink supervision relationship.', [
                'exception' => get_class($e),
                'status' => $status,
            ]);

            return ApiErrorResponse::fromException($e);
        }
    }
```

**Extracto:** `app/Http/Controllers/Api/SupervisionController.php`, líneas 287–334.

```php
    private function unlinkedPatient(array $patient, string $now): array
    {
        $patient['supervisor_uid'] = null;
        $patient['wants_supervision'] = false;
        $patient['supervision_status'] = 'not_requested';
        $patient['supervision_ended_at'] = $now;
        $patient['updated_at'] = $now;

        return $patient;
    }

    private function consentRevocations(
        string $patientUid,
        string $supervisorUid,
        string $now
    ): array {
        $documents = $this->db->collection('consents')
            ->where('patient_uid', '=', $patientUid)
            ->documents();
        $revocations = [];

        foreach ($documents as $document) {
            if (! $document->exists()) {
                continue;
            }

            $consent = $document->data();

            if (($consent['supervisor_uid'] ?? null) !== $supervisorUid) {
                continue;
            }

            $consent['explicit_consent'] = false;
            $consent['status'] = 'revoked';
            $consent['revoked_at'] = $now;
            $consent['paused_at'] = null;
            $consent['revocation_reason'] = 'supervision_unlinked';
            $consent['updated_at'] = $now;
            $revocations[] = [
                'reference' => $this->db
                    ->collection('consents')
                    ->document($document->id()),
                'data' => $consent,
            ];
        }

        return $revocations;
    }
```

### Explicación técnica

Entrada: UID de supervisor y paciente en la ruta protegida. Validaciones: comprueba rol e identidad del actor, autorización del supervisor cuando actúa como tal, existencia del paciente y relación activa. Datos: elimina la referencia al supervisor del paciente, cambia su estado a `not_requested` y marca como revocados los consentimientos de esa pareja con motivo `supervision_unlinked`; las escrituras se agrupan en una transacción. Respuesta: JSON con resumen de finalización y estado del paciente o error controlado. Seguridad: la pérdida del vínculo y del consentimiento impide superar las comprobaciones de acceso posteriores. Los documentos históricos se conservan. Las lecturas y la preparación de cambios ocurren antes de la transacción mostrada; el extracto no acredita aislamiento de todas las validaciones ante operaciones concurrentes.

## Anexo 7. Elegibilidad para IA y selección del consentimiento histórico

### Descripción

Determina si existen pacientes accesibles para el resumen IA y utiliza el consentimiento canónico más reciente antes de evaluar su vigencia.

### Archivo o ubicación

`app/Http/Controllers/SupervisorAIController.php`; `app/Services/FirestoreAccessService.php`.

### Fragmento de código

**Extracto:** `app/Http/Controllers/SupervisorAIController.php`, líneas 126–143.

```php
    public function eligibility(Request $request)
    {
        if ($this->access->role($request) !== 'supervisor') {
            abort(403, 'Solo los supervisores pueden usar este endpoint.');
        }

        $supervisorUid = $this->access->uid($request);
        $this->access->assertAuthorizedSupervisor($supervisorUid);
        $eligiblePatients = $this->getAuthorizedPatients($supervisorUid);
        $count = count($eligiblePatients);

        return response()->json([
            'ok' => true,
            'eligible' => $count > 0,
            'eligible_patients_count' => $count,
            'required_scope' => 'ai_chat_summary',
        ]);
    }
```

**Extracto:** `app/Http/Controllers/SupervisorAIController.php`, líneas 154–181.

```php
    private function getAuthorizedPatients($supervisorUid)
    {
        $documents = $this->db
            ->collection('patients')
            ->where('wants_supervision', '=', true)
            ->where('supervisor_uid', '=', $supervisorUid)
            ->documents();

        $patients = [];

        foreach ($documents as $document) {
            if ($document->exists()) {
                $patient = $document->data();
                $patientUid = (string) ($patient['uid'] ?? $document->id());

                if ($this->access->supervisorCanAccessPatient(
                    $supervisorUid,
                    $patientUid,
                    ['ai_chat_summary']
                )) {
                    $patient['uid'] = $patientUid;
                    $patients[] = $patient;
                }
            }
        }

        return $patients;
    }
```

**Extracto:** `app/Services/FirestoreAccessService.php`, líneas 719–749.

```php
    public function supervisorCanAccessPatient(string $supervisorUid, string $patientUid, array $scopes = []): bool
    {
        if ($patientUid === '') {
            return false;
        }

        $patient = $this->db->collection('patients')->document($patientUid)->snapshot();

        if (! $patient->exists()) {
            return false;
        }

        $patientData = $patient->data();

        if (($patientData['supervisor_uid'] ?? null) !== $supervisorUid
            || ($patientData['wants_supervision'] ?? false) !== true
            || ($patientData['supervision_status'] ?? null) !== 'accepted') {
            return false;
        }

        $consent = $this->canonicalConsent($supervisorUid, $patientUid);

        if ($consent === null || ! $this->isActiveConsent($consent)) {
            return false;
        }

        $requestedScopes = $this->normalizeScopeNames($scopes);
        $grantedScopes = $this->consentScopes($consent);

        return $requestedScopes === [] || array_diff($requestedScopes, $grantedScopes) === [];
    }
```

**Extracto:** `app/Services/FirestoreAccessService.php`, líneas 789–847.

```php
    private function canonicalConsent(string $supervisorUid, string $patientUid): ?array
    {
        $canonical = null;
        $canonicalTimestamp = PHP_INT_MIN;
        $documents = $this->db->collection('consents')
            ->where('patient_uid', '=', $patientUid)
            ->documents();

        foreach ($documents as $document) {
            if (! $document->exists()) {
                continue;
            }

            $consent = $document->data();

            if (($consent['supervisor_uid'] ?? null) !== $supervisorUid
                || ! empty($consent['deleted_at'])) {
                continue;
            }

            $timestamp = $this->consentTimestamp($consent);

            if ($canonical === null || $timestamp > $canonicalTimestamp) {
                $canonical = $consent;
                $canonicalTimestamp = $timestamp;
            }
        }

        return $canonical;
    }

    private function isActiveConsent(array $consent): bool
    {
        return ($consent['explicit_consent'] ?? false) === true
            && $this->consentStatus($consent) === 'active'
            && empty($consent['revoked_at'])
            && empty($consent['paused_at']);
    }

    private function consentStatus(array $consent): string
    {
        if (! empty($consent['revoked_at'])
            || ($consent['status'] ?? null) === 'revoked') {
            return 'revoked';
        }

        if (! empty($consent['paused_at']) || ($consent['status'] ?? null) === 'paused') {
            return 'paused';
        }

        if (($consent['explicit_consent'] ?? null) === false) {
            return 'revoked';
        }

        return ($consent['explicit_consent'] ?? false) === true
            && in_array($consent['status'] ?? 'active', ['active', null], true)
                ? 'active'
                : (string) ($consent['status'] ?? 'revoked');
    }
```

**Extracto:** `app/Services/FirestoreAccessService.php`, líneas 884–907.

```php
    private function consentTimestamp(array $consent): int
    {
        foreach (['updated_at', 'accepted_at', 'created_at'] as $field) {
            $value = $consent[$field] ?? null;

            if ($value instanceof Timestamp) {
                return $value->get()->getTimestamp();
            }

            if ($value instanceof \DateTimeInterface) {
                return $value->getTimestamp();
            }

            if (is_numeric($value)) {
                return (int) $value;
            }

            if (is_string($value) && ($timestamp = strtotime($value)) !== false) {
                return $timestamp;
            }
        }

        return 0;
    }
```

### Explicación técnica

Entrada: sesión del supervisor; la elegibilidad no requiere un UID de paciente enviado por el cliente. Validaciones: supervisor autorizado, paciente vinculado con supervisión aceptada y consentimiento activo que incluya `ai_chat_summary`. Datos: consulta `patients` y `consents`, excluye consentimientos de otro supervisor o con `deleted_at` y selecciona el de mayor marca temporal. Respuesta: `eligible`, cantidad de pacientes elegibles y ámbito requerido; puede existir elegibilidad aunque no haya notas. Seguridad: no selecciona simplemente cualquier consentimiento activo antiguo. Primero elige el más reciente y después comprueba vigencia; uno más reciente pausado o revocado bloquea el acceso. Para documentos históricos sin `status`, acepta consentimiento explícito verdadero siempre que no existan señales de pausa o revocación. `consentTimestamp` toma el primer campo interpretable en orden `updated_at`, `accepted_at`, `created_at`; no calcula el máximo entre ellos. En empate conserva el primer documento recorrido, sin desempate explícito. Esta es la implementación actual; la revisión no acredita cuándo se introdujo la corrección.

## Anexo 8. Procesamiento de preguntas del Chat IA

### Descripción

Valida al supervisor y construye el contexto exclusivamente a partir de los pacientes que superan los controles de vínculo y consentimiento.

### Archivo o ubicación

`app/Http/Controllers/SupervisorAIController.php`, método `chat`.

### Fragmento de código

**Extracto:** `app/Http/Controllers/SupervisorAIController.php`, líneas 29–67.

```php
    public function chat(Request $request)
    {
        if ($this->access->role($request) !== 'supervisor') {
            abort(403, 'Solo los supervisores pueden usar este endpoint.');
        }

        $validator = Validator::make($request->all(), [
            'supervisor_uid' => ['required', 'string', 'max:128'],
            'question' => ['required', 'string', 'max:3000'],
            'mode' => ['nullable', 'in:ai,local'],
        ]);

        if ($validator->fails()) {
            return ApiErrorResponse::make(
                'Los datos enviados no son validos.',
                422,
                $validator->errors()->toArray()
            );
        }

        $data = $validator->validated();
        $supervisorUid = $this->access->uid($request);

        if ($data['supervisor_uid'] !== $supervisorUid) {
            abort(403, 'supervisor_uid no coincide con la sesion autenticada.');
        }

        $this->access->assertAuthorizedSupervisor($supervisorUid);

        $question = $this->redactFreeText($data['question']);
        $mode = $data['mode'] ?? 'ai';
        $patients = $this->getAuthorizedPatients($supervisorUid);
        $question = $this->redactPatientIdentifiers($question, $patients);
        $question = $this->sanitizeExternalQuestion($question);
        $patientUids = array_column($patients, 'uid');

        $notes = $this->getNotesForPatients($patientUids);

        $context = $this->buildAIContext($supervisorUid, $patients, $notes);
```

**Extracto:** `app/Http/Controllers/SupervisorAIController.php`, líneas 86–124.

```php
        $aiResponse = $this->ai->askSupervisorAssistant($question, $context);

        if (! $aiResponse['ok']) {
            $fallbackAnswer = $this->generateSafeLocalAnswer(strtolower($question), $context);

            return response()->json([
                'ok' => true,
                'mode' => 'fallback_local',
                'message' => 'El proveedor de IA no está disponible. Se usó una respuesta local segura.',
                'provider_error' => [
                    'code' => $aiResponse['error_code'] ?? 'provider_unavailable',
                    'retryable' => (bool) ($aiResponse['retryable'] ?? false),
                ],
                'supervisor_uid' => $supervisorUid,
                'question' => $question,
                'answer' => $fallbackAnswer,
                'context' => [
                    'authorized_patients_count' => count($patients),
                    'notes_count' => count($notes),
                ],
                ...$this->disabledHistoryState(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'mode' => 'ai',
            'supervisor_uid' => $supervisorUid,
            'question' => $question,
            'answer' => $aiResponse['answer'],
            'model' => $aiResponse['model'] ?? null,
            'usage' => $aiResponse['usage'] ?? null,
            'context' => [
                'authorized_patients_count' => count($patients),
                'notes_count' => count($notes),
            ],
            ...$this->disabledHistoryState(),
        ]);
    }
```

### Explicación técnica

Entrada: `supervisor_uid`, pregunta de hasta 3000 caracteres y modo opcional `ai` o `local`. Validaciones: rol de supervisor, formato de entrada, coincidencia del UID con la sesión y autorización administrativa. Datos: obtiene pacientes autorizados, consulta sus notas y construye el contexto minimizado antes de invocar `AIService`. Respuesta: JSON con respuesta, modo, conteos y, si procede, modelo y uso; si el proveedor falla responde HTTP 200 con `fallback_local` y un código controlado. Seguridad: el cliente no elige libremente pacientes ajenos. El alcance es el conjunto de pacientes elegibles del supervisor, no un único paciente seleccionado. No existe una denegación explícita en `chat` cuando ese conjunto está vacío: en modo IA puede enviarse un contexto vacío. `getNotesForPatients` consulta notas por paciente, las ordena por `created_at` y conserva 20 en total; el límite se aplica después de leerlas.

## Anexo 9. Minimización, seudonimización y filtrado de texto

### Descripción

Reduce el contexto enviado al proveedor a referencias temporales, métricas y tiempos relativos; además filtra patrones de identificación en la pregunta.

### Archivo o ubicación

`app/Http/Controllers/SupervisorAIController.php`, métodos `buildAIContext`, `redactFreeText`, `redactPatientIdentifiers` y `sanitizeExternalQuestion`.

### Fragmento de código

**Extracto:** `app/Http/Controllers/SupervisorAIController.php`, líneas 207–251.

```php
    private function buildAIContext($supervisorUid, $patients, $notes)
    {
        $patientsByUid = [];
        $patientNumber = 0;

        foreach ($patients as $patient) {
            $patientNumber++;
            $patientsByUid[$patient['uid']] = [
                'patient_ref' => sprintf('P-%03d', $patientNumber),
                'recovery_days' => $this->recoveryDays($patient['sobriety_start_date'] ?? null),
            ];
        }

        $cleanNotes = [];

        foreach ($notes as $note) {
            $patientUid = $note['patient_uid'] ?? null;

            if (! $patientUid || ! isset($patientsByUid[$patientUid])) {
                continue;
            }

            $cleanNotes[] = [
                'patient_ref' => $patientsByUid[$patientUid]['patient_ref'],
                'mood_score' => $note['mood_score'] ?? null,
                'anxiety_level' => $note['anxiety_level'] ?? null,
                'craving_level' => $note['craving_level'] ?? null,
                'energy_level' => $note['energy_level'] ?? null,
                'sleep_quality' => $note['sleep_quality'] ?? null,
                'had_relapse' => $note['had_relapse'] ?? false,
                'ai_risk_score' => $note['ai_risk_score'] ?? null,
                'ai_risk_level' => $note['ai_risk_level'] ?? null,
                'days_ago' => $this->daysAgo($note['created_at'] ?? null),
            ];
        }

        return [
            'app' => 'RehabiAnex',
            'privacy_rule' => 'Contexto minimizado de pacientes vinculados con consentimiento activo para ai_chat_summary.',
            'authorized_patients_count' => count($patientsByUid),
            'notes_count' => count($cleanNotes),
            'patients' => array_values($patientsByUid),
            'recent_notes' => $cleanNotes,
        ];
    }
```

**Extracto:** `app/Http/Controllers/SupervisorAIController.php`, líneas 253–306.

```php
    private function redactFreeText(string $text): string
    {
        $text = preg_replace(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
            '[correo omitido]',
            $text
        ) ?? $text;

        return preg_replace(
            '/(?<!\w)(?:\+?\d[\d\s().\-]{7,}\d)(?!\w)/u',
            '[teléfono omitido]',
            $text
        ) ?? $text;
    }

    private function redactPatientIdentifiers(string $text, array $patients): string
    {
        foreach (array_values($patients) as $index => $patient) {
            $reference = sprintf('P-%03d', $index + 1);

            foreach ([
                $patient['uid'] ?? null,
                $patient['full_name'] ?? null,
                $patient['nickname'] ?? null,
            ] as $identifier) {
                if (is_string($identifier) && trim($identifier) !== '') {
                    $text = str_ireplace($identifier, $reference, $text);
                }
            }
        }

        return $text;
    }

    private function sanitizeExternalQuestion(string $text): string
    {
        $text = $this->redactFreeText($text);
        $text = preg_replace(
            '/\b(?:me llamo|se llama|nombre(?:\s+del\s+paciente)?\s*[:=]?)\s+[\p{L}][\p{L}\s.\'\-]{1,80}/iu',
            '[nombre omitido]',
            $text
        ) ?? $text;
        $text = preg_replace(
            '/(?<![\w-])[A-Za-z0-9_-]{20,128}(?![\w-])/u',
            '[identificador omitido]',
            $text
        ) ?? $text;

        return preg_replace(
            '/\b(?:calle|avenida|av\.?|boulevard|blvd\.?|carretera|privada|domicilio|colonia|fraccionamiento)\b[^\n,.;]{2,120}/iu',
            '[direccion omitida]',
            $text
        ) ?? $text;
    }
```

### Explicación técnica

Entrada: pacientes autorizados, sus notas y pregunta del supervisor. Validaciones: descarta notas cuyo UID no esté en el mapa de pacientes y reemplaza identificadores conocidos o patrones de correo, teléfono, nombre declarado y dirección. Datos: genera referencias `P-001`, `P-002`, etc.; selecciona métricas y tiempos relativos, omitiendo nombres, correos, teléfonos, UID originales y texto libre de las notas del contexto estructurado. Respuesta: arreglo minimizado y pregunta filtrada para el servicio IA. Seguridad: las referencias son locales a la construcción del contexto y no equivalen a anonimización irreversible. Las expresiones regulares y sustituciones reducen exposición, pero no garantizan detectar todos los datos identificables ni todas las variantes de direcciones o nombres. Las métricas conservadas siguen siendo información sensible. Estos métodos proyectan campos existentes como `ai_risk_level`; no muestran un modelo que calcule o valide clínicamente ese riesgo.

## Anexo 10. Conexión con el proveedor IA y restricciones del sistema

### Descripción

Envía la pregunta y el contexto a un servicio compatible con mensajes de chat, establece límites de espera y define instrucciones de uso complementario.

### Archivo o ubicación

`app/Services/AIService.php`.

### Fragmento de código

**Extracto:** `app/Services/AIService.php`, líneas 11–75.

```php
    public function askSupervisorAssistant(string $question, array $context): array
    {
        $apiKey = trim((string) config('ai.api_key'));
        $baseUrl = trim((string) config('ai.base_url'));
        $model = trim((string) config('ai.model'));

        if ($apiKey === '' || $baseUrl === '' || $model === '') {
            return $this->failure('configuration_missing', false);
        }

        try {
            $response = Http::connectTimeout(10)
                ->timeout(45)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('ai.site_url'),
                    'X-Title' => config('ai.app_name'),
                ])
                ->post($baseUrl, [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $this->buildSystemPrompt(),
                        ],
                        [
                            'role' => 'user',
                            'content' => $this->buildUserPrompt($question, $context),
                        ],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 700,
                ]);

            if ($response->status() === 429) {
                return $this->failure('provider_rate_limited', true);
            }

            if (in_array($response->status(), [408, 504], true)) {
                return $this->failure('provider_timeout', true);
            }

            if (! $response->successful()) {
                return $this->failure('provider_unavailable', $response->serverError());
            }

            $answer = trim((string) ($response->json('choices.0.message.content') ?? ''));

            if ($answer === '') {
                return $this->failure('empty_response', true);
            }

            return [
                'ok' => true,
                'answer' => $answer,
                'model' => $model,
                'usage' => $this->safeUsage($response->json('usage')),
            ];
        } catch (ConnectionException) {
            return $this->failure('provider_timeout', true);
        } catch (Throwable) {
            return $this->failure('provider_unavailable', true);
        }
    }
```

**Extracto:** `app/Services/AIService.php`, líneas 77–116.

```php
    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Eres un asistente de apoyo para supervisores de RehabiAnex.

Usa únicamente el contexto minimizado entregado por el backend.

Reglas obligatorias:
- No realices diagnósticos ni afirmes que una persona tiene una enfermedad.
- No prescribas medicamentos, tratamientos ni cambios de dosis.
- No proporciones terapia ni simules una sesión terapéutica.
- No sustituyas atención médica, psicológica, de emergencia ni criterio profesional.
- No inventes datos ni identidades.
- No intentes reidentificar referencias como P-001.
- No solicites teléfonos, correos, nombres, contactos de apoyo ni acceso a Firebase.
- No repitas posibles datos personales incluidos accidentalmente en la pregunta.
- Si falta información, indícalo.
- Ante señales graves, recomienda aplicar el protocolo profesional o de emergencia correspondiente.

Incluye cuando corresponda: "Esto no representa un diagnóstico; es un resumen de registros reportados por el usuario."
PROMPT;
    }

    private function buildUserPrompt(string $question, array $context): string
    {
        $jsonContext = json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return <<<PROMPT
Pregunta del supervisor:
{$question}

Contexto autorizado, minimizado y seudonimizado:
{$jsonContext}

Responde únicamente con base en este contexto.
PROMPT;
    }
```

### Explicación técnica

Entrada: pregunta filtrada y contexto minimizado; clave, URL y modelo se leen desde la configuración sin exponer sus valores. Validaciones: exige configuración no vacía, clasifica HTTP 429, tiempos de espera y respuestas fallidas, y rechaza contenido vacío. Datos: realiza una petición HTTP con mensajes `system` y `user`, temperatura 0.2 y máximo de 700 tokens; no consulta Firestore directamente. Respuesta: arreglo con contenido, modelo y uso permitido, o fallo normalizado. Seguridad: no devuelve el cuerpo bruto de errores del proveedor; `safeUsage` limita los campos de uso. El prompt prohíbe diagnóstico, prescripción, terapia, sustitución profesional y reidentificación. Son instrucciones al modelo: el código mostrado no contiene una validación semántica posterior que garantice su cumplimiento. La integración HTTP es real en el código, pero esta revisión no verifica una llamada real ni identifica un proveedor activo a partir de configuración privada.

## Anexo 11. Respuesta local de respaldo e historial deshabilitado

### Descripción

Mantiene una respuesta determinista cuando se elige modo local o falla el proveedor, sin necesitar inferencia externa para ese resumen.

Estado pre-hardening: esta salida está marcada para revisión de privacidad y
criterio clínico porque presenta agregados de seguimiento. No pertenece a FCM,
no debe utilizarse como texto de notificación, no sustituye alertas clínicas
tipadas y no debe mostrar nombres, alias ni identificadores humanos.

### Archivo o ubicación

`app/Http/Controllers/SupervisorAIController.php`, métodos `generateSafeLocalAnswer` y `disabledHistoryState`; rama local de `chat`.

### Fragmento de código

**Extracto:** `app/Http/Controllers/SupervisorAIController.php`, líneas 69–84.

```php
        if ($mode === 'local') {
            $answer = $this->generateSafeLocalAnswer(strtolower($question), $context);

            return response()->json([
                'ok' => true,
                'mode' => 'local',
                'supervisor_uid' => $supervisorUid,
                'question' => $question,
                'answer' => $answer,
                'context' => [
                    'authorized_patients_count' => count($patients),
                    'notes_count' => count($notes),
                ],
                ...$this->disabledHistoryState(),
            ]);
        }
```

**Extracto:** `app/Http/Controllers/SupervisorAIController.php`, líneas 334–364.

```php
    private function generateSafeLocalAnswer(string $question, array $context): string
    {
        $notes = $context['recent_notes'] ?? [];
        $patients = (int) ($context['authorized_patients_count'] ?? 0);

        if ($patients === 0) {
            return 'No hay pacientes vinculados con consentimiento activo para el resumen de IA.';
        }

        $highAnxiety = count(array_filter(
            $notes,
            fn (array $note): bool => (int) ($note['anxiety_level'] ?? 0) >= 7
        ));
        $highCraving = count(array_filter(
            $notes,
            fn (array $note): bool => (int) ($note['craving_level'] ?? 0) >= 7
        ));
        $highRisk = count(array_filter(
            $notes,
            fn (array $note): bool => in_array(
                $note['ai_risk_level'] ?? null,
                ['high', 'critical'],
                true
            )
        ));

        return "Resumen local seguro: {$patients} pacientes autorizados, "
            .count($notes)." registros recientes, {$highAnxiety} con ansiedad alta, "
            ."{$highCraving} con craving alto y {$highRisk} con señales de riesgo alto. "
            .'Esto no es diagnóstico, prescripción ni terapia y no sustituye atención profesional.';
    }
```

**Extracto:** `app/Http/Controllers/SupervisorAIController.php`, líneas 145–152.

```php
    private function disabledHistoryState(): array
    {
        return [
            'stored' => false,
            'session_id' => null,
            'history_persistence' => 'disabled',
        ];
    }
```

### Explicación técnica

Entrada: contexto previamente autorizado y minimizado. Validaciones: devuelve un mensaje específico cuando no hay pacientes; en otro caso cuenta registros con ansiedad o craving de al menos 7 y registros etiquetados con riesgo `high` o `critical`. Datos: opera en memoria sobre las notas ya cargadas, sin llamar al proveedor para generar esta respuesta. Respuesta: texto agregado con advertencia de alcance complementario; `chat` informa `local` o `fallback_local`. Seguridad: el flujo informa `stored=false`, sesión nula e historial deshabilitado; no se presenta persistencia del historial como funcionalidad activa. La respuesta local es un resumen por reglas, no un modelo IA ni una evaluación clínica. Aunque el método recibe la pregunta, no la utiliza para modificar el resumen. Los conteos corresponden a registros, no necesariamente a pacientes distintos; esta ausencia de persistencia en el controlador no acredita políticas de retención del proveedor externo.

## Anexo 12. Pruebas automatizadas de seguridad, consentimiento y privacidad

### Descripción

Documenta casos existentes que comprueban acceso sin token, respuesta del endpoint de salud, minimización del contexto y pérdida de acceso al pausar o terminar la supervisión.

### Archivo o ubicación

`tests/Feature/ApiSecurityTest.php`; `tests/Feature/HealthEndpointSecurityTest.php`; `tests/Unit/SupervisorAISecurityTest.php`; `tests/Feature/SupervisionLifecycleTest.php`.

### Fragmento de código

**Extracto:** `tests/Feature/ApiSecurityTest.php`, líneas 12–40.

```php
    public function test_protected_endpoints_reject_requests_without_a_firebase_token(): void
    {
        $this->app->instance(FirebaseService::class, Mockery::mock(FirebaseService::class));

        $requests = [
            ['GET', '/api/patients'],
            ['GET', '/api/patients/another-user'],
            ['POST', '/api/patients/another-user/notes'],
            ['POST', '/api/ai/supervisor-chat'],
            ['GET', '/api/achievements'],
            ['POST', '/api/support-contacts'],
            ['PATCH', '/api/supervision-requests/request-1/respond'],
            ['GET', '/api/ping'],
        ];

        foreach ($requests as [$method, $uri]) {
            $this->json($method, $uri)
                ->assertUnauthorized()
                ->assertJson([
                    'ok' => false,
                    'errors' => [],
                ])
                ->assertJsonStructure([
                    'ok',
                    'message',
                    'errors',
                ]);
        }
    }
```

**Extracto:** `tests/Feature/HealthEndpointSecurityTest.php`, líneas 10–30.

```php
    public function test_public_health_endpoint_exposes_only_safe_status(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertHeaderMissing('X-Powered-By')
            ->assertExactJson([
                'ok' => true,
                'status' => 'healthy',
            ]);
    }

    public function test_health_endpoint_is_the_only_public_non_auth_api_route_and_is_rate_limited(): void
    {
        $health = collect(Route::getRoutes())->first(
            fn ($route) => $route->uri() === 'api/health'
        );

        $this->assertNotNull($health);
        $this->assertContains('throttle:60,1', $health->gatherMiddleware());
        $this->assertNotContains('firebase.auth', $health->gatherMiddleware());
    }
```

**Extracto:** `tests/Unit/SupervisorAISecurityTest.php`, líneas 13–57.

```php
    public function test_provider_context_is_minimized_and_pseudonymized(): void
    {
        $context = $this->controllerMethod('buildAIContext', [
            'supervisor-secret',
            [[
                'uid' => 'patient-secret',
                'full_name' => 'Nombre Privado',
                'email' => 'patient@example.com',
                'phone' => '+524491234567',
                'gender' => 'private',
                'sobriety_start_date' => now()->subDays(20)->toIso8601String(),
                'supervisor_uid' => 'supervisor-secret',
                'wants_supervision' => true,
            ]],
            [[
                'note_id' => 'note-secret',
                'patient_uid' => 'patient-secret',
                'mood' => 'Texto libre privado',
                'mood_score' => 4,
                'anxiety_level' => 8,
                'craving_level' => 7,
                'energy_level' => 3,
                'sleep_quality' => 2,
                'had_relapse' => false,
                'triggers' => ['Dato privado'],
                'note_text' => 'Correo patient@example.com y teléfono +524491234567',
                'ai_risk_score' => 72,
                'ai_risk_level' => 'high',
                'created_at' => now()->subDay()->toIso8601String(),
            ]],
        ]);
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE);

        $this->assertSame('P-001', $context['patients'][0]['patient_ref']);
        $this->assertSame(20, $context['patients'][0]['recovery_days']);
        $this->assertSame('P-001', $context['recent_notes'][0]['patient_ref']);
        $this->assertSame(8, $context['recent_notes'][0]['anxiety_level']);
        $this->assertStringNotContainsString('patient-secret', $encoded);
        $this->assertStringNotContainsString('supervisor-secret', $encoded);
        $this->assertStringNotContainsString('Nombre Privado', $encoded);
        $this->assertStringNotContainsString('patient@example.com', $encoded);
        $this->assertStringNotContainsString('+524491234567', $encoded);
        $this->assertStringNotContainsString('Texto libre privado', $encoded);
        $this->assertStringNotContainsString('Dato privado', $encoded);
    }
```

**Extracto:** `tests/Feature/SupervisionLifecycleTest.php`, líneas 58–117.

```php
        $consent = $access->prepareCreate('consents', [
            'supervisor_uid' => 'supervisor-1',
            'explicit_consent' => true,
            'consent_text' => 'Autorizo compartir información.',
            'scope' => ['patient_notes'],
        ], $patientRequest);
        $store->data['consents']['consent-1'] = array_replace($consent, [
            'consent_id' => 'consent-1',
        ]);

        $this->assertTrue(
            $access->supervisorCanAccessPatient('supervisor-1', 'patient-1', ['patient_notes'])
        );

        $pause = $access->prepareUpdate(
            'consents',
            $store->data['consents']['consent-1'],
            ['status' => 'paused'],
            $patientRequest
        );
        $store->data['consents']['consent-1'] = array_replace(
            $store->data['consents']['consent-1'],
            $pause
        );
        $this->assertSame('paused', $pause['status']);
        $this->assertFalse($pause['explicit_consent']);
        $this->assertFalse(
            $access->supervisorCanAccessPatient('supervisor-1', 'patient-1', ['patient_notes'])
        );

        $resume = $access->prepareUpdate(
            'consents',
            $store->data['consents']['consent-1'],
            ['status' => 'active'],
            $patientRequest
        );
        $store->data['consents']['consent-1'] = array_replace(
            $store->data['consents']['consent-1'],
            $resume
        );
        $this->assertTrue(
            $access->supervisorCanAccessPatient('supervisor-1', 'patient-1', ['patient_notes'])
        );

        $unlink = $controller->unlink($supervisorRequest, 'supervisor-1', 'patient-1');
        $patient = $store->data['patients']['patient-1'];
        $revoked = $store->data['consents']['consent-1'];

        $this->assertSame(200, $unlink->getStatusCode());
        $this->assertNull($patient['supervisor_uid']);
        $this->assertFalse($patient['wants_supervision']);
        $this->assertSame('not_requested', $patient['supervision_status']);
        $this->assertNotEmpty($patient['supervision_ended_at']);
        $this->assertFalse($revoked['explicit_consent']);
        $this->assertSame('revoked', $revoked['status']);
        $this->assertSame('supervision_unlinked', $revoked['revocation_reason']);
        $this->assertFalse(
            $access->supervisorCanAccessPatient('supervisor-1', 'patient-1', ['patient_notes'])
        );
    }
```

### Explicación técnica

Entrada: solicitudes HTTP internas de Laravel, usuarios ficticios y registros sintéticos. Validaciones: espera 401 para rutas protegidas sin token; exige 200 y JSON exacto en salud; comprueba referencias seudonimizadas y ausencia de identificadores y texto privado; verifica que pausar elimina acceso, reactivar lo recupera y desvincular revoca consentimiento. Datos: el caso de autenticación usa Mockery; la prueba de minimización invoca el método mediante su auxiliar de reflexión; el ciclo de supervisión usa el entorno simulado preparado al inicio del método. El último bloque es una continuación de esa prueba, no un caso ejecutable aislado. Respuesta: aserciones de PHPUnit que pasan o fallan al ejecutar la suite. Seguridad: estos casos verifican contratos concretos con datos sintéticos y no constituyen pruebas con pacientes reales. No se ejecutaron aquí, por lo que no se informa un resultado aprobado. También existen pruebas específicas de roles administrativos, elegibilidad y respaldo local en `AdminRouteProtectionTest`, `SupervisorAIPatientScopeTest` y `SupervisorAISecurityTest`; las simulaciones no equivalen a comprobar Render o el proveedor IA en vivo.
