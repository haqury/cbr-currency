<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GetRatesRequest;
use App\Services\CurrencyRateQueryService;
use App\ValueObjects\BaseCurrency;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/rates: returns rate for date, previous trading day and delta.
 * Курс за дату, предыдущий торговый день и разница (delta).
 */
final class RatesController extends Controller
{
    public function __construct(
        private CurrencyRateQueryService $rates,
    ) {}

    /**
     * GET /api/rates?date=Y-m-d&currency_code=USD&base_currency_code=RUR
     */
    public function index(GetRatesRequest $request): JsonResponse
    {
        $date = $request->validated('date');
        $currencyCode = $request->validated('currency_code');
        $baseCurrencyCode = $request->validated('base_currency_code');

        $baseCurrency = BaseCurrency::fromInput($baseCurrencyCode);
        $baseForStorage = $baseCurrency->storage();

        $currentRate = $this->rates->findRateForDate($date, $currencyCode, $baseForStorage);
        if ($currentRate === null) {
            return new JsonResponse([
                'message' => 'Курс на запрошенную дату не найден.',
            ], 404);
        }

        $previous = $this->rates->findPreviousTradingDayRate($date, $currencyCode, $baseForStorage);

        // Дельта считается через bcsub, чтобы избежать погрешностей двоичной плавающей точки.
        $currentRateValue = (string) $currentRate['rate'];
        $previousRateValue = $previous['rate'] !== null ? (string) $previous['rate'] : null;
        $delta = $previousRateValue !== null
            ? (float) bcsub($currentRateValue, $previousRateValue, 6)
            : null;

        $payload = [
            'date' => $date,
            'currency_code' => $currencyCode,
            'base_currency_code' => $baseCurrency->display(),
            'rate' => (float) $currentRateValue,
            'previous_trade_date' => $previous['date'],
            'delta' => $delta,
        ];

        return new JsonResponse($payload);
    }
}
