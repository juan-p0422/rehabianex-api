<?php

namespace App\Jobs;

use App\Services\FcmService;
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
    ) {}

    public function handle(FcmService $fcm): void
    {
        $fcm->sendToUser(
            $this->userId,
            $this->type,
            $this->route,
            $this->entityId,
            $this->notificationId,
            $this->createdAt,
            $this->priority,
        );
    }

    public function uniqueId(): string
    {
        return $this->notificationId;
    }
}
