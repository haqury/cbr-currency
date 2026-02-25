<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\CbrClientInterface;
use Illuminate\Console\Command;

/**
 * Quick test of CBR rate fetch (no Tinker). Depends on CbrClientInterface.
 */
final class TestCbrCommand extends Command
{
    protected $signature = 'app:test-cbr
                            {date : Date Y-m-d (e.g. 2025-02-20)}
                            {code=USD : Currency code}';

    protected $description = 'Fetch one CBR rate for date/code and print it';

    public function handle(CbrClientInterface $client): int
    {
        $date = $this->argument('date');
        $code = $this->argument('code');

        $this->info("Fetching CBR rate for {$date} {$code}...");

        $dto = $client->getRateByDateAndCode($date, $code);

        if ($dto === null) {
            $this->warn('No rate found (e.g. weekend or invalid date/code).');

            return self::SUCCESS;
        }

        $this->line("Rate: {$dto->rate} (nominal: {$dto->nominal}, base: {$dto->baseCurrencyCode})");

        return self::SUCCESS;
    }
}
