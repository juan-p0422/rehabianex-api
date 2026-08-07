$ErrorActionPreference = 'Stop'

$env:FIREBASE_AUTH_EMULATOR_HOST = '127.0.0.1:9199'
$env:FIRESTORE_EMULATOR_HOST = '127.0.0.1:8180'
$env:FIREBASE_PROJECT_ID = 'demo-rehabianex'
$env:GCLOUD_PROJECT = 'demo-rehabianex'
$env:AI_API_KEY = 'qa-loopback-key-not-secret'
$env:AI_BASE_URL = 'http://127.0.0.1:8099/chat'
$env:AI_MODEL = 'qa-loopback-model'

php artisan serve --host=127.0.0.1 --port=8010
