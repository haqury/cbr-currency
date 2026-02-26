<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\CbrClientInterface;
use App\Services\CurrencyRateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fetches CBR rate for one date and one currency, saves via CurrencyRateService (ISO + CBR check, then save or update).
 * Idempotent: updateOrCreate by (date, currency_code, base_currency_code).
 * Connection берётся из config (QUEUE_CONNECTION). Retry при временной недоступности ЦБ.
 */
final class FetchCurrencyRateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Количество попыток при исключении (например, таймаут ЦБ). */
    public int $tries = 3;

    public function __construct(
        public string $date,
        public string $currencyCode,
    ) {}

    /**
     * Задержка перед повторной попыткой (секунды): 1 мин, затем 5 мин.
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(CbrClientInterface $client, CurrencyRateService $currencyRateService): void
    {
        $dto = $client->getRateByDateAndCode($this->date, $this->currencyCode);
        if ($dto === null) {
            return;
        }

        $currencyRateService->saveOrUpdateFromDto($dto);
    }
}
