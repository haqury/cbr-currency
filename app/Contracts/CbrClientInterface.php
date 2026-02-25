<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\Cbr\Dto\CbrRateDto;

/**
 * Abstraction for fetching CBR (or other) currency rates by date.
 * Dependency Inversion: depend on this, not on CbrClient.
 */
interface CbrClientInterface
{
    /**
     * Fetch all rates for the given date (Y-m-d).
     *
     * @param string $date Date in Y-m-d format
     * @return list<CbrRateDto>
     */
    public function getRatesByDate(string $date): array;

    /**
     * Fetch rate for one currency on the given date.
     * Returns null if not found (e.g. weekend).
     *
     * @param string $date         Date in Y-m-d format
     * @param string $currencyCode e.g. USD
     * @return CbrRateDto|null
     */
    public function getRateByDateAndCode(string $date, string $currencyCode): ?CbrRateDto;
}
