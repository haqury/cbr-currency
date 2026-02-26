<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Validates currency codes against ISO 4217 (via moneyphp/iso-currencies).
 * RUR is allowed as legacy alias for RUB (CBR uses RUR).
 * Проверка кода валюты по ISO 4217; RUR допускается как алиас RUB.
 */
final class CurrencyCodeValidator
{
    private const RUR_ALIAS = 'RUR';

    /** @var array<string, mixed>|null */
    private static ?array $isoCodes = null;

    public function isValid(string $code): bool
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return false;
        }
        if ($code === self::RUR_ALIAS) {
            return true;
        }

        return array_key_exists($code, $this->getIsoCodes());
    }

    /**
     * @return array<string, mixed>
     */
    private function getIsoCodes(): array
    {
        if (self::$isoCodes !== null) {
            return self::$isoCodes;
        }
        $path = base_path('vendor/moneyphp/iso-currencies/resources/current.php');
        if (! is_file($path)) {
            return [];
        }
        $data = require $path;
        self::$isoCodes = is_array($data) ? $data : [];

        return self::$isoCodes;
    }
}
