<?php

namespace App\Support;

final class AdminPasswordPolicy
{
    public const MIN_LENGTH = 14;

    public static function passes(string $password): bool
    {
        return strlen($password) >= self::MIN_LENGTH
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/[0-9]/', $password) === 1
            && preg_match('/[^A-Za-z0-9]/', $password) === 1
            && preg_match('/\s/', $password) !== 1;
    }

    public static function requirementMessage(): string
    {
        return 'La contraseña debe tener al menos 14 caracteres, mayúscula, minúscula, número, símbolo y no contener espacios.';
    }
}
