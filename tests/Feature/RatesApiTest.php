<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\CbrClientInterface;
use App\Models\CurrencyRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class RatesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_rates_returns_422_when_base_currency_code_invalid(): void
    {
        $response = $this->getJson('/api/rates?date=2025-02-20&currency_code=USD&base_currency_code=EUR');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['base_currency_code']);
    }

    public function test_rates_returns_422_when_date_missing(): void
    {
        $response = $this->getJson('/api/rates?currency_code=USD');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    public function test_rates_returns_422_when_date_format_invalid(): void
    {
        $response = $this->getJson('/api/rates?date=20-02-2025&currency_code=USD');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    public function test_rates_returns_422_when_currency_code_missing(): void
    {
        $response = $this->getJson('/api/rates?date=2025-02-20');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['currency_code']);
    }

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
}
