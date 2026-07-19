<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// CLI-only application — this single route exists so serving the app doesn't 404.
Route::get('/', fn () => response(
    sprintf("%s %s — CLI-only. Run `php artisan list analyse`.\n", config('app.name'), app()->version()),
    200,
    ['Content-Type' => 'text/plain'],
))->name('home');
