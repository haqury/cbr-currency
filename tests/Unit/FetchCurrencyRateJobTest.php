<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\CbrClientInterface;
use App\Jobs\FetchCurrencyRateJob;
use App\Models\CurrencyRate;
use App\Services\Cbr\Dto\CbrRateDto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class FetchCurrencyRateJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_handle_saves_rate_via_update_or_create_when_client_returns_dto(): void
    {
        $date = '2025-02-20';
        $dto = new CbrRateDto(
            date: $date,
            currencyCode: 'USD',
            rate: 98.5,
            nominal: 1,
            baseCurrencyCode: 'RUR',
        );

        $client = Mockery::mock(CbrClientInterface::class);
        $client->shouldReceive('getRateByDateAndCode')
            ->once()
            ->with($date, 'USD')
            ->andReturn($dto);

        $job = new FetchCurrencyRateJob($date, 'USD');
        $job->handle($client);

        $this->assertDatabaseHas('currency_rates', [
            'currency_code' => 'USD',
            'base_currency_code' => 'RUR',
            'nominal' => 1,
        ]);
        $record = CurrencyRate::where('currency_code', 'USD')->whereDate('date', $date)->first();
        $this->assertNotNull($record);
        $this->assertSame(98.5, (float) $record->rate);
    }

    public function test_handle_does_nothing_when_client_returns_null(): void
    {
        $client = Mockery::mock(CbrClientInterface::class);
        $client->shouldReceive('getRateByDateAndCode')
            ->once()
            ->with('2025-02-20', 'EUR')
            ->andReturn(null);

        $job = new FetchCurrencyRateJob('2025-02-20', 'EUR');
        $job->handle($client);

        $this->assertDatabaseCount('currency_rates', 0);
    }

    public function test_handle_is_idempotent_second_call_updates_same_row(): void
    {
        $date = '2025-02-20';
        $dto1 = new CbrRateDto($date, 'USD', 98.0, 1, 'RUR');
        $dto2 = new CbrRateDto($date, 'USD', 99.0, 1, 'RUR');

        $client = Mockery::mock(CbrClientInterface::class);
        $client->shouldReceive('getRateByDateAndCode')
            ->twice()
            ->with($date, 'USD')
            ->andReturn($dto1, $dto2);

        $job = new FetchCurrencyRateJob($date, 'USD');
        $job->handle($client);
        $job->handle($client);

        $this->assertDatabaseCount('currency_rates', 1);
        $record = CurrencyRate::where('currency_code', 'USD')->whereDate('date', $date)->first();
        $this->assertNotNull($record);
        $this->assertSame(99.0, (float) $record->rate);
    }
}
