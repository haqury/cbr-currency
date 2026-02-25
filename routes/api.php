<?php

declare(strict_types=1);

use App\Http\Controllers\RatesController;
use Illuminate\Support\Facades\Route;

Route::get('/rates', [RatesController::class, 'index']);
