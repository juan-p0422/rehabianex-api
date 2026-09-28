<?php

namespace App\Services;

use App\Contracts\FcmTokenRepository;

class FirestoreFcmTokenRepository implements FcmTokenRepository
{
    private const COLLECTION = 'fcm_tokens';

    private const METADATA_FIELDS = [
        'device_id',
        'app_version',
        'timezone',
        'locale',
    ];

    private $db;

    public function __construct(FirebaseService $firebase)
    {
        $this->db = $firebase->db();
    }

    public function registerToken(string $firebaseUid, string $token, array $metadata = []): array
    {
        $tokenHash = $this->hash($token);
        $reference = $this->db->collection(self::COLLECTION)->document($tokenHash);
        $snapshot = $reference->snapshot();
        $existing = $snapshot->exists() ? $snapshot->data() : [];
        $now = now()->toIso8601String();
        $sameOwner = ($existing['firebase_uid'] ?? null) === $firebaseUid;

        $document = [
            'token' => $token,
            'token_hash' => $tokenHash,
            'firebase_uid' => $firebaseUid,
            'platform' => 'android',
            'is_active' => true,
            'created_at' => $sameOwner && isset($existing['created_at'])
                ? $existing['created_at']
                : $now,
            'last_seen_at' => $now,
            'revoked_at' => null,
            'invalidated_at' => null,
        ];

        foreach (self::METADATA_FIELDS as $field) {
            $document[$field] = $metadata[$field] ?? null;
        }

        $reference->set($document);

        return $this->safeMetadata($document);
    }

    public function revokeToken(string $firebaseUid, ?string $token = null): int
    {
        if ($token !== null) {
            $reference = $this->db->collection(self::COLLECTION)->document($this->hash($token));
            $snapshot = $reference->snapshot();

            if (! $snapshot->exists()) {
                return 0;
            }

            $document = $snapshot->data();

            if (($document['firebase_uid'] ?? null) !== $firebaseUid
                || ($document['is_active'] ?? false) !== true) {
                return 0;
            }

            $reference->set([
                'is_active' => false,
                'revoked_at' => now()->toIso8601String(),
            ], ['merge' => true]);

            return 1;
        }

        $revoked = 0;
        $documents = $this->activeTokenDocumentsForUser($firebaseUid);

        foreach ($documents as $document) {
            $this->db->collection(self::COLLECTION)->document($document->id())->set([
                'is_active' => false,
                'revoked_at' => now()->toIso8601String(),
            ], ['merge' => true]);
            $revoked++;
        }

        return $revoked;
    }

    public function getActiveTokensForUser(string $firebaseUid): array
    {
        $tokens = [];

        foreach ($this->activeTokenDocumentsForUser($firebaseUid) as $document) {
            $data = $document->data();
            $token = $data['token'] ?? null;

            if (is_string($token) && $token !== '') {
                $tokens[] = $token;
            }
        }

        return array_values(array_unique($tokens));
    }

    public function markInvalid(string $token): void
    {
        $reference = $this->db->collection(self::COLLECTION)->document($this->hash($token));
        $snapshot = $reference->snapshot();

        if (! $snapshot->exists()) {
            return;
        }

        $reference->set([
            'is_active' => false,
            'invalidated_at' => now()->toIso8601String(),
        ], ['merge' => true]);
    }

    public function countActiveTokensForUser(string $firebaseUid): int
    {
        return count($this->getActiveTokensForUser($firebaseUid));
    }

    private function activeTokenDocumentsForUser(string $firebaseUid): array
    {
        $documents = [];
        $query = $this->db->collection(self::COLLECTION)
            ->where('firebase_uid', '=', $firebaseUid)
            ->where('is_active', '=', true);

        foreach ($query->documents() as $document) {
            if (! $document->exists()) {
                continue;
            }

            $data = $document->data();

            if (($data['platform'] ?? null) === 'android'
                && empty($data['revoked_at'])
                && empty($data['invalidated_at'])) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    private function safeMetadata(array $document): array
    {
        return [
            'platform' => $document['platform'],
            'device_id' => $document['device_id'],
            'app_version' => $document['app_version'],
            'timezone' => $document['timezone'],
            'locale' => $document['locale'],
            'is_active' => $document['is_active'],
            'last_seen_at' => $document['last_seen_at'],
        ];
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
