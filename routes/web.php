<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Read-only results dashboard over the emitted dataset (M5) — no write flows.
Route::view('results', 'results')->name('results');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
