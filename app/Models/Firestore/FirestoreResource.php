<?php

namespace App\Models\Firestore;

abstract class FirestoreResource
{
    public const COLLECTION = '';

    public const ID_FIELD = 'id';

    public const FILTERABLE = [];

    public static function collection(): string
    {
        return static::COLLECTION;
    }

    public static function idField(): string
    {
        return static::ID_FIELD;
    }

    public static function filterable(): array
    {
        return static::FILTERABLE;
    }
}
