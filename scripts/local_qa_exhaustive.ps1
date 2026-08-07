param([string] $BaseUrl = 'http://127.0.0.1:8011/api')

$ErrorActionPreference = 'Stop'

if ($BaseUrl -notmatch '^http://(127\.0\.0\.1|localhost)(:\d+)?/api/?$') {
    throw 'BaseUrl debe apuntar a una API local en loopback.'
}

$env:FIREBASE_AUTH_EMULATOR_HOST = '127.0.0.1:9199'
$env:FIRESTORE_EMULATOR_HOST = '127.0.0.1:8180'
$env:FIREBASE_PROJECT_ID = 'demo-rehabianex'
$env:GCLOUD_PROJECT = 'demo-rehabianex'
$env:AI_API_KEY = ''

$suffix = [Guid]::NewGuid().ToString('N').Substring(0, 12)
$password = 'Qa!' + [Guid]::NewGuid().ToString('N')
$patientEmail = "qa.patient.ext.$suffix@example.invalid"
$supervisorEmail = "qa.supervisor.ext.$suffix@example.invalid"
$adminEmail = "qa.admin.ext.$suffix@example.invalid"
$mutationId = "qa-note-ext-$suffix"
$results = [System.Collections.Generic.List[object]]::new()
$responses = @{}
$logPath = Join-Path $PSScriptRoot '..\storage\logs\laravel.log'
$logStart = if (Test-Path -LiteralPath $logPath) { (Get-Item -LiteralPath $logPath).Length } else { 0 }

function Add-Assertion {
    param([string] $Step, [bool] $Passed, [string] $Expected, [string] $Actual, [string] $Evidence)
    $results.Add([pscustomobject]@{
        Flow = 'assertion'; Step = $Step; Method = 'ASSERT'; Endpoint = '-'
        Expected = $Expected; Actual = $Actual; Result = if ($Passed) { 'PASS' } else { 'FAIL' }
        Evidence = $Evidence
    })
}

function Get-SafePath {
    param([string] $Path)
    $safe = $Path
    $safe = $safe -replace '^admin/supervisors/[^/]+/(authorize|suspend|reactivate)$', 'admin/supervisors/{uid}/$1'
    $safe = $safe -replace '^supervisors/[^/]+/patients/[^/]+/unlink$', 'supervisors/{supervisor_uid}/patients/{patient_uid}/unlink'
    $safe = $safe -replace '^supervisors/[^/]+/patients$', 'supervisors/{uid}/patients'
    $safe = $safe -replace '^supervisors/resolve\?code=.*$', 'supervisors/resolve?code={short_code}'
    $safe = $safe -replace '^patients/[^/]+/notes$', 'patients/{patient_uid}/notes'
    $safe = $safe -replace '^patients/[^/]+$', 'patients/{patient_uid}'
    $safe = $safe -replace '^patient-notes/[^/?]+$', 'patient-notes/{id}'
    $safe = $safe -replace '^patient-notes\?.*$', 'patient-notes?patient_uid={uid}&include_deleted=true'
    $safe = $safe -replace '^supervision-requests/[^/]+/respond$', 'supervision-requests/{id}/respond'
    $safe = $safe -replace '^consents/[^/]+$', 'consents/{id}'
    return $safe
}

function Invoke-QaRequest {
    param(
        [string] $Flow, [string] $Step, [string] $Method, [string] $Path,
        [object] $Body = $null, [string] $Token = '', [int[]] $Expected = @(200),
        [string] $RawBody = ''
    )

    $headers = @{ Accept = 'application/json' }
    if ($Token) { $headers.Authorization = "Bearer $Token" }
    $parameters = @{
        Uri = "$($BaseUrl.TrimEnd('/'))/$($Path.TrimStart('/'))"
        Method = $Method; Headers = $headers; UseBasicParsing = $true
    }
    if ($RawBody) {
        $parameters.ContentType = 'application/json'; $parameters.Body = $RawBody
    } elseif ($null -ne $Body) {
        $parameters.ContentType = 'application/json'; $parameters.Body = $Body | ConvertTo-Json -Depth 12 -Compress
    }

    $status = 0; $payload = $null
    try {
        $response = Invoke-WebRequest @parameters
        $status = [int] $response.StatusCode
        if ($response.Content) { $payload = $response.Content | ConvertFrom-Json }
    } catch {
        if ($_.Exception.Response) {
            $status = [int] $_.Exception.Response.StatusCode
            $stream = $_.Exception.Response.GetResponseStream()
            if ($stream) {
                $reader = [System.IO.StreamReader]::new($stream); $content = $reader.ReadToEnd(); $reader.Dispose()
                if ($content) { try { $payload = $content | ConvertFrom-Json } catch { $payload = $null } }
            }
        } else { $status = -1 }
    }

    $passed = $Expected -contains $status
    $envelope = if ($payload -and $payload.PSObject.Properties.Name -contains 'ok') { "ok=$($payload.ok)" } else { 'sin envolvente JSON' }
    $results.Add([pscustomobject]@{
        Flow = $Flow; Step = $Step; Method = $Method; Endpoint = Get-SafePath $Path
        Expected = ($Expected -join '|'); Actual = $status; Result = if ($passed) { 'PASS' } else { 'FAIL' }
        Evidence = "HTTP $status; $envelope"
    })
    $responses[$Step] = $payload
    return $payload
}

function Require-Value {
    param([object] $Value, [string] $Label)
    if ($null -eq $Value -or [string]::IsNullOrWhiteSpace([string] $Value)) {
        throw "No se obtuvo $Label; se detiene para evitar resultados ambiguos."
    }
    return $Value
}

$legal = @{ privacy_notice_accepted = $true; privacy_notice_version = '2026-08-01' }

Invoke-QaRequest 'Errors' 'malformed_json' 'POST' 'auth/login' -RawBody '{"email":' -Expected @(400) | Out-Null
Invoke-QaRequest 'Errors' 'missing_token' 'GET' 'auth/me' -Expected @(401) | Out-Null
Invoke-QaRequest 'Auth' 'public_admin_rejected' 'POST' 'auth/register' (@{
    email=$adminEmail; password=$password; full_name='Admin Publico QA'; role='admin'
} + $legal) -Expected @(422) | Out-Null

$patientRegistration = Invoke-QaRequest 'Auth' 'register_patient' 'POST' 'auth/register' (@{
    email=$patientEmail; password=$password; full_name='Paciente Real QA'; nickname='Alias QA'; role='patient'
} + $legal) -Expected @(201)
$patientUid = Require-Value $patientRegistration.auth.uid 'UID sintetico de paciente'

$supervisorRegistration = Invoke-QaRequest 'Auth' 'register_supervisor' 'POST' 'auth/register' (@{
    email=$supervisorEmail; password=$password; full_name='Supervisor QA'; role='supervisor'; supervisor_type='padrino'
} + $legal) -Expected @(201)
$supervisorUid = Require-Value $supervisorRegistration.auth.uid 'UID sintetico de supervisor'

$pendingLogin = Invoke-QaRequest 'Auth' 'login_pending_supervisor' 'POST' 'auth/login' @{ email=$supervisorEmail; password=$password } -Expected @(200)
$pendingToken = Require-Value $pendingLogin.auth.id_token 'token de supervisor pendiente'
Invoke-QaRequest 'Supervision' 'pending_supervisor_blocked' 'GET' "supervisors/$supervisorUid/patients" $null $pendingToken @(403) | Out-Null

$env:REHABIANEX_ADMIN_PASSWORD = $password
& php artisan rehabianex:create-admin $adminEmail 'Administrador QA' --no-interaction --quiet *> $null
if ($LASTEXITCODE -ne 0) { throw 'No se pudo crear el administrador sintetico en el emulador.' }
$adminLogin = Invoke-QaRequest 'Auth' 'login_admin' 'POST' 'auth/login' @{ email=$adminEmail; password=$password } -Expected @(200)
$adminToken = Require-Value $adminLogin.auth.id_token 'token de admin'
Invoke-QaRequest 'Admin' 'admin_ping' 'GET' 'admin/ping' $null $adminToken @(200) | Out-Null
Invoke-QaRequest 'Errors' 'patient_forbidden_admin' 'GET' 'admin/ping' $null (Require-Value $patientRegistration.auth.id_token 'token registro paciente') @(403) | Out-Null
Invoke-QaRequest 'Admin' 'list_pending_supervisors' 'GET' 'admin/supervisors?status=pending_review' $null $adminToken @(200) | Out-Null

$authorize = Invoke-QaRequest 'Admin' 'authorize_supervisor' 'PATCH' "admin/supervisors/$supervisorUid/authorize" @{ notes='QA local sintetico.' } $adminToken @(200)
$supervisorCode = if ($authorize.data.supervisor_code) { $authorize.data.supervisor_code } else { $authorize.supervisor.supervisor_code }
$supervisorCode = Require-Value $supervisorCode 'codigo corto'
Invoke-QaRequest 'Admin' 'suspend_supervisor' 'PATCH' "admin/supervisors/$supervisorUid/suspend" @{ reason='QA local sintetico.' } $adminToken @(200) | Out-Null
Invoke-QaRequest 'Supervision' 'suspended_supervisor_blocked' 'GET' "supervisors/$supervisorUid/patients" $null $pendingToken @(403) | Out-Null
Invoke-QaRequest 'Admin' 'reactivate_supervisor' 'PATCH' "admin/supervisors/$supervisorUid/reactivate" @{} $adminToken @(200) | Out-Null

$patientLogin = Invoke-QaRequest 'Auth' 'login_patient' 'POST' 'auth/login' @{ email=$patientEmail; password=$password } -Expected @(200)
$patientToken = Require-Value $patientLogin.auth.id_token 'token paciente'
$refreshToken = Require-Value $patientLogin.auth.refresh_token 'refresh token paciente'
$supervisorLogin = Invoke-QaRequest 'Auth' 'login_supervisor' 'POST' 'auth/login' @{ email=$supervisorEmail; password=$password } -Expected @(200)
$supervisorToken = Require-Value $supervisorLogin.auth.id_token 'token supervisor'
$refresh = Invoke-QaRequest 'Auth' 'refresh_valid' 'POST' 'auth/refresh' @{ refresh_token=$refreshToken } -Expected @(200)
$patientToken = Require-Value $refresh.tokens.id_token 'token paciente renovado'
Invoke-QaRequest 'Patient' 'auth_me' 'GET' 'auth/me' $null $patientToken @(200) | Out-Null

$profile = Invoke-QaRequest 'Patient' 'enable_anonymous_mode' 'PATCH' "patients/$patientUid" @{
    nickname='Alias QA'; is_anonymous=$true; privacy_mode=$true
} $patientToken @(200)
$profileData = if ($profile.data) { $profile.data } elseif ($profile.patient) { $profile.patient } else { $profile.profile }
$anonymousLabel = 'Paciente an' + [char]0x00F3 + 'nimo'
$safeName = if ($profileData.safe_display_name) { $profileData.safe_display_name } else { $profileData.display_name }
Add-Assertion 'anonymous_patch_safe_display' ($safeName -eq $anonymousLabel) 'safe anonymous label' $(if ($safeName -eq $anonymousLabel) { 'safe label' } else { 'unexpected label' }) 'La respuesta PATCH usa nombre de presentacion seguro.'

$anonymousLogin = Invoke-QaRequest 'Patient' 'login_after_anonymous' 'POST' 'auth/login' @{ email=$patientEmail; password=$password } -Expected @(200)
$patientToken = Require-Value $anonymousLogin.auth.id_token 'token paciente anonimo'
$loginSafeName = if ($anonymousLogin.profile.safe_display_name) { $anonymousLogin.profile.safe_display_name } else { $anonymousLogin.profile.display_name }
Add-Assertion 'anonymous_login_persists' ($loginSafeName -eq $anonymousLabel) 'safe anonymous label' $(if ($loginSafeName -eq $anonymousLabel) { 'safe label' } else { 'unexpected label' }) 'Login posterior conserva display seguro.'

$noteBody = @{ client_mutation_id=$mutationId; mood='estable'; mood_score=7; anxiety_level=3; craving_level=2; energy_level=6; sleep_quality=7; had_relapse=$false }
$firstNote = Invoke-QaRequest 'Patient' 'create_note' 'POST' "patients/$patientUid/notes" $noteBody $patientToken @(201)
$secondNote = Invoke-QaRequest 'Patient' 'repeat_note_mutation' 'POST' "patients/$patientUid/notes" $noteBody $patientToken @(200)
$noteId = Require-Value $firstNote.note.note_id 'ID nota'
Add-Assertion 'idempotency_same_note' ($noteId -eq $secondNote.note.note_id) 'same_id' 'same_or_different' 'La segunda mutacion devuelve el mismo documento.'
Invoke-QaRequest 'Offline' 'list_notes' 'GET' "patients/$patientUid/notes" $null $patientToken @(200) | Out-Null
Invoke-QaRequest 'Offline' 'soft_delete_note' 'DELETE' "patient-notes/$noteId" $null $patientToken @(200) | Out-Null
$deletedList = Invoke-QaRequest 'Offline' 'include_deleted_tombstone' 'GET' "patient-notes?patient_uid=$patientUid&include_deleted=true" $null $patientToken @(200)
$tombstone = @($deletedList.data | Where-Object { $_.note_id -eq $noteId -or $_.document_id -eq $noteId }) | Select-Object -First 1
$isTombstone = $null -ne $tombstone -and -not [string]::IsNullOrWhiteSpace([string]$tombstone.deleted_at) -and -not ($tombstone.PSObject.Properties.Name -contains 'mood')
Add-Assertion 'tombstone_minimal' $isTombstone 'deleted_at without clinical body' $(if ($isTombstone) { 'minimal tombstone' } else { 'missing/unsafe tombstone' }) 'include_deleted permite invalidar Room sin contenido sensible.'

Invoke-QaRequest 'Errors' 'not_found' 'GET' 'patient-notes/qa-missing-document' $null $patientToken @(404) | Out-Null
Invoke-QaRequest 'Errors' 'validation_error' 'POST' "patients/$patientUid/notes" @{ mood_score=99 } $patientToken @(422) | Out-Null

$settings = Invoke-QaRequest 'Notifications' 'settings_without_patient_uid' 'POST' 'notification-settings' @{
    daily_note_enabled=$true; daily_note_time='21:15'; event_reminders_enabled=$true
} $patientToken @(200,201)
$aliasOk = $settings.data.patient_uid -eq $patientUid -and $settings.data.daily_check_in_enabled -eq $true -and $settings.data.daily_check_in_time -eq '21:15'
Add-Assertion 'notification_aliases' $aliasOk 'inferred+aliases' $(if ($aliasOk) { 'inferred+aliases' } else { 'missing' }) 'patient_uid se infiere y daily_note se sincroniza.'

Invoke-QaRequest 'Supervision' 'resolve_supervisor' 'GET' "supervisors/resolve?code=$([Uri]::EscapeDataString($supervisorCode))" $null $patientToken @(200) | Out-Null
$link = Invoke-QaRequest 'Supervision' 'create_link' 'POST' 'supervision-requests' @{ supervisor_uid=$supervisorUid; type='link'; message='QA local.' } $patientToken @(201)
$linkId = Require-Value $link.id 'ID link'
Invoke-QaRequest 'Supervision' 'accept_link' 'PATCH' "supervision-requests/$linkId/respond" @{ status='accepted' } $supervisorToken @(200) | Out-Null
Invoke-QaRequest 'Errors' 'duplicate_link_conflict' 'POST' 'supervision-requests' @{ supervisor_uid=$supervisorUid; type='link' } $patientToken @(409) | Out-Null

$consent = Invoke-QaRequest 'Consent' 'create_consent' 'POST' 'consents' @{
    supervisor_uid=$supervisorUid; explicit_consent=$true; consent_text='QA local.'
    scope=@('patient_notes','support_contacts','agenda_events','ai_chat_summary'); status='active'
} $patientToken @(201)
$consentId = Require-Value $consent.id 'ID consentimiento'
Invoke-QaRequest 'Consent' 'supervisor_reads_notes' 'GET' "patients/$patientUid/notes" $null $supervisorToken @(200) | Out-Null
Invoke-QaRequest 'Consent' 'pause_consent' 'PATCH' "consents/$consentId" @{ status='paused' } $patientToken @(200) | Out-Null
Invoke-QaRequest 'Consent' 'paused_blocks_notes' 'GET' "patients/$patientUid/notes" $null $supervisorToken @(403) | Out-Null
Invoke-QaRequest 'Consent' 'reactivate_consent' 'PATCH' "consents/$consentId" @{ status='active' } $patientToken @(200) | Out-Null
Invoke-QaRequest 'Consent' 'active_restores_notes' 'GET' "patients/$patientUid/notes" $null $supervisorToken @(200) | Out-Null
Invoke-QaRequest 'Consent' 'revoke_consent' 'PATCH' "consents/$consentId" @{ status='revoked' } $patientToken @(200) | Out-Null
Invoke-QaRequest 'Consent' 'revoked_blocks_notes' 'GET' "patients/$patientUid/notes" $null $supervisorToken @(403) | Out-Null

$unlink = Invoke-QaRequest 'Supervision' 'create_unlink_request' 'POST' 'supervision-requests' @{ supervisor_uid=$supervisorUid; type='unlink' } $patientToken @(201)
$unlinkId = Require-Value $unlink.id 'ID unlink'
Invoke-QaRequest 'Supervision' 'accept_unlink_request' 'PATCH' "supervision-requests/$unlinkId/respond" @{ status='accepted' } $supervisorToken @(200) | Out-Null
$ownProfile = Invoke-QaRequest 'Supervision' 'verify_unlink_cleanup' 'GET' "patients/$patientUid" $null $patientToken @(200)
$patientData = if ($ownProfile.data) { $ownProfile.data } elseif ($ownProfile.patient) { $ownProfile.patient } else { $ownProfile.profile }
$cleaned = $null -eq $patientData.supervisor_uid -and $patientData.wants_supervision -eq $false -and $patientData.supervision_status -eq 'not_requested' -and -not [string]::IsNullOrWhiteSpace([string]$patientData.supervision_ended_at)
Add-Assertion 'unlink_cleanup' $cleaned 'relation cleared' $(if ($cleaned) { 'relation cleared' } else { 'incomplete' }) 'UID removido, wants=false, status reset y ended_at presente.'
Invoke-QaRequest 'Supervision' 'access_after_unlink' 'GET' "patients/$patientUid" $null $supervisorToken @(403) | Out-Null

# Segundo ciclo para validar el endpoint directo y la revocacion automatica.
$link2 = Invoke-QaRequest 'Supervision' 'create_second_link' 'POST' 'supervision-requests' @{ supervisor_uid=$supervisorUid; type='link' } $patientToken @(201)
$link2Id = Require-Value $link2.id 'ID segundo link'
Invoke-QaRequest 'Supervision' 'accept_second_link' 'PATCH' "supervision-requests/$link2Id/respond" @{ status='accepted' } $supervisorToken @(200) | Out-Null
$consent2 = Invoke-QaRequest 'Consent' 'create_second_consent' 'POST' 'consents' @{ supervisor_uid=$supervisorUid; explicit_consent=$true; consent_text='QA local.'; scope=@('patient_notes','ai_chat_summary'); status='active' } $patientToken @(201)
$consent2Id = Require-Value $consent2.id 'ID segundo consentimiento'
Invoke-QaRequest 'Supervision' 'direct_unlink' 'POST' "supervisors/$supervisorUid/patients/$patientUid/unlink" @{ reason='QA local.' } $supervisorToken @(200) | Out-Null
$consents = Invoke-QaRequest 'Consent' 'list_after_direct_unlink' 'GET' 'consents' $null $patientToken @(200)
$revoked = @($consents.data | Where-Object { ($_.consent_id -eq $consent2Id -or $_.document_id -eq $consent2Id) -and $_.status -eq 'revoked' }).Count -gt 0
Add-Assertion 'direct_unlink_revokes_consent' $revoked 'revoked' $(if ($revoked) { 'revoked' } else { 'not revoked' }) 'El consentimiento relacionado queda revocado.'
Invoke-QaRequest 'Supervision' 'direct_unlink_blocks_access' 'GET' "patients/$patientUid" $null $supervisorToken @(403) | Out-Null

$localChat = Invoke-QaRequest 'AI' 'chat_local' 'POST' 'ai/supervisor-chat' @{ supervisor_uid=$supervisorUid; mode='local'; question='Resume indicadores generales.' } $supervisorToken @(200)
Add-Assertion 'chat_local_mode' ($localChat.mode -eq 'local') 'local' ([string]$localChat.mode) 'Modo local estable.'
$fallbackChat = Invoke-QaRequest 'AI' 'chat_fallback' 'POST' 'ai/supervisor-chat' @{ supervisor_uid=$supervisorUid; mode='ai'; question='Escribe a qa.person@example.invalid o +52 449 000 0000.' } $supervisorToken @(200)
$fallbackOk = $fallbackChat.mode -eq 'fallback_local' -and -not ([string]$fallbackChat.question -match '@|\+52')
Add-Assertion 'chat_fallback_sanitized' $fallbackOk 'fallback_local without PII' $(if ($fallbackOk) { 'fallback sanitized' } else { 'unexpected' }) 'Proveedor deshabilitado de forma controlada y texto redactado.'

Invoke-QaRequest 'Auth' 'logout' 'POST' 'auth/logout' @{} $patientToken @(200) | Out-Null
Invoke-QaRequest 'Auth' 'revoked_token_rejected' 'GET' 'auth/me' $null $patientToken @(401) | Out-Null

$rateLimited = $false
for ($i = 1; $i -le 70; $i++) {
    $probe = Invoke-QaRequest 'Errors' "rate_limit_probe_$i" 'GET' 'health' -Expected @(200,429)
    if ($results[$results.Count - 1].Actual -eq 429) { $rateLimited = $true; break }
}
Add-Assertion 'rate_limit_observed' $rateLimited '429' $(if ($rateLimited) { '429' } else { 'not observed' }) 'Limite de /health probado al final para no interferir con el flujo.'

$newLog = ''
if (Test-Path -LiteralPath $logPath) {
    $stream = [System.IO.File]::Open($logPath, 'Open', 'Read', 'ReadWrite')
    try {
        if ($stream.Length -gt $logStart) {
            $stream.Seek($logStart, 'Begin') | Out-Null
            $reader = [System.IO.StreamReader]::new($stream); $newLog = $reader.ReadToEnd(); $reader.Dispose()
        }
    } finally { $stream.Dispose() }
}

$leakChecks = [pscustomobject]@{
    TokenValuePresent = [bool](($patientToken -and $newLog.Contains($patientToken)) -or ($supervisorToken -and $newLog.Contains($supervisorToken)) -or ($adminToken -and $newLog.Contains($adminToken)))
    PasswordValuePresent = [bool]($password -and $newLog.Contains($password))
    SyntheticEmailPresent = $newLog.Contains($patientEmail) -or $newLog.Contains($supervisorEmail) -or $newLog.Contains($adminEmail)
    ServiceAccountPrivateKeyMarker = $newLog.Contains('BEGIN PRIVATE KEY')
}

$failed = @($results | Where-Object Result -eq 'FAIL')
$reportResults = @($results | Where-Object Step -notlike 'rate_limit_probe_*')
[pscustomobject]@{
    Summary = [pscustomobject]@{ Total=$results.Count; Passed=$results.Count-$failed.Count; Failed=$failed.Count; FirebaseProject='demo-rehabianex'; ExternalAiDisabled=$true }
    Results = $reportResults
    LogSanitization = $leakChecks
} | ConvertTo-Json -Depth 8

if ($failed.Count -gt 0) { exit 1 }
