<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\CurrencyCodeValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that the value is a valid ISO 4217 currency code (RUR allowed as alias for RUB).
 */
final class Iso4217CurrencyCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('validation.string', ['attribute' => $attribute]));
            return;
        }
        $validator = app(CurrencyCodeValidator::class);
        if (! $validator->isValid($value)) {
            $fail('Код валюты должен быть из справочника ISO 4217 (допускается RUR).');
        }
    }
}
