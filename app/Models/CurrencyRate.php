<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CurrencyRate extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'currency_rates';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'date',
        'currency_code',
        'base_currency_code',
        'rate',
        'nominal',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'rate' => 'decimal:6',
            'nominal' => 'integer',
        ];
    }

    /**
     * Scope: filter by date.
     */
    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('date', $date);
    }

    /**
     * Scope: filter by currency code.
     */
    public function scopeForCurrency(Builder $query, string $currencyCode): Builder
    {
        return $query->where('currency_code', $currencyCode);
    }

    /**
     * Scope: filter by base currency code.
     */
    public function scopeForBaseCurrency(Builder $query, string $baseCurrencyCode): Builder
    {
        return $query->where('base_currency_code', $baseCurrencyCode);
    }
}
