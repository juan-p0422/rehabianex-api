<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiErrorResponse;
use App\Services\DataControlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class DataControlController extends Controller
{
    public function __construct(private DataControlService $dataControl) {}

    public function summary(Request $request)
    {
        return response()->json([
            'ok' => true,
            ...$this->dataControl->summary($request),
        ]);
    }

    public function export(Request $request)
    {
        try {
            $export = $this->dataControl->export($request);
            $json = json_encode(
                $export,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $filename = 'rehabianex-data-export-'.now('America/Mexico_City')->format('Ymd-His').'.json';

            return response($json, 200, [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (Throwable $e) {
            Log::error('Data export failed.', ['exception' => get_class($e)]);

            return ApiErrorResponse::make('No se pudo generar la exportación.', 500);
        }
    }

    public function requestAccountDeletion(Request $request)
    {
        $data = $request->json()->all() ?: $request->all();
        $validator = Validator::make($data, [
            'scope' => ['required', 'in:account'],
            'confirmation' => [
                'required',
                'string',
                'max:80',
                'in:'.config('data_control.deletion_confirmation'),
            ],
            'acknowledge_retention_limits' => ['required', 'accepted'],
        ], [
            'confirmation.in' => 'Escribe exactamente la frase de confirmación solicitada.',
            'acknowledge_retention_limits.accepted' => 'Debes confirmar que comprendes los límites de conservación.',
        ]);

        if ($validator->fails()) {
            return ApiErrorResponse::make(
                'Los datos enviados no son válidos.',
                422,
                $validator->errors()->toArray()
            );
        }

        $authenticatedAt = (int) $request->attributes->get('firebase_authenticated_at', 0);
        $maxAge = max(60, (int) config('data_control.recent_auth_seconds', 300));

        if ($authenticatedAt <= 0 || now()->timestamp - $authenticatedAt > $maxAge) {
            return ApiErrorResponse::make(
                'Esta acción requiere una autenticación reciente.',
                401,
                ['reauthentication' => ['Reautentícate con Firebase y envía un ID token nuevo.']]
            );
        }

        try {
            return response()->json([
                'ok' => true,
                ...$this->dataControl->requestAccountDeletion($request),
            ], 202);
        } catch (Throwable $e) {
            Log::error('Account deletion request could not be initialized.', [
                'exception' => get_class($e),
            ]);

            if ($e instanceof HttpExceptionInterface) {
                return ApiErrorResponse::fromException($e);
            }

            return ApiErrorResponse::make(
                'No se pudo bloquear la cuenta ni registrar la solicitud. No se aplicaron cambios parciales.',
                500
            );
        }
    }
}
