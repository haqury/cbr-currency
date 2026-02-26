<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\CbrClientInterface;
use App\Models\CurrencyRate;
use App\Services\Cbr\Dto\CbrRateDto;
use App\Services\CurrencyRateQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** Unit-тесты CurrencyRateQueryService: поиск курса за дату и предыдущий торговый день. */
final class CurrencyRateQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Проверяем: findRateForDate возвращает данные из БД, не обращаясь к клиенту ЦБ. */
    public function test_find_rate_for_date_prefers_database_over_cbr(): void
    {
        CurrencyRate::create([
            'date' => '2025-02-20',
            'currency_code' => 'USD',
            'base_currency_code' => 'RUR',
            'rate' => 100.5,
            'nominal' => 1,
        ]);

        $client = Mockery::mock(CbrClientInterface::class);
        $client->shouldNotReceive('getRateByDateAndCode');
        $this->app->instance(CbrClientInterface::class, $client);

        $service = app(CurrencyRateQueryService::class);
        $result = $service->findRateForDate('2025-02-20', 'USD', 'RUR');

        $this->assertNotNull($result);
        $this->assertSame(100.5, (float) $result['rate']);
        $this->assertSame('2025-02-20', $result['date']);
    }

    /** Проверяем: findRateForDate при отсутствии записи в БД использует клиента ЦБ. */
    public function test_find_rate_for_date_uses_cbr_when_database_empty(): void
    {
        $dto = new CbrRateDto(
            date: '2025-02-20',
            currencyCode: 'USD',
            rate: 98.5,
            nominal: 1,
            baseCurrencyCode: 'RUR',
        );

        $client = Mockery::mock(CbrClientInterface::class);
        $client->shouldReceive('getRateByDateAndCode')
            ->once()
            ->with('2025-02-20', 'USD')
            ->andReturn($dto);
        $this->app->instance(CbrClientInterface::class, $client);

        $service = app(CurrencyRateQueryService::class);
        $result = $service->findRateForDate('2025-02-20', 'USD', 'RUR');

        $this->assertNotNull($result);
        $this->assertSame(98.5, (float) $result['rate']);
        $this->assertSame('2025-02-20', $result['date']);
    }

    /** Проверяем: findPreviousTradingDayRate возвращает предыдущую дату и курс из БД, если запись есть. */
    public function test_find_previous_trading_day_rate_uses_previous_record_from_database(): void
    {
        CurrencyRate::create([
            'date' => '2025-02-19',
            'currency_code' => 'USD',
            'base_currency_code' => 'RUR',
            'rate' => 99.0,
            'nominal' => 1,
        ]);
        CurrencyRate::create([
            'date' => '2025-02-20',
            'currency_code' => 'USD',
            'base_currency_code' => 'RUR',
            'rate' => 100.5,
            'nominal' => 1,
        ]);

        $client = Mockery::mock(CbrClientInterface::class);
        $client->shouldNotReceive('getRateByDateAndCode');
        $this->app->instance(CbrClientInterface::class, $client);

        $service = app(CurrencyRateQueryService::class);
        $result = $service->findPreviousTradingDayRate('2025-02-20', 'USD', 'RUR');

        $this->assertSame('2025-02-19', $result['date']);
        $this->assertSame(99.0, $result['rate']);
    }

    /** Проверяем: findPreviousTradingDayRate при пустой БД ищет назад через ЦБ до max_days_back. */
    public function test_find_previous_trading_day_rate_falls_back_to_cbr_when_database_empty(): void
    {
        config(['cbr.max_days_back' => 3]);

        $dto = new CbrRateDto(
            date: '2025-02-19',
            currencyCode: 'USD',
            rate: 97.0,
            nominal: 1,
            baseCurrencyCode: 'RUR',
        );

        $client = Mockery::mock(CbrClientInterface::class);
        $client->shouldReceive('getRateByDateAndCode')
            ->once()
            ->with('2025-02-19', 'USD')
            ->andReturn($dto);
        $this->app->instance(CbrClientInterface::class, $client);

        $service = app(CurrencyRateQueryService::class);
        $result = $service->findPreviousTradingDayRate('2025-02-20', 'USD', 'RUR');

        $this->assertSame('2025-02-19', $result['date']);
        $this->assertSame(97.0, $result['rate']);
    }

    /** Проверяем: findPreviousTradingDayRate возвращает null, null, если данных нет ни в БД, ни у ЦБ в пределах max_days_back. */
    public function test_find_previous_trading_day_rate_returns_nulls_when_no_data_anywhere(): void
    {
        config(['cbr.max_days_back' => 2]);

        $client = Mockery::mock(CbrClientInterface::class);
        $client->shouldReceive('getRateByDateAndCode')
            ->andReturn(null);
        $this->app->instance(CbrClientInterface::class, $client);

        $service = app(CurrencyRateQueryService::class);
        $result = $service->findPreviousTradingDayRate('2025-02-20', 'USD', 'RUR');

        $this->assertNull($result['date']);
        $this->assertNull($result['rate']);
    }
}

