<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Индекс для запроса «предыдущий торговый день»: максимальная дата < X по валюте.
     * base_currency_code не включён — в таблице почти всегда одно значение (RUR), селективность низкая.
     */
    public function up(): void
    {
        Schema::table('currency_rates', function (Blueprint $table): void {
            $table->index(
                ['currency_code', 'date'],
                'currency_rates_prev_trade_date_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('currency_rates', function (Blueprint $table): void {
            $table->dropIndex('currency_rates_prev_trade_date_idx');
        });
    }
};
