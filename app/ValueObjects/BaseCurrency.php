<?php

declare(strict_types=1);

namespace App\ValueObjects;

/**
 * Объект базовой валюты, который знает, как нормализовать коды
 * для хранения (БД) и отображения.
 */
final class BaseCurrency
{
    public function __construct(
        private string $inputCode,
        private string $storageCode,
        private string $displayCode,
    ) {}

    public static function fromInput(string $code): self
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') {
            throw new \InvalidArgumentException('Base currency code cannot be empty.');
        }

        if ($normalized === 'RUR' || $normalized === 'RUB') {
            return new self(
                inputCode: $normalized,
                storageCode: 'RUR',
                displayCode: $normalized === 'RUB' ? 'RUB' : 'RUR',
            );
        }

        return new self(
            inputCode: $normalized,
            storageCode: $normalized,
            displayCode: $normalized,
        );
    }

    public function input(): string
    {
        return $this->inputCode;
    }

    public function storage(): string
    {
        return $this->storageCode;
    }

    public function display(): string
    {
        return $this->displayCode;
    }
}
