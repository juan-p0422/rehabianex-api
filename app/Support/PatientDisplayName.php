<?php

namespace App\Support;

final class PatientDisplayName
{
    public const ANONYMOUS = 'Paciente anónimo';

    public static function isPrivate(array $profile): bool
    {
        return ($profile['is_anonymous'] ?? false) === true
            || ($profile['privacy_mode'] ?? false) === true;
    }

    public static function forOwner(array $profile): string
    {
        if (self::isPrivate($profile)) {
            return self::ANONYMOUS;
        }

        return self::firstVisibleName($profile) ?? 'Paciente RehabiAnex';
    }

    public static function forSupervisor(array $profile, string $fallback): string
    {
        if (self::isPrivate($profile)) {
            $nickname = trim((string) ($profile['nickname'] ?? ''));

            return $nickname !== '' ? $nickname : $fallback;
        }

        return self::firstVisibleName($profile) ?? $fallback;
    }

    private static function firstVisibleName(array $profile): ?string
    {
        foreach (['full_name', 'nickname'] as $field) {
            $value = trim((string) ($profile[$field] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
