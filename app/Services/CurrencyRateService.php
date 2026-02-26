<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CbrClientInterface;
use App\Exceptions\CurrencyCodeNotAllowedException;
use App\Models\CurrencyRate;
use App\Services\Cbr\Dto\CbrRateDto;
use Illuminate\Support\Carbon;

/**
 * Reusable logic: validate currency code (ISO 4217 + CBR list for date), then save or update rate.
 * Единая логика проверки кода (ISO и ЦБ на дату) и сохранения курса.
 */
final class CurrencyRateService
{
    public function __construct(
        private CurrencyCodeValidator $isoValidator,
        private CbrClientInterface $cbrClient,
    ) {}

    /**
     * Validates that the currency code exists at least in one of:
     * - ISO 4217 list (CurrencyCodeValidator)
     * - CBR published codes for the given date.
     *
     * Throws CurrencyCodeNotAllowedException if it is missing from both.
     *
     * @throws CurrencyCodeNotAllowedException
     */
    public function validateCodeForDate(string $code, string $date): void
    {
        $code = strtoupper(trim($code));
        $isIsoValid = $this->isoValidator->isValid($code);

        $cbrCodes = $this->cbrClient->getAvailableCurrencyCodes($date);
        $codeForCbr = $code === 'RUB' ? 'RUR' : $code;
        $inCbr = in_array($codeForCbr, $cbrCodes, true) || in_array($code, $cbrCodes, true);

        if (! $isIsoValid && ! $inCbr) {
            throw CurrencyCodeNotAllowedException::notIso4217($code);
        }
    }

    /**
     * Validates code (ISO + CBR for dto date) and saves or updates the rate in currency_rates.
     *
     * @throws CurrencyCodeNotAllowedException
     */
    public function saveOrUpdateFromDto(CbrRateDto $dto): void
    {
        $this->validateCodeForDate($dto->currencyCode, $dto->date);

        $date = Carbon::parse($dto->date)->startOfDay();
        CurrencyRate::updateOrCreate(
            [
                'date' => $date,
                'currency_code' => $dto->currencyCode,
                'base_currency_code' => $dto->baseCurrencyCode,
            ],
            [
                'rate' => $dto->rate,
                'nominal' => $dto->nominal,
            ]
        );
    }
}
