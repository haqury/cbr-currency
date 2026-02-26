<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\Iso4217CurrencyCode;
use Illuminate\Foundation\Http\FormRequest;

final class GetRatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Перед валидацией: нормализуем коды в верхний регистр, подставляем RUR по умолчанию.
     */
    protected function prepareForValidation(): void
    {
        $data = [];
        if ($this->has('currency_code') && is_string($this->currency_code)) {
            $data['currency_code'] = strtoupper($this->currency_code);
        }
        $base = $this->input('base_currency_code');
        $data['base_currency_code'] = is_string($base) && $base !== '' ? strtoupper($base) : 'RUR';
        $this->merge($data);
    }

    /**
     * @return array<string, array<int, string|\Illuminate\Validation\Rule|\Illuminate\Contracts\Validation\ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'currency_code' => ['required', 'string', 'max:10', new Iso4217CurrencyCode],
            'base_currency_code' => ['sometimes', 'string', 'max:10', 'in:RUR,RUB'],
        ];
    }

    /**
     * Сообщения валидации.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'base_currency_code.in' => 'Поддерживается только base_currency_code RUR или RUB.',
        ];
    }
}
