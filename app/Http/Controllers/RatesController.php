<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\CbrClientInterface;
use App\Models\CurrencyRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * GET /api/rates: returns rate for date, previous trading day and delta.
 * Курс за дату, предыдущий торговый день и разница (delta).
 */
final class RatesController extends Controller
{
    private const int MAX_DAYS_BACK = 365;

    public function __construct(
        private CbrClientInterface $cbrClient,
    ) {
    }

    /**
     * GET /api/rates?date=Y-m-d&currency_code=USD&base_currency_code=RUR
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'currency_code' => ['required', 'string', 'max:10'],
            'base_currency_code' => ['sometimes', 'string', 'max:10'],
        ]);

        $date = $validated['date'];
        $currencyCode = strtoupper((string) $validated['currency_code']);
        $baseCurrencyCode = strtoupper((string) ($validated['base_currency_code'] ?? 'RUR'));

        // CBR provides rates only in RUR; other bases are not supported.
        if ($baseCurrencyCode !== 'RUR' && $baseCurrencyCode !== 'RUB') {
            return new JsonResponse([
                'message' => 'Only base_currency_code RUR (or RUB) is supported.',
            ], 422);
        }

        $baseForStorage = $baseCurrencyCode === 'RUB' ? 'RUR' : $baseCurrencyCode;

        $currentRate = $this->findRateForDate($date, $currencyCode, $baseForStorage);
        if ($currentRate === null) {
            return new JsonResponse([
                'message' => 'No rate found for the requested date.',
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
     * @return array{date: string|null, rate: float|null}
     */
    private function findPreviousTradingDayRate(string $date, string $currencyCode, string $baseCurrencyCode): array
    {
        $dt = Carbon::parse($date);
        $daysChecked = 0;

        while ($daysChecked < self::MAX_DAYS_BACK) {
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
