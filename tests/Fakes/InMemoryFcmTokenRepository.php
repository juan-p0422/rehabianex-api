<?php

namespace Tests\Fakes;

use App\Contracts\FcmTokenRepository;

class InMemoryFcmTokenRepository implements FcmTokenRepository
{
    /** @var array<string, array<string, mixed>> */
    private array $documents = [];

    public function registerToken(string $firebaseUid, string $token, array $metadata = []): array
    {
        $hash = hash('sha256', $token);
        $existing = $this->documents[$hash] ?? [];
        $now = now()->toIso8601String();
        $sameOwner = ($existing['firebase_uid'] ?? null) === $firebaseUid;
        $document = [
            'token' => $token,
            'token_hash' => $hash,
            'firebase_uid' => $firebaseUid,
            'platform' => 'android',
            'device_id' => $metadata['device_id'] ?? null,
            'app_version' => $metadata['app_version'] ?? null,
            'timezone' => $metadata['timezone'] ?? null,
            'locale' => $metadata['locale'] ?? null,
            'is_active' => true,
            'created_at' => $sameOwner && isset($existing['created_at'])
                ? $existing['created_at']
                : $now,
            'last_seen_at' => $now,
            'revoked_at' => null,
            'invalidated_at' => null,
        ];

        $this->documents[$hash] = $document;

        return $this->safeMetadata($document);
    }

    public function revokeToken(string $firebaseUid, ?string $token = null): int
    {
        $revoked = 0;

        foreach ($this->documents as $hash => $document) {
            if (($document['firebase_uid'] ?? null) !== $firebaseUid
                || ($document['is_active'] ?? false) !== true
                || ($token !== null && $hash !== hash('sha256', $token))) {
                continue;
            }

            $this->documents[$hash]['is_active'] = false;
            $this->documents[$hash]['revoked_at'] = now()->toIso8601String();
            $revoked++;
        }

        return $revoked;
    }

    public function getActiveTokensForUser(string $firebaseUid): array
    {
        $tokens = [];

        foreach ($this->documents as $document) {
            if (($document['firebase_uid'] ?? null) === $firebaseUid
                && ($document['platform'] ?? null) === 'android'
                && ($document['is_active'] ?? false) === true
                && empty($document['revoked_at'])
                && empty($document['invalidated_at'])) {
                $tokens[] = $document['token'];
            }
        }

        return array_values(array_unique($tokens));
    }

    public function markInvalid(string $token): void
    {
        $hash = hash('sha256', $token);

        if (! isset($this->documents[$hash])) {
            return;
        }

        $this->documents[$hash]['is_active'] = false;
        $this->documents[$hash]['invalidated_at'] = now()->toIso8601String();
    }

    public function countActiveTokensForUser(string $firebaseUid): int
    {
        return count($this->getActiveTokensForUser($firebaseUid));
    }

    public function seedToken(string $firebaseUid, string $token, array $overrides = []): void
    {
        $this->registerToken($firebaseUid, $token);
        $hash = hash('sha256', $token);
        $this->documents[$hash] = array_replace($this->documents[$hash], $overrides);
    }

    public function documentForToken(string $token): ?array
    {
        return $this->documents[hash('sha256', $token)] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    public function allDocuments(): array
    {
        return $this->documents;
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
}
