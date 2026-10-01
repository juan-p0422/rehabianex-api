<?php

namespace App\Services;

use InvalidArgumentException;

class FcmDispatchLedger
{
    private const COLLECTION = 'notification_dispatches';

    private $db;

    public function __construct(FirebaseService $firebase)
    {
        $this->db = $firebase->db();
    }

    public function dedupeId(string $dedupeKey): string
    {
        $dedupeKey = trim($dedupeKey);

        if ($dedupeKey === '') {
            throw new InvalidArgumentException('La clave de deduplicación FCM no puede estar vacía.');
        }

        return hash('sha256', $dedupeKey);
    }

    public function claim(string $dedupeId, string $type, string $notificationId): bool
    {
        $this->assertDedupeId($dedupeId);
        $reference = $this->db->collection(self::COLLECTION)->document($dedupeId);

        return (bool) $this->db->runTransaction(function ($transaction) use (
            $reference,
            $type,
            $notificationId,
        ): bool {
            $snapshot = $transaction->snapshot($reference);
            $existing = $snapshot->exists() ? $snapshot->data() : [];
            $status = (string) ($existing['status'] ?? '');

            if (in_array($status, ['claimed', 'sent'], true)) {
                return false;
            }

            $transaction->set($reference, [
                'type' => $type,
                'notification_id' => $notificationId,
                'status' => 'claimed',
                'claimed_at' => now()->toIso8601String(),
                'sent_at' => null,
                'attempts' => ((int) ($existing['attempts'] ?? 0)) + 1,
            ]);

            return true;
        });
    }

    public function markSent(string $dedupeId): void
    {
        $this->assertDedupeId($dedupeId);
        $this->db->collection(self::COLLECTION)->document($dedupeId)->set([
            'status' => 'sent',
            'sent_at' => now()->toIso8601String(),
        ], ['merge' => true]);
    }

    public function markFailed(string $dedupeId): void
    {
        $this->assertDedupeId($dedupeId);
        $this->db->collection(self::COLLECTION)->document($dedupeId)->set([
            'status' => 'failed',
            'sent_at' => null,
        ], ['merge' => true]);
    }

    private function assertDedupeId(string $dedupeId): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $dedupeId) !== 1) {
            throw new InvalidArgumentException('El identificador de deduplicación FCM no es válido.');
        }
    }
}
