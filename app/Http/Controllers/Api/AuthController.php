<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiErrorResponse;
use App\Rules\MexicoPhone;
use App\Rules\NonBlankText;
use App\Services\FcmNotificationDispatcher;
use App\Services\FirebaseService;
use App\Support\MexicoPhoneNumber;
use App\Support\PatientDisplayName;
use App\Support\ProfileFormOptions;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Kreait\Firebase\Exception\Auth\EmailExists;
use Throwable;

class AuthController extends Controller
{
    private $auth;

    private $db;

    private FcmNotificationDispatcher $fcmNotifications;

    public function __construct(
        FirebaseService $firebase,
        ?FcmNotificationDispatcher $fcmNotifications = null,
    ) {
        $this->auth = $firebase->auth();
        $this->db = $firebase->db();
        $this->fcmNotifications = $fcmNotifications ?? app(FcmNotificationDispatcher::class);
    }

    public function register(Request $request)
    {
        $data = $this->payload($request);
        $data['phone'] = MexicoPhoneNumber::normalize($data['phone'] ?? null);
        $validator = Validator::make($data, [
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'min:6', 'max:4096'],
            'full_name' => ['required', 'string', new NonBlankText, 'max:120'],
            'role' => ['required', Rule::in($this->publicRegistrationRoles())],
            'phone' => ['nullable', 'string', new MexicoPhone],
            'gender' => ['nullable', 'string', 'max:40'],
            'age' => ['nullable', 'integer', 'min:1', 'max:120'],
            'supervisor_type' => ['nullable', 'string', Rule::in(ProfileFormOptions::SUPERVISOR_TYPES)],
            'supervisor_type_other' => [
                'nullable',
                'string',
                new NonBlankText,
                'max:80',
                Rule::requiredIf(
                    ($data['role'] ?? null) === 'supervisor'
                    && ($data['supervisor_type'] ?? null) === 'other'
                ),
            ],
            'privacy_notice_accepted' => ['required', 'accepted'],
            'privacy_notice_version' => [
                'required',
                'string',
                Rule::in([$this->privacyNoticeVersion()]),
            ],
        ], [
            'email.max' => 'El correo no puede exceder 254 caracteres.',
            'password.max' => 'La contraseña excede la longitud permitida.',
            'supervisor_type.in' => 'El tipo de supervisor seleccionado no es válido.',
            'supervisor_type_other.required' => 'Describe el tipo de supervisor cuando seleccionas Otro.',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        try {
            $user = $this->auth->createUser([
                'email' => $data['email'],
                'password' => $data['password'],
                'displayName' => $data['full_name'],
                'disabled' => false,
                'emailVerified' => false,
            ]);

            $this->auth->setCustomUserClaims($user->uid, [
                'role' => $data['role'],
            ]);

            $acceptedAt = Carbon::now()->toIso8601String();
            $profile = $this->storeProfile($user->uid, $data['role'], [
                'email' => $user->email,
                'full_name' => $data['full_name'],
                'phone' => $data['phone'] ?? null,
                'gender' => $data['gender'] ?? null,
                'age' => isset($data['age']) ? (int) $data['age'] : null,
                'supervisor_type' => $data['supervisor_type'] ?? null,
                'supervisor_type_other' => ($data['role'] ?? null) === 'supervisor'
                    ? ($data['supervisor_type_other'] ?? null)
                    : null,
                'provider' => 'password',
                'status' => 'active',
                'legal_acceptance' => [
                    'uid' => $user->uid,
                    'role' => $data['role'],
                    'privacy_notice_version' => $data['privacy_notice_version'],
                    'accepted_at' => $acceptedAt,
                    'source' => 'android',
                    'explicit_acceptance' => true,
                    'status' => 'accepted',
                ],
            ]);

            $tokenResponse = $this->firebasePasswordRequest([
                'email' => $data['email'],
                'password' => $data['password'],
                'returnSecureToken' => true,
            ]);

            if (! $tokenResponse->successful()) {
                try {
                    $collection = $data['role'] === 'supervisor' ? 'supervisors' : 'patients';
                    $this->db->collection($collection)->document($user->uid)->delete();
                    $this->auth->deleteUser($user->uid);
                } catch (Throwable $cleanupError) {
                    Log::error('No se pudo revertir un registro sin sesion inicial.', [
                        'exception' => get_class($cleanupError),
                    ]);
                }

                return $this->firebaseError(
                    'No se pudo completar el registro ni iniciar la sesion.',
                    $tokenResponse
                );
            }

            $tokens = $this->authTokens($tokenResponse->json());
            $auth = $this->stableAuth(
                $this->userRecord($this->auth->getUser($user->uid), $data['role']),
                $profile,
                $tokens
            );

            if ($data['role'] === 'supervisor') {
                try {
                    $this->fcmNotifications->sendSupervisorValidationRequired(
                        $user->uid,
                        (string) ($profile['created_at'] ?? $acceptedAt),
                    );
                } catch (Throwable $notificationError) {
                    Log::warning('FCM supervisor validation dispatch skipped.', [
                        'exception' => get_class($notificationError),
                    ]);
                }
            }

            return response()->json([
                'ok' => true,
                'message' => 'Usuario creado correctamente en Firebase Authentication.',
                'auth' => $auth,
                'profile' => $this->stableProfile($profile, $data['role']),
                'tokens' => $tokens,
            ], 201);
        } catch (EmailExists) {
            return ApiErrorResponse::make(
                'Ya existe una cuenta registrada con este correo.',
                409,
                ['email' => ['El correo ya está registrado.']]
            );
        } catch (Throwable $e) {
            return $this->error('No se pudo registrar el usuario.', $e);
        }
    }

    public function login(Request $request)
    {
        $data = $this->payload($request);
        $validator = Validator::make($data, [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $response = $this->firebasePasswordRequest([
            'email' => $data['email'],
            'password' => $data['password'],
            'returnSecureToken' => true,
        ]);

        if (! $response->successful()) {
            return $this->firebaseError('Credenciales invalidas o usuario no disponible.', $response);
        }

        $tokens = $response->json();
        $uid = $tokens['localId'] ?? null;
        $user = $uid ? $this->auth->getUser($uid) : null;
        $normalizedTokens = $this->authTokens($tokens);
        $role = $user ? $this->roleForUser($user, 'patient') : 'patient';
        $profile = $uid ? $this->profileForUid($uid, $role) : null;

        if ($this->deletionBlocksAccess($profile)) {
            return $this->deletionBlockedResponse();
        }

        if ($uid !== null && $profile !== null) {
            $profile = $this->ensurePrivacyNoticeReadPending($uid, $role, $profile);
        }

        $normalizedProfile = $this->stableProfile($profile, $role);

        return response()->json([
            'ok' => true,
            'message' => 'Login correcto.',
            'auth' => $user
                ? $this->stableAuth($this->userRecord($user, $role), $normalizedProfile, $normalizedTokens)
                : null,
            'profile' => $normalizedProfile,
            'tokens' => $normalizedTokens,
        ]);
    }

    public function refresh(Request $request)
    {
        $data = $this->payload($request);
        $validator = Validator::make($data, [
            'refresh_token' => ['required', 'string', 'max:16384'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $apiKey = $this->apiKey();
        try {
            $response = Http::asForm()
                ->timeout(30)
                ->post($this->firebaseAuthUrl(
                    'securetoken.googleapis.com',
                    '/v1/token',
                    $apiKey
                ), [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $data['refresh_token'],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Firebase refresh provider unavailable.', [
                'exception' => get_class($e),
            ]);

            return ApiErrorResponse::make(
                'No se pudo refrescar la sesion por un fallo temporal del proveedor.',
                502
            );
        }

        if (! $response->successful()) {
            return $this->refreshError($response);
        }

        $tokens = $response->json();
        $uid = (string) ($tokens['user_id'] ?? '');

        if ($uid !== '') {
            if ($this->deletionBlocksAccess($this->profileForUid($uid))) {
                return $this->deletionBlockedResponse();
            }
        }

        return response()->json([
            'ok' => true,
            'message' => 'Token refrescado correctamente.',
            'tokens' => [
                'id_token' => $tokens['id_token'] ?? null,
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'expires_in' => isset($tokens['expires_in']) ? (int) $tokens['expires_in'] : null,
                'token_type' => $tokens['token_type'] ?? 'Bearer',
                'uid' => $tokens['user_id'] ?? null,
                'project_id' => $tokens['project_id'] ?? null,
            ],
        ]);
    }

    public function me(Request $request)
    {
        try {
            $uid = (string) $request->attributes->get('firebase_uid');
            $user = $request->attributes->get('firebase_user');
            $role = $this->roleForUser($user, 'patient');
            $storedProfile = $this->profileForUid($uid, $role);
            if ($storedProfile !== null) {
                $storedProfile = $this->ensurePrivacyNoticeReadPending($uid, $role, $storedProfile);
            }
            $profile = $this->stableProfile($storedProfile, $role);
            if ($role === 'patient') {
                $profile['supervisor'] = $this->linkedSupervisorSummary($profile);
            }
            $consentSummary = $this->consentSummary($uid, $role);

            if ($consentSummary !== null) {
                $profile['consent_summary'] = $consentSummary;
            }

            return response()->json([
                'ok' => true,
                'auth' => $this->stableAuth($this->userRecord($user, $role), $profile),
                'profile' => $profile,
            ]);
        } catch (Throwable $e) {
            return $this->error('Token invalido o expirado.', $e, 401);
        }
    }

    public function logout(Request $request)
    {
        try {
            $uid = (string) $request->attributes->get('firebase_uid');
            $tokenHash = (string) $request->attributes->get('firebase_token_hash');
            $expiresAt = $request->attributes->get('firebase_token_expires_at');

            $this->db->collection('revoked_tokens')->document($tokenHash)->set([
                'token_hash' => $tokenHash,
                'uid' => $uid,
                'revoked_at' => Carbon::now()->toIso8601String(),
                'expires_at' => $expiresAt instanceof \DateTimeInterface
                    ? $expiresAt
                    : null,
            ]);

            $this->auth->revokeRefreshTokens($uid);

            // Contrato FCM vigente: el cliente invoca
            // DELETE /api/notifications/fcm-token antes de cerrar la sesión.
            // Este endpoint revoca la sesión Firebase; la revocación del token
            // de dispositivo permanece en su endpoint autenticado y acotado.

            return response()->json([
                'ok' => true,
                'message' => 'Sesión cerrada correctamente.',
            ]);
        } catch (Throwable $e) {
            return $this->error('No se pudo cerrar la sesion.', $e, 401);
        }
    }

    public function syncEmail(Request $request)
    {
        try {
            $uid = (string) $request->attributes->get('firebase_uid');
            $user = $request->attributes->get('firebase_user');
            $email = trim((string) ($user->email ?? ''));

            if ($email === ''
                || filter_var($email, FILTER_VALIDATE_EMAIL) === false
                || ($user->emailVerified ?? false) !== true) {
                return ApiErrorResponse::make(
                    'Firebase Authentication todavía no confirma un correo verificado para sincronizar.',
                    409,
                    ['email' => ['Completa la verificación del nuevo correo y renueva el ID token.']]
                );
            }

            $role = $this->roleForUser($user, 'patient');
            $profile = $this->stableProfile(
                $this->storeProfile($uid, $role, ['email' => $email]),
                $role
            );

            return response()->json([
                'ok' => true,
                'message' => 'Correo verificado sincronizado correctamente.',
                'profile' => $profile,
            ]);
        } catch (Throwable $e) {
            return $this->error('No se pudo sincronizar el correo verificado.', $e);
        }
    }

    public function google(Request $request)
    {
        $data = $this->payload($request);
        $validator = Validator::make($data, [
            'id_token' => ['nullable', 'string'],
            'access_token' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        if (empty($data['id_token']) && empty($data['access_token'])) {
            return $this->validationError([
                'id_token' => ['Envia id_token de Google o access_token de Google.'],
            ]);
        }

        $providerToken = ! empty($data['id_token'])
            ? 'id_token='.$data['id_token']
            : 'access_token='.$data['access_token'];

        $response = $this->firebaseIdpRequest([
            'postBody' => $providerToken.'&providerId=google.com',
            'requestUri' => config('app.url', 'http://localhost'),
            'returnSecureToken' => true,
            'returnIdpCredential' => true,
        ]);

        if (! $response->successful()) {
            return $this->firebaseError('No se pudo iniciar sesion con Google.', $response);
        }

        return $this->finishFederatedLogin($response->json(), 'patient');
    }

    public function firebase(Request $request)
    {
        $data = $this->payload($request);
        $validator = Validator::make($data, [
            'id_token' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        try {
            $verified = $this->auth->verifyIdToken($data['id_token']);
            $uid = $verified->claims()->get('sub');
            $user = $this->auth->getUser($uid);
            $role = $this->roleForUser($user, 'patient');
            $currentProfile = $this->profileForUid($uid, $role);

            if ($this->deletionBlocksAccess($currentProfile)) {
                return $this->deletionBlockedResponse();
            }

            if (! isset($user->customClaims['role'])) {
                $this->auth->setCustomUserClaims($uid, ['role' => 'patient']);
            }

            $profile = $this->stableProfile(
                $this->syncProviderProfile($user, $role, 'firebase'),
                $role
            );

            if ($this->deletionBlocksAccess($profile)) {
                return $this->deletionBlockedResponse();
            }

            return response()->json([
                'ok' => true,
                'message' => 'Token Firebase verificado correctamente.',
                'auth' => $this->stableAuth($this->userRecord($user, $role), $profile),
                'profile' => $profile,
            ]);
        } catch (Throwable $e) {
            return $this->error('Token Firebase invalido o expirado.', $e, 401);
        }
    }

    private function finishFederatedLogin(array $tokens, string $defaultRole)
    {
        $idToken = $tokens['idToken'] ?? null;

        if (! $idToken) {
            return ApiErrorResponse::make(
                'Firebase no pudo completar el inicio de sesion.',
                502
            );
        }

        $verified = $this->auth->verifyIdToken($idToken, false, 60);
        $uid = $verified->claims()->get('sub');
        $user = $this->auth->getUser($uid);
        $role = $this->roleForUser($user, $defaultRole);
        $currentProfile = $this->profileForUid($uid, $role);

        if ($this->deletionBlocksAccess($currentProfile)) {
            return $this->deletionBlockedResponse();
        }

        if (! isset($user->customClaims['role'])) {
            $this->auth->setCustomUserClaims($uid, ['role' => 'patient']);
        }

        $profile = $this->stableProfile(
            $this->syncProviderProfile($user, $role, 'google.com'),
            $role
        );

        if ($this->deletionBlocksAccess($profile)) {
            return $this->deletionBlockedResponse();
        }
        $stableTokens = $this->authTokens($tokens);

        return response()->json([
            'ok' => true,
            'message' => 'Login con Google correcto.',
            'auth' => $this->stableAuth($this->userRecord($user, $role), $profile, $stableTokens),
            'profile' => $profile,
            'tokens' => $stableTokens,
        ]);
    }

    private function storeProfile(string $uid, string $role, array $data): array
    {
        $now = Carbon::now()->toIso8601String();
        $collection = $this->profileCollection($role);
        $snapshot = $this->db->collection($collection)->document($uid)->snapshot();
        $current = $snapshot->exists() ? $snapshot->data() : [];

        $profile = array_filter($data, static fn ($value) => $value !== null);
        $profile['uid'] = $uid;
        $profile['role'] = $role;
        $profile['created_at'] = $current['created_at'] ?? $now;
        $profile['updated_at'] = $now;

        if ($role === 'supervisor') {
            $profile['authorized'] = (bool) ($current['authorized'] ?? false);
            $profile['verified'] = (bool) ($current['verified'] ?? false);
            $profile['status'] = $current['status'] ?? 'pending_review';
            $profile['authorized_at'] = $current['authorized_at'] ?? null;
            $profile['verified_at'] = $current['verified_at'] ?? null;
            $profile['supervisor_code'] = $current['supervisor_code'] ?? null;
            $profile['supervisor_type'] = $profile['supervisor_type'] ?? ($current['supervisor_type'] ?? 'support_sponsor');
        } elseif ($role === 'patient') {
            $profile['wants_supervision'] = $current['wants_supervision'] ?? false;
            $profile['is_anonymous'] = $current['is_anonymous'] ?? false;
            $profile['supervisor_uid'] = $current['supervisor_uid'] ?? null;
            $profile['supervision_status'] = $current['supervision_status'] ?? 'not_requested';
        } else {
            $profile['status'] = $current['status'] ?? 'active';
        }

        $profile = array_replace($current, $profile);
        if (! is_array($profile['privacy_notice_read'] ?? null)) {
            $profile['privacy_notice_read'] = $this->pendingPrivacyNoticeRead();
        }
        $this->db->collection($collection)->document($uid)->set($profile);

        return $profile;
    }

    private function syncProviderProfile($user, string $role, string $provider): array
    {
        return $this->storeProfile($user->uid, $role, [
            'email' => $user->email,
            'full_name' => $user->displayName ?: ($user->email ? Str::before($user->email, '@') : 'Usuario RehabiAnex'),
            'phone' => $user->phoneNumber,
            'photo_url' => $user->photoUrl,
            'provider' => $provider,
            'status' => $user->disabled ? 'disabled' : 'active',
        ]);
    }

    private function profileForUid(string $uid, ?string $role = null): ?array
    {
        $profileCollections = config('firestore.profile_collections', []);
        $collections = $role !== null && isset($profileCollections[$role])
            ? [$profileCollections[$role]]
            : array_values($profileCollections);

        foreach ($collections as $collection) {
            $snapshot = $this->db->collection($collection)->document($uid)->snapshot();

            if ($snapshot->exists()) {
                $profile = $snapshot->data();

                $profile['collection'] = $collection;

                return $profile;
            }
        }

        return null;
    }

    private function deletionBlocksAccess(?array $profile): bool
    {
        return in_array(
            $profile['data_deletion_status'] ?? null,
            ['pending_manual_review', 'processing', 'completed'],
            true
        );
    }

    private function deletionBlockedResponse()
    {
        return ApiErrorResponse::make(
            'La cuenta tiene una solicitud de eliminación en curso y no admite nuevos accesos ni sincronizaciones.',
            423
        );
    }

    private function linkedSupervisorSummary(array $patient): ?array
    {
        $supervisorUid = trim((string) ($patient['supervisor_uid'] ?? ''));

        if ($supervisorUid === '' || ($patient['supervision_status'] ?? null) !== 'accepted') {
            return null;
        }

        $snapshot = $this->db->collection('supervisors')->document($supervisorUid)->snapshot();

        if (! $snapshot->exists()) {
            return null;
        }

        $supervisor = $snapshot->data();

        return [
            'uid' => $supervisorUid,
            'full_name' => (string) (($supervisor['full_name'] ?? null) ?: 'Supervisor RehabiAnex'),
            'supervisor_type' => $supervisor['supervisor_type'] ?? null,
        ];
    }

    private function roleForUser($user, string $default): string
    {
        $role = $user->customClaims['role'] ?? $default;

        return in_array($role, ['patient', 'supervisor', 'admin'], true) ? $role : 'patient';
    }

    private function firebasePasswordRequest(array $payload)
    {
        $apiKey = $this->apiKey();

        return Http::timeout(30)
            ->post($this->firebaseAuthUrl(
                'identitytoolkit.googleapis.com',
                '/v1/accounts:signInWithPassword',
                $apiKey
            ), $payload);
    }

    private function firebaseIdpRequest(array $payload)
    {
        $apiKey = $this->apiKey();

        return Http::timeout(30)
            ->post($this->firebaseAuthUrl(
                'identitytoolkit.googleapis.com',
                '/v1/accounts:signInWithIdp',
                $apiKey
            ), $payload);
    }

    private function firebaseAuthUrl(string $service, string $path, string $apiKey): string
    {
        $emulatorHost = trim((string) config('services.firebase.auth_emulator_host'));

        if ($emulatorHost === '') {
            return "https://{$service}{$path}?key={$apiKey}";
        }

        if (preg_match('/^(?:127\.0\.0\.1|localhost):\d{2,5}$/', $emulatorHost) !== 1) {
            abort(500, 'La configuración local del emulador Firebase no es válida.');
        }

        return "http://{$emulatorHost}/{$service}{$path}?key={$apiKey}";
    }

    private function authTokens(array $tokens): array
    {
        return [
            'id_token' => $tokens['idToken'] ?? null,
            'refresh_token' => $tokens['refreshToken'] ?? null,
            'expires_in' => isset($tokens['expiresIn']) ? (int) $tokens['expiresIn'] : null,
            'token_type' => 'Bearer',
            'uid' => $tokens['localId'] ?? null,
            'is_new_user' => $tokens['isNewUser'] ?? null,
        ];
    }

    private function userRecord($user, ?string $role = null): array
    {
        return [
            'uid' => $user->uid,
            'email' => $user->email,
            'email_verified' => $user->emailVerified,
            'display_name' => $user->displayName,
            'phone_number' => $user->phoneNumber,
            'photo_url' => $user->photoUrl,
            'disabled' => $user->disabled,
            'role' => $role ?? ($user->customClaims['role'] ?? null),
            'providers' => array_map(static fn ($provider) => $provider->providerId, $user->providerData),
        ];
    }

    private function stableAuth(array $auth, array $profile, array $tokens = []): array
    {
        $displayName = $profile['safe_display_name']
            ?? $profile['display_name']
            ?? $profile['full_name']
            ?? ($auth['display_name'] ?? null);
        $stable = array_replace($auth, [
            'full_name' => $profile['full_name'] ?? ($auth['display_name'] ?? null),
            'display_name' => $displayName,
            'safe_display_name' => $displayName,
        ]);

        if ($tokens !== []) {
            $stable['id_token'] = $tokens['id_token'] ?? null;
            $stable['refresh_token'] = $tokens['refresh_token'] ?? null;
            $stable['expires_in'] = $tokens['expires_in'] ?? null;
            $stable['token_type'] = $tokens['token_type'] ?? 'Bearer';
        }

        return $stable;
    }

    private function stableProfile(?array $profile, string $role): array
    {
        $profile ??= [];

        if ($role === 'admin') {
            $allowed = array_intersect_key($profile, array_flip([
                'uid',
                'role',
                'collection',
                'full_name',
                'email',
                'status',
                'created_at',
                'updated_at',
                'privacy_notice_read',
            ]));

            return array_replace([
                'uid' => null,
                'role' => 'admin',
                'collection' => 'admins',
                'full_name' => null,
                'email' => null,
                'status' => 'active',
                'created_at' => null,
                'updated_at' => null,
                'privacy_notice_read' => null,
            ], $allowed, [
                'role' => 'admin',
                'collection' => 'admins',
                'privacy_notice_read' => $this->privacyNoticeReadForResponse(
                    $profile['privacy_notice_read'] ?? null
                ),
            ]);
        }

        $stable = array_replace([
            'uid' => null,
            'collection' => $this->stableProfileCollection($role),
            'document_id' => null,
            'email' => null,
            'full_name' => null,
            'display_name' => null,
            'safe_display_name' => null,
            'nickname' => null,
            'phone' => null,
            'photo_url' => null,
            'age' => null,
            'gender' => null,
            'role' => $role,
            'status' => $role === 'supervisor' ? 'pending_review' : 'active',
            'authorized' => $role === 'patient',
            'verified' => $role === 'patient',
            'supervisor_type' => null,
            'supervisor_type_other' => null,
            'supervisor_code' => null,
            'is_anonymous' => false,
            'privacy_mode' => false,
            'supervisor_uid' => null,
            'supervision_status' => $role === 'patient' ? 'not_requested' : null,
            'sobriety_start_date' => null,
            'primary_risks' => [],
            'created_at' => null,
            'updated_at' => null,
            'legal_acceptance' => null,
            'privacy_notice_read' => null,
        ], $profile);

        $stable['role'] = $role;
        $stable['collection'] = $this->stableProfileCollection($role);
        $stable['document_id'] = $stable['uid'];
        $stable['updated_at'] = $profile['updated_at'] ?? $profile['created_at'] ?? null;
        $stable['authorized'] = (bool) ($stable['authorized'] ?? ($role === 'patient'));
        $stable['verified'] = (bool) ($stable['verified'] ?? ($role === 'patient'));
        $stable['is_anonymous'] = (bool) ($stable['is_anonymous'] ?? false);
        $stable['privacy_mode'] = (bool) ($stable['privacy_mode'] ?? false);
        $displayName = $role === 'patient'
            ? PatientDisplayName::forOwner($stable)
            : (string) (($stable['full_name'] ?? null) ?: ($stable['nickname'] ?? null) ?: 'Usuario RehabiAnex');
        $stable['display_name'] = $displayName;
        $stable['safe_display_name'] = $displayName;
        $stable['primary_risks'] = is_array($stable['primary_risks'] ?? null)
            ? array_values($stable['primary_risks'])
            : [];
        $stable['legal_acceptance'] = $this->legalAcceptanceForResponse(
            $profile['legal_acceptance'] ?? null
        );
        $stable['privacy_notice_read'] = $this->privacyNoticeReadForResponse(
            $profile['privacy_notice_read'] ?? null
        );

        return $stable;
    }

    private function legalAcceptanceForResponse(mixed $acceptance): ?array
    {
        if (! is_array($acceptance)
            || ($acceptance['explicit_acceptance'] ?? false) !== true
            || empty($acceptance['privacy_notice_version'])
            || empty($acceptance['accepted_at'])) {
            return null;
        }

        return [
            'privacy_notice_version' => (string) $acceptance['privacy_notice_version'],
            'accepted_at' => (string) $acceptance['accepted_at'],
            'status' => 'accepted',
        ];
    }

    private function privacyNoticeReadForResponse(mixed $confirmation): ?array
    {
        if (! is_array($confirmation)
            || ($confirmation['status'] ?? null) !== 'confirmed'
            || empty($confirmation['privacy_notice_version'])
            || empty($confirmation['confirmed_at'])) {
            return null;
        }

        return [
            'privacy_notice_version' => (string) $confirmation['privacy_notice_version'],
            'confirmed_at' => (string) $confirmation['confirmed_at'],
            'status' => 'confirmed',
            'is_current' => hash_equals(
                $this->privacyNoticeVersion(),
                (string) $confirmation['privacy_notice_version']
            ),
        ];
    }

    private function ensurePrivacyNoticeReadPending(string $uid, string $role, array $profile): array
    {
        if (is_array($profile['privacy_notice_read'] ?? null)) {
            return $profile;
        }

        $pending = $this->pendingPrivacyNoticeRead();

        $this->db->collection($this->profileCollection($role))
            ->document($uid)
            ->set(['privacy_notice_read' => $pending], ['merge' => true]);
        $profile['privacy_notice_read'] = $pending;

        return $profile;
    }

    private function pendingPrivacyNoticeRead(): array
    {
        return [
            'privacy_notice_version' => $this->privacyNoticeVersion(),
            'required_since' => Carbon::now()->toIso8601String(),
            'status' => 'pending',
        ];
    }

    private function stableProfileCollection(string $role): string
    {
        return match ($role) {
            'supervisor' => 'supervisors',
            'admin' => 'admins',
            default => 'patients',
        };
    }

    private function privacyNoticeVersion(): string
    {
        return (string) config('legal.privacy_notice_version', '2026-10-18');
    }

    private function publicRegistrationRoles(): array
    {
        return ['patient', 'supervisor'];
    }

    private function profileCollection(string $role): string
    {
        $collection = config("firestore.profile_collections.{$role}");

        if (! is_string($collection) || $collection === '') {
            abort(403, 'La cuenta no tiene un rol valido.');
        }

        return $collection;
    }

    private function consentSummary(string $uid, string $role): ?array
    {
        if ($role !== 'patient') {
            return null;
        }

        $documents = $this->db->collection('consents')
            ->where('patient_uid', '=', $uid)
            ->documents();

        $activeCount = 0;
        $supervisorUids = [];
        $scopes = [];

        foreach ($documents as $document) {
            if (! $document->exists()) {
                continue;
            }

            $consent = $document->data();

            if (($consent['explicit_consent'] ?? false) !== true
                || ! empty($consent['revoked_at'])) {
                continue;
            }

            $activeCount++;

            if (! empty($consent['supervisor_uid'])) {
                $supervisorUids[] = (string) $consent['supervisor_uid'];
            }

            foreach ($consent['scope'] ?? [] as $scope) {
                if (is_string($scope) && $scope !== '') {
                    $scopes[] = $scope;
                }
            }
        }

        if ($activeCount === 0) {
            return null;
        }

        return [
            'has_active_consent' => true,
            'active_count' => $activeCount,
            'supervisor_uids' => array_values(array_unique($supervisorUids)),
            'scopes' => array_values(array_unique($scopes)),
        ];
    }

    private function payload(Request $request): array
    {
        $data = $request->json()->all();

        return empty($data) ? $request->all() : $data;
    }

    private function apiKey(): string
    {
        $apiKey = config('services.firebase.web_api_key');

        if (! $apiKey) {
            abort(500, 'FIREBASE_WEB_API_KEY no esta configurado.');
        }

        return trim($apiKey);
    }

    private function validationError(array $errors)
    {
        return ApiErrorResponse::make('Los datos enviados no son validos.', 422, $errors);
    }

    private function firebaseError(string $message, $response)
    {
        $status = $response->status() >= 400 && $response->status() < 500 ? 401 : 502;

        return ApiErrorResponse::make($message, $status);
    }

    private function refreshError($response)
    {
        $providerCode = strtoupper((string) data_get($response->json(), 'error.message', ''));
        $invalidCodes = [
            'TOKEN_EXPIRED',
            'USER_DISABLED',
            'USER_NOT_FOUND',
            'INVALID_REFRESH_TOKEN',
            'INVALID_GRANT_TYPE',
        ];
        $status = in_array($providerCode, $invalidCodes, true) ? 401 : 502;

        return ApiErrorResponse::make(
            $status === 401
                ? 'El token de renovación es inválido, expiró o fue revocado.'
                : 'No se pudo refrescar la sesión por un fallo temporal del proveedor.',
            $status
        );
    }

    private function error(string $message, Throwable $e, int $status = 500)
    {
        Log::error('Authentication API request failed.', [
            'exception' => get_class($e),
            'status' => $status,
        ]);

        return ApiErrorResponse::make(
            $status >= 500 ? ApiErrorResponse::defaultMessage($status) : $message,
            $status
        );
    }
}
