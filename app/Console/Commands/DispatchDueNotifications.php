<?php

namespace App\Console\Commands;

use App\Services\DueNotificationDispatcher;
use Illuminate\Console\Command;

class DispatchDueNotifications extends Command
{
    protected $signature = 'notifications:dispatch-due';

    protected $description = 'Despacha recordatorios FCM vencidos de agenda y seguimiento.';

    public function handle(DueNotificationDispatcher $dueNotifications): int
    {
        if (! config('fcm.enabled')) {
            $this->components->info('FCM está deshabilitado; no se procesaron recordatorios.');

            return self::SUCCESS;
        }

        if (config('fcm.dry_run', true)) {
            $this->components->info('FCM está en dry-run; no se reservaron recordatorios en el ledger.');

            return self::SUCCESS;
        }

        $processed = $dueNotifications->dispatch();
        $this->components->info(
            "Candidatos procesados: agenda={$processed['agenda']}, check-in={$processed['check_in']}."
        );

        return self::SUCCESS;
    }
}
