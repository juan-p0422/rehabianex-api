<?php

namespace App\Jobs;

use App\Services\FcmService;
use App\Services\FcmDispatchLedger;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendFcmNotification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 86400;

    public function __construct(
        public string $userId,
        public string $type,
        public ?string $route,
        public ?string $entityId,
        public string $notificationId,
        public string $createdAt,
        public string $priority = 'normal',
        public ?string $dedupeId = null,
    ) {}

    public function handle(FcmService $fcm, FcmDispatchLedger $ledger): void
    {
        $dedupeId = $this->dedupeId ?? hash('sha256', $this->notificationId);

        if (! $ledger->claim($dedupeId, $this->type, $this->notificationId)) {
            return;
        }

        try {
            $result = $fcm->sendToUser(
                $this->userId,
                $this->type,
                $this->route,
                $this->entityId,
                $this->notificationId,
                $this->createdAt,
                $this->priority,
            );

            if (($result['failed'] ?? 0) > 0) {
                $ledger->markFailed($dedupeId);

                return;
            }

            $ledger->markSent($dedupeId);
        } catch (\Throwable $exception) {
            $ledger->markFailed($dedupeId);

            throw $exception;
        }
    }

    public function uniqueId(): string
    {
        return $this->notificationId;
    }
}
