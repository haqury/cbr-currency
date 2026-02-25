<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\CbrClientInterface;
use App\Models\CurrencyRate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Fetches CBR rate for one date and one currency, saves to currency_rates.
 * Idempotent: updateOrCreate by (date, currency_code, base_currency_code).
 */
final class FetchCurrencyRateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $date,
        public string $currencyCode,
    ) {
        $this->connection = extension_loaded('redis') ? 'redis' : 'sync';
    }

    public function handle(CbrClientInterface $client): void
    {
        $dto = $client->getRateByDateAndCode($this->date, $this->currencyCode);
        if ($dto === null) {
            return;
        }

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
