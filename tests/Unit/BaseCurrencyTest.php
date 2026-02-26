<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\ValueObjects\BaseCurrency;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Unit-тесты BaseCurrency: нормализация кодов базовой валюты. */
final class BaseCurrencyTest extends TestCase
{
    /** Проверяем: при вводе RUB код для хранения RUR, для отображения RUB. */
    public function test_from_input_with_rub_normalizes_storage_to_rur_and_display_to_rub(): void
    {
        $currency = BaseCurrency::fromInput('RUB');

        $this->assertSame('RUB', $currency->input());
        $this->assertSame('RUR', $currency->storage());
        $this->assertSame('RUB', $currency->display());
    }

    /** Проверяем: при вводе RUR код для хранения и отображения RUR. */
    public function test_from_input_with_rur_keeps_rur_for_storage_and_display(): void
    {
        $currency = BaseCurrency::fromInput('RUR');

        $this->assertSame('RUR', $currency->input());
        $this->assertSame('RUR', $currency->storage());
        $this->assertSame('RUR', $currency->display());
    }

    /** Проверяем: при вводе произвольного кода он нормализуется к верхнему регистру без пробелов. */
    public function test_from_input_trims_and_uppercases_other_codes(): void
    {
        $currency = BaseCurrency::fromInput('  usd ');

        $this->assertSame('USD', $currency->input());
        $this->assertSame('USD', $currency->storage());
        $this->assertSame('USD', $currency->display());
    }

    /** Проверяем: при пустой строке выбрасывается InvalidArgumentException. */
    public function test_from_input_throws_on_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BaseCurrency::fromInput('  ');
    }
}

