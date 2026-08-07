param(
    [string] $BaseUrl = 'http://127.0.0.1:8000/api'
)

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
$patientEmail = "qa.patient.$suffix@example.invalid"
$supervisorEmail = "qa.supervisor.$suffix@example.invalid"
$adminEmail = "qa.admin.$suffix@example.invalid"
$mutationId = "qa-note-$suffix"
$results = [System.Collections.Generic.List[object]]::new()
$responses = @{}
$logPath = Join-Path $PSScriptRoot '..\storage\logs\laravel.log'
$logStart = if (Test-Path -LiteralPath $logPath) { (Get-Item -LiteralPath $logPath).Length } else { 0 }

function Invoke-QaRequest {
    param(
        [string] $Step,
        [string] $Method,
        [string] $Path,
        [object] $Body = $null,
        [string] $Token = '',
        [int[]] $Expected = @(200)
    )

    $headers = @{ Accept = 'application/json' }
    if ($Token) {
        $headers.Authorization = "Bearer $Token"
    }

    $parameters = @{
        Uri = "$($BaseUrl.TrimEnd('/'))/$($Path.TrimStart('/'))"
        Method = $Method
        Headers = $headers
        UseBasicParsing = $true
    }

    if ($null -ne $Body) {
        $parameters.ContentType = 'application/json'
        $parameters.Body = $Body | ConvertTo-Json -Depth 12 -Compress
    }

    $status = 0
    $payload = $null
    try {
        $response = Invoke-WebRequest @parameters
        $status = [int] $response.StatusCode
        if ($response.Content) {
            $payload = $response.Content | ConvertFrom-Json
        }
    } catch {
        if ($_.Exception.Response) {
            $status = [int] $_.Exception.Response.StatusCode
            $stream = $_.Exception.Response.GetResponseStream()
            if ($stream) {
                $reader = [System.IO.StreamReader]::new($stream)
                $content = $reader.ReadToEnd()
                $reader.Dispose()
                if ($content) {
                    try { $payload = $content | ConvertFrom-Json } catch { $payload = $null }
                }
            }
        } else {
            $status = -1
        }
    }

    $passed = $Expected -contains $status
    $safePath = $Path
    $safePath = $safePath -replace '^admin/supervisors/[^/]+/authorize$', 'admin/supervisors/{uid}/authorize'
    $safePath = $safePath -replace '^patients/[^/]+/notes$', 'patients/{patient_uid}/notes'
    $safePath = $safePath -replace '^patients/[^/]+$', 'patients/{patient_uid}'
    $safePath = $safePath -replace '^supervision-requests/[^/]+/respond$', 'supervision-requests/{id}/respond'
    $safePath = $safePath -replace '^consents/[^/]+$', 'consents/{id}'
    $safePath = $safePath -replace '^supervisors/resolve\?code=.*$', 'supervisors/resolve?code={short_code}'

    $results.Add([pscustomobject]@{
        Step = $Step
        Method = $Method
        Endpoint = $safePath
        Expected = ($Expected -join '|')
        Actual = $status
        Result = if ($passed) { 'PASS' } else { 'FAIL' }
    })

    $responses[$Step] = $payload
    return $payload
}

function Require-Value {
    param([object] $Value, [string] $Label)
    if ($null -eq $Value -or [string]::IsNullOrWhiteSpace([string] $Value)) {
        $last = if ($results.Count -gt 0) { $results[$results.Count - 1] } else { $null }
        $context = if ($last) { " Ultimo paso: $($last.Step), HTTP $($last.Actual), $($last.Result)." } else { '' }
        throw "No se obtuvo $Label; se detiene el flujo para evitar resultados ambiguos.$context"
    }
    return $Value
}

$commonLegal = @{
    privacy_notice_accepted = $true
    privacy_notice_version = '2026-08-01'
}

Invoke-QaRequest 'health' 'GET' 'health' -Expected @(200) | Out-Null

$patientRegistration = Invoke-QaRequest 'register_patient' 'POST' 'auth/register' (@{
    email = $patientEmail
    password = $password
    full_name = 'Paciente QA'
    role = 'patient'
    nickname = 'Paciente QA'
} + $commonLegal) -Expected @(201)
$patientUid = Require-Value ($patientRegistration.auth.uid) 'UID sintético de paciente'

$supervisorRegistration = Invoke-QaRequest 'register_supervisor' 'POST' 'auth/register' (@{
    email = $supervisorEmail
    password = $password
    full_name = 'Supervisor QA'
    role = 'supervisor'
    supervisor_type = 'padrino'
} + $commonLegal) -Expected @(201)
$supervisorUid = Require-Value ($supervisorRegistration.auth.uid) 'UID sintético de supervisor'

$env:REHABIANEX_ADMIN_PASSWORD = $password
& php artisan rehabianex:create-admin $adminEmail 'Administrador QA' --no-interaction --quiet *> $null
if ($LASTEXITCODE -ne 0) {
    throw 'No se pudo crear el administrador sintético en el emulador.'
}

$adminLogin = Invoke-QaRequest 'login_admin' 'POST' 'auth/login' @{
    email = $adminEmail
    password = $password
} -Expected @(200)
$adminToken = Require-Value ($adminLogin.auth.id_token) 'token de admin'

$authorize = Invoke-QaRequest 'authorize_supervisor' 'PATCH' "admin/supervisors/$supervisorUid/authorize" @{
    notes = 'Autorización local QA con datos sintéticos.'
} $adminToken @(200)
$supervisorCode = $authorize.data.supervisor_code
if (-not $supervisorCode) { $supervisorCode = $authorize.supervisor.supervisor_code }
$supervisorCode = Require-Value $supervisorCode 'código corto del supervisor'

$patientLogin = Invoke-QaRequest 'login_patient' 'POST' 'auth/login' @{
    email = $patientEmail
    password = $password
} -Expected @(200)
$patientToken = Require-Value ($patientLogin.auth.id_token) 'token de paciente'

$supervisorLogin = Invoke-QaRequest 'login_supervisor' 'POST' 'auth/login' @{
    email = $supervisorEmail
    password = $password
} -Expected @(200)
$supervisorToken = Require-Value ($supervisorLogin.auth.id_token) 'token de supervisor'

Invoke-QaRequest 'auth_me_patient' 'GET' 'auth/me' $null $patientToken @(200) | Out-Null
Invoke-QaRequest 'auth_me_supervisor' 'GET' 'auth/me' $null $supervisorToken @(200) | Out-Null

$noteBody = @{
    client_mutation_id = $mutationId
    mood = 'estable'
    mood_score = 7
    anxiety_level = 3
    craving_level = 2
    energy_level = 6
    sleep_quality = 7
    had_relapse = $false
}
$firstNote = Invoke-QaRequest 'create_note' 'POST' "patients/$patientUid/notes" $noteBody $patientToken @(201)
$secondNote = Invoke-QaRequest 'repeat_note_mutation' 'POST' "patients/$patientUid/notes" $noteBody $patientToken @(200)
$firstNoteId = Require-Value ($firstNote.note.note_id) 'ID de la primera nota'
$secondNoteId = Require-Value ($secondNote.note.note_id) 'ID de la nota repetida'
if ($firstNoteId -ne $secondNoteId) {
    $results.Add([pscustomobject]@{ Step='idempotency_same_note'; Method='ASSERT'; Endpoint='patient_notes'; Expected='same_id'; Actual='different_id'; Result='FAIL' })
} else {
    $results.Add([pscustomobject]@{ Step='idempotency_same_note'; Method='ASSERT'; Endpoint='patient_notes'; Expected='same_id'; Actual='same_id'; Result='PASS' })
}

Invoke-QaRequest 'create_contact' 'POST' 'support-contacts' @{
    name = 'Contacto QA'
    phone = '+520000000000'
    relationship = 'familiar'
    can_receive_alerts = $false
} $patientToken @(201) | Out-Null

Invoke-QaRequest 'create_event' 'POST' 'agenda-events' @{
    title = 'Evento QA local'
    type = 'appointment'
    starts_at = '2026-08-10T18:00:00-06:00'
    ends_at = '2026-08-10T19:00:00-06:00'
    status = 'scheduled'
} $patientToken @(201) | Out-Null

$settings = Invoke-QaRequest 'notification_settings_without_patient_uid' 'POST' 'notification-settings' @{
    daily_note_enabled = $true
    daily_note_time = '21:00'
    event_reminders_enabled = $true
} $patientToken @(201, 200)
if ($settings.data.patient_uid -eq $patientUid -and $settings.data.daily_check_in_enabled -eq $true) {
    $results.Add([pscustomobject]@{ Step='notification_inference_alias'; Method='ASSERT'; Endpoint='notification-settings'; Expected='inferred+alias'; Actual='inferred+alias'; Result='PASS' })
} else {
    $results.Add([pscustomobject]@{ Step='notification_inference_alias'; Method='ASSERT'; Endpoint='notification-settings'; Expected='inferred+alias'; Actual='missing'; Result='FAIL' })
}

Invoke-QaRequest 'resolve_supervisor' 'GET' "supervisors/resolve?code=$([Uri]::EscapeDataString($supervisorCode))" $null $patientToken @(200) | Out-Null

$linkRequest = Invoke-QaRequest 'create_link_request' 'POST' 'supervision-requests' @{
    supervisor_uid = $supervisorUid
    type = 'link'
    message = 'Solicitud QA sintética.'
} $patientToken @(201)
$linkRequestId = Require-Value ($linkRequest.id) 'ID de solicitud link'

Invoke-QaRequest 'accept_link_request' 'PATCH' "supervision-requests/$linkRequestId/respond" @{
    status = 'accepted'
} $supervisorToken @(200) | Out-Null

$consent = Invoke-QaRequest 'create_consent' 'POST' 'consents' @{
    supervisor_uid = $supervisorUid
    explicit_consent = $true
    consent_text = 'Consentimiento sintético exclusivo para QA local.'
    scope = @('patient_notes', 'support_contacts', 'agenda_events', 'ai_chat_summary')
    status = 'active'
} $patientToken @(201)
$consentId = Require-Value ($consent.id) 'ID de consentimiento'

Invoke-QaRequest 'pause_consent' 'PATCH' "consents/$consentId" @{ status = 'paused' } $patientToken @(200) | Out-Null
Invoke-QaRequest 'reactivate_consent' 'PATCH' "consents/$consentId" @{ status = 'active' } $patientToken @(200) | Out-Null
Invoke-QaRequest 'revoke_consent' 'PATCH' "consents/$consentId" @{ status = 'revoked' } $patientToken @(200) | Out-Null

$unlinkRequest = Invoke-QaRequest 'create_unlink_request' 'POST' 'supervision-requests' @{
    supervisor_uid = $supervisorUid
    type = 'unlink'
    message = 'Desvinculación QA sintética.'
} $patientToken @(201)
$unlinkRequestId = Require-Value ($unlinkRequest.id) 'ID de solicitud unlink'

Invoke-QaRequest 'accept_unlink_request' 'PATCH' "supervision-requests/$unlinkRequestId/respond" @{
    status = 'accepted'
} $supervisorToken @(200) | Out-Null

Invoke-QaRequest 'access_after_unlink' 'GET' "patients/$patientUid" $null $supervisorToken @(403) | Out-Null

$localChat = Invoke-QaRequest 'chat_local' 'POST' 'ai/supervisor-chat' @{
    supervisor_uid = $supervisorUid
    question = 'Resume únicamente indicadores generales disponibles.'
    mode = 'local'
} $supervisorToken @(200)
if ($localChat.mode -ne 'local') {
    $results.Add([pscustomobject]@{ Step='chat_local_mode'; Method='ASSERT'; Endpoint='ai/supervisor-chat'; Expected='local'; Actual=$localChat.mode; Result='FAIL' })
} else {
    $results.Add([pscustomobject]@{ Step='chat_local_mode'; Method='ASSERT'; Endpoint='ai/supervisor-chat'; Expected='local'; Actual='local'; Result='PASS' })
}

$fallbackChat = Invoke-QaRequest 'chat_ai_or_fallback' 'POST' 'ai/supervisor-chat' @{
    supervisor_uid = $supervisorUid
    question = 'Resume únicamente indicadores generales disponibles.'
    mode = 'ai'
} $supervisorToken @(200)
if ($fallbackChat.mode -ne 'fallback_local') {
    $results.Add([pscustomobject]@{ Step='chat_ai_mode'; Method='ASSERT'; Endpoint='ai/supervisor-chat'; Expected='fallback_local'; Actual=$fallbackChat.mode; Result='FAIL' })
} else {
    $results.Add([pscustomobject]@{ Step='chat_ai_mode'; Method='ASSERT'; Endpoint='ai/supervisor-chat'; Expected='fallback_local'; Actual='fallback_local'; Result='PASS' })
}

Invoke-QaRequest 'logout_patient' 'POST' 'auth/logout' @{} $patientToken @(200) | Out-Null
Invoke-QaRequest 'logout_supervisor' 'POST' 'auth/logout' @{} $supervisorToken @(200) | Out-Null
Invoke-QaRequest 'logout_admin' 'POST' 'auth/logout' @{} $adminToken @(200) | Out-Null

$newLog = ''
if (Test-Path -LiteralPath $logPath) {
    $stream = [System.IO.File]::Open($logPath, 'Open', 'Read', 'ReadWrite')
    try {
        if ($stream.Length -gt $logStart) {
            $stream.Seek($logStart, 'Begin') | Out-Null
            $reader = [System.IO.StreamReader]::new($stream)
            $newLog = $reader.ReadToEnd()
            $reader.Dispose()
        }
    } finally {
        $stream.Dispose()
    }
}

$leakChecks = [pscustomobject]@{
    TokenValuePresent = [bool] ($patientToken -and $newLog.Contains($patientToken)) -or [bool] ($supervisorToken -and $newLog.Contains($supervisorToken)) -or [bool] ($adminToken -and $newLog.Contains($adminToken))
    PasswordValuePresent = [bool] ($password -and $newLog.Contains($password))
    SyntheticEmailPresent = $newLog.Contains($patientEmail) -or $newLog.Contains($supervisorEmail) -or $newLog.Contains($adminEmail)
    ServiceAccountPrivateKeyMarker = $newLog.Contains('BEGIN PRIVATE KEY')
}

$failed = @($results | Where-Object Result -eq 'FAIL')
[pscustomobject]@{
    Summary = [pscustomobject]@{
        Total = $results.Count
        Passed = $results.Count - $failed.Count
        Failed = $failed.Count
        ExternalAiDisabled = $true
        FirebaseProject = 'demo-rehabianex'
    }
    Results = $results
    LogSanitization = $leakChecks
} | ConvertTo-Json -Depth 8

if ($failed.Count -gt 0) {
    exit 1
}
