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

$suffix = [Guid]::NewGuid().ToString('N').Substring(0, 12)
$password = 'Qa!' + [Guid]::NewGuid().ToString('N')
$supervisorEmail = "qa.ai.supervisor.$suffix@example.invalid"
$adminEmail = "qa.ai.admin.$suffix@example.invalid"
$results = [System.Collections.Generic.List[object]]::new()
$stub = $null

function Invoke-QaApi {
    param(
        [string] $Name,
        [string] $Method,
        [string] $Path,
        [object] $Body = $null,
        [string] $Token = '',
        [int] $Expected = 200
    )

    $headers = @{ Accept = 'application/json' }
    if ($Token) { $headers.Authorization = "Bearer $Token" }
    $parameters = @{
        Uri = "$($BaseUrl.TrimEnd('/'))/$($Path.TrimStart('/'))"
        Method = $Method
        Headers = $headers
        UseBasicParsing = $true
    }
    if ($null -ne $Body) {
        $parameters.ContentType = 'application/json'
        $parameters.Body = $Body | ConvertTo-Json -Depth 10 -Compress
    }

    $status = 0
    $json = $null
    try {
        $response = Invoke-WebRequest @parameters
        $status = [int] $response.StatusCode
        $json = $response.Content | ConvertFrom-Json
    } catch {
        if ($_.Exception.Response) {
            $status = [int] $_.Exception.Response.StatusCode
        } else {
            $status = -1
        }
    }

    $safePath = $Path -replace '^admin/supervisors/[^/]+/authorize$', 'admin/supervisors/{uid}/authorize'
    $results.Add([pscustomobject]@{
        case = $Name
        endpoint = "$Method /api/$safePath"
        expected_http = $Expected
        actual_http = $status
        result = if ($status -eq $Expected) { 'PASS' } else { 'FAIL' }
    })

    return $json
}

function Assert-ChatContract {
    param([string] $Name, [object] $Payload, [string] $ExpectedMode, [bool] $ExpectProviderError)

    $forbiddenNavigationKeys = @('route', 'destination', 'deeplink', 'screen', 'navigation')
    $keys = @($Payload.PSObject.Properties.Name)
    $valid = $Payload.ok -eq $true
    $valid = $valid -and -not [string]::IsNullOrWhiteSpace([string] $Payload.answer)
    $valid = $valid -and $Payload.mode -eq $ExpectedMode
    $valid = $valid -and (($keys -contains 'provider_error') -eq $ExpectProviderError)
    $valid = $valid -and @($forbiddenNavigationKeys | Where-Object { $keys -contains $_ }).Count -eq 0

    $results.Add([pscustomobject]@{
        case = "$Name-contract"
        endpoint = 'response-shape'
        expected_http = 'stable'
        actual_http = if ($valid) { 'stable' } else { 'invalid' }
        result = if ($valid) { 'PASS' } else { 'FAIL' }
    })
}

try {
    Invoke-QaApi 'health' 'GET' 'health' -Expected 200 | Out-Null

    $registration = Invoke-QaApi 'register-supervisor' 'POST' 'auth/register' @{
        email = $supervisorEmail
        password = $password
        full_name = 'Supervisor IA QA'
        role = 'supervisor'
        supervisor_type = 'padrino'
        privacy_notice_accepted = $true
        privacy_notice_version = '2026-08-01'
    } -Expected 201
    $supervisorUid = $registration.auth.uid
    if (-not $supervisorUid) { throw 'No se obtuvo identidad sintética de supervisor.' }

    $env:REHABIANEX_ADMIN_PASSWORD = $password
    & php artisan rehabianex:create-admin $adminEmail 'Administrador IA QA' --no-interaction --quiet *> $null
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo crear el admin sintético.' }

    $adminLogin = Invoke-QaApi 'login-admin' 'POST' 'auth/login' @{
        email = $adminEmail
        password = $password
    }
    $adminToken = $adminLogin.auth.id_token

    Invoke-QaApi 'authorize-supervisor' 'PATCH' "admin/supervisors/$supervisorUid/authorize" @{} $adminToken | Out-Null

    $supervisorLogin = Invoke-QaApi 'login-supervisor' 'POST' 'auth/login' @{
        email = $supervisorEmail
        password = $password
    }
    $supervisorToken = $supervisorLogin.auth.id_token

    $local = Invoke-QaApi 'mode-local' 'POST' 'ai/supervisor-chat' @{
        supervisor_uid = $supervisorUid
        question = 'Resume indicadores generales disponibles.'
        mode = 'local'
    } $supervisorToken
    Assert-ChatContract 'mode-local' $local 'local' $false

    $stub = Start-Process php -ArgumentList '-S', '127.0.0.1:8099', 'scripts/ai-provider-stub.php' -PassThru -WindowStyle Hidden
    $ready = $false
    for ($attempt = 0; $attempt -lt 20; $attempt++) {
        try {
            Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8099/health' -TimeoutSec 1 | Out-Null
        } catch {
            if ($_.Exception.Response) { $ready = $true; break }
        }
        Start-Sleep -Milliseconds 100
    }
    if (-not $ready) { throw 'El proveedor sintético no inició.' }

    $ai = Invoke-QaApi 'mode-ai-stub' 'POST' 'ai/supervisor-chat' @{
        supervisor_uid = $supervisorUid
        question = 'Resume indicadores generales disponibles.'
        mode = 'ai'
    } $supervisorToken
    Assert-ChatContract 'mode-ai-stub' $ai 'ai' $false

    Stop-Process -Id $stub.Id -Force
    $stub = $null

    $fallback = Invoke-QaApi 'mode-ai-fallback' 'POST' 'ai/supervisor-chat' @{
        supervisor_uid = $supervisorUid
        question = 'Resume indicadores generales disponibles.'
        mode = 'ai'
    } $supervisorToken
    Assert-ChatContract 'mode-ai-fallback' $fallback 'fallback_local' $true

    Invoke-QaApi 'logout-supervisor' 'POST' 'auth/logout' @{} $supervisorToken | Out-Null
    Invoke-QaApi 'logout-admin' 'POST' 'auth/logout' @{} $adminToken | Out-Null
} finally {
    if ($stub -and -not $stub.HasExited) {
        Stop-Process -Id $stub.Id -Force
    }
    Remove-Item Env:REHABIANEX_ADMIN_PASSWORD -ErrorAction SilentlyContinue
}

$failed = @($results | Where-Object result -eq 'FAIL')
[pscustomobject]@{
    summary = [pscustomobject]@{
        total = $results.Count
        passed = $results.Count - $failed.Count
        failed = $failed.Count
        firebase = 'emulator-demo-only'
        ai_provider = 'loopback-stub-only'
    }
    results = $results
} | ConvertTo-Json -Depth 6

if ($failed.Count -gt 0) { exit 1 }
