<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\CbrClientInterface;
use App\Exceptions\CurrencyCodeNotAllowedException;
use App\Models\CurrencyRate;
use App\Services\Cbr\Dto\CbrRateDto;
use App\Services\CurrencyRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Unit-тесты CurrencyRateService: validateCodeForDate (ISO + ЦБ), saveOrUpdateFromDto сохраняет при валидном коде.
 */
final class CurrencyRateServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Проверяем: validateCodeForDate выбрасывает исключение, если код не в ISO 4217. */
    public function test_validate_code_for_date_throws_when_not_iso4217(): void
    {
        $mock = Mockery::mock(CbrClientInterface::class);
        // @phpstan-ignore-next-line Mockery dynamic expectation API
        $mock->shouldReceive('getAvailableCurrencyCodes')->never();
        $this->app->instance(CbrClientInterface::class, $mock);

        $service = app(CurrencyRateService::class);
        $this->expectException(CurrencyCodeNotAllowedException::class);
        $this->expectExceptionMessage('ISO 4217');
        $service->validateCodeForDate('FAKE', '2025-02-20');
    }

    /** Проверяем: validateCodeForDate выбрасывает исключение, если ЦБ не публикует курс на дату. */
    public function test_validate_code_for_date_throws_when_not_in_cbr_for_date(): void
    {
        $mock = Mockery::mock(CbrClientInterface::class);
        // @phpstan-ignore-next-line Mockery dynamic expectation API
        $mock->shouldReceive('getAvailableCurrencyCodes')->with('2025-02-20')->andReturn(['USD', 'RUR']);
        $this->app->instance(CbrClientInterface::class, $mock);

        $service = app(CurrencyRateService::class);
        $this->expectException(CurrencyCodeNotAllowedException::class);
        $this->expectExceptionMessage('ЦБ РФ не публикует');
        $service->validateCodeForDate('CHF', '2025-02-20');
    }

    /** Проверяем: validateCodeForDate не выбрасывает при валидном коде (ISO + в списке ЦБ). */
    public function test_validate_code_for_date_passes_when_iso_and_in_cbr(): void
    {
        $mock = Mockery::mock(CbrClientInterface::class);
        // @phpstan-ignore-next-line Mockery dynamic expectation API
        $mock->shouldReceive('getAvailableCurrencyCodes')->with('2025-02-20')->andReturn(['USD', 'RUR']);
        $this->app->instance(CbrClientInterface::class, $mock);

        $service = app(CurrencyRateService::class);
        $service->validateCodeForDate('USD', '2025-02-20');
        $this->addToAssertionCount(1);
    }

    /** Проверяем: saveOrUpdateFromDto сохраняет запись при валидном DTO (ISO + ЦБ на дату). */
    public function test_save_or_update_from_dto_saves_when_valid(): void
    {
        $date = '2025-02-20';
        $dto = new CbrRateDto($date, 'USD', 98.5, 1, 'RUR');

        $mock = Mockery::mock(CbrClientInterface::class);
        // @phpstan-ignore-next-line Mockery dynamic expectation API
        $mock->shouldReceive('getAvailableCurrencyCodes')->with($date)->andReturn(['USD', 'RUR']);
        $this->app->instance(CbrClientInterface::class, $mock);

        $service = app(CurrencyRateService::class);
        $service->saveOrUpdateFromDto($dto);

        $this->assertDatabaseHas('currency_rates', [
            'currency_code' => 'USD',
            'base_currency_code' => 'RUR',
            'nominal' => 1,
        ]);
        $record = CurrencyRate::where('currency_code', 'USD')->whereDate('date', $date)->first();
        $this->assertSame(98.5, (float) $record->rate);
    }
}
