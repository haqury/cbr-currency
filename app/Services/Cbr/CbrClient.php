<?php

declare(strict_types=1);

namespace App\Services\Cbr;

use App\Contracts\CbrClientInterface;
use App\Services\Cbr\Dto\CbrRateDto;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Client for CBR (Central Bank of Russia) daily XML rates.
 * Кодировка ответа: Windows-1251 → UTF-8; запятые в числах заменяются на точки.
 */
final class CbrClient implements CbrClientInterface
{
    private const string URL_TEMPLATE = 'https://www.cbr.ru/scripts/XML_daily.asp?date_req=%s';

    private const string CACHE_KEY_PREFIX = 'cbr:rates:';

    /** Версия ключа кэша: при изменении структуры DTO увеличить, чтобы не читать старый формат. */
    private const string CACHE_VERSION = 'v1';

    /** TTL in seconds (24 hours; historical data does not change). */
    private const int CACHE_TTL = 86400;

    /**
     * Fetch all rates for the given date (Y-m-d).
     * Uses Redis cache: key cbr:rates:{version}:{date}, TTL 24h.
     *
     * @param  string  $date  Date in Y-m-d format
     * @return list<CbrRateDto>
     */
    public function getRatesByDate(string $date): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX.self::CACHE_VERSION.':'.$date;

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $rates = $this->fetchAndParse($date);
        Cache::put($cacheKey, $rates, self::CACHE_TTL);

        return $rates;
    }

    /**
     * Fetch rates for one currency on the given date.
     * Returns null if not found (e.g. weekend with no data).
     *
     * @param  string  $date  Date in Y-m-d format
     * @param  string  $currencyCode  e.g. USD
     */
    public function getRateByDateAndCode(string $date, string $currencyCode): ?CbrRateDto
    {
        $rates = $this->getRatesByDate($date);
        foreach ($rates as $dto) {
            if (strtoupper($dto->currencyCode) === strtoupper($currencyCode)) {
                return $dto;
            }
        }

        return null;
    }

    /**
     * Return list of currency codes published by CBR for the given date (uses same cache as getRatesByDate).
     *
     * @return list<string>
     */
    public function getAvailableCurrencyCodes(string $date): array
    {
        $rates = $this->getRatesByDate($date);
        $codes = [];
        foreach ($rates as $dto) {
            $codes[] = $dto->currencyCode;
        }

        return $codes;
    }

    /**
     * Perform HTTP request, convert encoding, parse XML, return DTOs.
     *
     * @param  string  $date  Y-m-d
     * @return list<CbrRateDto>
     */
    private function fetchAndParse(string $date): array
    {
        $dateReq = $this->formatDateForCbr($date);
        $url = sprintf(self::URL_TEMPLATE, $dateReq);

        $verifySsl = config('cbr.verify_ssl', true);

        $client = new Client(['timeout' => 15]);
        $requestOptions = [
            'verify' => $verifySsl,
        ];
        if (! $verifySsl) {
            $requestOptions['curl'] = [
                \CURLOPT_SSL_VERIFYPEER => false,
                \CURLOPT_SSL_VERIFYHOST => 0,
            ];
        }

        try {
            $response = $client->get($url, $requestOptions);
        } catch (GuzzleException $e) {
            Log::warning('CBR request failed', ['url' => $url, 'message' => $e->getMessage()]);
            throw $e;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            Log::warning('CBR request failed', ['url' => $url, 'status' => $response->getStatusCode()]);

            return [];
        }

        $body = (string) $response->getBody();
        $utf8 = $this->convertToUtf8($body);
        if ($utf8 === null) {
            Log::warning('CBR response encoding conversion failed', ['url' => $url]);

            return [];
        }

        return $this->parseXml($utf8, $date);
    }

    private function formatDateForCbr(string $ymd): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $ymd);
        if ($dt === false) {
            return $ymd;
        }

        return $dt->format('d/m/Y');
    }

    private function convertToUtf8(string $body): ?string
    {
        if ($body === '') {
            return '';
        }
        $utf8 = @iconv('windows-1251', 'utf-8//IGNORE', $body);
        if ($utf8 === false) {
            return null;
        }
        // So that SimpleXML does not misinterpret UTF-8 as Windows-1251.
        $utf8 = preg_replace(
            '/encoding\s*=\s*["\']windows-1251["\']/i',
            'encoding="utf-8"',
            $utf8,
            1
        );

        return $utf8;
    }

    /**
     * @return list<CbrRateDto>
     */
    private function parseXml(string $xml, string $dateYmd): array
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = new \SimpleXMLElement($xml);
        } catch (\Exception $e) {
            Log::warning('CBR XML parse failed', ['message' => $e->getMessage()]);

            return [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }

        $rates = [];
        $list = $doc->xpath('//ValCurs/Valute') ?: $doc->xpath('//Valute');
        if (! is_array($list) || $list === []) {
            return [];
        }

        foreach ($list as $valute) {
            $charCode = $this->text($valute, 'CharCode');
            $valueStr = $this->text($valute, 'Value');
            $nominalStr = $this->text($valute, 'Nominal');
            if ($charCode === '' || $valueStr === '') {
                continue;
            }
            $rate = $this->parseDecimal($valueStr);
            $nominal = (int) preg_replace('/\s+/', '', $nominalStr) ?: 1;
            $name = $this->text($valute, 'Name');
            $numCode = $this->text($valute, 'NumCode');
            $rates[] = new CbrRateDto(
                date: $dateYmd,
                currencyCode: $charCode,
                rate: $rate,
                nominal: $nominal,
                baseCurrencyCode: 'RUR',
                name: $name !== '' ? $name : null,
                numCode: $numCode !== '' ? $numCode : null,
            );
        }

        return $rates;
    }

    private function text(\SimpleXMLElement $el, string $child): string
    {
        $node = $el->{$child};
        if ($node === null || $node->count() === 0) {
            return '';
        }

        return trim((string) $node);
    }

    private function parseDecimal(string $value): float
    {
        $normalized = str_replace(',', '.', $value);
        $normalized = preg_replace('/\s+/', '', $normalized);

        return (float) $normalized;
    }
}
