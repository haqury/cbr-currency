<?php

declare(strict_types=1);

namespace App\Services\Cbr\Dto;

/**
 * Immutable DTO for a single CBR currency rate.
 * Неизменяемый DTO для одного курса валюты ЦБ.
 */
final readonly class CbrRateDto
{
    public function __construct(
        public string $date,
        public string $currencyCode,
        public float $rate,
        public int $nominal = 1,
        public string $baseCurrencyCode = 'RUR',
        public ?string $name = null,
        public ?string $numCode = null,
    ) {}
}
