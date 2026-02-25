<?php

use App\Console\Commands\SyncCurrencyHistoryCommand;
use App\Console\Commands\TestCbrCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::registerCommand(app(SyncCurrencyHistoryCommand::class));
Artisan::registerCommand(app(TestCbrCommand::class));
