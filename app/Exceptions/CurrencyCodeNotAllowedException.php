<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a currency code is not allowed: not in ISO 4217 or not published by CBR for the date.
 * Выбрасывается, если код валюты не допускается: нет в ISO 4217 или ЦБ не публикует курс на дату.
 */
final class CurrencyCodeNotAllowedException extends Exception
{
    public static function notIso4217(string $code): self
    {
        return new self("Код валюты «{$code}» не входит в справочник ISO 4217 (допускается RUR).");
    }

    public static function notPublishedByCbr(string $code, string $date): self
    {
        return new self("ЦБ РФ не публикует курс для валюты «{$code}» на дату {$date}.");
    }
}
