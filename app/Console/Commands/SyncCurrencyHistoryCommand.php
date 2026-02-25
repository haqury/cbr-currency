<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FetchCurrencyRateJob;
use Illuminate\Console\Command;

/**
 * Sync CBR currency history: dispatch one job per day (no fetching in command).
 * Синхронизация истории курсов ЦБ: только постановка джобов по дням.
 */
final class SyncCurrencyHistoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-currency-history
                            {code : Currency code (e.g. USD, EUR)}
                            {--days=180 : Number of days to sync (from today backwards)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch jobs to sync CBR currency rates for the given code over the last N days';

    public function handle(): int
    {
        $code = $this->argument('code');
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('Option --days must be at least 1.');

            return self::FAILURE;
        }

        $code = strtoupper(trim($code));
        if ($code === '') {
            $this->error('Currency code cannot be empty.');

            return self::FAILURE;
        }

        $end = new \DateTimeImmutable('today');
        $start = $end->modify("-{$days} days");
        $interval = new \DateInterval('P1D');
        $period = new \DatePeriod($start, $interval, $end->modify('+1 day'));

        $count = 0;
        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            FetchCurrencyRateJob::dispatch($dateStr, $code);
            $count++;
        }

        $this->info("Dispatched {$count} job(s) for {$code} (last {$days} days).");

        return self::SUCCESS;
    }
}
