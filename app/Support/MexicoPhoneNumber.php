<?php

namespace App\Support;

final class MexicoPhoneNumber
{
    public const COUNTRY_CODE = '+52';

    public static function normalize(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        $candidate = trim($value);

        if ($candidate === ''
            || preg_match('/^\+?[0-9() .-]+$/', $candidate) !== 1) {
            return $value;
        }

        $digits = preg_replace('/\D/', '', $candidate);

        if (strlen($digits) === 10) {
            return self::COUNTRY_CODE.$digits;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            return '+'.$digits;
        }

        return $value;
    }

    public static function isValid(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return preg_match('/^\+52\d{10}$/', self::normalize($value)) === 1;
    }
}
