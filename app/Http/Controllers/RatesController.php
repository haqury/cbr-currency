<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\CbrClientInterface;
use App\Http\Requests\GetRatesRequest;
use App\Models\CurrencyRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * GET /api/rates: returns rate for date, previous trading day and delta.
 * Курс за дату, предыдущий торговый день и разница (delta).
 */
final class RatesController extends Controller
{
    public function __construct(
        private CbrClientInterface $cbrClient,
    ) {
    }

    /**
     * GET /api/rates?date=Y-m-d&currency_code=USD&base_currency_code=RUR
     */
    public function index(GetRatesRequest $request): JsonResponse
    {
        $date = $request->validated('date');
        $currencyCode = $request->validated('currency_code');
        $baseCurrencyCode = $request->validated('base_currency_code');

        $baseForStorage = $baseCurrencyCode === 'RUB' ? 'RUR' : $baseCurrencyCode;

        $currentRate = $this->findRateForDate($date, $currencyCode, $baseForStorage);
        if ($currentRate === null) {
            return new JsonResponse([
                'message' => 'Курс на запрошенную дату не найден.',
            ], 404);
        }

        $previous = $this->findPreviousTradingDayRate($date, $currencyCode, $baseForStorage);

        $payload = [
            'date' => $date,
            'currency_code' => $currencyCode,
            'base_currency_code' => $baseCurrencyCode,
            'rate' => (float) $currentRate['rate'],
            'previous_trade_date' => $previous['date'],
            'delta' => $previous['rate'] !== null
                ? round((float) $currentRate['rate'] - (float) $previous['rate'], 6)
                : null,
        ];

        return new JsonResponse($payload);
    }

    /**
     * Find rate for the given date (DB first, then CBR with cache).
     * @return array{rate: float|string, date: string}|null
     */
    private function findRateForDate(string $date, string $currencyCode, string $baseCurrencyCode): ?array
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
     * @return array{date: string|null, rate: float|null}
     */
    private function findPreviousTradingDayRate(string $date, string $currencyCode, string $baseCurrencyCode): array
    {
        $previousRecord = CurrencyRate::forCurrency($currencyCode)
            ->forBaseCurrency($baseCurrencyCode)
            ->where('date', '<', $date)
            ->orderByDesc('date')
            ->first();

        if ($previousRecord !== null) {
            $prevDate = $previousRecord->date->format('Y-m-d');

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
