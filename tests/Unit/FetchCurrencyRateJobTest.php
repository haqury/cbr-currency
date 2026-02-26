<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\CbrClientInterface;
use App\Exceptions\CurrencyCodeNotAllowedException;
use App\Jobs\FetchCurrencyRateJob;
use App\Models\CurrencyRate;
use App\Services\Cbr\Dto\CbrRateDto;
use App\Services\CurrencyRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Unit-тесты FetchCurrencyRateJob: сохранение через CurrencyRateService (ISO + ЦБ), null от клиента, идемпотентность.
 */
final class FetchCurrencyRateJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockCbrClientForUpsert(string $date, ?CbrRateDto $dto, array $cbrCodes = ['USD', 'RUR', 'EUR']): void
    {
        $mock = Mockery::mock(CbrClientInterface::class);
        $mock->shouldReceive('getRateByDateAndCode')->andReturn($dto);
        // @phpstan-ignore-next-line Mockery dynamic expectation API
        $mock->shouldReceive('getAvailableCurrencyCodes')->with($date)->andReturn($cbrCodes);
        $this->app->instance(CbrClientInterface::class, $mock);
    }

    /** Проверяем: при возврате DTO от клиента в БД сохраняется запись с датой, валютой, базой, курсом и номиналом. */
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

        $this->mockCbrClientForUpsert($date, $dto);

        $job = new FetchCurrencyRateJob($date, 'USD');
        $job->handle(app(CbrClientInterface::class), app(CurrencyRateService::class));

        $this->assertDatabaseHas('currency_rates', [
            'currency_code' => 'USD',
            'base_currency_code' => 'RUR',
            'nominal' => 1,
        ]);
        $record = CurrencyRate::where('currency_code', 'USD')->whereDate('date', $date)->first();
        $this->assertNotNull($record);
        $this->assertSame(98.5, (float) $record->rate);
    }

    /** Проверяем: когда клиент возвращает null (нет курса на дату), запись в БД не создаётся. */
    public function test_handle_does_nothing_when_client_returns_null(): void
    {
        $date = '2025-02-20';
        $this->mockCbrClientForUpsert($date, null);

        $job = new FetchCurrencyRateJob($date, 'EUR');
        $job->handle(app(CbrClientInterface::class), app(CurrencyRateService::class));

        $this->assertDatabaseCount('currency_rates', 0);
    }

    /** Проверяем: повторный вызов handle с теми же датой и валютой обновляет одну и ту же запись (идемпотентность updateOrCreate). */
    public function test_handle_is_idempotent_second_call_updates_same_row(): void
    {
        $date = '2025-02-20';
        $dto1 = new CbrRateDto($date, 'USD', 98.0, 1, 'RUR');
        $dto2 = new CbrRateDto($date, 'USD', 99.0, 1, 'RUR');

        $mock = Mockery::mock(CbrClientInterface::class);
        // @phpstan-ignore-next-line Mockery dynamic expectation API
        $mock->shouldReceive('getRateByDateAndCode')->with($date, 'USD')->andReturn($dto1, $dto2);
        // @phpstan-ignore-next-line Mockery dynamic expectation API
        $mock->shouldReceive('getAvailableCurrencyCodes')->with($date)->andReturn(['USD', 'RUR']);
        $this->app->instance(CbrClientInterface::class, $mock);

        $currencyRateService = app(CurrencyRateService::class);
        $job = new FetchCurrencyRateJob($date, 'USD');
        $job->handle(app(CbrClientInterface::class), $currencyRateService);
        $job->handle(app(CbrClientInterface::class), $currencyRateService);

        $this->assertDatabaseCount('currency_rates', 1);
        $record = CurrencyRate::where('currency_code', 'USD')->whereDate('date', $date)->first();
        $this->assertNotNull($record);
        $this->assertSame(99.0, (float) $record->rate);
    }

    /** Проверяем: при DTO с кодом, которого нет в ISO, но он есть в списке ЦБ, запись создаётся (валиден по правилу ISO OR CBR). */
    public function test_handle_saves_when_currency_code_only_in_cbr(): void
    {
        $date = '2025-02-20';
        $dto = new CbrRateDto(
            date: $date,
            currencyCode: 'FAKE',
            rate: 1.0,
            nominal: 1,
            baseCurrencyCode: 'RUR',
        );

        $this->mockCbrClientForUpsert($date, $dto, ['FAKE']);

        $job = new FetchCurrencyRateJob($date, 'FAKE');
        $job->handle(app(CbrClientInterface::class), app(CurrencyRateService::class));

        $this->assertDatabaseHas('currency_rates', [
            'currency_code' => 'FAKE',
            'base_currency_code' => 'RUR',
        ]);
    }
}
