param(
    [int] $Port = 8011
)

$ErrorActionPreference = 'Stop'

if ($Port -lt 1024 -or $Port -gt 65535) {
    throw 'El puerto QA debe estar entre 1024 y 65535.'
}

foreach ($emulatorPort in @(9199, 8180)) {
    if (-not (Get-NetTCPConnection -State Listen -LocalAddress '127.0.0.1' -LocalPort $emulatorPort -ErrorAction SilentlyContinue)) {
        throw "El emulador local requerido en 127.0.0.1:$emulatorPort no está disponible."
    }
}

$env:APP_ENV = 'local'
$env:APP_DEBUG = 'true'
$env:FIREBASE_AUTH_EMULATOR_HOST = '127.0.0.1:9199'
$env:FIRESTORE_EMULATOR_HOST = '127.0.0.1:8180'
$env:FIREBASE_PROJECT_ID = 'demo-rehabianex'
$env:GCLOUD_PROJECT = 'demo-rehabianex'
$env:FIREBASE_WEB_API_KEY = 'demo-api-key'
$env:AI_API_KEY = 'qa-disabled-not-a-secret'
$env:AI_BASE_URL = 'http://127.0.0.1:9'
$env:AI_STORE_RESPONSES = 'false'

php artisan serve --host=127.0.0.1 --port=$Port
