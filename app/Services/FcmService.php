<?php

namespace App\Services;

use App\Contracts\FcmTokenRepository;
use App\Support\FcmNotificationTypes;
use App\Support\FcmPayloadSanitizer;
use App\Support\FcmSafeTexts;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;
use RuntimeException;
use Throwable;

class FcmService
{
    public function __construct(
        private FcmTokenRepository $tokens,
        private Messaging $messaging,
    ) {}

    public function sendToUser(
        string $userId,
        string $type,
        ?string $route,
        ?string $entityId,
        string $notificationId,
        string $createdAt,
        string $priority = 'normal',
        ?bool $validateOnly = null,
    ): array {
        if (! config('fcm.enabled')) {
            return $this->result('disabled');
        }

        $payload = FcmPayloadSanitizer::sanitize([
            'type' => $type,
            'title' => FcmSafeTexts::TITLE,
            'body' => FcmSafeTexts::bodyFor($type),
            'route' => (string) $route,
            'entity_id' => (string) $entityId,
            'notification_id' => $notificationId,
            'created_at' => $createdAt,
            'priority' => $priority,
        ]);

        $tokens = $this->tokens->getActiveTokensForUser($userId);

        if ($tokens === []) {
            return $this->result('no_active_tokens');
        }

        $sent = 0;
        $invalid = 0;
        $failed = 0;

        foreach ($tokens as $token) {
            try {
                $this->sendMessage(
                    $token,
                    $payload,
                    $validateOnly ?? (bool) config('fcm.dry_run', true),
                );
                $sent++;
            } catch (NotFound) {
                $this->tokens->markInvalid($token);
                $invalid++;
            } catch (Throwable) {
                // Provider errors can contain token material, so they are not logged.
                $failed++;
            }
        }

        return [
            'status' => $failed > 0 ? 'partial_failure' : 'processed',
            'sent' => $sent,
            'invalid' => $invalid,
            'failed' => $failed,
        ];
    }

    public function sendSafeTestToUser(string $userId): array
    {
        if (! config('fcm.enabled')) {
            return $this->result('disabled');
        }

        $dryRun = (bool) config('fcm.dry_run', true);

        if (! $dryRun && ! app()->environment(['local', 'staging'])) {
            throw new RuntimeException('El envío real de prueba solo está permitido en local o staging.');
        }

        $notificationId = 'test_'.Str::uuid()->toString();
        $result = $this->sendToUser(
            $userId,
            FcmNotificationTypes::TEST_NOTIFICATION,
            '',
            '',
            $notificationId,
            now()->toIso8601String(),
            'normal',
        );

        $result['notification_id'] = $notificationId;

        return $result;
    }

    private function sendMessage(
        string $token,
        array $payload,
        bool $validateOnly,
    ): array {
        $androidPriority = in_array($payload['priority'], ['high', 'critical'], true)
            ? 'high'
            : (string) config('fcm.android.priority', 'high');

        $message = CloudMessage::new()
            ->toToken($token)
            ->withData($payload)
            ->withAndroidConfig([
                'priority' => $androidPriority,
                'collapse_key' => $payload['notification_id'],
            ]);

        return $this->messaging->send($message, $validateOnly);
    }

    private function result(string $status): array
    {
        return [
            'status' => $status,
            'sent' => 0,
            'invalid' => 0,
            'failed' => 0,
        ];
    }
}
