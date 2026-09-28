<?php

namespace App\Contracts;

interface FcmTokenRepository
{
    public function registerToken(string $firebaseUid, string $token, array $metadata = []): array;

    public function revokeToken(string $firebaseUid, ?string $token = null): int;

    /** @return list<string> */
    public function getActiveTokensForUser(string $firebaseUid): array;

    public function markInvalid(string $token): void;

    public function countActiveTokensForUser(string $firebaseUid): int;
}
