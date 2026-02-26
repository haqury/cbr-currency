<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\CbrClientInterface;
use App\Models\CurrencyRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Feature-тесты эндпоинта GET /api/rates: валидация, 404, успешный ответ с rate/delta, граничные случаи.
 */
final class RatesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Проверяем: при недопустимом base_currency_code (не RUR/RUB) возвращается 422 и ошибка валидации. */
    public function test_rates_returns_422_when_base_currency_code_invalid(): void
    {
        $response = $this->getJson('/api/rates?date=2025-02-20&currency_code=USD&base_currency_code=EUR');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['base_currency_code']);
    }

    /** Проверяем: при отсутствии date возвращается 422 и ошибка валидации. */
    public function test_rates_returns_422_when_date_missing(): void
    {
        $response = $this->getJson('/api/rates?currency_code=USD');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    /** Проверяем: при неверном формате даты (не Y-m-d) возвращается 422 и ошибка валидации. */
    public function test_rates_returns_422_when_date_format_invalid(): void
    {
        $response = $this->getJson('/api/rates?date=20-02-2025&currency_code=USD');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    /** Проверяем: при отсутствии currency_code возвращается 422 и ошибка валидации. */
    public function test_rates_returns_422_when_currency_code_missing(): void
    {
        $response = $this->getJson('/api/rates?date=2025-02-20');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['currency_code']);
    }

    /** Проверяем: при коде валюты не из ISO 4217 возвращается 422 и ошибка валидации. */
    public function test_rates_returns_422_when_currency_code_not_iso4217(): void
    {
        $response = $this->getJson('/api/rates?date=2025-02-20&currency_code=FAKE&base_currency_code=RUR');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['currency_code']);
    }

    /** Проверяем: когда курса на дату нет ни в БД, ни у ЦБ — возвращается 404 и сообщение об ошибке. */
    public function test_rates_returns_404_when_no_rate_for_date(): void
    {
        $this->instance(CbrClientInterface::class, Mockery::mock(CbrClientInterface::class, function ($mock): void {
            $mock->shouldReceive('getRateByDateAndCode')
                ->with('2025-02-20', 'USD')
                ->andReturn(null);
        }));

        $response = $this->getJson('/api/rates?date=2025-02-20&currency_code=USD&base_currency_code=RUR');

        $response->assertStatus(404)
            ->assertJson(['message' => 'Курс на запрошенную дату не найден.']);
    }

    /** Проверяем: при наличии курсов за дату и предыдущий день в БД — 200, в ответе rate, previous_trade_date и delta. */
    public function test_rates_returns_200_with_rate_and_delta_when_data_in_db(): void
    {
        $date = '2025-02-20';
        $prevDate = '2025-02-19';

        CurrencyRate::create([
            'date' => $date,
            'currency_code' => 'USD',
            'base_currency_code' => 'RUR',
            'rate' => 100.5,
            'nominal' => 1,
        ]);
        CurrencyRate::create([
            'date' => $prevDate,
            'currency_code' => 'USD',
            'base_currency_code' => 'RUR',
            'rate' => 99.0,
            'nominal' => 1,
        ]);

        $response = $this->getJson('/api/rates?date='.$date.'&currency_code=USD&base_currency_code=RUR');

        $response->assertStatus(200)
            ->assertJsonPath('date', $date)
            ->assertJsonPath('currency_code', 'USD')
            ->assertJsonPath('base_currency_code', 'RUR')
            ->assertJsonPath('rate', 100.5)
            ->assertJsonPath('previous_trade_date', $prevDate)
            ->assertJsonPath('delta', 1.5);
    }

    /** Проверяем: base_currency_code=RUB принимается и в ответе возвращается как RUB, delta считается корректно. */
    public function test_rates_accepts_base_currency_rub_and_normalizes(): void
    {
        CurrencyRate::create([
            'date' => '2025-02-20',
            'currency_code' => 'USD',
            'base_currency_code' => 'RUR',
            'rate' => 90.0,
            'nominal' => 1,
        ]);
        CurrencyRate::create([
            'date' => '2025-02-19',
            'currency_code' => 'USD',
            'base_currency_code' => 'RUR',
            'rate' => 89.0,
            'nominal' => 1,
        ]);

        $response = $this->getJson('/api/rates?date=2025-02-20&currency_code=USD&base_currency_code=RUB');

        $response->assertStatus(200)
            ->assertJsonPath('base_currency_code', 'RUB')
            ->assertJsonPath('rate', 90)
            ->assertJsonPath('delta', 1);
    }

    /** Проверяем: когда есть курс только за запрошенную дату (нет предыдущего торгового дня) — previous_trade_date и delta в ответе null. */
    public function test_rates_returns_null_delta_when_no_previous_trading_day(): void
    {
        CurrencyRate::create([
            'date' => '2025-02-20',
            'currency_code' => 'USD',
            'base_currency_code' => 'RUR',
            'rate' => 95.0,
            'nominal' => 1,
        ]);

        $this->instance(CbrClientInterface::class, Mockery::mock(CbrClientInterface::class, function ($mock): void {
            $mock->shouldReceive('getRateByDateAndCode')->andReturn(null);
        }));

        $response = $this->getJson('/api/rates?date=2025-02-20&currency_code=USD&base_currency_code=RUR');

        $response->assertStatus(200)
            ->assertJsonPath('rate', 95)
            ->assertJsonPath('previous_trade_date', null)
            ->assertJsonPath('delta', null);
    }
}
