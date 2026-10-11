<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NonBlankText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/\S/u', $value) !== 1) {
            $fail('El campo :attribute debe contener texto y no solo espacios.');
        }
    }
}
