<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('currency_rates', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('currency_code');
            $table->string('base_currency_code')->default('RUR');
            $table->decimal('rate', 18, 6);
            $table->unsignedInteger('nominal')->default(1);
            $table->timestamps();

            // Составной индекс по полям входа: дата, код валюты, базовая валюта (для поиска и идемпотентности)
            $table->unique(['date', 'currency_code', 'base_currency_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('currency_rates');
    }
};
