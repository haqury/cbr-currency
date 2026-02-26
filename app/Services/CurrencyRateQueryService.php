<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CbrClientInterface;
use App\Models\CurrencyRate;
use Illuminate\Support\Carbon;

/**
 * Read/query service for currency rates:
 * - find rate for a given date;
 * - find rate for the previous trading day (DB first, then CBR).
 * Сервис чтения курсов:
 * - поиск курса за дату;
 * - поиск курса за предыдущий торговый день (сначала БД, потом ЦБ).
 */
final class CurrencyRateQueryService
{
    public function __construct(
        private CbrClientInterface $cbrClient,
    ) {}

    /**
     * Find rate for the given date (DB first, then CBR with cache).
     *
     * @return array{rate: float|string, date: string}|null
     */
    public function findRateForDate(string $date, string $currencyCode, string $baseCurrencyCode): ?array
    {
        $fromDb = CurrencyRate::forDate($date)
            ->forCurrency($currencyCode)
            ->forBaseCurrency($baseCurrencyCode)
            ->first();

        if ($fromDb !== null) {
            return ['rate' => $fromDb->rate, 'date' => $date];
        }

        $dto = $this->cbrClient->getRateByDateAndCode($date, $currencyCode);
        if ($dto === null) {
            return null;
        }

        return ['rate' => $dto->rate, 'date' => $dto->date];
    }

    /**
     * Find rate for the previous trading day (last date < $date with data).
     * First tries one DB query (index-friendly); if no data in DB, walks back via CBR (cache).
     *
     * @return array{date: string|null, rate: float|null}
     */
    public function findPreviousTradingDayRate(string $date, string $currencyCode, string $baseCurrencyCode): array
    {
        $previousRecord = CurrencyRate::forCurrency($currencyCode)
            ->forBaseCurrency($baseCurrencyCode)
            ->where('date', '<', $date)
            ->orderByDesc('date')
            ->first();

        if ($previousRecord !== null) {
            $prevDate = Carbon::parse($previousRecord->date)->format('Y-m-d');

            return [
                'date' => $prevDate,
                'rate' => (float) $previousRecord->rate,
            ];
        }

        // В БД нет более ранних дат — ищем по ЦБ (кэш), перебор дней назад.
        $dt = Carbon::parse($date);
        $daysChecked = 0;

        $maxDaysBack = config('cbr.max_days_back', 365);

        while ($daysChecked < $maxDaysBack) {
            $dt = $dt->subDay();
            $prevDate = $dt->format('Y-m-d');
            $daysChecked++;

            $prevRate = $this->findRateForDate($prevDate, $currencyCode, $baseCurrencyCode);
            if ($prevRate !== null) {
                return ['date' => $prevRate['date'], 'rate' => (float) $prevRate['rate']];
            }
        }

        return ['date' => null, 'rate' => null];
    }
}
