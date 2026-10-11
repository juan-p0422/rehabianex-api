<?php

namespace App\Rules;

use App\Support\MexicoPhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class MexicoPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! MexicoPhoneNumber::isValid($value)) {
            $fail('El campo :attribute debe contener 10 dígitos nacionales de México; se almacena con el prefijo +52.');
        }
    }
}
