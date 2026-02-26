<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\CbrClientInterface;
use App\Jobs\FetchCurrencyRateJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

/**
 * Feature-тесты команды app:sync-currency-history: постановка джобов в очередь, валидация опций, нормализация кода валюты.
 */
final class SyncCurrencyHistoryCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockCbrClientWithCodes(['USD', 'EUR', 'RUR']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockCbrClientWithCodes(array $codes): void
    {
        $mock = Mockery::mock(CbrClientInterface::class);
        $mock->shouldReceive('getAvailableCurrencyCodes')
            ->andReturn($codes);
        $this->app->instance(CbrClientInterface::class, $mock);
    }

    /** Проверяем: команда ставит в очередь по одному Job на каждый день (при --days=3 диспатчится 4 джоба: по числу дней в периоде). */
    public function test_command_dispatches_jobs_for_each_day(): void
    {
        Bus::fake();

        $this->artisan('app:sync-currency-history', ['code' => 'USD', '--days' => 3])
            ->assertSuccessful();

        Bus::assertDispatched(FetchCurrencyRateJob::class, 4);
    }

    /** Проверяем: при --days=0 команда завершается с ошибкой и джобы не диспатчатся. */
    public function test_command_fails_when_days_less_than_one(): void
    {
        Bus::fake();

        $this->artisan('app:sync-currency-history', ['code' => 'USD', '--days' => 0])
            ->assertFailed();

        Bus::assertNotDispatched(FetchCurrencyRateJob::class);
    }

    /** Проверяем: код валюты из аргумента передаётся в Job в верхнем регистре (eur → EUR). */
    public function test_command_normalizes_currency_code_to_uppercase(): void
    {
        Bus::fake();

        $this->artisan('app:sync-currency-history', ['code' => 'eur', '--days' => 1])
            ->assertSuccessful();

        Bus::assertDispatched(FetchCurrencyRateJob::class, function (FetchCurrencyRateJob $job): bool {
            return $job->currencyCode === 'EUR';
        });
    }

    /** Проверяем: при коде валюты не из ISO 4217 команда завершается с ошибкой, джобы не диспатчатся. */
    public function test_command_fails_when_currency_code_not_iso4217(): void
    {
        Bus::fake();

        $this->artisan('app:sync-currency-history', ['code' => 'FAKE', '--days' => 1])
            ->assertFailed();

        Bus::assertNotDispatched(FetchCurrencyRateJob::class);
    }

    /**
     * Проверяем: при коде валюты, который ЦБ не публикует на дату, команда всё равно считается валидной,
     * если код существует в ISO 4217 (правило ISO OR CBR), и джобы ставятся в очередь.
     */
    public function test_command_does_not_fail_when_currency_not_published_by_cbr_but_exists_in_iso(): void
    {
        Bus::fake();
        $this->mockCbrClientWithCodes(['USD', 'RUR']);

        $this->artisan('app:sync-currency-history', ['code' => 'CHF', '--days' => 1])
            ->assertSuccessful();

        Bus::assertDispatched(FetchCurrencyRateJob::class);
    }
}
